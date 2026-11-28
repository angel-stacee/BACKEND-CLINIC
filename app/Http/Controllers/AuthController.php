<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $rawLogin = (string) $request->input('email', $request->input('login', ''));
        $loginInput = Str::lower(trim(str_replace(',', '.', $rawLogin)));
        $inputPassword = (string) $request->input('password', '');

        $request->validate([
            'password' => 'required|string',
        ]);

        if ($loginInput === '') {
            return response()->json([
                'message' => 'Email or user identifier is required.',
            ], 422);
        }

        $throttleKey = 'login_attempt:' . Str::transliterate($loginInput . '|' . $request->ip());

        // Check if user has exceeded 5 failed attempts
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Too many failed login attempts (5/5). Account temporarily locked. Please try again in {$seconds} seconds or reset your password.",
                'locked' => true,
                'retry_after' => $seconds,
                'remaining_attempts' => 0,
            ], 429);
        }

        $user = User::query()
            ->select(['id', 'name', 'email', 'role', 'profile_picture', 'password'])
            ->where('email', $loginInput)
            ->when(ctype_digit($loginInput), fn ($q) => $q->orWhere('id', (int) $loginInput))
            ->first();

        $isValidPassword = $user && Hash::check($inputPassword, $user->password);

        if (! $user || ! $isValidPassword) {
            // Register failed attempt (locked for 60 seconds upon reaching 5 attempts)
            RateLimiter::hit($throttleKey, 60);
            $remaining = RateLimiter::remaining($throttleKey, 5);

            if ($remaining <= 0) {
                $seconds = RateLimiter::availableIn($throttleKey);

                return response()->json([
                    'message' => "Too many failed login attempts (5/5). Account temporarily locked for {$seconds} seconds. Please use Forgot Password to reset your password.",
                    'locked' => true,
                    'retry_after' => $seconds,
                    'remaining_attempts' => 0,
                ], 429);
            }

            return response()->json([
                'message' => "Invalid email or password. You have {$remaining} attempt(s) remaining.",
                'remaining_attempts' => $remaining,
            ], 401);
        }

        // On successful credentials, clear the rate limiter
        RateLimiter::clear($throttleKey);

        \App\Services\AuditLogService::record(
            'auth.login',
            "User {$user->email} logged in",
            'user',
            $user->id,
            [],
            $user,
            $request
        );

        $token = $user->createToken(
            'clinic-system-token'
        )->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            if ($user) {
                \App\Services\AuditLogService::record(
                    'auth.logout',
                    "User {$user->email} logged out",
                    'user',
                    $user->id,
                    [],
                    $user,
                    $request
                );
            }
            $user?->currentAccessToken()?->delete();
        } catch (\Throwable $e) {
            // Ignore token deletion error on logout
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        if ($request->has('email')) {
            $request->merge([
                'email' => Str::lower(trim((string) $request->input('email'))),
            ]);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'avatar' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }

        // Account email is permanent and cannot be changed via profile update

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $extension = strtolower($file->getClientOriginalExtension());
            $fileName = 'avatar_'.$user->id.'_'.time().'.'.$extension;
            $destination = public_path('storage/avatars');

            if ($user->profile_picture && ! str_starts_with($user->profile_picture, 'data:')) {
                $oldFile = public_path($user->profile_picture);
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }

            try {
                if (! is_dir($destination)) {
                    @mkdir($destination, 0755, true);
                }
                $file->move($destination, $fileName);
            } catch (\Throwable $e) {
                // Ignore filesystem failure if disk is restricted
            }

            $localPath = '/storage/avatars/'.$fileName;
            $savedFilePath = $destination.'/'.$fileName;
            $dataUrl = null;

            if (file_exists($savedFilePath) && filesize($savedFilePath) <= 3 * 1024 * 1024) {
                $mime = mime_content_type($savedFilePath) ?: ('image/'.$extension);
                $dataUrl = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($savedFilePath));
            }

            if ($dataUrl) {
                try {
                    $user->profile_picture = $dataUrl;
                    $user->save();
                } catch (\Throwable $e) {
                    $user->profile_picture = $localPath;
                    $user->save();
                }
            } else {
                $user->profile_picture = $localPath;
                $user->save();
            }
        } else {
            $user->save();
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }

    public function sendResetOtp(Request $request)
    {
        $rawEmail = (string) $request->input('email', '');
        $cleanEmail = Str::lower(trim(str_replace(',', '.', $rawEmail)));

        $request->merge([
            'email' => $cleanEmail,
        ]);

        $request->validate([
            'email' => 'required|email',
        ]);

        $email = $cleanEmail;

        $user = User::where('email', $email)->first();

        if (! $user) {
            return response()->json([
                'message' => 'No account was found with that email address.',
            ], 404);
        }

        // Throttle check: allow resend after 10s cooldown
        $cooldown = 10;
        $existing = DB::table('password_reset_tokens')->where('email', $email)->first();
        if ($existing && $existing->created_at) {
            $elapsed = (int) Carbon::parse($existing->created_at, 'UTC')->diffInSeconds(now('UTC'));
            if ($elapsed < $cooldown) {
                $secondsRemaining = $cooldown - $elapsed;

                return response()->json([
                    'message' => "An OTP code was already sent recently. Please wait {$secondsRemaining} seconds before requesting a new code.",
                ], 429);
            }
        }

        // Generate 6-digit numeric OTP token
        $otp = $this->generateOtp();

        // Store hashed token and timestamp in password_reset_tokens
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($otp),
                'created_at' => now(),
            ]
        );

        $fromAddress = config('mail.from.address');
        if (! $fromAddress || $fromAddress === 'null' || $fromAddress === 'hello@example.com') {
            $fromAddress = env('MAIL_FROM_ADDRESS') ?: config('mail.mailers.smtp.username') ?: env('MAIL_USERNAME');
        }
        $fromName = config('mail.from.name') ?: env('MAIL_FROM_NAME') ?: 'TCC CLINIC';

        if (! app()->environment('testing')) {
            config(['mail.mailers.smtp.timeout' => (int) (env('MAIL_TIMEOUT') ?: 4)]);
        }

        $tccLogoUrl = 'https://raw.githubusercontent.com/angel-stacee/TCCCMS/main/public/tcc-logo.png';

        $htmlBody = '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Arial, sans-serif; max-width: 560px; margin: 0 auto; padding: 28px 24px; border: 1px solid #e8dfd4; border-radius: 16px; background-color: #ffffff; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
            <div style="text-align: center; margin-bottom: 24px;">
                <img src="'.$tccLogoUrl.'" alt="Tagoloan Community College" width="80" height="80" style="display: block; margin: 0 auto 12px auto; width: 80px; height: 80px; border-radius: 50%; border: 3px solid #d4a017; box-shadow: 0 4px 10px rgba(112,9,31,0.18); object-fit: cover;" />
                <h2 style="color: #70091f; margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.3px;">Tagoloan Community College</h2>
                <p style="color: #665b50; font-size: 14px; margin: 4px 0 0 0; font-weight: 600;">Clinic Management System</p>
                <div style="display: inline-block; margin-top: 10px; padding: 4px 12px; background-color: #fdf6ec; border: 1px solid #f3d19c; border-radius: 999px; color: #92400e; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px;">
                    Password Reset Verification Code
                </div>
            </div>
            <div style="padding: 24px; background-color: #faf7f2; border-radius: 12px; border: 1px solid #ebd9c8; text-align: center;">
                <p style="font-size: 15px; color: #2d241e; margin-top: 0; font-weight: 500;">Hello <strong style="color: #70091f;">'.htmlspecialchars($user->name).'</strong>,</p>
                <p style="font-size: 14px; color: #5a4f44; line-height: 1.5; margin: 0 0 16px 0;">Use the 6-digit OTP verification code below to reset your clinic account password:</p>
                <div style="margin: 16px auto; font-size: 36px; font-weight: 800; letter-spacing: 10px; color: #70091f; background: #ffffff; padding: 14px 28px; display: inline-block; border-radius: 12px; border: 2px dashed #70091f; box-shadow: 0 2px 8px rgba(112,9,31,0.08);">
                    '.$otp.'
                </div>
                <p style="font-size: 13px; color: #7d7065; margin: 16px 0 0 0;">This code is valid for <strong>15 minutes</strong>.</p>
            </div>
            <p style="font-size: 12px; color: #9e9389; margin-top: 24px; text-align: center; line-height: 1.5;">
                If you did not request this password reset, please ignore this email or contact the TCC Clinic Administrator immediately.<br />
                &copy; '.date('Y').' Tagoloan Community College Health Services Clinic
            </p>
        </div>';

        try {
            $brevoKey = env('BREVO_API_KEY');
            $resendKey = env('RESEND_API_KEY');

            if ($brevoKey) {
                // Send via Brevo REST API over HTTPS (Port 443) - works seamlessly on Render!
                $apiRes = Http::withHeaders([
                    'api-key' => $brevoKey,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ])->timeout(12)->post('https://api.brevo.com/v3/smtp/email', [
                    'sender' => [
                        'name' => $fromName,
                        'email' => $fromAddress,
                    ],
                    'to' => [
                        [
                            'email' => $user->email,
                            'name' => $user->name,
                        ],
                    ],
                    'subject' => 'Your TCC Clinic OTP Code',
                    'htmlContent' => $htmlBody,
                ]);

                if (! $apiRes->successful()) {
                    throw new \Exception('Brevo API error ('.$apiRes->status().'): '.$apiRes->body());
                }
            } elseif ($resendKey) {
                // Send via Resend REST API over HTTPS (Port 443)
                $apiRes = Http::withHeaders([
                    'Authorization' => 'Bearer '.$resendKey,
                    'content-type' => 'application/json',
                ])->timeout(12)->post('https://api.resend.com/emails', [
                    'from' => "{$fromName} <{$fromAddress}>",
                    'to' => [$user->email],
                    'subject' => 'Your TCC Clinic OTP Code',
                    'html' => $htmlBody,
                ]);

                if (! $apiRes->successful()) {
                    Log::warning('Resend API error ('.$apiRes->status().'): '.$apiRes->body());
                }
            } else {
                Mail::html($htmlBody, function ($message) use ($user, $fromAddress, $fromName) {
                    $message->from($fromAddress, $fromName)
                        ->to($user->email, $user->name)
                        ->subject('Your TCC Clinic OTP Code');
                });
            }

            return response()->json([
                'message' => "A 6-digit OTP verification code has been sent to {$user->email}. Please check your inbox (and Spam folder).",
            ]);
        } catch (\Throwable $e) {
            Log::error("Mail delivery failed for {$user->email}: ".$e->getMessage());

            return response()->json([
                'message' => 'Unable to send verification email right now. Please contact the clinic administrator or try again later.',
            ], 503);
        }
    }

    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function verifyResetOtp(Request $request)
    {
        $rawEmail = (string) $request->input('email', '');
        $cleanEmail = Str::lower(trim(str_replace(',', '.', $rawEmail)));

        $request->merge([
            'email' => $cleanEmail,
            'otp' => trim((string) $request->input('otp')),
        ]);

        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        $email = $cleanEmail;
        $otp = $request->input('otp');

        $user = User::where('email', $email)->first();

        if (! $user) {
            return response()->json([
                'message' => 'No account was found with that email address.',
            ], 404);
        }

        $otpThrottleKey = 'otp_verify:' . Str::transliterate($email . '|' . $request->ip());
        if (RateLimiter::tooManyAttempts($otpThrottleKey, 5)) {
            $seconds = RateLimiter::availableIn($otpThrottleKey);
            return response()->json([
                'message' => "Too many invalid OTP attempts. Please try again in {$seconds} seconds or request a new code.",
            ], 429);
        }

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        $isValidOtp = false;
        if ($record && ! Carbon::parse($record->created_at)->addMinutes(15)->isPast()) {
            if (Hash::check($otp, $record->token)) {
                $isValidOtp = true;
            }
        }

        if (! $isValidOtp) {
            RateLimiter::hit($otpThrottleKey, 900);
            return response()->json([
                'message' => 'Invalid or expired OTP code. Please enter the 6-digit verification code sent to your email.',
            ], 400);
        }

        return response()->json([
            'message' => 'OTP verified successfully.',
            'valid' => true,
        ]);
    }

    public function resetPasswordWithOtp(Request $request)
    {
        $rawEmail = (string) $request->input('email', '');
        $cleanEmail = Str::lower(trim(str_replace(',', '.', $rawEmail)));

        $request->merge([
            'email' => $cleanEmail,
            'otp' => trim((string) $request->input('otp')),
        ]);

        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $email = $cleanEmail;
        $otp = $request->input('otp');

        $user = User::where('email', $email)->first();

        if (! $user) {
            return response()->json([
                'message' => 'Account not found.',
            ], 404);
        }

        $otpThrottleKey = 'otp_verify:' . Str::transliterate($email . '|' . $request->ip());
        if (RateLimiter::tooManyAttempts($otpThrottleKey, 5)) {
            $seconds = RateLimiter::availableIn($otpThrottleKey);
            return response()->json([
                'message' => "Too many invalid OTP attempts. Please try again in {$seconds} seconds or request a new code.",
            ], 429);
        }

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        $isValidOtp = false;
        if ($record && ! Carbon::parse($record->created_at)->addMinutes(15)->isPast()) {
            if (Hash::check($otp, $record->token)) {
                $isValidOtp = true;
            }
        }

        if (! $isValidOtp) {
            RateLimiter::hit($otpThrottleKey, 900);
            return response()->json([
                'message' => 'Invalid or expired OTP code. Please enter the 6-digit verification code sent to your email.',
            ], 400);
        }

        RateLimiter::clear($otpThrottleKey);

        $user->update([
            'password' => Hash::make($request->input('password')),
        ]);

        // Revoke active sessions / sanctum tokens for security
        $user->tokens()->delete();

        // Remove token from password_reset_tokens
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'message' => 'Password has been reset successfully. You can now log in with your new password.',
        ]);
    }

    private function userPayload(User $user): array
    {
        return $user->only([
            'id',
            'name',
            'email',
            'role',
            'profile_picture',
        ]);
    }
}

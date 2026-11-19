<?php

namespace App\Http\Controllers;

use App\Models\ClinicVisit;
use App\Models\Faculty;
use App\Models\Medicine;
use App\Models\Staff;
use App\Models\Student;
use App\Services\GeminiContentGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiChatbotController extends Controller
{
    private const OUT_OF_SCOPE_REPLY = 'I can only help with TCC Clinic topics, such as clinic visits, patient records, medicine inventory, clinic workflows, and general health questions. Please ask a clinic-related question.';

    public function __construct(private GeminiContentGenerator $gemini) {}

    public function chat(Request $request)
    {
        $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:30'],
            'messages.*.role' => ['required', 'in:user,model'],
            'messages.*.content' => ['required', 'string', 'max:2000'],
        ]);

        $messages = $request->input('messages');

        if (end($messages)['role'] !== 'user') {
            return response()->json([
                'message' => 'Send a question to continue the conversation.',
            ], 422);
        }

        $latestUserMessage = end($messages)['content'] ?? '';

        // Check if user is initiating or continuing an interactive patient intake conversation
        $intakeResponse = $this->handlePatientIntake($messages);
        if ($intakeResponse !== null) {
            return response()->json($intakeResponse);
        }

        if (! $this->isClinicRelatedQuestion($latestUserMessage)) {
            return response()->json([
                'message' => self::OUT_OF_SCOPE_REPLY,
            ]);
        }

        $apiKey = config('services.gemini.key');
        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $fallbackModel = config('services.gemini.fallback_model', 'gemini-1.5-flash');

        $user = $request->user();
        $groundingData = $this->buildDatabaseGroundingContext($latestUserMessage, $user);

        $contents = collect($messages)
            ->map(function ($message) {
                return [
                    'role' => $message['role'],
                    'parts' => [
                        [
                            'text' => $message['content'],
                        ],
                    ],
                ];
            })
            ->values()
            ->all();

        while (
            ! empty($contents) &&
            $contents[0]['role'] !== 'user'
        ) {
            array_shift($contents);
        }

        if (empty($contents)) {
            return response()->json([
                'message' => 'Please enter a message.',
            ], 422);
        }

        $clinicSnapshot = [
            'students' => Student::count(),
            'staff' => Staff::count(),
            'faculty' => Faculty::count(),
            'clinic_visits_this_month' => ClinicVisit::query()
                ->whereBetween('visit_date', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'medicines' => [
                'total' => Medicine::count(),
                'low_stock' => Medicine::query()
                    ->where('stock', '>', 0)
                    ->whereColumn('stock', '<=', 'minimum_stock')
                    ->count(),
                'out_of_stock' => Medicine::query()
                    ->where('stock', '<=', 0)
                    ->count(),
            ],
        ];

        $systemInstruction = "You are the TCC Clinic Management System assistant.\n\n"
            .'Answer TCC Clinic Management System questions: system navigation, clinic workflows, clinic visits, patient records, medicine inventory, aggregate clinic statistics, and general health education. '
            .'If a request is outside those clinic topics, reply only that you can help with TCC Clinic topics. Never answer unrelated requests. '
            ."LIVE VERIFIED DATABASE CONTEXT (retrieved live from the MySQL database):\n"
            .$groundingData['facts_text']."\n\n"
            ."Aggregate system snapshot:\n"
            .json_encode($clinicSnapshot, JSON_PRETTY_PRINT)."\n\n"
            ."CRITICAL ACCURACY RULES:\n"
            ."1. Use ONLY the verified database facts provided above when answering questions about students, medicines, inventory, and clinic visit statistics. Never invent fake student records, non-existent medicines, or false numbers.\n"
            ."2. If a student or medicine is not found in the database, clearly state that no matching record was found in the clinic database.\n"
            ."3. Do not diagnose or prescribe. Keep answers concise, clear, and professional with markdown formatting.\n"
            ."4. Do not modify or delete records unless the user is going through the explicit confirmation step of the patient registration workflow.";

        if (! $apiKey) {
            $offlineReply = $groundingData['direct_reply'] ?? $this->generateOfflineReply($latestUserMessage, $clinicSnapshot, $groundingData);

            return response()->json([
                'message' => $offlineReply,
            ]);
        }

        try {
            $response = $this->gemini->generate(
                $apiKey,
                $model,
                $systemInstruction,
                $contents
            );

            if (
                $response->failed()
                && $fallbackModel
                && $fallbackModel !== $model
            ) {
                Log::warning('Gemini primary model unavailable; using fallback model.', [
                    'status' => $response->status(),
                ]);

                $response = $this->gemini->generate(
                    $apiKey,
                    $fallbackModel,
                    $systemInstruction,
                    $contents
                );
            }

            if ($response->successful()) {
                $data = $response->json();
                $parts = data_get($data, 'candidates.0.content.parts', []);
                $message = collect($parts)->pluck('text')->filter()->implode("\n");

                if ($message) {
                    return response()->json([
                        'message' => $message,
                    ]);
                }
            }

            Log::error('Gemini API Error, falling back to database grounded reply', [
                'status' => $response->status(),
                'provider_message' => data_get($response->json(), 'error.message'),
            ]);

            $offlineReply = $groundingData['direct_reply'] ?? $this->generateOfflineReply($latestUserMessage, $clinicSnapshot, $groundingData);

            return response()->json([
                'message' => $offlineReply,
            ]);

        } catch (\Throwable $e) {
            Log::error('AI Chat Exception, falling back to database grounded reply', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            $offlineReply = $groundingData['direct_reply'] ?? $this->generateOfflineReply($latestUserMessage, $clinicSnapshot, $groundingData);

            return response()->json([
                'message' => $offlineReply,
            ]);
        }
    }

    private function handlePatientIntake(array $messages): ?array
    {
        $latestUserMessage = trim(end($messages)['content'] ?? '');

        // Find if any assistant message in the conversation is part of [Patient Registration]
        $hasIntakeHistory = false;
        $lastAssistantIntakeMsg = null;
        foreach (array_reverse($messages) as $m) {
            if ($m['role'] === 'model' && str_contains($m['content'], '[Patient Registration]')) {
                $hasIntakeHistory = true;
                if ($lastAssistantIntakeMsg === null) {
                    $lastAssistantIntakeMsg = $m['content'];
                }
            }
        }

        // Did the user trigger patient registration in this turn or earlier?
        $isStartTrigger = (bool) preg_match(
            '/\b(add|register|record|create|input)\b.*\b(patient|student|staff|faculty)\b/i',
            $latestUserMessage
        ) || (bool) preg_match(
            '/\b(add\s+new\s+patient|new\s+patient|patient\s+registration|record.*new.*patient|talk.*record.*patient)\b/i',
            $latestUserMessage
        );

        if (! $hasIntakeHistory && ! $isStartTrigger) {
            return null;
        }

        // Handle cancellation
        if (preg_match('/^(cancel|abort|stop|exit|quit|never\s*mind|no\b|wag|ayoko)/i', $latestUserMessage)) {
            if ($hasIntakeHistory && ! str_contains($lastAssistantIntakeMsg ?? '', 'Would you like me to record this patient now?')) {
                return [
                    'message' => "❌ **Patient Registration Cancelled.**\n\nNo records were saved. How else can I assist you today with clinic workflows or statistics?",
                ];
            }
        }

        // If the previous assistant message asked for confirmation to save
        if ($lastAssistantIntakeMsg && str_contains($lastAssistantIntakeMsg, 'Would you like me to record this patient now?')) {
            if (preg_match('/^(yes|confirm|proceed|save|ok|okay|sure|sige|oo|yes\s+please|record)\b/i', $latestUserMessage)) {
                return $this->finalizePatientRegistration($lastAssistantIntakeMsg);
            }

            if (preg_match('/^(no|cancel|stop|abort|don\'t|dont|wag|hindi)\b/i', $latestUserMessage)) {
                return [
                    'message' => "❌ **Patient Registration Cancelled.**\n\nNo records were saved. Let me know if you would like to start over or need anything else!",
                ];
            }
        }

        // Extract accumulated state across the conversation history
        $state = $this->extractIntakeState($messages);

        // 1. Missing Category?
        if (empty($state['category'])) {
            return [
                'message' => "📋 [Patient Registration]\n\n"
                    ."I would be glad to help you record a new patient right here in the chat!\n\n"
                    ."What category is the patient?\n"
                    ."1️⃣ **Student**\n"
                    ."2️⃣ **Faculty**\n"
                    ."3️⃣ **Staff**\n\n"
                    .'*(Reply with **Student**, **Faculty**, or **Staff**)*',
            ];
        }

        $category = $state['category'];
        $catLabel = ucfirst($category);
        $idLabel = $category === 'student' ? 'Student ID' : ($category === 'faculty' ? 'Faculty ID' : 'Staff ID');

        // 2. Missing Full Name?
        if (empty($state['full_name'])) {
            return [
                'message' => "📋 [Patient Registration]\n\n"
                    ."Great! We are registering a **{$catLabel}**.\n\n"
                    ."What is the patient's **Full Name**?\n"
                    .'*(e.g., Juan Dela Cruz or First: Maria, Last: Santos)*',
            ];
        }

        $fullName = $state['full_name'];

        // 3. Missing ID?
        if (empty($state['identifier'])) {
            return [
                'message' => "📋 [Patient Registration]\n\n"
                    ."Got it! What is **{$fullName}**'s **{$idLabel} number**?\n"
                    .'*(numbers only, e.g. 20241047)*',
            ];
        }

        $identifier = $state['identifier'];

        // Check if ID is already registered in DB
        $idExists = false;
        if ($category === 'student') {
            $idExists = Student::where('student_id', $identifier)->exists();
        } elseif ($category === 'faculty') {
            $idExists = Faculty::where('employee_id', $identifier)->exists();
        } else {
            $idExists = Staff::where('staff_id', $identifier)->exists();
        }

        if ($idExists) {
            return [
                'message' => "📋 [Patient Registration]\n\n"
                    ."⚠️ The {$idLabel} **{$identifier}** is already registered to an existing patient in the database!\n\n"
                    ."Please reply with a different {$idLabel} number for **{$fullName}** (or reply **Cancel** to abort).",
            ];
        }

        // 4. Missing Course & Year (for student) OR Position & Department (for faculty/staff)?
        if ($category === 'student') {
            if (empty($state['course']) || empty($state['year_level'])) {
                return [
                    'message' => "📋 [Patient Registration]\n\n"
                        ."Thanks! What is **{$fullName}**'s **Course** and **Year Level**?\n"
                        ."• Courses: BSIT, BSBA, MidWifery, BSHM, BLIS, BSED, BSCRIM\n"
                        ."• Year Levels: 1st Year, 2nd Year, 3rd Year, 4th Year\n\n"
                        .'*(e.g., BSIT 3rd Year Section 3B)*',
                ];
            }
        } else {
            if (empty($state['position'])) {
                return [
                    'message' => "📋 [Patient Registration]\n\n"
                        ."What is **{$fullName}**'s **Position** and **Department**?\n"
                        .'*(e.g., Position: Instructor I, Department: College of BSIT)*',
                ];
            }
        }

        // 5. Missing Sex, Birth Date, or Contact Number?
        if (empty($state['sex']) || empty($state['birth_date']) || empty($state['contact_number'])) {
            $missing = [];
            if (empty($state['sex'])) {
                $missing[] = 'Sex';
            }
            if (empty($state['birth_date'])) {
                $missing[] = 'Birth Date';
            }
            if (empty($state['contact_number'])) {
                $missing[] = 'Contact Number';
            }

            return [
                'message' => "📋 [Patient Registration]\n\n"
                    ."What is **{$fullName}**'s **".implode(', ', $missing)."**?\n"
                    .'*(e.g. Male, March 03 2006, 09959048004)*',
            ];
        }

        // 6. Missing Address?
        if (empty($state['address'])) {
            return [
                'message' => "📋 [Patient Registration]\n\n"
                    ."Almost finished! What is **{$fullName}**'s **Address**?\n"
                    .'*(e.g. Taguig City or Napocor Natumolan 20B)*',
            ];
        }

        // 7. Everything is complete! Show review confirmation card
        $roleDetails = $category === 'student'
            ? "• **Course & Year**: {$state['course']} - {$state['year_level']}".($state['section'] ? " ({$state['section']})" : '')
            : "• **Position & Dept**: {$state['position']}".($state['department'] ? " - {$state['department']}" : '');

        return [
            'message' => "📋 [Patient Registration]\n"
                ."Please review the patient details before saving:\n\n"
                ."• **Category**: {$catLabel}\n"
                ."• **Full Name**: {$fullName}\n"
                ."• **ID**: {$identifier}\n"
                ."{$roleDetails}\n"
                ."• **Sex**: {$state['sex']}\n"
                ."• **Birth Date**: {$state['birth_date']}\n"
                ."• **Contact Number**: {$state['contact_number']}\n"
                ."• **Address**: {$state['address']}\n\n"
                ."Would you like me to record this patient now?\n"
                .'Reply **Yes** to confirm and save, or **Cancel** to abort.',
        ];
    }

    private function finalizePatientRegistration(string $summaryMessage): array
    {
        $cleanSummary = str_replace(['**', '*'], '', $summaryMessage);

        preg_match('/Category:\s*([^\n\r]+)/i', $cleanSummary, $catM);
        preg_match('/Full Name:\s*([^\n\r]+)/i', $cleanSummary, $nameM);
        preg_match('/ID:\s*([^\n\r]+)/i', $cleanSummary, $idM);
        preg_match('/Course & Year:\s*([^\n\r]+)/i', $cleanSummary, $cyM);
        preg_match('/Position & Dept:\s*([^\n\r]+)/i', $cleanSummary, $pdM);
        preg_match('/Sex:\s*([^\n\r]+)/i', $cleanSummary, $sexM);
        preg_match('/Birth Date:\s*([^\n\r]+)/i', $cleanSummary, $bdM);
        preg_match('/Contact Number:\s*([^\n\r]+)/i', $cleanSummary, $cnM);
        preg_match('/Address:\s*([^\n\r]+)/i', $cleanSummary, $addrM);

        $category = strtolower(trim(strip_tags($catM[1] ?? 'student')));
        $fullName = trim(strip_tags($nameM[1] ?? 'Patient'));
        $identifier = trim(strip_tags($idM[1] ?? ''));
        $sex = trim(strip_tags($sexM[1] ?? 'Female'));
        $birthDate = trim(strip_tags($bdM[1] ?? '2005-01-01'));
        $contactNumber = trim(strip_tags($cnM[1] ?? '09123456789'));
        $address = trim(strip_tags($addrM[1] ?? 'Taguig City'));

        $nameParts = $this->parseName($fullName);

        if ($category === 'faculty') {
            $posDept = trim(strip_tags($pdM[1] ?? 'Instructor I'));
            $posParts = explode('-', $posDept);
            $position = trim($posParts[0] ?? 'Instructor I');
            $department = trim($posParts[1] ?? 'College of Information Technology (BSIT)');

            $existing = Faculty::where('employee_id', $identifier)->first();
            if ($existing) {
                return [
                    'message' => "⚠️ Faculty ID **{$identifier}** already exists. No record was saved.",
                ];
            }

            Faculty::create([
                'employee_id' => $identifier,
                'first_name' => $nameParts['first_name'],
                'middle_name' => $nameParts['middle_name'],
                'last_name' => $nameParts['last_name'],
                'position' => $position,
                'department' => $department,
                'sex' => $sex,
                'birth_date' => $birthDate,
                'contact_number' => $contactNumber,
                'address' => $address,
            ]);
        } elseif ($category === 'staff') {
            $posDept = trim(strip_tags($pdM[1] ?? 'Staff Member'));
            $posParts = explode('-', $posDept);
            $position = trim($posParts[0] ?? 'Staff Member');
            $department = trim($posParts[1] ?? 'Health Services & Campus Clinic');

            $existing = Staff::where('staff_id', $identifier)->first();
            if ($existing) {
                return [
                    'message' => "⚠️ Staff ID **{$identifier}** already exists. No record was saved.",
                ];
            }

            Staff::create([
                'staff_id' => $identifier,
                'first_name' => $nameParts['first_name'],
                'middle_name' => $nameParts['middle_name'],
                'last_name' => $nameParts['last_name'],
                'position' => $position,
                'department' => $department,
                'sex' => $sex,
                'birth_date' => $birthDate,
                'contact_number' => $contactNumber,
                'address' => $address,
            ]);
        } else {
            // Student
            $courseYear = trim(strip_tags($cyM[1] ?? 'BSIT - 1st Year'));
            $cyParts = explode('-', $courseYear);
            $course = trim($cyParts[0] ?? 'BSIT');
            $yearLevel = trim($cyParts[1] ?? '1st Year');
            $section = null;
            if (preg_match('/\(([^)]+)\)/', $courseYear, $secM)) {
                $section = trim($secM[1]);
                $yearLevel = trim(str_replace("({$section})", '', $yearLevel));
            }

            $existing = Student::where('student_id', $identifier)->first();
            if ($existing) {
                return [
                    'message' => "⚠️ Student ID **{$identifier}** already exists. No record was saved.",
                ];
            }

            Student::create([
                'student_id' => $identifier,
                'first_name' => $nameParts['first_name'],
                'middle_name' => $nameParts['middle_name'],
                'last_name' => $nameParts['last_name'],
                'course' => $course,
                'year_level' => $yearLevel,
                'section' => $section ?: 'Section A',
                'sex' => $sex,
                'birth_date' => $birthDate,
                'contact_number' => $contactNumber,
                'address' => $address,
            ]);
        }

        $catLabel = ucfirst($category);

        return [
            'message' => "✅ **Patient Successfully Recorded!**\n\n"
                ."**{$fullName}** ({$identifier}) has been added to the clinic database as a **{$catLabel}**.\n\n"
                ."You can now view their record under **Patient Records** or log clinic visits for them under **Clinic Visits**.\n\n"
                .'What else would you like help with?',
            'patient_created' => [
                'type' => $category,
                'name' => $fullName,
                'id' => $identifier,
            ],
        ];
    }

    private function extractIntakeState(array $messages): array
    {
        $state = [
            'category' => null,
            'first_name' => '',
            'middle_name' => null,
            'last_name' => '',
            'full_name' => '',
            'identifier' => null,
            'course' => null,
            'year_level' => null,
            'section' => null,
            'position' => null,
            'department' => null,
            'sex' => null,
            'birth_date' => null,
            'contact_number' => null,
            'address' => null,
        ];

        // First pass: inspect assistant messages to recover any confirmed data
        foreach ($messages as $msg) {
            if ($msg['role'] === 'model') {
                $text = $msg['content'];
                if (preg_match('/Got it!\s*What is \*\*([^*]+)\*\*\'s/i', $text, $m)) {
                    $state['full_name'] = trim($m[1]);
                } elseif (preg_match('/What is \*\*([^*]+)\*\*\'s \*\*/i', $text, $m)) {
                    $state['full_name'] = trim($m[1]);
                }
                if (preg_match('/registering a \*\*(Student|Faculty|Staff)\*\*/i', $text, $m)) {
                    $state['category'] = strtolower(trim($m[1]));
                }
            }
        }

        // Second pass: iterate through question -> answer pairs
        for ($i = 0; $i < count($messages); $i++) {
            $msg = $messages[$i];
            if ($msg['role'] === 'model') {
                $bot = $msg['content'];

                // Find the next user message
                $userMsg = null;
                for ($j = $i + 1; $j < count($messages); $j++) {
                    if ($messages[$j]['role'] === 'user') {
                        $userMsg = trim($messages[$j]['content']);
                        break;
                    }
                }

                if ($userMsg === null || preg_match('/^(cancel|abort|stop)\b/i', $userMsg)) {
                    continue;
                }

                // 1. Category question
                if (str_contains($bot, 'What category is the patient?')) {
                    if (preg_match('/\b(faculty|prof|instructor|teacher)\b/i', $userMsg) || preg_match('/^\s*2\b/', $userMsg)) {
                        $state['category'] = 'faculty';
                    } elseif (preg_match('/\b(staff|employee|non-teaching)\b/i', $userMsg) || preg_match('/^\s*3\b/', $userMsg)) {
                        $state['category'] = 'staff';
                    } elseif (preg_match('/\b(student|stud)\b/i', $userMsg) || preg_match('/^\s*1\b/', $userMsg)) {
                        $state['category'] = 'student';
                    }
                }

                // 2. Full Name question
                if (str_contains($bot, "What is the patient's **Full Name**?") || str_contains($bot, 'Full Name')) {
                    if (! preg_match('/\b(student|faculty|staff|add\s+new|cancel)\b/i', $userMsg) && strlen($userMsg) > 2) {
                        $parsed = $this->parseName($userMsg);
                        if (! empty($parsed['first_name'])) {
                            $state['first_name'] = $parsed['first_name'];
                            $state['middle_name'] = $parsed['middle_name'];
                            $state['last_name'] = $parsed['last_name'];
                            $state['full_name'] = trim($parsed['first_name'].' '.($parsed['middle_name'] ? $parsed['middle_name'].' ' : '').$parsed['last_name']);
                        }
                    }
                }

                // 3. ID question (or duplicate ID prompt)
                if (str_contains($bot, 'Student ID number') || str_contains($bot, 'Faculty ID number') || str_contains($bot, 'Employee ID number') || str_contains($bot, 'Staff ID number') || str_contains($bot, 'already registered') || str_contains($bot, 'different Student ID') || str_contains($bot, 'different Faculty ID') || str_contains($bot, 'different Employee ID') || str_contains($bot, 'different Staff ID')) {
                    if (preg_match('/\b(\d{7,10}|[A-Za-z0-9\-]{5,15})\b/', $userMsg, $idm)) {
                        $state['identifier'] = $idm[1];
                    }
                }

                // 4. Course & Year question
                if (str_contains($bot, 'Course') && str_contains($bot, 'Year Level')) {
                    $this->parseCourseYear($userMsg, $state);
                }

                // 5. Position & Dept question
                if (str_contains($bot, 'Position') && str_contains($bot, 'Department')) {
                    $parts = preg_split('/[,-]/', $userMsg);
                    $state['position'] = trim($parts[0] ?? $userMsg);
                    if (isset($parts[1])) {
                        $state['department'] = trim($parts[1]);
                    }
                }

                // 6. Sex, Birth Date, and Contact Number question
                if (str_contains($bot, 'Sex') || str_contains($bot, 'Birth Date') || str_contains($bot, 'Contact Number')) {
                    $this->parseSexBirthContact($userMsg, $state);
                }

                // 7. Address question
                if ((str_contains($bot, 'What is **') && str_contains($bot, 'Address')) || str_contains($bot, 'What is their **Address**?')) {
                    $cleanAddr = trim(preg_replace('/^(it\'s|address is|address:?)\s*/i', '', $userMsg));
                    if (! empty($cleanAddr) && ! in_array(strtolower($cleanAddr), ['yes', 'no', 'cancel'], true)) {
                        $state['address'] = $cleanAddr;
                    }
                }
            }
        }

        // Third pass: backfill any still-missing fields from any user message
        foreach ($messages as $msg) {
            if ($msg['role'] === 'user') {
                $u = $msg['content'];
                if (empty($state['category'])) {
                    if (preg_match('/\b(faculty|prof|instructor)\b/i', $u)) {
                        $state['category'] = 'faculty';
                    } elseif (preg_match('/\b(staff|employee)\b/i', $u)) {
                        $state['category'] = 'staff';
                    } elseif (preg_match('/\b(student)\b/i', $u)) {
                        $state['category'] = 'student';
                    }
                }

                if (empty($state['full_name']) && preg_match('/(?:full\s*name|name)[:\s]+([a-zA-Z\sñÑ\-.\']+)/i', $u, $nm)) {
                    $parsed = $this->parseName($nm[1]);
                    if (! empty($parsed['first_name'])) {
                        $state['first_name'] = $parsed['first_name'];
                        $state['middle_name'] = $parsed['middle_name'];
                        $state['last_name'] = $parsed['last_name'];
                        $state['full_name'] = trim($parsed['first_name'].' '.($parsed['middle_name'] ? $parsed['middle_name'].' ' : '').$parsed['last_name']);
                    }
                }

                if (empty($state['identifier']) && preg_match('/\b(20\d{6,10})\b/', $u, $idm)) {
                    $state['identifier'] = $idm[1];
                }

                if (empty($state['course']) || empty($state['year_level'])) {
                    $this->parseCourseYear($u, $state);
                }

                if (empty($state['sex']) || empty($state['birth_date']) || empty($state['contact_number'])) {
                    $this->parseSexBirthContact($u, $state);
                }
            }
        }

        // Ensure name components exist if full_name is set
        if (! empty($state['full_name']) && empty($state['first_name'])) {
            $parsed = $this->parseName($state['full_name']);
            $state['first_name'] = $parsed['first_name'];
            $state['middle_name'] = $parsed['middle_name'];
            $state['last_name'] = $parsed['last_name'];
        }

        return $state;
    }

    private function parseCourseYear(string $text, array &$state): void
    {
        $courses = ['BSIT', 'BSBA', 'MidWifery', 'BSHM', 'BLIS', 'BSED', 'BSCRIM'];
        foreach ($courses as $c) {
            if (stripos($text, $c) !== false) {
                $state['course'] = $c;
                break;
            }
        }

        if (preg_match('/\b(1st|2nd|3rd|4th|[1-4])\s*(?:year)?\b/i', $text, $ym)) {
            $yNum = strtolower($ym[1]);
            if ($yNum === '1' || $yNum === '1st') {
                $state['year_level'] = '1st Year';
            } elseif ($yNum === '2' || $yNum === '2nd') {
                $state['year_level'] = '2nd Year';
            } elseif ($yNum === '3' || $yNum === '3rd') {
                $state['year_level'] = '3rd Year';
            } elseif ($yNum === '4' || $yNum === '4th') {
                $state['year_level'] = '4th Year';
            }
        }

        if (preg_match('/\b([1-4][A-Za-z]|section\s+[A-Za-z0-9]+)\b/i', $text, $sm)) {
            $sec = strtoupper($sm[1]);
            if (! in_array($sec, ['BSIT', 'BSBA', 'BSHM', 'BLIS', 'BSED', 'BSCRIM', '1ST', '2ND', '3RD', '4TH', 'YEAR'], true)) {
                $state['section'] = $sec;
            }
        }
    }

    private function parseSexBirthContact(string $text, array &$state): void
    {
        if (preg_match('/\b(female|woman|girl)\b/i', $text)) {
            $state['sex'] = 'Female';
        } elseif (preg_match('/\b(male|man|boy)\b/i', $text)) {
            $state['sex'] = 'Male';
        }

        if (preg_match('/\b(09\d{9})\b/', $text, $cm)) {
            $state['contact_number'] = $cm[1];
        }

        $bd = $this->normalizeBirthDate($text);
        if ($bd) {
            $state['birth_date'] = $bd;
        }
    }

    private function parseName(string $raw): array
    {
        $clean = trim(preg_replace('/^(my name is|name is|it is|it\'s|name:?|full name:?)\s*/i', '', $raw));
        $clean = preg_replace('/[^a-zA-Z\sñÑ\-.\']/', '', $clean);

        if (preg_match('/first:\s*([^,]+),?\s*last:\s*([^,]+)(?:,?\s*middle:\s*([^,]+))?/i', $raw, $nm)) {
            return [
                'first_name' => trim($nm[1]),
                'last_name' => trim($nm[2]),
                'middle_name' => ! empty($nm[3]) ? trim($nm[3]) : null,
            ];
        }

        $parts = preg_split('/\s+/', trim($clean));
        if (count($parts) === 1) {
            return [
                'first_name' => $parts[0],
                'middle_name' => null,
                'last_name' => $parts[0],
            ];
        } elseif (count($parts) === 2) {
            return [
                'first_name' => $parts[0],
                'middle_name' => null,
                'last_name' => $parts[1],
            ];
        } elseif (count($parts) === 3) {
            return [
                'first_name' => $parts[0],
                'middle_name' => $parts[1],
                'last_name' => $parts[2],
            ];
        } else {
            return [
                'first_name' => $parts[0].' '.$parts[1],
                'middle_name' => implode(' ', array_slice($parts, 2, -1)) ?: null,
                'last_name' => end($parts),
            ];
        }
    }

    private function normalizeBirthDate(string $text): ?string
    {
        // Try YYYY-MM-DD
        if (preg_match('/\b(19\d{2}|20\d{2})[-\/](\d{1,2})[-\/](\d{1,2})\b/', $text, $m)) {
            $y = $m[1];
            $mon = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $d = str_pad($m[3], 2, '0', STR_PAD_LEFT);
            if (checkdate((int) $mon, (int) $d, (int) $y)) {
                return "{$y}-{$mon}-{$d}";
            }
        }

        // Try Month DD, YYYY or Month DD YYYY (e.g. March 03 2006)
        $months = [
            'january' => '01', 'february' => '02', 'march' => '03', 'april' => '04',
            'may' => '05', 'june' => '06', 'july' => '07', 'august' => '08',
            'september' => '09', 'october' => '10', 'november' => '11', 'december' => '12',
            'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
            'jun' => '06', 'jul' => '07', 'aug' => '08', 'sep' => '09',
            'oct' => '10', 'nov' => '11', 'dec' => '12',
        ];

        if (preg_match('/\b([a-zA-Z]+)\s+(\d{1,2})[,]?\s+(19\d{2}|20\d{2})\b/i', $text, $m)) {
            $monName = strtolower($m[1]);
            if (isset($months[$monName])) {
                $mon = $months[$monName];
                $d = str_pad($m[2], 2, '0', STR_PAD_LEFT);
                $y = $m[3];
                if (checkdate((int) $mon, (int) $d, (int) $y)) {
                    return "{$y}-{$mon}-{$d}";
                }
            }
        }

        // Try DD Month YYYY (e.g. 03 March 2006)
        if (preg_match('/\b(\d{1,2})\s+([a-zA-Z]+)[,]?\s+(19\d{2}|20\d{2})\b/i', $text, $m)) {
            $monName = strtolower($m[2]);
            if (isset($months[$monName])) {
                $mon = $months[$monName];
                $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $y = $m[3];
                if (checkdate((int) $mon, (int) $d, (int) $y)) {
                    return "{$y}-{$mon}-{$d}";
                }
            }
        }

        $time = strtotime($text);
        if ($time !== false && $time > 0 && $time < time()) {
            return date('Y-m-d', $time);
        }

        return null;
    }

    private function buildDatabaseGroundingContext(string $userQuery, $user): array
    {
        $q = strtolower(trim($userQuery));
        $facts = [];
        $directReply = null;

        // 1. General System Counts
        $studentCount = Student::count();
        $facultyCount = Faculty::count();
        $staffCount = Staff::count();
        $medicineCount = Medicine::count();
        $lowStockCount = Medicine::where('stock', '>', 0)->whereColumn('stock', '<=', 'minimum_stock')->count();
        $outOfStockCount = Medicine::where('stock', '<=', 0)->count();
        $visitsThisMonth = ClinicVisit::whereBetween('visit_date', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $totalVisitsAllTime = ClinicVisit::count();

        $facts[] = "SYSTEM TOTALS: Students: {$studentCount}, Faculty: {$facultyCount}, Staff: {$staffCount}, Medicines registered: {$medicineCount} ({$lowStockCount} low stock, {$outOfStockCount} out of stock). Clinic visits this month: {$visitsThisMonth}, All-time visits: {$totalVisitsAllTime}.";

        // 2. Check for Student Queries
        // Look for ID numbers (5-15 digits) or keywords like "student", "course"
        $hasIdMatch = preg_match('/\b(\d{5,15})\b/', $userQuery, $idMatch);
        $isStudentQuery = str_contains($q, 'student') || str_contains($q, 'find') || str_contains($q, 'search') || $hasIdMatch;

        if ($hasIdMatch || $isStudentQuery) {
            $studentSearch = null;
            if ($hasIdMatch) {
                $studentSearch = Student::where('student_id', 'like', "%{$idMatch[1]}%")->first();
            }

            if (! $studentSearch && (str_contains($q, 'student') || str_contains($q, 'find') || str_contains($q, 'who is') || str_contains($q, 'search'))) {
                // Try to find name tokens
                $words = preg_split('/[\s,]+/', preg_replace('/[^\w\s]/', '', $q));
                $stopWords = ['student', 'find', 'search', 'show', 'who', 'is', 'the', 'details', 'record', 'records', 'info', 'about', 'can', 'you', 'please', 'me', 'tell'];
                $filteredWords = array_values(array_filter($words, fn ($w) => ! in_array($w, $stopWords, true) && strlen($w) > 2));
                if (! empty($filteredWords)) {
                    $nameSearch = implode(' ', $filteredWords);
                    $studentSearch = Student::where(function ($query) use ($filteredWords, $nameSearch) {
                        $query->where('first_name', 'like', "%{$nameSearch}%")
                            ->orWhere('last_name', 'like', "%{$nameSearch}%");
                        foreach ($filteredWords as $w) {
                            $query->orWhere('first_name', 'like', "%{$w}%")
                                ->orWhere('last_name', 'like', "%{$w}%");
                        }
                    })->first();
                }
            }

            if ($studentSearch) {
                $visits = $studentSearch->clinicVisits()->latest('visit_date')->take(5)->get();
                $visitCount = $studentSearch->clinicVisits()->count();
                $lastVisit = $visits->first();
                $lastVisitInfo = $lastVisit ? "Last visited on {$lastVisit->visit_date} for '{$lastVisit->reason}'" : "No clinic visits recorded yet.";

                $facts[] = "FOUND STUDENT RECORD: Name: {$studentSearch->first_name} {$studentSearch->last_name}, Student ID: {$studentSearch->student_id}, Course: {$studentSearch->course}, Year/Section: {$studentSearch->year_level} - {$studentSearch->section}, Sex: {$studentSearch->sex}, Contact: {$studentSearch->contact_number}, Total Visits: {$visitCount}. {$lastVisitInfo}.";

                $directReply = "👤 **Student Record Found**\n\n"
                    ."• **Name**: {$studentSearch->first_name} {$studentSearch->middle_name} {$studentSearch->last_name}\n"
                    ."• **Student ID**: `{$studentSearch->student_id}`\n"
                    ."• **Course & Year**: {$studentSearch->course} - {$studentSearch->year_level} ({$studentSearch->section})\n"
                    ."• **Sex**: {$studentSearch->sex}\n"
                    ."• **Contact**: {$studentSearch->contact_number}\n"
                    ."• **Total Clinic Visits**: {$visitCount}\n"
                    ."• **Status**: {$lastVisitInfo}\n\n"
                    ."You can view full medical details in the **Student Records** section.";
            } elseif ($hasIdMatch && ! str_contains($q, 'patient')) {
                $facts[] = "STUDENT QUERY: ID '{$idMatch[1]}' was searched but NOT FOUND in the database.";
                $directReply = "❌ **Student Not Found**\n\nNo student record with ID `{$idMatch[1]}` was found in the clinic database. Please verify the Student ID number.";
            }
        }

        // 3. Check for Medicine / Stock queries
        if (str_contains($q, 'medicine') || str_contains($q, 'stock') || str_contains($q, 'inventory') || str_contains($q, 'supply') || str_contains($q, 'paracetamol') || str_contains($q, 'amoxicillin') || str_contains($q, 'biogesic') || str_contains($q, 'alcohol') || str_contains($q, 'mefenamic') || str_contains($q, 'ibuprofen') || str_contains($q, 'antacid') || str_contains($q, 'cetirizine')) {
            $medicines = Medicine::all();
            $matchedMed = null;
            foreach ($medicines as $med) {
                if (str_contains($q, strtolower($med->medicine_name))) {
                    $matchedMed = $med;
                    break;
                }
            }

            if ($matchedMed) {
                $status = $matchedMed->stock <= 0 ? "Out of Stock" : ($matchedMed->stock <= $matchedMed->minimum_stock ? "Low Stock" : "In Stock");
                $facts[] = "MEDICINE INVENTORY: '{$matchedMed->medicine_name}' has {$matchedMed->stock} {$matchedMed->unit} available (minimum threshold: {$matchedMed->minimum_stock}). Status: {$status}. Expiry: " . ($matchedMed->expiration_date ?? 'N/A') . ".";
                $directReply = "💊 **Medicine Inventory: {$matchedMed->medicine_name}**\n\n"
                    ."• **Current Stock**: {$matchedMed->stock} {$matchedMed->unit}\n"
                    ."• **Minimum Threshold**: {$matchedMed->minimum_stock} {$matchedMed->unit}\n"
                    ."• **Status**: **{$status}**\n"
                    ."• **Category / Treatment**: {$matchedMed->treatment_type}\n"
                    .($matchedMed->expiration_date ? "• **Expiration Date**: {$matchedMed->expiration_date}\n" : "");
            } elseif (str_contains($q, 'low') || str_contains($q, 'out of stock') || str_contains($q, 'critical') || str_contains($q, 'reorder')) {
                $lowMeds = Medicine::where('stock', '<=', \Illuminate\Support\Facades\DB::raw('minimum_stock'))->orderBy('stock')->get();
                $medList = $lowMeds->map(fn ($m) => "• **{$m->medicine_name}**: {$m->stock} {$m->unit} remaining (Min: {$m->minimum_stock})")->implode("\n");
                $facts[] = "LOW / OUT OF STOCK MEDICINES: " . ($medList ?: "None. All medicines are adequately stocked.");
                $directReply = "⚠️ **Medicines Requiring Attention**\n\n"
                    .($medList ?: "All medicines currently meet or exceed minimum stock levels!")
                    ."\n\nYou can update inventory or restock supplies in the **Medicine Inventory** section.";
            } else {
                $allMeds = Medicine::orderBy('stock')->take(8)->get();
                $medSummary = $allMeds->map(fn ($m) => "• **{$m->medicine_name}**: {$m->stock} {$m->unit}")->implode("\n");
                $facts[] = "MEDICINE STOCK OVERVIEW: Total medicines: {$medicineCount}, Low: {$lowStockCount}, Out of stock: {$outOfStockCount}. Sample items:\n{$medSummary}";
            }
        }

        // 4. Check for Visit / Report statistics queries
        if (str_contains($q, 'statistic') || str_contains($q, 'report') || str_contains($q, 'common') || str_contains($q, 'reason') || (str_contains($q, 'how many') && str_contains($q, 'visit'))) {
            $monthStart = now()->startOfMonth()->toDateString();
            $monthEnd = now()->endOfMonth()->toDateString();
            $monthVisits = ClinicVisit::whereBetween('visit_date', [$monthStart, $monthEnd])->get();
            $topReasons = $monthVisits->groupBy('reason')->map(fn ($g, $r) => "{$r} ({$g->count()})")->take(5)->implode(', ');
            $stdCount = $monthVisits->whereNotNull('student_id')->count();
            $facCount = $monthVisits->whereNotNull('faculty_id')->count();
            $stfCount = $monthVisits->whereNotNull('staff_id')->count();

            $facts[] = "CLINIC VISIT STATS THIS MONTH: Total visits: {$monthVisits->count()} (Students: {$stdCount}, Faculty: {$facCount}, Staff: {$stfCount}). Top consultation reasons: " . ($topReasons ?: "None recorded yet this month.");

            if (! $directReply && (str_contains($q, 'statistic') || str_contains($q, 'report') || str_contains($q, 'summary') || str_contains($q, 'common'))) {
                $directReply = "📊 **Monthly Clinic Statistics (" . now()->format('F Y') . ")**\n\n"
                    ."• **Total Visits**: {$monthVisits->count()}\n"
                    ."• **Students Treated**: {$stdCount}\n"
                    ."• **Faculty Consultations**: {$facCount}\n"
                    ."• **Staff Consultations**: {$stfCount}\n"
                    ."• **Top Consultation Reasons**: " . ($topReasons ?: "None recorded yet this month") . "\n\n"
                    ."You can generate and export complete reports with PDF downloads in the **Reports** section.";
            }
        }

        return [
            'facts_text' => implode("\n\n", $facts),
            'direct_reply' => $directReply,
        ];
    }

    private function generateOfflineReply(string $userQuery, array $clinicSnapshot, array $groundingData = []): string
    {
        if (! empty($groundingData['direct_reply'])) {
            return $groundingData['direct_reply'];
        }

        $q = strtolower(trim($userQuery));

        // Greetings
        if (in_array($q, ['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening'], true)) {
            return 'Hello! I am the TCC Clinic Assistant. How can I help you today with clinic workflows, statistics, or medicine inventory?';
        }

        // Patient / Student / Faculty / Staff addition or forms
        if (str_contains($q, 'patient') || (str_contains($q, 'add') && (str_contains($q, 'new') || str_contains($q, 'form') || str_contains($q, 'record') || str_contains($q, 'student') || str_contains($q, 'staff') || str_contains($q, 'faculty')))) {
            if (str_contains($q, 'add') || str_contains($q, 'form') || str_contains($q, 'register') || str_contains($q, 'create') || str_contains($q, 'new')) {
                return "To add a new patient in the system:\n"
                    ."1. Go to the Patient Records section (Students, Staff, Faculty) from the navigation bar.\n"
                    ."2. Click the '+ Add Patient' button at the top right.\n"
                    ."3. Select the patient category: Student, Faculty, or Staff.\n"
                    ."4. Fill in the required patient details (Name, ID, Academic/Department details, Sex, Birth Date, Contact Number, and Address).\n"
                    ."5. Click 'Save Patient' to register the record.";
            }

            $students = $clinicSnapshot['students'] ?? 0;
            $staff = $clinicSnapshot['staff'] ?? 0;
            $faculty = $clinicSnapshot['faculty'] ?? 0;
            $total = $students + $staff + $faculty;

            return "Patient Records Summary:\n"
                ."• Total patients: {$total}\n"
                ."• Students: {$students}\n"
                ."• Faculty: {$faculty}\n"
                ."• Staff: {$staff}\n\n"
                .'You can search, add, edit, or view medical profiles under the Patient Records section.';
        }

        // Visits recording or counts
        if (str_contains($q, 'visit')) {
            if (str_contains($q, 'add') || str_contains($q, 'new') || str_contains($q, 'record') || str_contains($q, 'how') || str_contains($q, 'log')) {
                return "To record a new clinic visit:\n"
                    ."1. Open the Clinic Visits page from the navigation bar.\n"
                    ."2. Click the '+ New Visit' button.\n"
                    ."3. Search and select the patient (Student, Faculty, or Staff).\n"
                    ."4. Select the Nurse on Duty, Reason for Visit, Vital Signs, Symptoms, Assessment, and Treatment.\n"
                    ."5. If medicine is dispensed, choose the Medicine/Supply and enter the quantity used.\n"
                    ."6. Click 'Save Visit' to log the record.";
            }

            $count = $clinicSnapshot['clinic_visits_this_month'] ?? 0;

            return "There have been {$count} clinic visit(s) recorded so far this month.";
        }

        // Medicine / Stock / Inventory
        if (str_contains($q, 'medicine') || str_contains($q, 'stock') || str_contains($q, 'inventory') || str_contains($q, 'drug') || str_contains($q, 'supply')) {
            $total = $clinicSnapshot['medicines']['total'] ?? 0;
            $low = $clinicSnapshot['medicines']['low_stock'] ?? 0;
            $out = $clinicSnapshot['medicines']['out_of_stock'] ?? 0;

            return "Medicine Inventory Snapshot:\n• Total medicines: {$total}\n• Low stock items: {$low}\n• Out of stock items: {$out}\n\nYou can review stock levels and manage batches under the Medicine Inventory section.";
        }

        if (str_contains($q, 'student')) {
            $count = $clinicSnapshot['students'] ?? 0;

            return "There are currently {$count} student record(s) registered in the clinic system.";
        }

        if (str_contains($q, 'staff') || str_contains($q, 'faculty') || str_contains($q, 'employee')) {
            $staff = $clinicSnapshot['staff'] ?? 0;
            $faculty = $clinicSnapshot['faculty'] ?? 0;

            return "The clinic system currently manages {$staff} staff member(s) and {$faculty} faculty member(s).";
        }

        // Reports
        if (str_contains($q, 'report') || str_contains($q, 'export') || str_contains($q, 'summary') || str_contains($q, 'statistic')) {
            return "To view and export clinic reports:\n"
                ."1. Click 'Reports' in the navigation menu.\n"
                ."2. Select your desired date range (daily, weekly, monthly, or custom).\n"
                ."3. View visit distributions, common symptoms, and medication usage.\n"
                ."4. Click 'Export' to generate a report.";
        }

        if (str_contains($q, 'hour') || str_contains($q, 'schedule') || str_contains($q, 'open') || str_contains($q, 'time')) {
            return 'The TCC Clinic is open during regular school operating hours (Monday to Friday, 8:00 AM – 5:00 PM). For urgent symptoms outside clinic hours, please contact hospital emergency services.';
        }

        if (str_contains($q, 'help') || str_contains($q, 'what can you do') || str_contains($q, 'what do you do')) {
            return "I am the TCC Clinic Assistant. I can help you with:\n"
                ."• Patient registration procedures and records ('add new patient form')\n"
                ."• Clinic visit logs and statistics ('how to add visit', 'visits this month')\n"
                ."• Medicine stock counts and low-stock inventory ('medicine stock')\n"
                .'• Clinic operating hours and guidelines';
        }

        return "I can help with TCC Clinic workflows and statistics:\n"
            ."• Patient Registration: Type 'add new patient form' or 'how to add patient'\n"
            ."• Clinic Visits: Type 'how to record visit' or 'visits this month'\n"
            ."• Medicine Inventory: Type 'medicine stock' or 'low stock'\n"
            ."• Clinic Reports: Type 'clinic reports'";
    }

    private function isClinicRelatedQuestion(string $question): bool
    {
        $normalized = mb_strtolower(trim($question));

        if ($normalized === '') {
            return false;
        }

        $clinicTerms = [
            'clinic', 'patient', 'student', 'faculty', 'staff', 'nurse',
            'visit', 'medical record', 'record', 'medicine', 'medication',
            'inventory', 'stock', 'prescription', 'treatment', 'first aid',
            'health', 'symptom', 'illness', 'injury', 'fever', 'pain',
            'emergency', 'appointment', 'dashboard', 'report', 'login',
        ];

        foreach ($clinicTerms as $term) {
            if (preg_match(
                '/(?<![\\p{L}\\p{N}])'.preg_quote($term, '/').'(?![\\p{L}\\p{N}])/u',
                $normalized
            )) {
                return true;
            }
        }

        return in_array($normalized, [
            'help',
            'hello',
            'hi',
            'what can you do',
            'what do you do',
        ], true);
    }
}

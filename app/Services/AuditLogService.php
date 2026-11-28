<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogService
{
    public static function record(
        string $action,
        string $description,
        ?string $subjectType = null,
        string|int|null $subjectId = null,
        array $metadata = [],
        ?User $user = null,
        ?Request $request = null
    ): void {
        try {
            $actor = $user ?? auth()->user();
            $req = $request ?? request();

            AuditLog::create([
                'user_id' => $actor?->id,
                'user_name' => $actor?->name,
                'user_role' => $actor?->role,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId !== null ? (string) $subjectId : null,
                'description' => mb_substr($description, 0, 500),
                'ip_address' => $req?->ip(),
                'metadata' => empty($metadata) ? null : $metadata,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never fail primary clinical operation if audit logging encounters a transient error
        }
    }
}

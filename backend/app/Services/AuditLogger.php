<?php

namespace App\Services;

use App\Models\AccessLog;
use App\Models\AuditLog;
use App\Models\TherapySession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(?User $actor, string $action, ?Model $subject = null, array $meta = []): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta,
            'ip_hash' => request()?->ip() ? hash('sha256', request()->ip()) : null,
            'created_at' => now(),
        ]);
    }

    public function access(User $user, TherapySession $session, string $resource, string $purpose = 'care'): void
    {
        AccessLog::create([
            'user_id' => $user->id,
            'therapy_session_id' => $session->id,
            'resource' => $resource,
            'purpose' => $purpose,
            'ip_hash' => request()?->ip() ? hash('sha256', request()->ip()) : null,
            'created_at' => now(),
        ]);
    }
}

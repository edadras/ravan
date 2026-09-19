<?php

namespace App\Models;

use App\Enums\ConsentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionConsent extends Model
{
    protected $fillable = ['therapy_session_id', 'user_id', 'type', 'version', 'granted_at', 'withdrawn_at', 'ip_hash', 'user_agent_hash'];

    protected $casts = ['type' => ConsentType::class, 'granted_at' => 'datetime', 'withdrawn_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }
}

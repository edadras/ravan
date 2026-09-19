<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SessionReport extends Model
{
    protected $fillable = ['therapy_session_id', 'structured', 'ai_draft_summary', 'ai_provider', 'guardrail_removed', 'status', 'finalized_at', 'finalized_by'];

    protected $casts = ['structured' => 'array', 'guardrail_removed' => 'array', 'finalized_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ReportVersion::class)->orderByDesc('version');
    }
}

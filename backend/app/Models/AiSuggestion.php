<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiSuggestion extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['input_summary' => 'array', 'output' => 'array', 'guardrail_removed' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(PatientRecord::class, 'patient_record_id');
    }
}

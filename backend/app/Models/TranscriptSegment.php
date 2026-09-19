<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TranscriptSegment extends Model
{
    protected $fillable = ['therapy_session_id', 'speaker', 't_start_ms', 't_end_ms', 'text', 'confidence', 'is_question', 'topic', 'language', 'features'];

    protected $casts = ['is_question' => 'boolean', 'features' => 'array', 'confidence' => 'float'];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }
}

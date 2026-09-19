<?php

namespace App\Models;

use App\Enums\EventReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BehaviorEvent extends Model
{
    protected $fillable = [
        'uuid', 'therapy_session_id', 'patient_id', 'signal_id', 'group', 'tier', 't_start_ms', 't_end_ms',
        'observation_en', 'observation_fa', 'observation_tr', 'baseline_value', 'observed_value', 'delta', 'delta_ratio', 'z_score', 'unit',
        'confidence', 'quality', 'context', 'possible_contexts', 'clinical_rationale_en', 'clinical_rationale_fa', 'clinical_rationale_tr',
        'clinical_note_en', 'clinical_note_fa', 'clinical_note_tr', 'member_event_uuids', 'transcript_segment_id', 'clinician_status',
    ];

    protected $casts = [
        'quality' => 'array', 'context' => 'array', 'possible_contexts' => 'array', 'member_event_uuids' => 'array',
        'confidence' => 'float', 'clinician_status' => EventReviewStatus::class,
    ];

    protected $appends = ['diagnostic_claim'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Always null: the schema forbids diagnostic output. Present so clients can rely on the key. */
    public function getDiagnosticClaimAttribute(): mixed
    {
        return null;
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ClinicianEventReview::class);
    }

    public function transcriptSegment(): BelongsTo
    {
        return $this->belongsTo(TranscriptSegment::class);
    }
}

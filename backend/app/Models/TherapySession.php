<?php

namespace App\Models;

use App\Enums\ConsentType;
use App\Enums\SessionMode;
use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TherapySession extends Model
{
    use SoftDeletes;

    /** Internal state fields are set by controllers/services after validation; user input never reaches fill() unvalidated. */
    protected $guarded = ['id'];

    protected $casts = [
        'mode' => SessionMode::class,
        'status' => SessionStatus::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'analysis_started_at' => 'datetime',
        'analysis_paused_at' => 'datetime',
        'analysis_enabled' => 'boolean',
        'quality_summary' => 'array',
        'baseline_summary' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $s) {
            $s->uuid ??= (string) Str::uuid();
            $s->room_name ??= 'ravan-'.$s->uuid;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function clinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinician_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SessionParticipant::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(SessionConsent::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SessionMessage::class);
    }

    public function transcriptSegments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class)->orderBy('t_start_ms');
    }

    public function behaviorEvents(): HasMany
    {
        return $this->hasMany(BehaviorEvent::class)->orderBy('t_start_ms');
    }

    public function baselines(): HasMany
    {
        return $this->hasMany(BehaviorBaseline::class);
    }

    public function clinicalNotes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(SessionReport::class);
    }

    public function aiSuggestions(): HasMany
    {
        return $this->hasMany(AiSuggestion::class);
    }

    public function hasActiveConsent(ConsentType $type): bool
    {
        $version = config('ravan.consent.current_versions')[$type->value] ?? null;

        return $this->consents()
            ->where('user_id', $this->patient_id)
            ->where('type', $type->value)
            ->when($version, fn ($q) => $q->where('version', $version))
            ->whereNull('withdrawn_at')
            ->exists();
    }

    public function isParticipant(User $user): bool
    {
        return $user->id === $this->patient_id || $user->id === $this->clinician_id;
    }

    public function elapsedMs(): int
    {
        return $this->started_at ? (int) $this->started_at->diffInMilliseconds(now()) : 0;
    }
}

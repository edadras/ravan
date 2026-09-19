<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The clinical record. Clinician-authored; AI suggestions only enter it after clinician acceptance. */
class PatientRecord extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['current_medications' => 'array', 'allergies' => 'array', 'goals' => 'array'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function primaryClinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_clinician_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class)->orderByDesc('created_at');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RecordEntry::class)->orderByDesc('created_at');
    }

    public function screenings(): HasMany
    {
        return $this->hasMany(ScreeningResult::class)->orderByDesc('created_at');
    }

    public function medications(): HasMany
    {
        return $this->hasMany(Medication::class)->orderByDesc('created_at');
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(RecordAccessGrant::class);
    }

    public function aiSuggestions(): HasMany
    {
        return $this->hasMany(AiSuggestion::class)->orderByDesc('created_at');
    }

    /** A clinician may read/write if primary, has a grant, or has had a session with the patient. */
    public function allowsClinician(User $user): bool
    {
        if ($user->id === $this->primary_clinician_id) {
            return true;
        }
        if ($this->accessGrants()->where('clinician_id', $user->id)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) {
            return true;
        }

        return TherapySession::where('patient_id', $this->patient_id)->where('clinician_id', $user->id)->exists();
    }
}

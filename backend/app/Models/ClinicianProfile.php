<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicianProfile extends Model
{
    /** Internal state fields are set by admin controllers after validation. */
    protected $guarded = ['id'];

    protected $casts = [
        'languages' => 'array',
        'session_modes' => 'array',
        'license_expires_at' => 'date',
        'verified_at' => 'datetime',
        'verification_status' => VerificationStatus::class,
        'accepts_new_patients' => 'boolean',
    ];

    protected $hidden = ['license_number'];

    public function scopeVerified(Builder $q): Builder
    {
        return $q->where('verification_status', VerificationStatus::Approved->value)
            ->whereHas('user', fn ($u) => $u->where('is_active', true));
    }

    public function isVerified(): bool
    {
        return $this->verification_status === VerificationStatus::Approved;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'clinician_specialty');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ClinicianDocument::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(ClinicianVerification::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ClinicianSchedule::class);
    }

    public function timeOff(): HasMany
    {
        return $this->hasMany(ClinicianTimeOff::class);
    }
}

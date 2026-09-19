<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'phone', 'password', 'role', 'locale', 'terms_accepted_at', 'terms_version', 'email_verified_at', 'phone_verified_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isClinician(): bool
    {
        return $this->role === Role::Clinician;
    }

    public function isPatient(): bool
    {
        return $this->role === Role::Patient;
    }

    public function patientProfile(): HasOne
    {
        return $this->hasOne(PatientProfile::class);
    }

    public function clinicianProfile(): HasOne
    {
        return $this->hasOne(ClinicianProfile::class);
    }

    public function sessionsAsPatient(): HasMany
    {
        return $this->hasMany(TherapySession::class, 'patient_id');
    }

    public function sessionsAsClinician(): HasMany
    {
        return $this->hasMany(TherapySession::class, 'clinician_id');
    }

    public function record(): HasOne
    {
        return $this->hasOne(PatientRecord::class, 'patient_id');
    }
}

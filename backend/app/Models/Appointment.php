<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\SessionMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Appointment extends Model
{
    /** Internal state fields are set by controllers/services after validation; user input never reaches fill() unvalidated. */
    protected $guarded = ['id'];

    protected $casts = [
        'starts_at' => 'datetime', 'ends_at' => 'datetime',
        'mode' => SessionMode::class, 'status' => AppointmentStatus::class,
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->uuid ??= (string) Str::uuid());
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function clinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinician_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function session(): HasOne
    {
        return $this->hasOne(TherapySession::class);
    }
}

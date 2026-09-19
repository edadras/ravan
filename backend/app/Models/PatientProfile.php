<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PatientProfile extends Model
{
    protected $fillable = ['user_id', 'pseudonym', 'birth_date', 'gender', 'preferred_language', 'timezone', 'accessibility_prefs'];

    protected $casts = ['birth_date' => 'date', 'accessibility_prefs' => 'array', 'emergency_contact_encrypted' => 'encrypted'];

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->pseudonym ??= (string) Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

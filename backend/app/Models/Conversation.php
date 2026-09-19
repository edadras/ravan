<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['last_message_at' => 'datetime'];

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function clinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinician_id');
    }

    public function isParticipant(User $u): bool
    {
        return $u->id === $this->patient_id || $u->id === $this->clinician_id;
    }
}

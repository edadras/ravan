<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionMessage extends Model
{
    protected $fillable = ['therapy_session_id', 'sender_id', 'body', 't_ms', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}

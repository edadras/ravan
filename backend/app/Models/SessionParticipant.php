<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionParticipant extends Model
{
    protected $fillable = ['therapy_session_id', 'user_id', 'role', 'joined_at', 'left_at', 'device_info'];

    protected $casts = ['joined_at' => 'datetime', 'left_at' => 'datetime', 'device_info' => 'array'];
}

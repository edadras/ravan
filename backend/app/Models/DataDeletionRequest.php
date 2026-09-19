<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataDeletionRequest extends Model
{
    protected $fillable = ['user_id', 'therapy_session_id', 'scope', 'status', 'scheduled_for', 'completed_at'];

    protected $casts = ['scheduled_for' => 'datetime', 'completed_at' => 'datetime'];
}

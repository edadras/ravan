<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'therapy_session_id', 'resource', 'purpose', 'ip_hash', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}

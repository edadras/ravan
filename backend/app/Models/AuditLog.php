<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'meta', 'ip_hash', 'created_at'];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];
}

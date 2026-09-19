<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicalNote extends Model
{
    protected $fillable = ['therapy_session_id', 'clinician_id', 't_ms', 'body', 'is_private'];

    protected $casts = ['is_private' => 'boolean'];
}

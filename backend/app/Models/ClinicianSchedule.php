<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicianSchedule extends Model
{
    protected $fillable = ['clinician_profile_id', 'weekday', 'start_time', 'end_time', 'slot_minutes', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}

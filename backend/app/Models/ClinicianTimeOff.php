<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicianTimeOff extends Model
{
    protected $table = 'clinician_time_off';

    protected $fillable = ['clinician_profile_id', 'starts_at', 'ends_at', 'reason'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
}

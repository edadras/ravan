<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BehaviorBaseline extends Model
{
    protected $fillable = ['therapy_session_id', 'feature', 'speaker_state', 'median', 'sigma', 'rate_per_min', 'n', 'coverage_s', 'quality_fraction'];
}

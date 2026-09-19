<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicianEventReview extends Model
{
    protected $fillable = ['behavior_event_id', 'clinician_id', 'status', 'note', 'selected_context'];
}

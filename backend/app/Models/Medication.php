<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Medication extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'date', 'stopped_at' => 'date'];
}

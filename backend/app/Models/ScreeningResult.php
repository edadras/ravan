<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreeningResult extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['answers' => 'array', 'item_flag' => 'boolean'];

    public function record(): BelongsTo
    {
        return $this->belongsTo(PatientRecord::class, 'patient_record_id');
    }
}

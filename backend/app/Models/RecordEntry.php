<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordEntry extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['mental_status_exam' => 'array', 'is_locked' => 'boolean'];

    public function record(): BelongsTo
    {
        return $this->belongsTo(PatientRecord::class, 'patient_record_id');
    }
}

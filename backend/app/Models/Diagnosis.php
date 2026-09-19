<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Diagnosis extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['onset_date' => 'date', 'confirmed_at' => 'datetime'];

    public function code(): BelongsTo
    {
        return $this->belongsTo(DiagnosisCode::class, 'diagnosis_code_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(PatientRecord::class, 'patient_record_id');
    }
}

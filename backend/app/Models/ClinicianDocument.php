<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicianDocument extends Model
{
    protected $fillable = ['clinician_profile_id', 'type', 'path', 'original_name', 'mime', 'size_bytes', 'sha256'];

    protected $hidden = ['path'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ClinicianProfile::class, 'clinician_profile_id');
    }
}

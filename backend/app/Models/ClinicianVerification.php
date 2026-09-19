<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicianVerification extends Model
{
    protected $fillable = ['clinician_profile_id', 'reviewed_by', 'decision', 'notes', 'checked_items'];

    protected $casts = ['checked_items' => 'array'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ClinicianProfile::class, 'clinician_profile_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

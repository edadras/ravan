<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Specialty extends Model
{
    protected $fillable = ['slug', 'name_fa', 'name_en'];

    public function clinicians(): BelongsToMany
    {
        return $this->belongsToMany(ClinicianProfile::class, 'clinician_specialty');
    }
}

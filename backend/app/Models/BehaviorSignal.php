<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BehaviorSignal extends Model
{
    protected $fillable = [
        'signal_id', 'group', 'tier', 'observation_en', 'observation_fa', 'observation_tr', 'features', 'detector', 'quality_gates',
        'possible_contexts', 'clinical_rationale_en', 'clinical_rationale_fa', 'clinical_rationale_tr',
        'clinical_note_en', 'clinical_note_fa', 'clinical_note_tr',
        'forbidden_labels', 'catalog_version', 'is_enabled',
    ];

    protected $casts = [
        'features' => 'array', 'detector' => 'array', 'quality_gates' => 'array',
        'possible_contexts' => 'array', 'forbidden_labels' => 'array', 'is_enabled' => 'boolean',
    ];
}

<?php

namespace App\Services;

use App\Models\BehaviorSignal;
use RuntimeException;

class SignalCatalogImporter
{
    public function import(?string $path = null): array
    {
        $path ??= config('ravan.catalog_path');
        if (! is_file($path)) {
            throw new RuntimeException("Catalog not found at {$path}. Run catalog/build_catalog.py first.");
        }
        $catalog = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $version = $catalog['schema_version'];
        $n = 0;
        foreach ($catalog['signals'] as $s) {
            BehaviorSignal::updateOrCreate(['signal_id' => $s['id']], [
                'group' => $s['group'],
                'tier' => $s['tier'],
                'observation_en' => $s['observation']['en'],
                'observation_fa' => $s['observation']['fa'],
                'observation_tr' => $s['observation']['tr'],
                'features' => $s['features'],
                'detector' => $s['detector'],
                'quality_gates' => $s['quality_gates'],
                'possible_contexts' => $s['possible_contexts'],
                'clinical_rationale_en' => $s['clinical_rationale']['en'] ?? null,
                'clinical_rationale_fa' => $s['clinical_rationale']['fa'] ?? null,
                'clinical_rationale_tr' => $s['clinical_rationale']['tr'] ?? null,
                'clinical_note_en' => $s['clinical_note']['en'] ?? null,
                'clinical_note_fa' => $s['clinical_note']['fa'] ?? null,
                'clinical_note_tr' => $s['clinical_note']['tr'] ?? null,
                'forbidden_labels' => $s['forbidden_labels'],
                'catalog_version' => $version,
            ]);
            $n++;
        }

        return ['version' => $version, 'signals' => $n, 'groups' => count($catalog['groups'])];
    }
}

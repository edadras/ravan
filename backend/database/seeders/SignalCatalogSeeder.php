<?php

namespace Database\Seeders;

use App\Services\SignalCatalogImporter;
use Illuminate\Database\Seeder;

class SignalCatalogSeeder extends Seeder
{
    public function run(SignalCatalogImporter $importer): void
    {
        $result = $importer->import();
        $this->command?->info("Imported catalog v{$result['version']}: {$result['signals']} signals in {$result['groups']} groups.");
    }
}

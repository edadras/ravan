<?php

namespace Tests\Feature;

use App\Models\BehaviorSignal;
use App\Models\User;
use Database\Seeders\SignalCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_is_imported_with_bilingual_text_and_no_diagnostic_fields(): void
    {
        $this->seed(SignalCatalogSeeder::class);
        $this->assertGreaterThan(250, BehaviorSignal::count());
        $s = BehaviorSignal::where('signal_id', 'arms_crossed_sustained')->firstOrFail();
        $this->assertNotEmpty($s->observation_fa);
        $this->assertNotEmpty($s->observation_tr);
        $this->assertNotEmpty($s->clinical_note_tr);
        $this->assertContains('deception', $s->forbidden_labels);
        $this->assertSame('sustained', $s->detector['type']);
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/catalog/signals?group=hands_arms')->assertOk()->assertJsonPath('signals.0.group', 'hands_arms');
    }
}

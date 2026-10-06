<?php

namespace Tests\Feature;

use App\Models\Aggregate;
use App\Models\Document;
use App\Models\QuestionnaireVersion;
use App\Models\ValidationRule;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_health_passes_on_a_clean_seed(): void
    {
        $this->artisan('susenas:health')
            ->expectsOutputToContain('OK')
            ->assertExitCode(0);
    }

    public function test_health_flags_a_rule_with_broken_expression(): void
    {
        ValidationRule::query()->first()->update(['expr' => 'n(a) and and (']);

        $this->artisan('susenas:health')
            ->expectsOutputToContain('sintaks ekspresi rusak')
            ->assertExitCode(1);
    }

    public function test_health_flags_clean_document_that_still_has_errors(): void
    {
        $doc = Document::where('status', '!=', 'bersih')->firstOrFail();
        $doc->update(['status' => 'bersih']);
        \App\Models\RuleResult::create([
            'document_id' => $doc->id, 'rule_id' => ValidationRule::first()->id,
            'status' => 'error', 'message' => 'x', 'target_var' => '', 'row_index' => 0, 'computed_at' => now(),
        ]);

        $this->artisan('susenas:health')
            ->expectsOutputToContain('bersih tapi masih ada error')
            ->assertExitCode(1);
    }

    public function test_health_flags_aggregate_with_missing_block(): void
    {
        $qv = QuestionnaireVersion::first();
        Aggregate::create([
            'qversion_id' => $qv->id, 'name' => 'uji_agg', 'label' => 'x',
            'block_code' => 'BLOK-HANTU', 'value_var' => 'x', 'op' => 'sum',
            'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '', 'order' => 99,
        ]);

        $this->artisan('susenas:health')
            ->expectsOutputToContain('block_code yang tidak ada')
            ->assertExitCode(1);
    }

    public function test_health_flags_two_active_pj_in_the_same_kecamatan(): void
    {
        \App\Models\User::create([
            'username' => 'pj_kamal_dobel', 'name' => 'Dobel', 'email' => 'dobel@example.test',
            'password' => bcrypt('x'), 'role' => 'pj', 'wilayah_scope' => '3526010', 'active' => true,
        ]);

        $this->artisan('susenas:health')
            ->expectsOutputToContain('Lebih dari satu PJ aktif')
            ->assertExitCode(1);
    }
}

<?php

namespace Tests\Feature;

use App\Models\SurveyCycle;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CycleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_admin_creates_a_cycle(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin)->post('/admin/jadwal', [
            'name' => 'SUSENAS Maret 2027', 'period' => '2027-03', 'status' => 'persiapan',
            'field_start' => '2027-03-01', 'field_end' => '2027-03-31',
        ])->assertRedirect(route('admin.jadwal'))->assertSessionHas('ok');

        $this->assertDatabaseHas('survey_cycles', ['name' => 'SUSENAS Maret 2027', 'status' => 'persiapan']);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'cycle', 'action' => 'buat']);
    }

    public function test_admin_edits_cycle_name_and_status(): void
    {
        $c = SurveyCycle::first();
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)->post("/admin/jadwal/{$c->id}", [
            'name' => 'Nama Diubah', 'status' => 'pengolahan',
        ])->assertRedirect();

        $c->refresh();
        $this->assertSame('Nama Diubah', $c->name);
        $this->assertSame('pengolahan', $c->status);
    }

    public function test_invalid_status_rejected(): void
    {
        $c = SurveyCycle::first();
        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->post("/admin/jadwal/{$c->id}", ['name' => 'x', 'status' => 'ngawur'])
            ->assertSessionHasErrors('status');
    }

    public function test_non_admin_blocked(): void
    {
        $this->actingAs(User::where('username', 'rahmawati')->firstOrFail())
            ->post('/admin/jadwal', ['name' => 'x', 'period' => '2027-03', 'status' => 'persiapan'])
            ->assertForbidden();
    }
}

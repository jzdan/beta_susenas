<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditFilterTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    public function test_filters_by_actor_date_and_text(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        AuditLog::create(['entity' => 'uji', 'entity_id' => '77', 'action' => 'uji-aksi',
            'detail' => 'penanda unik zzz', 'by_user' => $pml->id, 'at' => now()->subDays(2)]);
        AuditLog::create(['entity' => 'uji', 'entity_id' => '78', 'action' => 'lain',
            'detail' => 'bukan itu', 'by_user' => $this->admin()->id, 'at' => now()->subDays(40)]);

        $byText = collect($this->actingAs($this->admin())->get('/monitoring/audit?q=penanda+unik+zzz')->viewData('rows'));
        $this->assertCount(1, $byText);
        $this->assertSame('77', $byText->first()->entity_id);

        $byActor = collect($this->actingAs($this->admin())->get('/monitoring/audit?user='.$pml->id)->viewData('rows'));
        $this->assertTrue($byActor->every(fn ($r) => (int) $r->by_user === $pml->id));

        $from = now()->subDays(7)->toDateString();
        $recent = collect($this->actingAs($this->admin())->get("/monitoring/audit?from={$from}")->viewData('rows'));
        $this->assertTrue($recent->every(fn ($r) => $r->at->gte(now()->subDays(7)->startOfDay())));
        $this->assertFalse($recent->contains(fn ($r) => $r->entity_id === '78'));
    }

    public function test_csv_export_honours_filters(): void
    {
        AuditLog::create(['entity' => 'uji', 'entity_id' => '90', 'action' => 'x',
            'detail' => 'target-ekspor', 'by_user' => $this->admin()->id, 'at' => now()]);

        $res = $this->actingAs($this->admin())->get('/monitoring/audit?q=target-ekspor&export=1');
        $res->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $res->streamedContent();
        $this->assertStringContainsString('target-ekspor', $csv);
        $this->assertStringContainsString('waktu,entitas', $csv);
    }

    public function test_audit_is_pml_or_admin_only(): void
    {
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->get('/monitoring/audit')->assertForbidden();
    }
}

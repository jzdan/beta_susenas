<?php

namespace Tests\Feature;

use App\Models\Aggregate;
use App\Models\Block;
use App\Models\BlockRow;
use App\Models\Parameter;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use App\Models\ValidationRule;
use App\Services\ExpressionEvaluator;
use App\Services\QuestionnairePackage;
use App\Services\VersionChecker;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inc 145 — struktur kuesioner tidak terikat ke kode blok / nama variabel tertentu:
 * blok komoditas boleh berganti kode (mis. IV.1 → V), nama variabel kunci lewat
 * parameter sistem, dan versi diperiksa dulu sebelum diaktifkan.
 */
class VersionFlexibilityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    /** Salinan VSEN26.KP lewat duplikasi versi (supaya tes tak mengganggu versi seed). */
    private function copyOfKp(string $code): QuestionnaireVersion
    {
        $kp = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $this->actingAs($this->admin())->post("/admin/versi/{$kp->id}/aktifkan")->assertRedirect();
        $this->actingAs($this->admin())->post('/admin/versi/duplikat', ['new_code' => $code])->assertRedirect();

        return QuestionnaireVersion::where('code', $code)->firstOrFail();
    }

    public function test_ganti_kode_blok_ikut_memperbarui_aturan_dan_agregat(): void
    {
        $qv = $this->copyOfKp('UJI-RENAME');
        $b = Block::where('qversion_id', $qv->id)->where('code', 'IV.1')->firstOrFail();
        $nRules = ValidationRule::where('qversion_id', $qv->id)->where('row_block', 'IV.1')->count();
        $nAggs = Aggregate::where('qversion_id', $qv->id)->where('block_code', 'IV.1')->count();
        $this->assertGreaterThan(0, $nRules);
        $this->assertGreaterThan(0, $nAggs);

        $this->actingAs($this->admin())->post("/admin/blok/{$b->id}", [
            'code' => 'IX', 'title' => $b->title, 'repeat' => 'list', 'harga_kalori' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('IX', $b->fresh()->code);
        $this->assertSame(0, ValidationRule::where('qversion_id', $qv->id)->where('row_block', 'IV.1')->count());
        $this->assertSame($nRules, ValidationRule::where('qversion_id', $qv->id)->where('row_block', 'IX')->count());
        $this->assertSame($nAggs, Aggregate::where('qversion_id', $qv->id)->where('block_code', 'IX')->count());

        // tab Harga & Kalori tetap menemukan komoditasnya (lewat tanda, bukan kode)
        $res = $this->actingAs($this->admin())->get("/admin/aturan?qv={$qv->id}&tab=harga")->assertOk();
        $this->assertSame(BlockRow::where('block_id', $b->id)->count(), $res->viewData('komoditas')->count());

        // versi tetap utuh menurut pengecek
        $this->assertSame([], app(VersionChecker::class)->check($qv)['errors']);

        // versi lain tidak ikut berubah
        $kp = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $this->assertTrue(Block::where('qversion_id', $kp->id)->where('code', 'IV.1')->exists());
    }

    public function test_kode_blok_ganda_ditolak(): void
    {
        $qv = $this->copyOfKp('UJI-DUPKODE');
        $b = Block::where('qversion_id', $qv->id)->where('code', 'IV.1')->firstOrFail();

        $this->actingAs($this->admin())->post("/admin/blok/{$b->id}", [
            'code' => 'IV.2', 'title' => $b->title, 'repeat' => 'list',
        ])->assertSessionHasErrors('code');
        $this->assertSame('IV.1', $b->fresh()->code);
    }

    public function test_tanda_harga_kalori_menentukan_isi_tab_harga_dan_ikut_diduplikasi(): void
    {
        $qv = $this->copyOfKp('UJI-HARGA');
        $iv1 = Block::where('qversion_id', $qv->id)->where('code', 'IV.1')->firstOrFail();
        $iv2 = Block::where('qversion_id', $qv->id)->where('code', 'IV.2')->firstOrFail();
        $this->assertTrue($iv1->harga_kalori, 'duplikasi versi harus membawa tanda harga_kalori');
        $this->assertFalse($iv2->harga_kalori);

        // pindahkan tanda ke IV.2 → tab Harga menampilkan baris IV.2
        $this->actingAs($this->admin())->post("/admin/blok/{$iv1->id}", [
            'code' => 'IV.1', 'title' => $iv1->title, 'repeat' => 'list',
        ])->assertRedirect();
        $this->actingAs($this->admin())->post("/admin/blok/{$iv2->id}", [
            'code' => 'IV.2', 'title' => $iv2->title, 'repeat' => 'list', 'harga_kalori' => '1',
        ])->assertRedirect();

        $res = $this->actingAs($this->admin())->get("/admin/aturan?qv={$qv->id}&tab=harga")->assertOk();
        $ids = $res->viewData('komoditas')->pluck('block_id')->unique()->values()->all();
        $this->assertSame([$iv2->id], $ids);

        // blok IV.1 punya patokan harga tapi tak lagi bertanda → peringatan pengecek
        $warn = implode("\n", app(VersionChecker::class)->check($qv)['warnings']);
        $this->assertStringContainsString('Blok IV.1 punya patokan harga', $warn);
    }

    public function test_tanda_harga_kalori_diabaikan_untuk_blok_bukan_daftar(): void
    {
        $qv = $this->copyOfKp('UJI-HARGA-RUTA');
        $b3 = Block::where('qversion_id', $qv->id)->where('code', 'III')->firstOrFail();

        $this->actingAs($this->admin())->post("/admin/blok/{$b3->id}", [
            'code' => 'III', 'title' => $b3->title, 'repeat' => 'ruta', 'harga_kalori' => '1',
        ])->assertRedirect();
        $this->assertFalse($b3->fresh()->harga_kalori);
    }

    public function test_paket_json_membawa_tanda_harga_kalori(): void
    {
        $kp = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $svc = app(QuestionnairePackage::class);
        $pkg = $svc->export($kp);
        $iv1 = collect($pkg['blocks'])->firstWhere('code', 'IV.1');
        $this->assertTrue($iv1['harga_kalori']);

        // paket lama tanpa tanda: IV.1 dianggap blok harga
        foreach ($pkg['blocks'] as &$b) {
            unset($b['harga_kalori']);
        }
        unset($b);
        $new = $svc->import($pkg, $kp->cycle_id, 'UJI-PAKET-LAMA');
        $this->assertTrue(Block::where('qversion_id', $new->id)->where('code', 'IV.1')->value('harga_kalori'));
        $this->assertFalse((bool) Block::where('qversion_id', $new->id)->where('code', 'IV.2')->value('harga_kalori'));
    }

    public function test_aktivasi_ditolak_bila_aturan_merujuk_variabel_yang_tidak_ada(): void
    {
        $qv = $this->copyOfKp('UJI-PUTUS');
        $before = QuestionnaireVersion::where('is_active', true)->value('id');
        ValidationRule::create([
            'qversion_id' => $qv->id, 'code' => 'UJI-PUTUS', 'scope' => 'dokumen', 'row_block' => '',
            'target_var' => '', 'expr' => 'n(var_yang_tidak_ada) > 0', 'severity' => 'warning',
            'message' => 'x', 'active' => true,
        ]);

        $this->actingAs($this->admin())->post("/admin/versi/{$qv->id}/aktifkan")
            ->assertRedirect()->assertSessionHas('versiCheck', fn ($c) => $c['blocked']
                && str_contains(implode(' ', $c['errors']), 'var_yang_tidak_ada'));
        $this->assertSame($before, QuestionnaireVersion::where('is_active', true)->value('id'));

        // dipaksa → aktif, tercatat di log audit
        $this->actingAs($this->admin())->post("/admin/versi/{$qv->id}/aktifkan", ['force' => '1'])->assertRedirect();
        $this->assertTrue($qv->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'qversion', 'entity_id' => (string) $qv->id, 'action' => 'aktifkan']);
        $this->assertStringContainsString('DIPAKSA',
            \App\Models\AuditLog::where('entity', 'qversion')->where('entity_id', (string) $qv->id)->latest('id')->value('detail'));
    }

    public function test_versi_seed_lolos_pemeriksaan(): void
    {
        foreach (QuestionnaireVersion::all() as $qv) {
            $this->assertSame([], app(VersionChecker::class)->check($qv)['errors'], "versi {$qv->code}");
        }
        $kp = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $this->actingAs($this->admin())->post("/admin/versi/{$kp->id}/periksa")
            ->assertRedirect()->assertSessionHas('versiCheck');
    }

    public function test_parameter_sistem_var_jumlah_art(): void
    {
        $kp = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        // bawaan = perilaku lama
        $this->assertSame(['banyak_art', 'r301', 'jml_art', 'jumlah_art'], Parameter::systemVars($kp->id, 'var_jumlah_art'));

        // diatur admin → dipakai; menunjuk variabel yang tak ada → error pengecek
        $qv = $this->copyOfKp('UJI-PARAM');
        Parameter::create(['qversion_id' => $qv->id, 'name' => 'var_jumlah_art', 'value' => 'jumlah_anggota_baru']);
        $this->assertSame(['jumlah_anggota_baru'], Parameter::systemVars($qv->id, 'var_jumlah_art'));
        $err = implode("\n", app(VersionChecker::class)->check($qv)['errors']);
        $this->assertStringContainsString('var_jumlah_art', $err);

        Parameter::where('qversion_id', $qv->id)->where('name', 'var_jumlah_art')->update(['value' => 'jumlah_anggota_baru, banyak_art']);
        $this->assertSame([], app(VersionChecker::class)->check($qv)['errors']);
    }

    public function test_referenced_names_termasuk_argumen_roster(): void
    {
        $ev = new ExpressionEvaluator;
        $names = $ev->referencedNames('n(a) > 0 and roster_count(b, 1) > max(c, 2) and not blank(d)');
        sort($names);
        $this->assertSame(['a', 'b', 'c', 'd'], $names);
        $this->assertSame([], $ev->referencedNames(''));
    }

    public function test_rincian_sampel_per_versi(): void
    {
        $kp = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $expected = \App\Models\SampleHousehold::where('cycle_id', $kp->cycle_id)
            ->where('status', '!=', 'ganti_sampel')->count();

        // Metadata menampilkan total sampel + tautan rincian
        $this->actingAs($this->admin())->get("/admin/metadata?qv={$kp->id}")->assertOk()
            ->assertViewHas('sampleTotals', fn ($t) => (int) $t[$kp->cycle_id] === $expected)
            ->assertSee(route('admin.versi.sampel', $kp), false);

        $res = $this->actingAs($this->admin())->get("/admin/versi/{$kp->id}/sampel")->assertOk();
        $this->assertSame($expected, $res->viewData('total'));
        $kec = $res->viewData('kec');
        $this->assertNotEmpty($kec);
        $this->assertSame($expected, collect($kec)->sum('total'));
        // tiap kecamatan memuat desa & nama petugas
        $first = reset($kec);
        $this->assertNotEmpty($first['desa']);
        $this->assertNotEmpty($first['petugas']);
        $res->assertSee(reset($first['petugas']));

        // hanya admin
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->get("/admin/versi/{$kp->id}/sampel")->assertForbidden();
    }

    public function test_metadata_menampilkan_jenis_untuk_semua_blok(): void
    {
        $kp = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $this->actingAs($this->admin())->get("/admin/metadata?qv={$kp->id}")->assertOk()
            ->assertSee('per rumah tangga')->assertSee('daftar baris tetap')->assertSee('harga &amp; kalori', false);
    }
}

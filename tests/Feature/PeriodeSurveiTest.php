<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Document;
use App\Models\QuestionnaireVersion;
use App\Models\SurveyCycle;
use App\Models\User;
use App\Models\ValidationRule;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inc 154 — Periode Survei: tahun → triwulan → survei → hub, siapkan triwulan, kunci versi. */
class PeriodeSurveiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function u(string $name): User
    {
        return User::where('username', $name)->firstOrFail();
    }

    private function kp(): SurveyCycle
    {
        return SurveyCycle::where('name', 'SUSENAS Maret 2026 (KP)')->firstOrFail();
    }

    public function test_identity_is_inferred_from_name_and_version_code(): void
    {
        $this->assertSame(['SERUTI', 'INTI'], SurveyCycle::inferIdentity('SERUTI Juni 2026 (INTI)', null));
        $this->assertSame(['SUSENAS', 'KOR'], SurveyCycle::inferIdentity('SUSENAS Maret 2026 (KOR)', 'VSEN26.K'));
        $this->assertSame(['SUSENAS', 'KP'], SurveyCycle::inferIdentity('SUSENAS Maret 2027 (contoh)', 'VSEN27.KP'));
        $this->assertSame(['SERUTI', 'INTI'], SurveyCycle::inferIdentity('SERUTI Maret 2026', null));

        $kp = $this->kp();
        $this->assertSame([2026, 1, 'SUSENAS KP'], [$kp->year(), $kp->quarter(), $kp->label()]);
    }

    public function test_four_levels_render_for_admin_and_pengawas(): void
    {
        $kp = $this->kp();
        foreach (['admin', 'rahmawati'] as $who) {
            $this->actingAs($this->u($who))->get('/periode')->assertOk()->assertSee('2026')->assertSee((string) now()->year);
            $this->actingAs($this->u($who))->get('/periode/2026')->assertOk()->assertSee('TW1')->assertSee('TW4');
            $this->actingAs($this->u($who))->get('/periode/2026/tw/1')->assertOk()
                ->assertSee('SUSENAS KP')->assertSee('SUSENAS KOR')->assertSee('Beban petugas');
            $this->actingAs($this->u($who))->get("/periode/siklus/{$kp->id}")->assertOk()
                ->assertSee('Tahap sampel')->assertSee('Peserta');
            $this->actingAs($this->u($who))->get("/periode/siklus/{$kp->id}/peserta")->assertOk()
                ->assertSee('Pencacah Lapangan');
        }
        // petugas lapangan tidak masuk ke halaman pengelolaan periode
        $this->actingAs($this->u('ppl_ani'))->get('/periode')->assertForbidden();
    }

    public function test_hub_tabs_follow_role(): void
    {
        $kp = $this->kp();
        $this->actingAs($this->u('admin'))->get("/periode/siklus/{$kp->id}")
            ->assertSee('Kuesioner')->assertSee('Aturan Validasi')->assertSee('Persiapan &amp; Penutupan', false);
        $this->actingAs($this->u('rahmawati'))->get("/periode/siklus/{$kp->id}")
            ->assertDontSee('Aturan Validasi')->assertSee('Pengolahan');
    }

    public function test_old_pages_show_hub_header_when_tied_to_a_cycle(): void
    {
        $kp = $this->kp();
        $qv = QuestionnaireVersion::where('cycle_id', $kp->id)->firstOrFail();
        $admin = $this->u('admin');
        foreach (["/admin/metadata?qv={$qv->id}", "/admin/aturan?qv={$qv->id}", "/monitoring/sampel?cycle={$kp->id}",
            "/pengolahan?cycle={$kp->id}", "/admin/siklus/{$kp->id}"] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('hub-tabs', false);
        }
    }

    public function test_pengolahan_can_be_filtered_to_one_cycle(): void
    {
        $kp = $this->kp();
        $rows = $this->actingAs($this->u('admin'))->get("/pengolahan?cycle={$kp->id}")->viewData('rows');
        $this->assertNotEmpty($rows);
        $this->assertTrue(collect($rows)->every(fn ($r) => $r['doc']->sample->cycle_id === $kp->id));
    }

    public function test_sidebar_moves_admin_menus_into_hub(): void
    {
        $res = $this->actingAs($this->u('admin'))->get('/monitoring')->assertOk();
        $res->assertSee('Periode Survei')->assertSee('Triwulan berjalan')->assertSee('Data bersama')
            ->assertDontSee('>Metadata Kuesioner<', false)->assertDontSee('>Jadwal Lapangan<', false);
        // alamat lama tetap bisa dibuka
        $this->actingAs($this->u('admin'))->get('/admin/jadwal')->assertOk();
    }

    public function test_prepare_quarter_copies_questionnaire_into_a_new_cycle(): void
    {
        $kp = $this->kp();
        $src = QuestionnaireVersion::where('cycle_id', $kp->id)->firstOrFail();

        $this->actingAs($this->u('admin'))->get('/periode/2026/tw/2')->assertOk()->assertSee('Siapkan triwulan ini');
        $this->actingAs($this->u('admin'))->post('/periode/2026/tw/2/siapkan', [
            'pick' => ['SUSENAS|KP'], 'src' => ['SUSENAS|KP' => $src->id], 'month' => 6,
        ])->assertRedirect('/periode/2026/tw/2');

        $new = SurveyCycle::where('period', '2026-06')->firstOrFail();
        $this->assertSame(['SUSENAS Juni 2026 (KP)', 'persiapan', 'SUSENAS', 'KP'],
            [$new->name, $new->status, $new->survey, $new->instrument]);
        $qv = QuestionnaireVersion::where('cycle_id', $new->id)->firstOrFail();
        $this->assertSame('VSEN26.KP-TW2', $qv->code);
        $this->assertSame(Block::where('qversion_id', $src->id)->count(), Block::where('qversion_id', $qv->id)->count());
        $this->assertSame(ValidationRule::where('qversion_id', $src->id)->count(), ValidationRule::where('qversion_id', $qv->id)->count());

        // bulan harus di dalam triwulan
        $this->actingAs($this->u('admin'))->post('/periode/2026/tw/2/siapkan', ['pick' => ['SUSENAS|KP'], 'src' => ['SUSENAS|KP' => $src->id], 'month' => 9])
            ->assertSessionHasErrors('month');
        // versi sumber harus milik survei yang dipilih
        $kor = QuestionnaireVersion::where('code', 'VSEN26.K')->firstOrFail();
        $this->actingAs($this->u('admin'))->post('/periode/2026/tw/3/siapkan', ['pick' => ['SUSENAS|KP'], 'src' => ['SUSENAS|KP' => $kor->id], 'month' => 9])
            ->assertStatus(422);
        // pilihan versi sumber tampil lengkap dengan bulan & jumlah blok
        $this->actingAs($this->u('admin'))->get('/periode/2026/tw/3')->assertOk()->assertSee('VSEN26.KP · Maret 2026');
        // hanya admin
        $this->actingAs($this->u('rahmawati'))->post('/periode/2026/tw/3/siapkan', ['pick' => ['SUSENAS|KP'], 'src' => ['SUSENAS|KP' => $src->id], 'month' => 9])
            ->assertForbidden();
    }

    public function test_questionnaire_of_a_locked_cycle_is_read_only(): void
    {
        $kp = $this->kp();
        $qv = QuestionnaireVersion::where('cycle_id', $kp->id)->firstOrFail();
        $rule = ValidationRule::where('qversion_id', $qv->id)->firstOrFail();
        $kp->update(['status' => 'selesai']);
        $admin = $this->u('admin');

        $this->actingAs($admin)->post("/admin/aturan/{$rule->id}/toggle")->assertStatus(423);
        $this->actingAs($admin)->post('/admin/aturan-toggle-massal', ['qv' => $qv->id, 'ids' => [$rule->id], 'aksi' => 'nonaktif'])
            ->assertStatus(423);
        $this->assertTrue($rule->fresh()->active);
        $this->actingAs($admin)->get("/admin/metadata?qv={$qv->id}")->assertOk()->assertSee('hanya bisa dilihat');

        // dibuka kuncinya → boleh diubah lagi
        $kp->update(['status' => 'pengolahan']);
        $this->actingAs($admin)->post("/admin/aturan/{$rule->id}/toggle")->assertRedirect();
        $this->assertFalse($rule->fresh()->active);
    }

    public function test_peta_progres_shows_one_cycle_by_default(): void
    {
        $admin = $this->u('admin');
        $lapangan = SurveyCycle::where('status', 'lapangan')->orderByDesc('period')->firstOrFail();
        $res = $this->actingAs($admin)->get('/monitoring/peta')->assertOk();
        $this->assertSame($lapangan->id, $res->viewData('cycle')->id);
        $this->assertSame($lapangan->samples()->where('status', '!=', 'ganti_sampel')->count(), $res->viewData('total'));
        $res->assertSee('TW'.$lapangan->quarter().' '.$lapangan->year());

        $all = $this->actingAs($admin)->get('/monitoring/peta?cycle=semua')->assertOk();
        $this->assertNull($all->viewData('cycle'));
        $this->assertGreaterThan($res->viewData('total'), $all->viewData('total'));
    }

    public function test_dashboard_and_kinerja_default_to_the_running_cycle(): void
    {
        $admin = $this->u('admin');
        $lapangan = SurveyCycle::where('status', 'lapangan')->orderByDesc('period')->firstOrFail();

        $dash = $this->actingAs($admin)->get('/monitoring')->assertOk();
        $this->assertSame($lapangan->id, $dash->viewData('cycle')->id);
        $this->assertSame($lapangan->samples()->count(), $dash->viewData('nAll'));
        $this->assertGreaterThan($dash->viewData('nAll'), $this->actingAs($admin)->get('/monitoring?cycle=semua')->viewData('nAll'));

        $kin = $this->actingAs($admin)->get('/monitoring/kinerja')->assertOk();
        $this->assertSame($lapangan->id, $kin->viewData('cycle')->id);
        $this->assertSame(Document::whereHas('sample', fn ($q) => $q->where('cycle_id', $lapangan->id))->count(),
            $kin->viewData('totalDocs'));

        // Daftar Sampel tetap bawaan semua siklus
        $this->assertSame('', $this->actingAs($admin)->get('/monitoring/sampel')->viewData('fCycle'));
    }

    public function test_running_quarter_box_follows_the_calendar(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::create(2026, 11, 25));
        // siklus lapangan di triwulan lain: yang belum mulai & yang jadwalnya sudah lewat diberi catatan (Inc 163)
        SurveyCycle::create(['name' => 'SUSENAS Maret 2027 (KP)', 'period' => '2027-03', 'status' => 'lapangan',
            'field_start' => '2027-03-01', 'field_end' => '2027-03-31']);
        SurveyCycle::create(['name' => 'SERUTI Juni 2026 (INTI)', 'period' => '2026-06', 'status' => 'lapangan',
            'field_start' => '2026-06-01', 'field_end' => '2026-06-30']);
        SurveyCycle::create(['name' => 'SERUTI November 2026 (MAK)', 'period' => '2026-11', 'status' => 'persiapan',
            'field_start' => '2026-11-01', 'field_end' => '2026-11-30']);
        $res = $this->actingAs($this->u('admin'))->get('/monitoring')->assertOk();
        $res->assertSee('TW4 2026')->assertSee(route('periode.triwulan', [2026, 4]), false);
        $res->assertSee('Juga di lapangan')->assertSee('belum waktunya')->assertSee('jadwal selesai, belum ditutup')
            ->assertSee('Jadwal lapangan sudah mulai 1 November', false);
    }

    public function test_schedule_must_fall_in_its_period(): void
    {
        $this->assertNull(SurveyCycle::scheduleProblem('2026-03', '2026-03-01', '2026-04-15'));   // SERUTI Maret s.d. April: wajar
        $this->assertNotNull(SurveyCycle::scheduleProblem('2027-03', '2026-09-06', '2026-10-06'));
        $this->assertNotNull(SurveyCycle::scheduleProblem('2026-03', '2026-03-01', '2026-07-01'));
        $admin = $this->u('admin');
        $this->actingAs($admin)->post('/admin/jadwal', ['name' => 'SUSENAS Maret 2027 (KP)', 'period' => '2027-03',
            'status' => 'persiapan', 'field_start' => '2026-09-06', 'field_end' => '2026-10-06'])
            ->assertSessionHasErrors('field_start');
        $c = SurveyCycle::create(['name' => 'SERUTI Juni 2027 (INTI)', 'period' => '2027-06', 'status' => 'persiapan']);
        $this->actingAs($admin)->post("/admin/jadwal/{$c->id}", ['name' => $c->name, 'status' => 'persiapan',
            'field_start' => '2027-09-01', 'field_end' => '2027-09-30'])->assertSessionHasErrors('field_start');
        $prep = app(\App\Services\CyclePrep::class)->check($c->fresh());
        $item = collect($prep['items'])->firstWhere('key', 'triwulan_mulai');
        $this->assertFalse($item['ok']);
        $this->assertFalse($item['wajib']);
    }

    public function test_pages_reached_from_other_pages_have_a_back_button(): void
    {
        $kp = $this->kp();
        $qv = QuestionnaireVersion::where('cycle_id', $kp->id)->firstOrFail();
        $block = Block::where('qversion_id', $qv->id)->firstOrFail();
        $admin = $this->u('admin');
        foreach (['/periode/2026', '/periode/2026/tw/1', "/periode/siklus/{$kp->id}", "/admin/blok/{$block->id}",
            "/admin/impor?qv={$qv->id}", '/admin/jadwal', '/akun', '/notifikasi'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('back-link', false);
        }
    }

    public function test_metadata_shows_fixed_rows_of_list_blocks(): void
    {
        $qv = QuestionnaireVersion::where('cycle_id', $this->kp()->id)->firstOrFail();
        $block = Block::where('qversion_id', $qv->id)->where('repeat', 'list')->whereHas('rows')->firstOrFail();
        $first = $block->rows()->orderBy('row_index')->value('label');
        $this->actingAs($this->u('admin'))->get("/admin/metadata?qv={$qv->id}")->assertOk()
            ->assertSee('Baris / rincian')->assertSee('Kolom isian')->assertSee($first);
    }

    public function test_commodity_can_be_added_from_harga_tab(): void
    {
        $qv = QuestionnaireVersion::where('cycle_id', $this->kp()->id)->firstOrFail();
        $block = Block::where('qversion_id', $qv->id)->where('harga_kalori', true)->firstOrFail();
        $max = (int) $block->rows()->max('row_index');
        $admin = $this->u('admin');

        $this->actingAs($admin)->get("/admin/aturan?qv={$qv->id}&tab=harga")->assertOk()->assertSee('+ Tambah komoditas');
        $this->actingAs($admin)->post('/admin/harga/tambah', [
            'qv' => $qv->id, 'block_id' => $block->id, 'code' => 'X99', 'label' => 'Beras ketan (Kg)',
            'harga_min' => 15000, 'harga_max' => 22000, 'kalori' => 3600,
        ])->assertRedirect()->assertSessionHas('ok');
        $row = \App\Models\BlockRow::where('block_id', $block->id)->where('code', 'X99')->firstOrFail();
        $this->assertSame($max + 1, (int) $row->row_index);
        $this->assertSame(['harga_min' => '15000', 'harga_max' => '22000', 'kalori' => '3600'], (array) $row->ref);

        // kode ganda & harga terbalik ditolak
        $this->actingAs($admin)->post('/admin/harga/tambah', ['qv' => $qv->id, 'block_id' => $block->id, 'code' => 'X99', 'label' => 'x'])
            ->assertSessionHasErrors('code');
        $this->actingAs($admin)->post('/admin/harga/tambah', ['qv' => $qv->id, 'block_id' => $block->id, 'label' => 'y',
            'harga_min' => 5, 'harga_max' => 1])->assertSessionHasErrors('harga');
    }

    public function test_new_cycle_period_must_be_year_month(): void
    {
        $this->actingAs($this->u('admin'))->post('/admin/jadwal', [
            'name' => 'Uji', 'period' => 'Maret 2028', 'status' => 'persiapan',
        ])->assertSessionHasErrors('period');
    }
}

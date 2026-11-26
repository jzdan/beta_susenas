<?php

namespace Database\Seeders;

use App\Models\Answer;
use App\Models\AuditLog;
use App\Models\Block;
use App\Models\DerivedField;
use App\Models\Document;
use App\Models\Parameter;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\ResponseOption;
use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\User;
use App\Models\ValidationRule;
use App\Models\Wilayah;
use App\Services\RuleEngine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Data contoh. CATATAN: seluruh wilayah, sampel, garis kemiskinan, dan isian
 * di sini adalah DUMMY untuk demo — bukan alokasi sampel atau angka resmi.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = $this->users();
        $desa = $this->wilayah();
        [$cycle, $qv] = $this->questionnaire();
        $samples = $this->samples($cycle, $users, $desa);
        $this->documents($qv, $samples, $users);

        // Kuesioner KOR nyata (VSEN26.K) + aturan anomali Sidona + rumah tangga contoh nyata
        $this->korQuestionnaire($users);

        // Kuesioner KP nyata (VSEN26.KP) — Blok IV.1 lengkap 194 komoditas
        $this->kpFullQuestionnaire();

        // Modul SERUTI (VSERUTI26.INTI) — ditunda; jalankan manual bila perlu:
        //   php artisan db:seed --class=Database\\Seeders\\SerutiSeeder
        // $this->call(SerutiSeeder::class);

        // penimbang contoh (design weight dummy) — supaya agregasi tertimbang bisa diuji
        foreach (SampleHousehold::whereNull('weight')->get() as $s) {
            $s->update(['weight' => 120 + ($s->id * 37) % 260]);
        }

        // satu dokumen contoh berstatus "dikembalikan ke petugas"
        if ($d = Document::where('status', 'selesai')->orderByDesc('id')->skip(1)->first()) {
            $d->update([
                'status' => 'dikembalikan',
                'return_reason' => 'Blok IV.1: kuantitas beras 3x lipat rata-rata BS — mohon konfirmasi ke responden dan perbaiki.',
                'returned_by' => User::where('username', 'rahmawati')->value('id'),
                'returned_at' => now()->subDay(),
            ]);
        }

        // Inc 164: kerangka Periode Survei 2026 & 2027 (status mengikuti kalender) + sampel contoh.
        // TIDAK dimuat saat tes — dataset tes tetap 3 versi kuesioner (tes memanggil seedernya sendiri).
        if (! app()->runningUnitTests()) {
            // Inc 165: 18 kecamatan + 281 desa Bangkalan dan tim lapangan per kecamatan
            $this->call(WilayahBangkalanSeeder::class);
            $this->call(PetugasBangkalanSeeder::class);
            $this->call(PeriodeSurveiSeeder::class);
            // SEED_ATURAN=false di .env → aturan validasi dikosongkan (admin menyusun sendiri dari awal)
            if (! filter_var(env('SEED_ATURAN', true), FILTER_VALIDATE_BOOL)) {
                $this->call(KosongkanAturanSeeder::class);
            }
        }
    }

    /**
     * Increment 12 — VSEN26.KP dengan Blok IV.1 LENGKAP (194 komoditas nyata,
     * kalori & rentang harga dari sheet KALORI/RH tool WTF). Data komoditas
     * dimuat dari database/data/iv1_komoditas.csv (impor CSV, bukan kode).
     */
    private function kpFullQuestionnaire(): void
    {
        $csvPath = database_path('data/iv1_komoditas.csv');
        if (! is_file($csvPath)) {
            return;
        }

        $cycle = SurveyCycle::create([
            'name' => 'SUSENAS Maret 2026 (KP)', 'period' => '2026-03', 'status' => 'pengolahan',
            'field_start' => '2026-03-01', 'field_end' => '2026-03-31',
        ]);
        $qv = QuestionnaireVersion::create([
            'cycle_id' => $cycle->id, 'code' => 'VSEN26.KP', 'effective_date' => '2025-11-02',
            'is_active' => true,
        ]);

        // Blok IV.1 — konsumsi makanan seminggu (daftar komoditas)
        $b = Block::create(['qversion_id' => $qv->id, 'code' => 'IV.1',
            'title' => 'Konsumsi & Pengeluaran Makanan Seminggu Terakhir', 'order' => 1, 'repeat' => 'list',
            'harga_kalori' => true]);

        $fh = fopen($csvPath, 'r');
        $header = array_map('trim', fgetcsv($fh));
        $n = 0;
        $groups = [];
        while (($cells = fgetcsv($fh)) !== false) {
            $row = array_combine($header, array_pad($cells, count($header), ''));
            if (($row['row_index'] ?? '') === '') {
                continue;
            }
            $ref = [];
            foreach (['group', 'kalori', 'harga_min', 'harga_max', 'max_kons'] as $k) {
                if (($row[$k] ?? '') !== '') {
                    $ref[$k] = $row[$k];
                }
            }
            if (($ref['group'] ?? '') !== '' && ! in_array($ref['group'], $groups, true)) {
                $groups[] = $ref['group'];
            }
            $n++;
            \App\Models\BlockRow::create([
                'block_id' => $b->id, 'row_index' => (int) $row['row_index'],
                'code' => $row['code'] ?? '', 'label' => $row['label'], 'order' => $n,
                'ref' => $ref ?: null,
            ]);
        }
        fclose($fh);

        // rentang harga wajar disetel lebih ketat untuk kondisi Kab. Bangkalan
        foreach ([
            ['002', 11000, 15000],   // beras
            ['075', 1800, 2600],     // telur ayam ras
            ['156', 15000, 22000],   // minyak goreng
            ['160', 1300, 2000],     // gula pasir
        ] as [$code, $min, $max]) {
            $row = \App\Models\BlockRow::where('block_id', $b->id)->where('code', $code)->first();
            if ($row) {
                $row->update(['ref' => array_merge((array) $row->ref, ['harga_min' => $min, 'harga_max' => $max])]);
            }
        }

        // Kolom sesuai VSEN26.KP / WTF: (5)(6) dari pembelian, (7)(8) dari produksi
        // sendiri/pemberian, (9)=(5)+(7) & (10)=(6)+(8) jumlah konsumsi.
        Question::create(['block_id' => $b->id, 'code_rincian' => 'K5', 'var_name' => 'iv1_kuantitas_beli',
            'label' => 'Banyaknya dari pembelian — kolom (5) (satuan komoditas, seminggu)', 'qtype' => 'decimal', 'unit' => 'satuan', 'order' => 1]);
        Question::create(['block_id' => $b->id, 'code_rincian' => 'K6', 'var_name' => 'iv1_nilai_beli',
            'label' => 'Nilai dari pembelian — kolom (6) (Rp, seminggu)', 'qtype' => 'decimal', 'unit' => 'Rp', 'order' => 2]);
        Question::create(['block_id' => $b->id, 'code_rincian' => 'K7', 'var_name' => 'iv1_kuantitas_lain',
            'label' => 'Banyaknya dari produksi sendiri / pemberian — kolom (7) (satuan komoditas, seminggu)', 'qtype' => 'decimal', 'unit' => 'satuan', 'order' => 3]);
        Question::create(['block_id' => $b->id, 'code_rincian' => 'K8', 'var_name' => 'iv1_nilai_lain',
            'label' => 'Nilai dari produksi sendiri / pemberian — kolom (8) (Rp, seminggu)', 'qtype' => 'decimal', 'unit' => 'Rp', 'order' => 4]);
        $qa = Question::create(['block_id' => $b->id, 'code_rincian' => 'K8a', 'var_name' => 'iv1_asal_lain',
            'label' => 'Asal konsumsi selain pembelian — kolom (8a)', 'qtype' => 'code', 'order' => 5]);
        foreach ([['1', 'Produksi sendiri'], ['2', 'Pemberian rumah tangga lain'], ['3', 'Pemberian lembaga/pemerintah']] as $j => [$c, $l]) {
            ResponseOption::create(['question_id' => $qa->id, 'code' => $c, 'label' => $l, 'order' => $j + 1]);
        }
        Question::create(['block_id' => $b->id, 'code_rincian' => 'K9', 'var_name' => 'iv1_kuantitas',
            'label' => 'Jumlah konsumsi — kolom (9) = (5)+(7)', 'qtype' => 'decimal', 'unit' => 'satuan', 'order' => 6]);
        Question::create(['block_id' => $b->id, 'code_rincian' => 'K10', 'var_name' => 'iv1_nilai',
            'label' => 'Nilai jumlah konsumsi — kolom (10) = (6)+(8)', 'qtype' => 'decimal', 'unit' => 'Rp', 'order' => 7]);

        // Blok IV.1M — Makanan & minuman jadi + rokok (R.188–225) dirinci PER ART.
        // Daftar lengkap sesuai VSEN26.KP; kalori diambil dari daftar komoditas IV.1.
        $bm = Block::create(['qversion_id' => $qv->id, 'code' => 'IV.1M',
            'title' => 'Konsumsi Makanan & Minuman Jadi + Rokok per Anggota Rumah Tangga', 'order' => 2, 'repeat' => 'list_art']);
        $kalByCode = \App\Models\BlockRow::where('block_id', $b->id)->get()
            ->mapWithKeys(fn ($r) => [(int) $r->code => $r->ref['kalori'] ?? null]);
        $mjRows = [
            [188, 'Roti tawar', 'Potong', 'MAKANAN & MINUMAN JADI'],
            [189, 'Roti manis / roti lainnya', 'Potong', 'MAKANAN & MINUMAN JADI'],
            [190, 'Kue kering / biskuit / semprong', 'Ons', 'MAKANAN & MINUMAN JADI'],
            [191, 'Kue basah (kue lapis, bika ambon, lemper, dll.)', 'Buah', 'MAKANAN & MINUMAN JADI'],
            [192, 'Makanan gorengan (tahu, tempe, bakwan, pisang)', 'Potong', 'MAKANAN & MINUMAN JADI'],
            [193, 'Makanan gorengan lainnya', 'Potong', 'MAKANAN & MINUMAN JADI'],
            [194, 'Bubur kacang hijau', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [195, 'Gado-gado / ketoprak / pecel', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [196, 'Nasi campur / rames', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [197, 'Nasi goreng', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [198, 'Nasi putih', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [199, 'Lontong / ketupat sayur', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [200, 'Soto / gule / sop / rawon / cincang', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [201, 'Sayur matang (ditumis, disantan, dll.)', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [202, 'Sate / tongseng', 'Porsi / 5 tusuk', 'MAKANAN & MINUMAN JADI'],
            [203, 'Mie bakso / mie rebus / mie goreng', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [204, 'Mie instan (matang)', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [205, 'Makanan ringan anak-anak, kerupuk / keripik', 'Ons', 'MAKANAN & MINUMAN JADI'],
            [206, 'Ikan matang', 'Potong', 'MAKANAN & MINUMAN JADI'],
            [207, 'Ayam / bebek / daging matang (ayam goreng, rendang, dll.)', 'Potong', 'MAKANAN & MINUMAN JADI'],
            [208, 'Daging olahan (sosis, nugget, daging asap, dll.) matang', 'Potong', 'MAKANAN & MINUMAN JADI'],
            [209, 'Bubur ayam', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [210, 'Siomay / batagor', 'Porsi / 5 potong', 'MAKANAN & MINUMAN JADI'],
            [211, 'Makanan jadi lainnya', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [212, 'Air kemasan', 'Liter', 'MAKANAN & MINUMAN JADI'],
            [213, 'Air kemasan galon', 'Galon', 'MAKANAN & MINUMAN JADI'],
            [214, 'Air teh kemasan, minuman bersoda / mengandung CO2', '≥ 250 ml', 'MAKANAN & MINUMAN JADI'],
            [215, 'Sari buah kemasan, minuman kesehatan, minuman berenergi', '≥ 200 ml', 'MAKANAN & MINUMAN JADI'],
            [216, 'Minuman jadi (kopi, kopi susu, teh, susu cokelat, dll.)', 'Gelas', 'MAKANAN & MINUMAN JADI'],
            [217, 'Es krim', 'Mangkok kecil', 'MAKANAN & MINUMAN JADI'],
            [218, 'Es lainnya', 'Porsi', 'MAKANAN & MINUMAN JADI'],
            [219, 'Minuman keras / mengandung alkohol', 'Liter', 'MAKANAN & MINUMAN JADI'],
            [221, 'Rokok kretek filter', 'Batang', 'ROKOK & TEMBAKAU'],
            [222, 'Rokok kretek tanpa filter', 'Batang', 'ROKOK & TEMBAKAU'],
            [223, 'Rokok putih', 'Batang', 'ROKOK & TEMBAKAU'],
            [224, 'Tembakau', 'Ons', 'ROKOK & TEMBAKAU'],
            [225, 'Rokok lainnya (cerutu, kelembak, dll.)', 'Batang', 'ROKOK & TEMBAKAU'],
        ];
        foreach ($mjRows as $j => [$idx, $lbl, $sat, $grp]) {
            $ref = ['satuan' => $sat, 'group' => $grp];
            if (($kalByCode[$idx] ?? null) !== null && $kalByCode[$idx] !== '') {
                $ref['kalori'] = $kalByCode[$idx];
            }
            \App\Models\BlockRow::create(['block_id' => $bm->id, 'row_index' => $idx,
                'code' => str_pad((string) $idx, 3, '0', STR_PAD_LEFT), 'label' => $lbl,
                'order' => $j + 1, 'ref' => $ref]);
        }
        Question::create(['block_id' => $bm->id, 'code_rincian' => 'MJ1', 'var_name' => 'mj_kuantitas',
            'label' => 'Jumlah konsumsi (satuan komoditas, seminggu)', 'qtype' => 'decimal', 'unit' => 'satuan', 'order' => 1]);
        Question::create(['block_id' => $bm->id, 'code_rincian' => 'MJ2', 'var_name' => 'mj_nilai',
            'label' => 'Nilai konsumsi (Rp, seminggu)', 'qtype' => 'decimal', 'unit' => 'Rp', 'order' => 2]);
        $qs = Question::create(['block_id' => $bm->id, 'code_rincian' => 'MJ3', 'var_name' => 'mj_sumber',
            'label' => 'Sumber utama', 'qtype' => 'code', 'order' => 3]);
        foreach ([['1', 'Pembelian'], ['2', 'Produksi sendiri'], ['3', 'Pemberian rumah tangga lain'],
            ['4', 'Program pemerintah / MBG']] as $j => [$c, $l]) {
            ResponseOption::create(['question_id' => $qs->id, 'code' => $c, 'label' => $l, 'order' => $j + 1]);
        }
        foreach ([
            ['IV1M-NEGATIF', 'IV.1M', 'mj_kuantitas',
                'filled(mj_kuantitas) and n(mj_kuantitas) < 0', 'error',
                'Jumlah konsumsi makanan jadi bernilai negatif.'],
            ['IV1M-NILAI-KOSONG', 'IV.1M', 'mj_nilai',
                'filled(mj_kuantitas) and n(mj_kuantitas) > 0 and n(mj_sumber) == 1 and (blank(mj_nilai) or n(mj_nilai) == 0)', 'warning',
                'Sumber = pembelian tetapi nilai konsumsi kosong.'],
            ['IV1M-MBG-KUANTITAS', 'IV.1M', 'mj_kuantitas',
                'n(mj_sumber) == 4 and (blank(mj_kuantitas) or n(mj_kuantitas) == 0)', 'warning',
                'Sumber = program pemerintah/MBG tetapi jumlah konsumsi belum diisi.'],
        ] as [$code, $rowBlock, $target, $expr, $sev, $msg]) {
            ValidationRule::create(['qversion_id' => $qv->id, 'code' => $code, 'scope' => 'baris',
                'row_block' => $rowBlock, 'target_var' => $target, 'expr' => $expr,
                'severity' => $sev, 'message' => $msg, 'active' => true]);
        }

        // Blok I — Keterangan Tempat. Rincian & penomoran disamakan ke PDF ASLI
        // `2. 2025.11.02 VSEN26.KP Maret 2026.pdf` (R.101-111) — versi sebelumnya
        // (Inc <130) salah nomor & kehilangan beberapa rincian (SLS/Sub SLS,
        // nomor bangunan, alamat), lihat CATATAN Inc 130.
        // R.101-104 (provinsi/kab-kota/kecamatan/desa) digabung jadi satu kode 10
        // digit — sistem ini 1 kabupaten saja & sudah punya master wilayah kode
        // gabungan, jadi tidak dipisah 4 kotak seperti kertas aslinya.
        $bI = Block::create(['qversion_id' => $qv->id, 'code' => 'I',
            'title' => 'Keterangan Tempat', 'order' => -3, 'repeat' => 'ruta']);
        $this->addQuestions($bI, [
            ['R101-104', 'b1_kode_wilayah', 'Kode wilayah (Provinsi+Kab/Kota+Kecamatan+Desa/Kel, 10 digit)', 'text', '', []],
            ['R105', 'b1_klasifikasi', 'Klasifikasi Desa/Kelurahan', 'code', '', [['1', 'Perkotaan'], ['2', 'Perdesaan']]],
            ['R106', 'b1_kode_sls', 'Nomor Kode Sampel SLS', 'text', '', []],
            ['R107', 'b1_sls', 'Satuan Lingkungan Setempat (SLS) / Sub SLS', 'text', '', []],
            ['R108', 'b1_no_bangunan', 'Nomor Urut Bangunan Tempat Tinggal', 'text', '', []],
            ['R109', 'b1_no_ruta', 'Nomor Urut Sampel Rumah Tangga', 'text', '', []],
            ['R110', 'b1_nama_krt', 'Nama Kepala Rumah Tangga', 'text', '', []],
            ['R111', 'b1_alamat', 'Alamat (Nama Jalan/Gang, RT/RW/Dusun)', 'text', '', []],
        ]);

        // Blok II — Keterangan Pendataan. PDF asli cuma 3 rincian (201 Pendata,
        // 202 Pemeriksa, 203 Hasil) — "jumlah kunjungan" & "moda pencacahan" yang
        // dulu ada di sini (R207/R209) TIDAK ADA di kuesioner asli, dihapus.
        $bII = Block::create(['qversion_id' => $qv->id, 'code' => 'II',
            'title' => 'Keterangan Pendataan', 'order' => -2, 'repeat' => 'ruta']);
        $jabatan = [['1', 'Staf BPS Provinsi'], ['2', 'Staf BPS Kab/Kota'], ['3', 'Mitra']];
        $this->addQuestions($bII, [
            ['R201', 'b2_pendata', 'Nama & Kode/NIP Pendata', 'text', '', []],
            ['R201', 'b2_pendata_jabatan', 'Jabatan Pendata', 'code', '', $jabatan],
            ['R201', 'b2_tgl_cacah', 'Tanggal Pendataan', 'text', 'YYYY-MM-DD', []],
            ['R202', 'b2_pemeriksa', 'Nama & Kode/NIP Pemeriksa', 'text', '', []],
            ['R202', 'b2_pemeriksa_jabatan', 'Jabatan Pemeriksa', 'code', '', $jabatan],
            ['R202', 'b2_tgl_periksa', 'Tanggal Pemeriksaan', 'text', 'YYYY-MM-DD', []],
            ['R203', 'b2_hasil', 'Hasil Pendataan Rumah Tangga', 'code', '', [
                ['1', 'Terisi lengkap'],
                ['2', 'Terisi tidak lengkap'],
                ['3', 'Tidak ada ART/responden yang dapat memberi jawaban sampai akhir masa pendataan'],
                ['4', 'Responden menolak'],
                ['5', 'Rumah tangga pindah/bangunan sensus sudah tidak ada'],
            ]],
        ]);

        // Blok III — "Banyaknya ART, Pemberi Informasi, dan Jumlah Komoditas yang Terisi"
        // (rincian 301–305 asli VSEN26.KP — ringkasan, BUKAN roster demografi; itu urusan
        // VSEN26.K/KOR, lewat blok terpisah kode 'ART'. Inc 132 — Blok III sebelumnya salah
        // dibangun meniru roster K, padahal field itu tidak ada di form KP sama sekali.)
        $bIII = Block::create(['qversion_id' => $qv->id, 'code' => 'III',
            'title' => 'Banyaknya ART, Pemberi Informasi, dan Jumlah Komoditas yang Terisi',
            'order' => -1, 'repeat' => 'ruta']);
        $this->addQuestions($bIII, [
            ['301', 'banyak_art', 'Banyaknya anggota rumah tangga', 'int', 'orang', []],
            ['302', 'b3_no_urut_informasi', 'Nomor urut pemberi informasi', 'int', '', []],
            ['303', 'b3_nama_informasi', 'Nama pemberi informasi', 'text', '', []],
            ['304', 'b3_jml_komoditas_makanan', 'Jumlah komoditas bahan makanan, bahan minuman, dan rokok yang terisi', 'int', '', []],
            ['305', 'b3_jml_komoditas_nonmakanan', 'Jumlah komoditas barang bukan makanan yang terisi', 'int', '', []],
        ]);

        foreach ([
            ['IDENT-WILAYAH', 'dokumen', 'error',
                'filled(b1_kode_wilayah) and b1_kode_wilayah != sampel_wilayah',
                'Kode wilayah Blok I tidak sama dengan wilayah sampel yang ditugaskan.'],
            ['IDENT-BS', 'dokumen', 'warning',
                'filled(b1_kode_sls) and b1_kode_sls != sampel_bs',
                'Nomor kode sampel SLS Blok I berbeda dengan sampel.'],
            ['IDENT-RUTA', 'dokumen', 'warning',
                'filled(b1_no_ruta) and b1_no_ruta != sampel_ruta',
                'Nomor urut rumah tangga Blok I berbeda dengan sampel.'],
            ['II-TANGGAL', 'dokumen', 'error',
                'filled(b2_tgl_cacah) and filled(b2_tgl_periksa) and ymd(b2_tgl_periksa) < ymd(b2_tgl_cacah)',
                'Tanggal pemeriksaan lebih awal daripada tanggal pendataan.'],
            ['II-HASIL-ART', 'dokumen', 'warning',
                'n(b2_hasil) == 1 and blank(banyak_art)',
                'Hasil "terisi lengkap" tetapi banyaknya ART belum diisi.'],
            ['II-PCL', 'dokumen', 'warning',
                'n(b2_hasil) == 1 and blank(b2_pendata)',
                'Hasil "terisi lengkap" tetapi nama/kode pendata kosong.'],
            ['II-NONRESPON', 'dokumen', 'warning',
                'filled(b2_hasil) and n(b2_hasil) != 1 and n(kalori_total) > 0',
                'Hasil pencacahan bukan "terisi lengkap" tetapi Blok IV sudah terisi.'],
        ] as [$code, $scope, $sev, $expr, $msg]) {
            ValidationRule::create(['qversion_id' => $qv->id, 'code' => $code, 'scope' => $scope,
                'row_block' => '', 'target_var' => '', 'expr' => $expr,
                'severity' => $sev, 'message' => $msg, 'active' => true]);
        }

        foreach ([
            ['garis_kemiskinan_kab', '550000', 'Rp/kapita/bulan (contoh)'],
            ['kalori_min', '1700', 'kkal/kapita/hari'],
            ['kalori_max', '4500', 'kkal/kapita/hari'],
        ] as [$name, $value, $note]) {
            Parameter::create(['qversion_id' => $qv->id, 'name' => $name, 'value' => $value, 'note' => $note]);
        }

        \App\Models\Aggregate::create([
            'qversion_id' => $qv->id, 'name' => 'kalori_total', 'label' => 'Total kalori konsumsi (kkal/minggu)',
            'block_code' => 'IV.1', 'value_var' => 'iv1_kuantitas', 'op' => 'weighted_sum',
            'weight_ref' => 'kalori', 'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '', 'order' => 1,
        ]);
        \App\Models\Aggregate::create([
            'qversion_id' => $qv->id, 'name' => 'iv1_belanja_minggu', 'label' => 'Total belanja makanan (Rp/minggu)',
            'block_code' => 'IV.1', 'value_var' => 'iv1_nilai_beli', 'op' => 'sum',
            'weight_ref' => '', 'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '', 'order' => 2,
        ]);
        // Blok IV.1M (makanan/minuman jadi + rokok per ART) — sebelumnya divalidasi per rincian
        // tapi TIDAK ikut ke total kalori/belanja mingguan. Rokok kalorinya 0 (tak ada ref.kalori),
        // tapi nilainya tetap masuk hitungan belanja.
        \App\Models\Aggregate::create([
            'qversion_id' => $qv->id, 'name' => 'iv1m_kalori_minggu', 'label' => 'IV.1M · kalori makanan/minuman jadi (kkal/minggu)',
            'block_code' => 'IV.1M', 'value_var' => 'mj_kuantitas', 'op' => 'weighted_sum',
            'weight_ref' => 'kalori', 'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '', 'order' => 3,
        ]);
        \App\Models\Aggregate::create([
            'qversion_id' => $qv->id, 'name' => 'iv1m_belanja_minggu', 'label' => 'IV.1M · belanja makanan jadi & rokok (Rp/minggu)',
            'block_code' => 'IV.1M', 'value_var' => 'mj_nilai', 'op' => 'sum',
            'weight_ref' => '', 'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '', 'order' => 4,
        ]);

        // Rekap Blok IV.1 per kelompok komoditas — subtotal nilai & kalori (disaring nilai ref baris "group")
        $slugs = [];
        $order = 10;
        foreach ($groups as $g) {
            $slug = \Illuminate\Support\Str::of($g)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
            $slugs[] = $slug;
            \App\Models\Aggregate::create([
                'qversion_id' => $qv->id, 'name' => "iv1_nilai_{$slug}", 'label' => "Rekap IV.1 · nilai {$g} (Rp/minggu)",
                'block_code' => 'IV.1', 'value_var' => 'iv1_nilai_beli', 'op' => 'sum',
                'weight_ref' => '', 'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '',
                'filter_ref' => 'group', 'filter_ref_val' => $g, 'order' => $order++,
            ]);
            \App\Models\Aggregate::create([
                'qversion_id' => $qv->id, 'name' => "iv1_kalori_{$slug}", 'label' => "Rekap IV.1 · kalori {$g} (kkal/minggu)",
                'block_code' => 'IV.1', 'value_var' => 'iv1_kuantitas', 'op' => 'weighted_sum',
                'weight_ref' => 'kalori', 'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '',
                'filter_ref' => 'group', 'filter_ref_val' => $g, 'order' => $order++,
            ]);
        }

        // konsistensi rekap: jumlah subtotal kelompok harus sama dengan total keseluruhan
        $sumExpr = implode(' + ', array_map(fn ($s) => "n(iv1_nilai_{$s})", $slugs));
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'iv1_nilai_rekap_kelompok',
            'label' => 'Jumlah subtotal nilai IV.1 semua kelompok (Rp)',
            'expr' => "round({$sumExpr}, 0)", 'order' => 3]);
        ValidationRule::create(['qversion_id' => $qv->id, 'code' => 'REKAP-IV1-NILAI', 'scope' => 'neraca',
            'row_block' => '', 'target_var' => '',
            'expr' => 'abs(n(iv1_nilai_rekap_kelompok) - n(iv1_belanja_minggu)) > 1',
            'severity' => 'error', 'message' => 'Rekap IV.1 per kelompok tidak sama dengan total belanja makanan.', 'active' => true]);
        // porsi rokok/tembakau yang tinggi perlu dicek (indikator kesejahteraan)
        $rokokSlug = \Illuminate\Support\Str::of('ROKOK DAN TEMBAKAU')->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
        if (in_array($rokokSlug, $slugs, true)) {
            ValidationRule::create(['qversion_id' => $qv->id, 'code' => 'REKAP-IV1-ROKOK', 'scope' => 'kalori',
                'row_block' => '', 'target_var' => '',
                'expr' => "n(iv1_belanja_minggu) > 0 and n(iv1_nilai_{$rokokSlug}) / n(iv1_belanja_minggu) > 0.25",
                'severity' => 'warning', 'message' => 'Belanja rokok/tembakau melebihi 25% belanja makanan — konfirmasi ke lapangan.', 'active' => true]);
        }

        // --- Blok IV.3.2 — Rekapitulasi Pengeluaran Makanan, Minuman, dan Rokok ---
        // Kolom (3) = pembelian (Blok IV.1 kolom nilai_beli); Kolom (4) = produksi sendiri/
        // pemberian (Blok IV.1 kolom nilai_lain); Kolom (5) = (3)+(4); R.16 = R.15 x 30/7.
        \App\Models\Aggregate::create([
            'qversion_id' => $qv->id, 'name' => 'iv1_lain_minggu',
            'label' => 'Konsumsi makanan dari produksi sendiri/pemberian (Rp/minggu)',
            'block_code' => 'IV.1', 'value_var' => 'iv1_nilai_lain', 'op' => 'sum',
            'weight_ref' => '', 'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '', 'order' => 3,
        ]);
        foreach ($slugs as $i => $s) {
            \App\Models\Aggregate::create([
                'qversion_id' => $qv->id, 'name' => "iv1_lain_{$s}", 'label' => "Rekap IV.3.2 · produksi sendiri/pemberian ({$groups[$i]})",
                'block_code' => 'IV.1', 'value_var' => 'iv1_nilai_lain', 'op' => 'sum',
                'weight_ref' => '', 'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '',
                'filter_ref' => 'group', 'filter_ref_val' => $groups[$i], 'order' => $order++,
            ]);
        }
        $lainSum = implode(' + ', array_map(fn ($s) => "n(iv1_lain_{$s})", $slugs));
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'iv1_lain_rekap_kelompok',
            'label' => 'Jumlah subtotal produksi sendiri/pemberian semua kelompok (Rp)',
            'expr' => "round({$lainSum}, 0)", 'order' => 4]);
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'rekap_iv32_subjumlah',
            'label' => 'Blok IV.3.2 R.15 · SUB JUMLAH makanan seminggu (Rp)',
            'expr' => 'round(n(iv1_belanja_minggu) + n(iv1_lain_minggu), 0)', 'order' => 5]);
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'rekap_makanan_sebulan',
            'label' => 'Blok IV.3.2 R.16 · rata-rata pengeluaran makanan sebulan (Rp)',
            'expr' => 'round(n(rekap_iv32_subjumlah) * 30 / 7, 0)', 'order' => 6]);
        ValidationRule::create(['qversion_id' => $qv->id, 'code' => 'REKAP-IV1-LAIN', 'scope' => 'neraca',
            'row_block' => '', 'target_var' => '',
            'expr' => 'abs(n(iv1_lain_rekap_kelompok) - n(iv1_lain_minggu)) > 1',
            'severity' => 'error', 'message' => 'Rekap IV.3.2 kolom (4) per kelompok tidak sama dengan total produksi sendiri/pemberian.', 'active' => true]);

        // "kalori_total" & "iv1_belanja_minggu" sengaja dibiarkan IV.1-saja (dipakai rekap
        // IV.3.2 & konsistensi per-kelompok yang memang hanya soal Blok IV.1) — total kalori/
        // belanja yang BENAR (termasuk makanan jadi & rokok Blok IV.1M) dihitung di sini.
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'kalori_total_gabungan',
            'label' => 'Total kalori konsumsi, termasuk makanan jadi (kkal/minggu)',
            'expr' => 'round(n(kalori_total) + n(iv1m_kalori_minggu), 0)', 'order' => 0]);
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'kalori_per_kapita_hari',
            'label' => 'Kalori per kapita per hari (kkal)',
            'expr' => 'round(kalori_total_gabungan / max(n(banyak_art), 1) / 7, 0)', 'order' => 1]);
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'belanja_makanan_kapita_bulan',
            'label' => 'Belanja makanan per kapita per bulan (Rp)',
            'expr' => 'round((n(iv1_belanja_minggu) + n(iv1m_belanja_minggu)) * 30 / 7 / max(n(banyak_art), 1), 0)', 'order' => 2]);

        foreach ([
            ['KALORI-UNDER', 'kalori', '', '',
                'kalori_per_kapita_hari > 0 and kalori_per_kapita_hari < n(kalori_min)',
                'Konsumsi kalori per kapita per hari di bawah ambang — periksa kelengkapan Blok IV.1.'],
            ['KALORI-OVER', 'kalori', '', '',
                'kalori_per_kapita_hari > n(kalori_max)',
                'Konsumsi kalori per kapita per hari di atas ambang wajar — periksa kuantitas Blok IV.1.'],
            ['IV1-KUANTITAS', 'baris', 'IV.1', 'iv1_kuantitas',
                'filled(iv1_nilai_beli) and n(iv1_nilai_beli) > 0 and (blank(iv1_kuantitas) or n(iv1_kuantitas) == 0)',
                'Nilai pembelian terisi tetapi kuantitas kosong.'],
            ['IV1-HARGA-MAHAL', 'baris', 'IV.1', 'iv1_nilai_beli',
                'filled(iv1_nilai_beli) and n(iv1_kuantitas) > 0 and filled(ref_harga_max) '
                . 'and n(iv1_nilai_beli) / n(iv1_kuantitas) > n(ref_harga_max)',
                'Harga satuan Rp{=n(iv1_nilai_beli)/n(iv1_kuantitas)} kemahalan — batas wajar Rp{ref_harga_min}–Rp{ref_harga_max} per satuan komoditas.'],
            ['IV1-HARGA-MURAH', 'baris', 'IV.1', 'iv1_nilai_beli',
                'filled(iv1_nilai_beli) and n(iv1_kuantitas) > 0 and filled(ref_harga_min) '
                . 'and n(iv1_nilai_beli) / n(iv1_kuantitas) < n(ref_harga_min)',
                'Harga satuan Rp{=n(iv1_nilai_beli)/n(iv1_kuantitas)} kemurahan — batas wajar Rp{ref_harga_min}–Rp{ref_harga_max} per satuan komoditas.'],
            ['IV1-KUANTITAS-MAX', 'baris', 'IV.1', 'iv1_kuantitas',
                'filled(iv1_kuantitas) and n(iv1_kuantitas) > 0 and filled(ref_max_kons) and n(iv1_kuantitas) > n(ref_max_kons)',
                'Kuantitas {=n(iv1_kuantitas)} melebihi batas maksimum wajar ({ref_max_kons} per rumah tangga per minggu).'],
            ['IV1-JUMLAH-BANYAK', 'baris', 'IV.1', 'iv1_kuantitas',
                '(filled(iv1_kuantitas_beli) or filled(iv1_kuantitas_lain)) and filled(iv1_kuantitas) '
                . 'and abs(n(iv1_kuantitas) - (n(iv1_kuantitas_beli) + n(iv1_kuantitas_lain))) > 0.01',
                'Jumlah konsumsi kolom (9) tidak sama dengan pembelian (5) + produksi sendiri (7).'],
            ['IV1-JUMLAH-NILAI', 'baris', 'IV.1', 'iv1_nilai',
                '(filled(iv1_nilai_beli) or filled(iv1_nilai_lain)) and filled(iv1_nilai) '
                . 'and abs(n(iv1_nilai) - (n(iv1_nilai_beli) + n(iv1_nilai_lain))) > 1',
                'Nilai jumlah konsumsi kolom (10) tidak sama dengan pembelian (6) + produksi sendiri (8).'],
        ] as [$code, $scope, $rowBlock, $target, $expr, $msg]) {
            ValidationRule::create(['qversion_id' => $qv->id, 'code' => $code, 'scope' => $scope,
                'row_block' => $rowBlock, 'target_var' => $target, 'expr' => $expr,
                'severity' => 'warning', 'message' => $msg, 'active' => true]);
        }
        $this->ruleNotes($qv->id);

        // ============ Blok IV.2 — Pengeluaran untuk Barang Bukan Makanan + rekap IV.3.3 ============
        $iv2Path = database_path('data/iv2_komoditas.csv');
        if (is_file($iv2Path)) {
            $b2 = Block::create(['qversion_id' => $qv->id, 'code' => 'IV.2',
                'title' => 'Pengeluaran untuk Barang Bukan Makanan (Sebulan & Setahun Terakhir)',
                'order' => 3, 'repeat' => 'list']);
            $fh2 = fopen($iv2Path, 'r');
            $h2 = array_map('trim', fgetcsv($fh2));
            $n2 = 0;
            $sections = [];
            while (($c2 = fgetcsv($fh2)) !== false) {
                $r2 = array_combine($h2, array_pad($c2, count($h2), ''));
                if (($r2['row_index'] ?? '') === '') {
                    continue;
                }
                $ref2 = ['group' => $r2['section'], 'period' => $r2['period']];
                if (($r2['coicop'] ?? '') !== '') {
                    $ref2['coicop'] = $r2['coicop'];
                }
                if (! in_array($r2['section'], $sections, true)) {
                    $sections[] = $r2['section'];
                }
                $n2++;
                \App\Models\BlockRow::create(['block_id' => $b2->id, 'row_index' => (int) $r2['row_index'],
                    'code' => $r2['code'], 'label' => $r2['label'], 'order' => $n2, 'ref' => $ref2]);
            }
            fclose($fh2);

            Question::create(['block_id' => $b2->id, 'code_rincian' => 'K4', 'var_name' => 'iv2_rupiah_bulan',
                'label' => 'Nilai pengeluaran sebulan terakhir (Rp)', 'qtype' => 'decimal', 'unit' => 'Rp', 'order' => 1]);
            Question::create(['block_id' => $b2->id, 'code_rincian' => 'K5', 'var_name' => 'iv2_rupiah_tahun',
                'label' => 'Nilai pengeluaran setahun terakhir (Rp)', 'qtype' => 'decimal', 'unit' => 'Rp', 'order' => 2]);

            // agregat subtotal per seksi (disaring nilai ref baris "group")
            $secSlug = [];
            foreach ($sections as $s) {
                $sl = \Illuminate\Support\Str::of($s)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
                $secSlug[$s] = $sl;
                foreach (['bulan' => 'iv2_rupiah_bulan', 'tahun' => 'iv2_rupiah_tahun'] as $per => $vv) {
                    \App\Models\Aggregate::create(['qversion_id' => $qv->id, 'name' => "iv2_{$per}_{$sl}",
                        'label' => "Rekap IV.3.3 · {$s} (Rp/{$per})", 'block_code' => 'IV.2', 'value_var' => $vv,
                        'op' => 'sum', 'weight_ref' => '', 'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '',
                        'filter_ref' => 'group', 'filter_ref_val' => $s, 'order' => $order++]);
                }
            }
            foreach (['bulan' => 'iv2_rupiah_bulan', 'tahun' => 'iv2_rupiah_tahun'] as $per => $vv) {
                \App\Models\Aggregate::create(['qversion_id' => $qv->id, 'name' => "iv2_{$per}_total",
                    'label' => "IV.2 total nilai se{$per} (Rp)", 'block_code' => 'IV.2', 'value_var' => $vv,
                    'op' => 'sum', 'weight_ref' => '', 'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '',
                    'order' => $order++]);
            }

            // Blok IV.3.3 — nilai bulanan ekuivalen per seksi = (sebulan) + (setahun / 12)
            $do = 10;
            $iv33Parts = [];
            foreach ($sections as $s) {
                $sl = $secSlug[$s];
                $iv33Parts[] = "n(iv33_{$sl})";
                DerivedField::create(['qversion_id' => $qv->id, 'var_name' => "iv33_{$sl}",
                    'label' => "Blok IV.3.3 · {$s} (Rp/bulan ekuivalen)",
                    'expr' => "round(n(iv2_bulan_{$sl}) + n(iv2_tahun_{$sl}) / 12, 0)", 'order' => $do++]);
            }
            DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'rekap_bukan_makanan_sebulan',
                'label' => 'Blok IV.3.3 R.8 · rata-rata pengeluaran bukan makanan sebulan (Rp)',
                'expr' => 'round(' . implode(' + ', $iv33Parts) . ', 0)', 'order' => 30]);
            DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'rekap_ruta_sebulan',
                'label' => 'Blok IV.3.3 R.9 · rata-rata pengeluaran rumah tangga sebulan (Rp)',
                'expr' => 'round(n(rekap_makanan_sebulan) + n(rekap_bukan_makanan_sebulan), 0)', 'order' => 31]);
            DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'pengeluaran_per_kapita_bulan',
                'label' => 'Pengeluaran per kapita per bulan (Rp)',
                'expr' => 'round(n(rekap_ruta_sebulan) / max(n(banyak_art), 1), 0)', 'order' => 32]);

            ValidationRule::create(['qversion_id' => $qv->id, 'code' => 'IV2-PERIODE', 'scope' => 'baris',
                'row_block' => 'IV.2', 'target_var' => 'iv2_rupiah_bulan',
                'expr' => "(ref_period == 'bulan' and blank(iv2_rupiah_bulan) and filled(iv2_rupiah_tahun)) or "
                    . "(ref_period == 'tahun' and blank(iv2_rupiah_tahun) and filled(iv2_rupiah_bulan))",
                'severity' => 'warning', 'message' => 'Nilai terisi pada kolom periode yang tidak sesuai rincian ini.', 'active' => true]);
            ValidationRule::create(['qversion_id' => $qv->id, 'code' => 'QC-MISKIN-KP', 'scope' => 'neraca',
                'row_block' => '', 'target_var' => '',
                'expr' => 'n(pengeluaran_per_kapita_bulan) > 0 and n(pengeluaran_per_kapita_bulan) < n(garis_kemiskinan_kab)',
                'severity' => 'warning', 'message' => 'Pengeluaran per kapita di bawah garis kemiskinan kabupaten — periksa kelengkapan Blok IV.', 'active' => true]);
        }

        // ============ Blok V (baris jumlah) + Blok VI Rekapitulasi Penerimaan-Pengeluaran ============
        $bV = Block::create(['qversion_id' => $qv->id, 'code' => 'V',
            'title' => 'Pendapatan, Penerimaan, dan Pengeluaran Bukan Konsumsi (baris jumlah, setahun)',
            'order' => 4, 'repeat' => 'ruta']);
        $this->addQuestions($bV, [
            ['VA', 'v_a_upah', 'Blok V.A Jumlah — upah/gaji uang + barang/jasa + lembur/THR (Kolom 5+6+7)', 'decimal', 'Rp', []],
            ['VB', 'v_b_surplus', 'Blok V.B Jumlah — surplus usaha rumah tangga (Kolom 7)', 'decimal', 'Rp', []],
            ['VC', 'v_c_surplus', 'Blok V.C Jumlah — surplus produksi dikonsumsi/digunakan sendiri (Kolom 4)', 'decimal', 'Rp', []],
            ['VD1', 'v_d_diterima', 'Blok V.D Jumlah — pendapatan kepemilikan diterima (Kolom 2)', 'decimal', 'Rp', []],
            ['VD2', 'v_d_dibayar', 'Blok V.D Jumlah — pendapatan kepemilikan dibayar (Kolom 3)', 'decimal', 'Rp', []],
            ['VE1', 'v_e_diterima', 'Blok V.E Jumlah — transfer berjalan diterima (Kolom 2+3)', 'decimal', 'Rp', []],
            ['VE2', 'v_e_dibayar', 'Blok V.E Jumlah — transfer berjalan dibayar (Kolom 4+5)', 'decimal', 'Rp', []],
            ['VF1', 'v_f_diterima', 'Blok V.F Jumlah — transfer modal/aset diterima (Kolom 2+3)', 'decimal', 'Rp', []],
            ['VF2', 'v_f_dibayar', 'Blok V.F Jumlah — transfer modal/aset dibayar (Kolom 4+5)', 'decimal', 'Rp', []],
            ['VG', 'v_g_aset_neto', 'Blok V.G — total aset neto (Kolom 4)', 'decimal', 'Rp', []],
        ]);

        // Blok VI — rekapitulasi (setahun); konsumsi setahun = Blok IV.3.3 R.9 x 12
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'vi_konsumsi_setahun',
            'label' => 'Blok VI Pengeluaran R.1 · konsumsi rumah tangga setahun (Rp)',
            'expr' => 'round(n(rekap_ruta_sebulan) * 12, 0)', 'order' => 40]);
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'vi_penerimaan',
            'label' => 'Blok VI · jumlah penerimaan setahun (Rp)',
            'expr' => 'round(n(v_a_upah) + n(v_b_surplus) + n(v_c_surplus) + n(v_d_diterima) + n(v_e_diterima) + n(v_f_diterima), 0)',
            'order' => 41]);
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'vi_pengeluaran',
            'label' => 'Blok VI · jumlah pengeluaran setahun (Rp)',
            'expr' => 'round(n(vi_konsumsi_setahun) + n(v_d_dibayar) + n(v_e_dibayar) + n(v_f_dibayar) + n(v_g_aset_neto), 0)',
            'order' => 42]);
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'vi_selisih',
            'label' => 'Blok VI · selisih penerimaan − pengeluaran (Rp)',
            'expr' => 'round(n(vi_penerimaan) - n(vi_pengeluaran), 0)', 'order' => 43]);

        ValidationRule::create(['qversion_id' => $qv->id, 'code' => 'NERACA-VI', 'scope' => 'neraca',
            'row_block' => '', 'target_var' => '',
            'expr' => 'n(vi_penerimaan) > 0 and abs(n(vi_selisih)) / n(vi_penerimaan) > 0.1',
            'severity' => 'warning',
            'message' => 'Selisih penerimaan dan pengeluaran rumah tangga di Blok VI melebihi 10% — periksa Blok IV dan V.', 'active' => true]);

        // ============ Blok VII — Transaksi Keuangan Rumah Tangga (setahun) ============
        $bVII = Block::create(['qversion_id' => $qv->id, 'code' => 'VII',
            'title' => 'Transaksi Keuangan Rumah Tangga Selama Setahun Terakhir',
            'order' => 5, 'repeat' => 'ruta']);
        $this->addQuestions($bVII, [
            ['VII-P1', 'vii_p1_ambil_tabungan', 'Penerimaan 1 · pengambilan uang tunai dan tabungan', 'decimal', 'Rp', []],
            ['VII-P2', 'vii_p2_pinjam_uang', 'Penerimaan 2 · meminjam uang', 'decimal', 'Rp', []],
            ['VII-P3', 'vii_p3_terima_kredit_urt', 'Penerimaan 3 · menerima pembayaran kredit barang (usaha RT)', 'decimal', 'Rp', []],
            ['VII-P4', 'vii_p4_kredit_barang', 'Penerimaan 4 · kredit barang', 'decimal', 'Rp', []],
            ['VII-P5', 'vii_p5_lain', 'Penerimaan 5 · lainnya (pengembalian piutang, gadai, arisan, klaim asuransi, dll.)', 'decimal', 'Rp', []],
            ['VII-K1', 'vii_k1_nabung', 'Pengeluaran 1 · menyimpan uang tunai dan menabung', 'decimal', 'Rp', []],
            ['VII-K2', 'vii_k2_bayar_hutang', 'Pengeluaran 2 · membayar hutang', 'decimal', 'Rp', []],
            ['VII-K3', 'vii_k3_beri_kredit_urt', 'Pengeluaran 3 · memberikan kredit barang (usaha RT)', 'decimal', 'Rp', []],
            ['VII-K4', 'vii_k4_bayar_kredit_barang', 'Pengeluaran 4 · membayar kredit barang', 'decimal', 'Rp', []],
            ['VII-K5', 'vii_k5_lain', 'Pengeluaran 5 · lainnya (meminjamkan uang, menebus barang, membayar arisan, premi asuransi, dll.)', 'decimal', 'Rp', []],
        ]);
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'vii_penerimaan',
            'label' => 'Blok VII · jumlah penerimaan transaksi keuangan (Rp)',
            'expr' => 'round(n(vii_p1_ambil_tabungan) + n(vii_p2_pinjam_uang) + n(vii_p3_terima_kredit_urt) + n(vii_p4_kredit_barang) + n(vii_p5_lain), 0)',
            'order' => 44]);
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'vii_pengeluaran',
            'label' => 'Blok VII · jumlah pengeluaran transaksi keuangan (Rp)',
            'expr' => 'round(n(vii_k1_nabung) + n(vii_k2_bayar_hutang) + n(vii_k3_beri_kredit_urt) + n(vii_k4_bayar_kredit_barang) + n(vii_k5_lain), 0)',
            'order' => 45]);
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'vii_selisih',
            'label' => 'Blok VII · selisih transaksi keuangan (pengeluaran − penerimaan) (Rp)',
            'expr' => 'round(n(vii_pengeluaran) - n(vii_penerimaan), 0)', 'order' => 46]);

        // Identitas neraca: surplus pendapatan atas pengeluaran (Blok VI) harus terserap
        // oleh transaksi keuangan neto (Blok VII). Toleransi 15% dari magnitudo terbesar.
        ValidationRule::create(['qversion_id' => $qv->id, 'code' => 'NERACA-VII', 'scope' => 'neraca',
            'row_block' => '', 'target_var' => '',
            'expr' => '(vii_penerimaan > 0 or vii_pengeluaran > 0) and '
                . 'abs(n(vi_selisih) - n(vii_selisih)) > 0.15 * max(abs(n(vi_selisih)), abs(n(vii_selisih)), 1000000)',
            'severity' => 'warning',
            'message' => 'Selisih Blok VI tidak sepadan dengan transaksi keuangan neto Blok VII — periksa tabungan/pinjaman.', 'active' => true]);

        // dokumen contoh: beberapa komoditas terisi (sisanya kosong)
        $adm = User::where('username', 'admin')->first();
        $sample = SampleHousehold::create([
            'cycle_id' => $cycle->id, 'wilayah_code' => '3526010001', 'no_bs' => '020B',
            'no_ruta' => '001', 'krt_name' => 'Contoh KP', 'status' => 'proses',
            'petugas_id' => User::where('username', 'ppl_ani')->first()->id,
            'pml_id' => User::where('username', 'rahmawati')->first()->id,
        ]);
        $doc = Document::create(['sample_id' => $sample->id, 'qversion_id' => $qv->id,
            'status' => 'draft', 'created_by' => $adm->id]);
        $qmap = Question::where('block_id', $b->id)->get()->keyBy('var_name');
        $qart = Question::where('block_id', $bIII->id)->where('var_name', 'banyak_art')->first();
        Answer::create(['document_id' => $doc->id, 'question_id' => $qart->id, 'art_index' => 0, 'raw_value' => '4']);
        // beras (1), telur ayam ras (~72), minyak goreng (~), gula pasir; harga 1 di luar rentang
        foreach ([
            1 => ['iv1_kuantitas' => '14', 'iv1_nilai_beli' => '196000', 'iv1_nilai_lain' => '28000', 'iv1_asal_lain' => '1'],
            72 => ['iv1_kuantitas' => '2', 'iv1_nilai_beli' => '90000'],
            50 => ['iv1_kuantitas' => '1', 'iv1_nilai_beli' => '5000'],   // sayur, harga rendah
        ] as $ri => $cells) {
            foreach ($cells as $var => $val) {
                if ($q = $qmap->get($var)) {
                    Answer::create(['document_id' => $doc->id, 'question_id' => $q->id,
                        'art_index' => $ri, 'raw_value' => (string) $val]);
                }
            }
        }
        if (isset($b2)) {
            $q2map = Question::where('block_id', $b2->id)->get()->keyBy('var_name');
            foreach ([
                234 => ['iv2_rupiah_bulan' => '150000'],   // listrik
                264 => ['iv2_rupiah_bulan' => '100000'],   // pulsa HP
                293 => ['iv2_rupiah_bulan' => '200000'],   // uang sekolah
                308 => ['iv2_rupiah_tahun' => '500000'],   // pakaian laki-laki dewasa
                318 => ['iv2_rupiah_tahun' => '1200000'],  // peralatan rumah tangga
                335 => ['iv2_rupiah_tahun' => '150000'],   // PBB
            ] as $ri => $cells) {
                foreach ($cells as $var => $val) {
                    if ($q = $q2map->get($var)) {
                        Answer::create(['document_id' => $doc->id, 'question_id' => $q->id,
                            'art_index' => $ri, 'raw_value' => (string) $val]);
                    }
                }
            }
        }
        $qVmap = Question::where('block_id', $bV->id)->get()->keyBy('var_name');
        foreach ([
            'v_a_upah' => '22000000', 'v_b_surplus' => '1500000', 'v_c_surplus' => '500000',
            'v_d_diterima' => '200000', 'v_d_dibayar' => '100000',
            'v_e_diterima' => '600000', 'v_e_dibayar' => '400000',
        ] as $var => $val) {
            if ($q = $qVmap->get($var)) {
                Answer::create(['document_id' => $doc->id, 'question_id' => $q->id, 'art_index' => 0, 'raw_value' => $val]);
            }
        }
        $qViimap = Question::where('block_id', $bVII->id)->get()->keyBy('var_name');
        foreach ([
            'vii_k1_nabung' => '700000',        // menabung ≈ surplus pendapatan Blok VI
            'vii_p1_ambil_tabungan' => '55000',
        ] as $var => $val) {
            if ($q = $qViimap->get($var)) {
                Answer::create(['document_id' => $doc->id, 'question_id' => $q->id, 'art_index' => 0, 'raw_value' => $val]);
            }
        }
        // Blok III — ringkasan (bukan roster; lihat catatan Inc 132 di atas)
        $qIIImap = Question::where('block_id', $bIII->id)->get()->keyBy('var_name');
        foreach ([
            'b3_no_urut_informasi' => '1', 'b3_nama_informasi' => 'Contoh KP',
            'b3_jml_komoditas_makanan' => '3', 'b3_jml_komoditas_nonmakanan' => '6',
        ] as $var => $val) {
            if ($q = $qIIImap->get($var)) {
                Answer::create(['document_id' => $doc->id, 'question_id' => $q->id, 'art_index' => 0, 'raw_value' => $val]);
            }
        }

        // Blok I & II — identitas konsisten dengan sampel
        $qIdent = Question::whereIn('block_id', [$bI->id, $bII->id])->get()->keyBy('var_name');
        foreach ([
            'b1_kode_wilayah' => $sample->wilayah_code, 'b1_klasifikasi' => '2',
            'b1_kode_sls' => $sample->no_bs, 'b1_sls' => 'RT 002/RW 001',
            'b1_no_bangunan' => '007', 'b1_no_ruta' => $sample->no_ruta, 'b1_nama_krt' => $sample->krt_name,
            'b1_alamat' => 'Jl. Contoh No. 1',
            'b2_pendata' => 'Ani Kurniawati / 3526-01', 'b2_pendata_jabatan' => '3',
            'b2_pemeriksa' => 'Rahmawati / 3526-P01', 'b2_pemeriksa_jabatan' => '3',
            'b2_tgl_cacah' => '2026-03-05', 'b2_tgl_periksa' => '2026-03-08', 'b2_hasil' => '1',
        ] as $var => $val) {
            if ($q = $qIdent->get($var)) {
                Answer::create(['document_id' => $doc->id, 'question_id' => $q->id, 'art_index' => 0, 'raw_value' => (string) $val]);
            }
        }
        app(RuleEngine::class)->run($doc);

        // ---- generator ~28 rumah tangga KP realistis (untuk indikator & pemantauan) ----
        $engine = app(RuleEngine::class);
        $qmap = Question::where('block_id', $b->id)->get()->keyBy('var_name');
        $q2map = Question::where('block_id', $b2->id)->get()->keyBy('var_name');
        $qVmap = Question::where('block_id', $bV->id)->get()->keyBy('var_name');
        $qViimap = Question::where('block_id', $bVII->id)->get()->keyBy('var_name');
        $qIIImap = Question::where('block_id', $bIII->id)->get()->keyBy('var_name');
        $qIdent = Question::whereIn('block_id', [$bI->id, $bII->id])->get()->keyBy('var_name');
        $qart = Question::where('block_id', $bIII->id)->where('var_name', 'banyak_art')->first();
        $foodRows = \App\Models\BlockRow::where('block_id', $b->id)->orderBy('row_index')->pluck('ref', 'row_index');
        $petugas = ['ppl_ani', 'ppl_dodi', 'ppl_eka'];
        $desaList = ['3526010001', '3526010002', '3526020001', '3526020002'];
        $klas = ['3526010001' => '1', '3526010002' => '2', '3526020001' => '2', '3526020002' => '2'];
        $gk = 550000;
        mt_srand(20260301);
        $put = function ($doc, $qMap, $var, $val, $art = 0) {
            if (($q = $qMap->get($var)) && $val !== null && $val !== '') {
                Answer::create(['document_id' => $doc->id, 'question_id' => $q->id,
                    'art_index' => $art, 'raw_value' => (string) $val]);
            }
        };

        // lebih sedikit saat pengujian agar suite tetap cepat
        $perDesa = app()->runningUnitTests() ? 2 : 7;
        $seq = 0;
        foreach ($desaList as $desa) {
            for ($h = 0; $h < $perDesa; $h++) {
                $seq++;
                $art = 1 + ($seq % 6) + (mt_rand(0, 1));                 // 1..7
                $bs = sprintf('%03dB', 100 + $seq);
                $sh = SampleHousehold::create([
                    'cycle_id' => $cycle->id, 'wilayah_code' => $desa, 'no_bs' => $bs, 'no_ruta' => '001',
                    'krt_name' => 'RT '.$desa.'-'.$seq, 'status' => 'selesai',
                    'petugas_id' => User::where('username', $petugas[$seq % 3])->value('id'),
                    'pml_id' => User::where('username', 'rahmawati')->value('id'),
                    'weight' => mt_rand(90, 380),
                ]);
                $status = ['bersih', 'bersih', 'selesai', 'selesai', 'selesai', 'draft'][$seq % 6];
                $dg = Document::create(['sample_id' => $sh->id, 'qversion_id' => $qv->id,
                    'status' => $status, 'created_by' => $adm->id,
                    'submitted_at' => in_array($status, ['selesai', 'bersih'], true)
                        ? now()->subDays(mt_rand(0, 18)) : null]);

                Answer::create(['document_id' => $dg->id, 'question_id' => $qart->id,
                    'art_index' => 0, 'raw_value' => (string) $art]);

                // Blok III — ringkasan (bukan roster; lihat catatan Inc 132 di atas)
                $put($dg, $qIIImap, 'b3_no_urut_informasi', '1');
                $put($dg, $qIIImap, 'b3_nama_informasi', 'ART 1');
                $put($dg, $qIIImap, 'b3_jml_komoditas_makanan', '16');
                $put($dg, $qIIImap, 'b3_jml_komoditas_nonmakanan', '7');

                // Blok IV.1 — kuantitas ditentukan dari target kalori (≈ 2100 kkal/kapita/hari),
                // nilai = kuantitas × harga satuan dalam rentang wajar → IV1-HARGA & KALORI konsisten
                // pilih ~16 komoditas dari kelompok bahan pokok (harga per-kg wajar),
                // hindari MAKANAN JADI / ROKOK yang harganya bulk/porsi
                $stapleGroups = ['PADI-PADIAN', 'UMBI-UMBIAN', 'IKAN', 'DAGING', 'TELUR DAN SUSU',
                    'SAYUR-SAYURAN', 'KACANG-KACANGAN', 'BUAH-BUAHAN', 'MINYAK DAN KELAPA', 'BUMBU-BUMBUAN'];
                $pool = $foodRows->filter(fn ($ref, $ri) => in_array($ref['group'] ?? '', $stapleGroups, true)
                    && (float) ($ref['harga_min'] ?? 0) >= 2000 && (float) ($ref['harga_max'] ?? 0) <= 120000)->keys()->all();
                shuffle($pool);
                $picks = array_slice($pool, 0, 16);
                $kcalWeek = 2000 * 7 * $art;
                $priced = [];
                $wkSum = 0.0;                                            // Σ(bobot × kalori)
                foreach ($picks as $ri) {
                    // padi-padian & minyak menyumbang mayoritas kalori (pola konsumsi Indonesia)
                    $g = $foodRows[$ri]['group'] ?? '';
                    $base = in_array($g, ['PADI-PADIAN', 'MINYAK DAN KELAPA'], true) ? mt_rand(70, 120) : mt_rand(2, 9);
                    $priced[$ri] = $base;
                    $wkSum += $base * max(1.0, (float) ($foodRows[$ri]['kalori'] ?? 1500));
                }
                $badPrice = ($seq % 7 === 0);
                $foodWeek = 0;
                $firstRi = array_key_first($priced);
                foreach ($priced as $ri => $w) {
                    $ref = $foodRows[$ri] ?? [];
                    $kal = max(1.0, (float) ($ref['kalori'] ?? 1500));
                    $hmin = (float) ($ref['harga_min'] ?? 10000);
                    $hmax = (float) ($ref['harga_max'] ?? 20000);
                    $unit = $hmin + ($hmax - $hmin) * (mt_rand(30, 70) / 100);   // pertengahan rentang
                    $kuant = round($kcalWeek * $w / max($wkSum, 1), 2);
                    if ($kuant < 0.3) { continue; }
                    $nilai = (int) round($kuant * $unit);                        // harga satuan = tepat $unit
                    if ($badPrice && $ri === $firstRi) {
                        $nilai = (int) round($nilai * 2.4);               // satu komoditas harga tak wajar
                    }
                    // kolom (5)(6) dari pembelian; baris pertama sebagian dari produksi sendiri (7)(8)
                    $qLain = 0.0;
                    $nLain = 0;
                    if ($ri === $firstRi) {
                        $qLain = round($kuant * 0.25, 2);
                        $nLain = (int) round($qLain * $unit);
                    }
                    $qBeli = round($kuant - $qLain, 2);
                    $nBeli = $nilai - $nLain;
                    $foodWeek += $nilai;
                    $put($dg, $qmap, 'iv1_kuantitas_beli', $qBeli, $ri);
                    $put($dg, $qmap, 'iv1_nilai_beli', $nBeli, $ri);
                    if ($qLain > 0) {
                        $put($dg, $qmap, 'iv1_kuantitas_lain', $qLain, $ri);
                        $put($dg, $qmap, 'iv1_nilai_lain', $nLain, $ri);
                        $put($dg, $qmap, 'iv1_asal_lain', '1', $ri);
                    }
                    $put($dg, $qmap, 'iv1_kuantitas', $kuant, $ri);              // kolom (9) = (5)+(7)
                    $put($dg, $qmap, 'iv1_nilai', $nilai, $ri);                  // kolom (10) = (6)+(8)
                }

                // Blok IV.2 — non-pangan sebagai porsi dari pengeluaran makanan aktual
                $makananSebulan = (int) round($foodWeek * 30 / 7);
                $nonFoodBulan = (int) round($makananSebulan * mt_rand(55, 110) / 100);
                $nfBulan = (int) round($nonFoodBulan * mt_rand(50, 68) / 100);
                $nfTahunTotal = max(0, $nonFoodBulan - $nfBulan) * 12;
                $put($dg, $q2map, 'iv2_rupiah_bulan', (int) round($nfBulan * 0.35), 234);   // listrik
                $put($dg, $q2map, 'iv2_rupiah_bulan', (int) round($nfBulan * 0.25), 264);   // pulsa
                $put($dg, $q2map, 'iv2_rupiah_bulan', (int) round($nfBulan * 0.20), 262);   // kebutuhan rumah
                $put($dg, $q2map, 'iv2_rupiah_bulan', (int) round($nfBulan * 0.20), 298);   // transportasi
                $put($dg, $q2map, 'iv2_rupiah_tahun', (int) round($nfTahunTotal * 0.45), 308);   // pakaian
                $put($dg, $q2map, 'iv2_rupiah_tahun', (int) round($nfTahunTotal * 0.35), 318);   // peralatan RT
                $put($dg, $q2map, 'iv2_rupiah_tahun', (int) round($nfTahunTotal * 0.20), 335);   // PBB

                // Blok I & II identitas — sebagian besar konsisten
                $badIdent = ($seq % 9 === 0);
                $put($dg, $qIdent, 'b1_kode_wilayah', $badIdent ? '3526999999' : $desa);
                $put($dg, $qIdent, 'b1_klasifikasi', $klas[$desa]);
                $put($dg, $qIdent, 'b1_kode_sls', $bs);
                $put($dg, $qIdent, 'b1_sls', 'RT 00'.(1 + $seq % 9).'/RW 001');
                $put($dg, $qIdent, 'b1_no_bangunan', sprintf('%03d', 1 + $seq % 40));
                $put($dg, $qIdent, 'b1_no_ruta', '001');
                $put($dg, $qIdent, 'b1_nama_krt', $sh->krt_name);
                $put($dg, $qIdent, 'b1_alamat', 'Jl. Contoh No. '.(1 + $seq % 40));
                $put($dg, $qIdent, 'b2_pendata', 'Petugas '.sprintf('%02d', 1 + $seq % 5).' / 3526-'.sprintf('%02d', 1 + $seq % 5));
                $put($dg, $qIdent, 'b2_pendata_jabatan', '3');
                $put($dg, $qIdent, 'b2_pemeriksa', 'Rahmawati / 3526-P01');
                $put($dg, $qIdent, 'b2_pemeriksa_jabatan', '3');
                $put($dg, $qIdent, 'b2_tgl_cacah', '2026-03-'.sprintf('%02d', 3 + $seq % 20));
                $put($dg, $qIdent, 'b2_tgl_periksa', '2026-03-'.sprintf('%02d', 6 + $seq % 20));
                $put($dg, $qIdent, 'b2_hasil', '1');

                // Blok V/VII dari estimasi PHP rekap pengeluaran (satu kali jalan mesin)
                $rutaSebulanEst = $makananSebulan + $nfBulan + (int) round($nfTahunTotal / 12);
                $rutaSetahun = max(1, $rutaSebulanEst * 12);
                $surplus = mt_rand(-4, 9) / 100;                          // mayoritas surplus tipis
                $penerimaan = (int) round($rutaSetahun * (1 + $surplus));
                $put($dg, $qVmap, 'v_a_upah', (int) round($penerimaan * 0.85));
                $put($dg, $qVmap, 'v_b_surplus', (int) round($penerimaan * 0.10));
                $put($dg, $qVmap, 'v_c_surplus', (int) round($penerimaan * 0.03));
                $put($dg, $qVmap, 'v_e_diterima', (int) round($penerimaan * 0.02));
                $selisih = $penerimaan - $rutaSetahun;                    // = vi_selisih
                if ($selisih >= 0) {
                    $put($dg, $qViimap, 'vii_k1_nabung', $selisih);       // surplus ditabung
                } else {
                    $put($dg, $qViimap, 'vii_p2_pinjam_uang', -$selisih); // defisit ditutup pinjaman
                }
                // demo tidak boleh menandai 'bersih' dokumen yang masih ada error
                if ($status === 'bersih' && $engine->run($dg)['errors'] > 0) {
                    $dg->update(['status' => 'selesai', 'cleaned_by' => null, 'cleaned_at' => null]);
                } else {
                    $engine->run($dg);
                }
            }
        }
        mt_srand();
    }

    /** Struktur + 1 admin saja — untuk lingkungan produksi (tanpa data dummy). */
    public function metadataOnly(): void
    {
        User::create([
            'username' => 'admin', 'name' => 'Administrator',
            'email' => 'admin@bps.go.id', 'password' => Hash::make(Str::random(16)),
            'role' => 'admin', 'wilayah_scope' => '', 'active' => true,
        ]);
        $this->questionnaire();
        $this->command?->warn('Sandi admin diacak — atur ulang via: php artisan tinker');
    }

    private function users(): array
    {
        $rows = [
            ['admin', 'Administrator Sistem', 'admin123', 'admin', ''],
            ['pj_kamal', 'Bambang Wijaya', 'pj123', 'pj', '3526010'],
            ['rahmawati', 'Rahmawati', 'pengawas123', 'pml', '3526'],
            ['santoso', 'Budi Santoso', 'pengawas123', 'pml', '3526'],
            ['ppl_ani', 'Ani Kurniawati', 'operator123', 'operator', ''],
            ['ppl_dodi', 'Dodi Firmansyah', 'operator123', 'operator', ''],
            ['ppl_eka', 'Eka Wijaya', 'operator123', 'operator', ''],
            // pengolah tingkat kantor (dulu peran "editor" — kini dilebur ke pml, tanpa batas kecamatan)
            ['siti_editor', 'Siti Nurjanah', 'editor123', 'pml', ''],
            ['viewer', 'Peninjau', 'viewer123', 'viewer', ''],
        ];
        $out = [];
        foreach ($rows as [$u, $name, $pw, $role, $scope]) {
            $out[$u] = User::create([
                'username' => $u,
                'name' => $name,
                'email' => "{$u}@example.test",
                'password' => Hash::make($pw),
                'role' => $role,
                'wilayah_scope' => $scope,
                'active' => true,
            ]);
        }
        return $out;
    }

    private function wilayah(): array
    {
        $rows = [
            ['3526', 'Kabupaten Bangkalan', 'kabupaten', ''],
            ['3526010', 'Kamal', 'kecamatan', '3526'],
            ['3526020', 'Socah', 'kecamatan', '3526'],
            ['3526010001', 'Kamal', 'desa', '3526010'],
            ['3526010002', 'Tajungan', 'desa', '3526010'],
            ['3526020001', 'Socah', 'desa', '3526020'],
            ['3526020002', 'Buduran', 'desa', '3526020'],
        ];
        $desa = [];
        foreach ($rows as [$code, $name, $level, $parent]) {
            Wilayah::create(['code' => $code, 'name' => $name, 'level' => $level, 'parent_code' => $parent]);
            if ($level === 'desa') {
                $desa[] = $code;
            }
        }
        return $desa;
    }

    private function questionnaire(): array
    {
        // Inc 163: siklus contoh selalu di triwulan BERJALAN (periode = bulan ini) dan jadwalnya di dalam
        // periode itu. Dulu periode tetap "Maret 2027" dengan jadwal relatif hari ini → di November 2026
        // tampil sebagai "TW1 2027 · lapangan, jadwal lewat".
        [$qStart] = SurveyCycle::quarterRange(now()->format('Y-m'));
        $cycle = SurveyCycle::create([
            'name' => 'SUSENAS '.SurveyCycle::MONTHS[now()->month].' '.now()->year.' (contoh)',
            'period' => now()->format('Y-m'), 'status' => 'lapangan', 'survey' => 'SUSENAS', 'instrument' => 'KP',
            // pencacahan sedang berjalan: mulai ≤ 16 hari lalu (tidak sebelum awal triwulan), selesai 14 hari lagi
            'field_start' => now()->subDays(16)->max($qStart)->toDateString(),
            'field_end' => now()->addDays(14)->toDateString(),
        ]);
        $qv = QuestionnaireVersion::create([
            // Inc 164: kode VSEN27.KP dipakai SUSENAS Maret 2027 (PeriodeSurveiSeeder) — contoh punya kode sendiri
            'cycle_id' => $cycle->id, 'code' => 'VSEN'.now()->format('y').'.KP-CONTOH', 'effective_date' => $qStart->toDateString(),
        ]);

        $bAwal = Block::create(['qversion_id' => $qv->id, 'code' => 'POKOK',
            'title' => 'Keterangan Pokok (ringkas)', 'order' => 1, 'repeat' => 'ruta']);
        $yn = [['1', 'Ya'], ['5', 'Tidak']];
        $this->addQuestions($bAwal, [
            ['R105', 'kode_wilayah', 'Kode klasifikasi wilayah (Blok I R105)', 'text', '', []],
            ['R301', 'banyak_art', 'Banyaknya ART (Blok III R301)', 'int', 'orang', []],
            ['R302', 'ada_balita', 'Ada balita di rumah tangga? (Blok III R302)', 'code', '', $yn],
            ['R610', 'ada_art_sekolah', 'Ada ART yang sedang bersekolah? (Blok VI R610=2)', 'code', '', $yn],
            ['R706', 'ada_art_usaha', 'Ada ART berstatus berusaha? (Blok VII R706=1/2/3)', 'code', '', $yn],
            ['R1101', 'ada_asuransi', 'Memiliki asuransi/jaminan kesehatan? (Blok XI R1101)', 'code', '', $yn],
            ['R1207', 'ada_merokok', 'Ada ART yang merokok? (Blok XII R1207)', 'code', '', $yn],
            ['R1602', 'status_rumah', 'Status penguasaan bangunan tempat tinggal (Blok XVI R1602)', 'code', '', [
                ['1', 'Milik sendiri'], ['2', 'Kontrak'], ['3', 'Sewa'], ['4', 'Bebas sewa/dinas'], ['5', 'Lainnya'],
            ]],
        ]);

        $bArt = Block::create(['qversion_id' => $qv->id, 'code' => 'ART',
            'title' => 'Keterangan Anggota Rumah Tangga (ringkas)', 'order' => 2, 'repeat' => 'art']);
        $this->addQuestions($bArt, [
            ['R401', 'art_nama', 'Nama ART', 'text', '', []],
            ['R403', 'art_hub_krt', 'Hubungan dengan kepala rumah tangga', 'code', '', [
                ['1', 'Kepala rumah tangga'], ['2', 'Istri/suami'], ['3', 'Anak'], ['4', 'Menantu'],
                ['5', 'Cucu'], ['6', 'Orang tua/mertua'], ['7', 'Famili lain'], ['8', 'Lainnya'],
            ]],
            ['R405', 'art_jk', 'Jenis kelamin', 'code', '', [['1', 'Laki-laki'], ['2', 'Perempuan']]],
            ['R407', 'art_umur', 'Umur (tahun)', 'int', 'tahun', []],
            ['R610', 'art_sekolah', 'Partisipasi sekolah', 'code', '', [
                ['1', 'Tidak/belum pernah'], ['2', 'Masih bersekolah'], ['3', 'Tidak bersekolah lagi'],
            ]],
            ['R706', 'art_bekerja', 'Bekerja seminggu terakhir?', 'code', '', [['1', 'Ya'], ['2', 'Tidak']]],
        ]);

        $bIv2 = Block::create(['qversion_id' => $qv->id, 'code' => 'IV.2',
            'title' => 'Pengeluaran Bukan Makanan — subset (Blok IV.2)', 'order' => 3, 'repeat' => 'ruta']);
        $iv2 = [
            ['R234', 'iv2_listrik', 'Listrik', 'Rp / bulan', '', ''],
            ['R236', 'iv2_air', 'Air (PAM/beli/pikulan)', 'Rp / bulan', '', ''],
            ['R264', 'iv2_pulsa_hp', 'Pulsa/paket data HP', 'Rp / bulan', '', ''],
            ['R266', 'iv2_internet', 'Biaya internet, warnet, TV kabel', 'Rp / bulan', '', ''],
            ['R293', 'iv2_spp', 'Uang sekolah (SPP/UKT) dan iuran komite', 'Rp / bulan',
                'n(ada_art_sekolah) != 1', 'Muncul bila ada ART bersekolah'],
            ['R295', 'iv2_buku', 'Buku pelajaran, fotokopi bahan, alat tulis', 'Rp / bulan', '', ''],
            ['R310', 'iv2_pakaian_anak', 'Pakaian jadi untuk anak-anak', 'Rp / tahun', '', ''],
            ['R335', 'iv2_pbb', 'Pajak Bumi dan Bangunan (PBB)', 'Rp / tahun',
                'n(status_rumah) != 1', 'Muncul bila rumah milik sendiri'],
            ['R338', 'iv2_asuransi_kesehatan', 'Asuransi kesehatan (premi/iuran)', 'Rp / tahun', '', ''],
        ];
        foreach ($iv2 as $i => [$rc, $var, $label, $unit, $skip, $help]) {
            Question::create(['block_id' => $bIv2->id, 'code_rincian' => $rc, 'var_name' => $var,
                'label' => $label, 'qtype' => 'decimal', 'unit' => $unit, 'order' => $i + 1,
                'skip_expr' => $skip, 'help_text' => $help]);
        }

        foreach ([
            ['garis_kemiskinan_kab', '550000', 'Rp/kapita/bulan (contoh, ganti dgn angka resmi)'],
            ['kalori_min', '1700', 'kkal/kapita/hari'],
            ['kalori_max', '4500', 'kkal/kapita/hari'],
            ['komoditas_bukan_makanan_min', '19', 'jumlah komoditas minimal'],
        ] as [$name, $value, $note]) {
            Parameter::create(['qversion_id' => $qv->id, 'name' => $name, 'value' => $value, 'note' => $note]);
        }

        foreach ([
            ['total_bkn_makanan_bulan', 'Subtotal bukan makanan (sebulan)',
                'n(iv2_listrik)+n(iv2_air)+n(iv2_pulsa_hp)+n(iv2_internet)+n(iv2_spp)+n(iv2_buku)'],
            ['total_bkn_makanan_tahun', 'Subtotal bukan makanan (setahun)',
                'n(iv2_pakaian_anak)+n(iv2_pbb)+n(iv2_asuransi_kesehatan)'],
            ['pengeluaran_bkn_makanan_sebulan', 'Rata-rata bukan makanan per bulan',
                'round(total_bkn_makanan_bulan + total_bkn_makanan_tahun / 12, 0)'],
            ['pengeluaran_per_kapita', 'Pengeluaran (subset) per kapita per bulan',
                'round(pengeluaran_bkn_makanan_sebulan / max(n(banyak_art), 1), 0)'],
            ['jml_art_roster', 'Jumlah ART terisi di roster', 'art_umur__n'],
            ['jml_art_bekerja', 'Jumlah ART bekerja', 'art_bekerja__count1'],
        ] as $i => [$var, $label, $expr]) {
            DerivedField::create(['qversion_id' => $qv->id, 'var_name' => $var,
                'label' => $label, 'expr' => $expr, 'order' => $i + 1]);
        }

        $this->crosscheck($qv);

        foreach ($this->ruleDefs() as [$code, $scope, $target, $expr, $sev, $msg]) {
            ValidationRule::create(['qversion_id' => $qv->id, 'code' => $code, 'scope' => $scope,
                'target_var' => $target, 'expr' => $expr, 'severity' => $sev, 'message' => $msg, 'active' => true]);
        }

        return [$cycle, $qv];
    }

    /**
     * Increment 6 — miniatur NERACA: Blok IV.1 (daftar komoditas) + Blok V,
     * agregat terkelompok, turunan disetahunkan, dan aturan cek-silang.
     */
    private function crosscheck(QuestionnaireVersion $qv): void
    {
        // --- Blok IV.1: konsumsi makanan seminggu (daftar baris tetap) ---
        // Baris = komoditas, dengan data referensi kalori (kkal/satuan) & rentang harga.
        // Versi penuh (186 komoditas) tinggal diimpor lewat menu Impor Baris.
        $b = Block::create(['qversion_id' => $qv->id, 'code' => 'IV.1',
            'title' => 'Konsumsi Makanan Seminggu — subset (Blok IV.1)', 'order' => 4, 'repeat' => 'list']);
        $komoditas = [
            // code, label, satuan, kalori/satuan, harga_min, harga_max
            ['01', 'Beras', 'Kg', 3600, 12000, 16000],
            ['02', 'Telur ayam ras', 'Kg', 1550, 24000, 32000],
            ['03', 'Daging ayam ras', 'Kg', 2150, 30000, 42000],
            ['04', 'Bayam', 'Kg', 230, 8000, 16000],
            ['05', 'Kangkung', 'Kg', 190, 6000, 12000],
            ['06', 'Tempe', 'Kg', 2010, 12000, 20000],
            ['07', 'Tahu', 'Kg', 760, 8000, 14000],
            ['08', 'Minyak goreng', 'Kg', 9020, 15000, 20000],
            ['09', 'Gula pasir', 'Kg', 3870, 14000, 18000],
            ['10', 'Cabai merah', 'Kg', 400, 30000, 80000],
            ['11', 'Bawang merah', 'Kg', 460, 25000, 45000],
            ['12', 'Kopi bubuk', 'Ons', 350, 3000, 6000],
            ['13', 'Ikan tongkol', 'Kg', 1110, 25000, 40000],
            ['14', 'Susu kental manis', 'Kaleng', 1330, 10000, 14000],
            ['15', 'Mie instan', 'Bungkus', 350, 2800, 4000],
            ['16', 'Rokok kretek filter', 'Bungkus', 0, 20000, 30000],
        ];
        foreach ($komoditas as $i => [$code, $label, $sat, $kal, $hmin, $hmax]) {
            \App\Models\BlockRow::create(['block_id' => $b->id, 'row_index' => $i + 1,
                'code' => $code, 'label' => "{$label} ({$sat})", 'order' => $i + 1,
                'ref' => ['kalori' => $kal, 'harga_min' => $hmin, 'harga_max' => $hmax]]);
        }
        Question::create(['block_id' => $b->id, 'code_rincian' => 'K4', 'var_name' => 'iv1_kuantitas',
            'label' => 'Kuantitas konsumsi (satuan komoditas, seminggu)', 'qtype' => 'decimal', 'unit' => 'satuan', 'order' => 1]);
        Question::create(['block_id' => $b->id, 'code_rincian' => 'K5', 'var_name' => 'iv1_nilai_beli',
            'label' => 'Nilai konsumsi dari pembelian (Rp, seminggu)', 'qtype' => 'decimal', 'unit' => 'Rp', 'order' => 2]);
        Question::create(['block_id' => $b->id, 'code_rincian' => 'K8', 'var_name' => 'iv1_nilai_lain',
            'label' => 'Nilai konsumsi dari produksi sendiri / pemberian (Rp, seminggu)', 'qtype' => 'decimal', 'unit' => 'Rp', 'order' => 3]);
        $qAsal = Question::create(['block_id' => $b->id, 'code_rincian' => 'K8a', 'var_name' => 'iv1_asal_lain',
            'label' => 'Asal konsumsi selain pembelian', 'qtype' => 'code', 'unit' => '', 'order' => 4]);
        foreach ([['1', 'Produksi sendiri'], ['2', 'Pemberian rumah tangga lain'], ['3', 'Pemberian lembaga/pemerintah']] as $j => [$c, $l]) {
            ResponseOption::create(['question_id' => $qAsal->id, 'code' => $c, 'label' => $l, 'order' => $j + 1]);
        }

        // --- Blok V: pendapatan, penerimaan & pengeluaran bukan konsumsi (subset) ---
        $v = Block::create(['qversion_id' => $qv->id, 'code' => 'V',
            'title' => 'Pendapatan, Penerimaan & Pengeluaran Bukan Konsumsi — subset (Blok V)', 'order' => 5, 'repeat' => 'ruta']);
        foreach ([
            ['R502', 'v_produksi_dikonsumsi_setahun', 'Nilai produksi RT yang dikonsumsi sendiri, setahun', 'Rp / tahun'],
            ['R504', 'v_transfer_barang_setahun', 'Nilai transfer barang/jasa diterima, setahun', 'Rp / tahun'],
            ['R601', 'v_total_penerimaan', 'Jumlah seluruh penerimaan, setahun', 'Rp / tahun'],
            ['R602', 'v_total_pengeluaran', 'Jumlah seluruh pengeluaran, setahun', 'Rp / tahun'],
        ] as $i => [$rc, $var, $label, $unit]) {
            Question::create(['block_id' => $v->id, 'code_rincian' => $rc, 'var_name' => $var,
                'label' => $label, 'qtype' => 'decimal', 'unit' => $unit, 'order' => $i + 1]);
        }

        // --- Agregat terkelompok atas Blok IV.1 ---
        foreach ([
            // name, label, block, value_var, op, weight_ref, filter_var, filter_op, filter_val
            ['iv1_total_beli', 'IV.1 · total nilai dari pembelian (minggu)', 'IV.1', 'iv1_nilai_beli', 'sum', '', '', 'any', ''],
            ['iv1_produksi_sendiri', 'IV.1 · konsumsi dari produksi sendiri (minggu)', 'IV.1', 'iv1_nilai_lain', 'sum', '', 'iv1_asal_lain', 'eq', '1'],
            ['iv1_transfer_diterima', 'IV.1 · konsumsi dari pemberian (minggu)', 'IV.1', 'iv1_nilai_lain', 'sum', '', 'iv1_asal_lain', 'in', '2,3'],
            ['kalori_total', 'IV.1 · total kalori konsumsi (kkal/minggu)', 'IV.1', 'iv1_kuantitas', 'weighted_sum', 'kalori', '', 'any', ''],
        ] as $i => [$name, $label, $bc, $vv, $op, $wr, $fv, $fo, $fval]) {
            \App\Models\Aggregate::create(['qversion_id' => $qv->id, 'name' => $name, 'label' => $label,
                'block_code' => $bc, 'value_var' => $vv, 'op' => $op, 'weight_ref' => $wr,
                'filter_var' => $fv, 'filter_op' => $fo, 'filter_val' => $fval, 'order' => $i + 1]);
        }

        // --- Turunan NERACA (seminggu -> setahun: faktor 30/7*12 ~ 51.43) ---
        $ord = DerivedField::where('qversion_id', $qv->id)->max('order') ?? 0;
        foreach ([
            ['neraca_min_produksi_sendiri', 'NERACA · minimal nilai produksi dikonsumsi sendiri (setahun)',
                'round(iv1_produksi_sendiri * 30 / 7 * 12, 0)'],
            ['neraca_min_transfer_barang', 'NERACA · minimal transfer barang diterima (setahun)',
                'round(iv1_transfer_diterima * 30 / 7 * 12, 0)'],
            ['neraca_selisih', 'NERACA · selisih penerimaan − pengeluaran',
                'n(v_total_penerimaan) - n(v_total_pengeluaran)'],
            ['neraca_diskrepansi_pct', 'NERACA · diskrepansi relatif',
                'abs(neraca_selisih) / max(n(v_total_penerimaan), 1)'],
            ['kalori_per_kapita_hari', 'Kalori per kapita per hari (kkal)',
                'round(kalori_total / max(n(banyak_art), 1) / 7, 0)'],
        ] as $i => [$var, $label, $expr]) {
            DerivedField::create(['qversion_id' => $qv->id, 'var_name' => $var,
                'label' => $label, 'expr' => $expr, 'order' => $ord + $i + 1]);
        }

        foreach ([
            ['NERACA-PRODUKSI', 'neraca', '', 'v_produksi_dikonsumsi_setahun',
                'neraca_min_produksi_sendiri > 0 and n(v_produksi_dikonsumsi_setahun) < neraca_min_produksi_sendiri',
                'error', 'Blok V nilai produksi dikonsumsi sendiri lebih kecil dari konsumsi produksi sendiri di Blok IV.1 (disetahunkan). Periksa Blok V R502.'],
            ['NERACA-TRANSFER', 'neraca', '', 'v_transfer_barang_setahun',
                'neraca_min_transfer_barang > 0 and n(v_transfer_barang_setahun) < neraca_min_transfer_barang',
                'warning', 'Blok V transfer barang diterima lebih kecil dari konsumsi dari pemberian di Blok IV.1 (disetahunkan). Periksa Blok V R504.'],
            ['NERACA-BALANCE', 'neraca', '', '',
                'n(v_total_penerimaan) > 0 and neraca_diskrepansi_pct > 0.05',
                'warning', 'Selisih penerimaan & pengeluaran Blok V lebih dari 5% — periksa keseimbangan neraca rumah tangga.'],
            ['KALORI-UNDER', 'kalori', '', '',
                'kalori_per_kapita_hari > 0 and kalori_per_kapita_hari < n(kalori_min)',
                'warning', 'Konsumsi kalori per kapita per hari di bawah ambang — periksa kelengkapan isian Blok IV.1.'],
            ['KALORI-OVER', 'kalori', '', '',
                'kalori_per_kapita_hari > n(kalori_max)',
                'warning', 'Konsumsi kalori per kapita per hari di atas ambang wajar — periksa kuantitas Blok IV.1.'],
            // aturan PER-BARIS Blok IV.1 (row_block = 'IV.1')
            ['IV1-KUANTITAS', 'baris', 'IV.1', 'iv1_kuantitas',
                'filled(iv1_nilai_beli) and n(iv1_nilai_beli) > 0 and (blank(iv1_kuantitas) or n(iv1_kuantitas) == 0)',
                'error', 'Nilai pembelian terisi tetapi kuantitas kosong.'],
            ['IV1-HARGA-MAHAL', 'baris', 'IV.1', 'iv1_nilai_beli',
                'filled(iv1_nilai_beli) and n(iv1_kuantitas) > 0 and filled(ref_harga_max) '
                . 'and n(iv1_nilai_beli) / n(iv1_kuantitas) > n(ref_harga_max)',
                'warning', 'Harga satuan Rp{=n(iv1_nilai_beli)/n(iv1_kuantitas)} kemahalan — batas wajar Rp{ref_harga_min}–Rp{ref_harga_max} per satuan komoditas.'],
            ['IV1-HARGA-MURAH', 'baris', 'IV.1', 'iv1_nilai_beli',
                'filled(iv1_nilai_beli) and n(iv1_kuantitas) > 0 and filled(ref_harga_min) '
                . 'and n(iv1_nilai_beli) / n(iv1_kuantitas) < n(ref_harga_min)',
                'warning', 'Harga satuan Rp{=n(iv1_nilai_beli)/n(iv1_kuantitas)} kemurahan — batas wajar Rp{ref_harga_min}–Rp{ref_harga_max} per satuan komoditas.'],
            ['IV1-KUANTITAS-MAX', 'baris', 'IV.1', 'iv1_kuantitas',
                'filled(iv1_kuantitas) and n(iv1_kuantitas) > 0 and filled(ref_max_kons) and n(iv1_kuantitas) > n(ref_max_kons)',
                'warning', 'Kuantitas {=n(iv1_kuantitas)} melebihi batas maksimum wajar ({ref_max_kons} per rumah tangga per minggu).'],
        ] as [$code, $scope, $rowBlock, $target, $expr, $sev, $msg]) {
            ValidationRule::create(['qversion_id' => $qv->id, 'code' => $code, 'scope' => $scope,
                'row_block' => $rowBlock, 'target_var' => $target, 'expr' => $expr,
                'severity' => $sev, 'message' => $msg, 'active' => true]);
        }
        $this->ruleNotes($qv->id);
    }

    /** Keterangan bahasa manusia untuk aturan per-baris Blok IV.1. */
    private function ruleNotes(int $qvId): void
    {
        foreach ([
            'IV1-HARGA-MAHAL' => 'Harga satuan hasil bagi nilai ÷ kuantitas lebih tinggi dari batas atas harga wajar komoditas (kolom harga_max). Cek salah ketik nol atau salah satuan.',
            'IV1-HARGA-MURAH' => 'Harga satuan hasil bagi nilai ÷ kuantitas lebih rendah dari batas bawah harga wajar komoditas (kolom harga_min). Cek nilai/kuantitas tertukar atau kurang nol.',
            'IV1-KUANTITAS-MAX' => 'Kuantitas konsumsi seminggu melebihi batas maksimum wajar per rumah tangga (kolom max_kons).',
        ] as $code => $note) {
            ValidationRule::where('qversion_id', $qvId)->where('code', $code)->update(['note' => $note]);
        }
    }

    /**
     * Increment 10 — kuesioner KOR nyata (VSEN26.K Susenas Maret 2026) + aturan
     * anomali hasil terjemahan dari tool Sidona + 3 rumah tangga contoh
     * (2 data nyata dari contoh terisi, 1 sintetis untuk memicu anomali).
     * Pemetaan aturan: docs/pemetaan-aturan-sidona.md
     */
    private function korQuestionnaire(array $users): void
    {
        $cycle = SurveyCycle::create([
            'name' => 'SUSENAS Maret 2026 (KOR)', 'period' => '2026-03', 'status' => 'pengolahan',
            'field_start' => '2026-03-01', 'field_end' => '2026-03-31',
        ]);
        $qv = QuestionnaireVersion::create([
            'cycle_id' => $cycle->id, 'code' => 'VSEN26.K', 'effective_date' => '2025-11-07',
        ]);

        // --- Blok III: Ringkasan (per rumah tangga) ---
        $b3 = Block::create(['qversion_id' => $qv->id, 'code' => 'III',
            'title' => 'Ringkasan', 'order' => 1, 'repeat' => 'ruta']);
        $this->addQuestions($b3, [
            ['301', 'r301', 'Banyaknya anggota rumah tangga', 'int', 'orang', []],
            ['302', 'r302', 'Banyaknya ART umur 0–4 tahun', 'int', 'orang', []],
            ['303', 'r303', 'Banyaknya ART umur 5 tahun ke atas', 'int', 'orang', []],
            ['304', 'r304', 'Banyaknya ART umur 10 tahun ke atas', 'int', 'orang', []],
            ['305', 'r305', 'Banyaknya perempuan umur 10–54 tahun pernah kawin', 'int', 'orang', []],
        ]);

        // --- Blok IV–VI: Keterangan ART (satu baris per ART) ---
        $bart = Block::create(['qversion_id' => $qv->id, 'code' => 'ART',
            'title' => 'Keterangan ART (Demografi, Dokumen, Pendidikan)', 'order' => 2, 'repeat' => 'art']);
        $this->addQuestions($bart, [
            ['403', 'r403', 'Hubungan dengan kepala rumah tangga', 'code', '', [
                ['1', 'Kepala rumah tangga'], ['2', 'Istri/suami'], ['3', 'Anak kandung/tiri'],
                ['4', 'Anak angkat'], ['5', 'Menantu'], ['6', 'Cucu'], ['7', 'Orang tua/mertua'],
                ['8', 'Pembantu/sopir'], ['9', 'Lainnya (famili lain / non-famili)'],
            ]],
            ['404', 'r404', 'Status perkawinan', 'code', '', [
                ['1', 'Belum kawin'], ['2', 'Kawin'], ['3', 'Cerai hidup'], ['4', 'Cerai mati'],
            ]],
            ['405', 'r405', 'Jenis kelamin', 'code', '', [['1', 'Laki-laki'], ['2', 'Perempuan']]],
            ['407', 'r407', 'Umur (tahun)', 'int', 'tahun', []],
            ['409', 'r409', 'Umur saat perkawinan pertama (tahun)', 'int', 'tahun', []],
            ['410', 'r410', 'No. urut ART pemberi informasi', 'int', '', []],
            ['501', 'r501', 'Memiliki/pernah memiliki buku/akta nikah', 'code', '', [
                ['1', 'Ya, dapat ditunjukkan'], ['2', 'Ya, tidak dapat ditunjukkan'], ['5', 'Tidak'],
            ]],
            ['502', 'r502', 'No. urut keluarga', 'int', '', []],
            ['503', 'r503', 'No. urut ibu kandung (00 jika tidak di ruta ini)', 'int', '', []],
            ['504', 'r504', 'No. urut ayah kandung (00 jika tidak di ruta ini)', 'int', '', []],
            ['505', 'r505', 'Mempunyai NIK', 'code', '', [
                ['1', 'Ya'], ['2', 'Ya, tidak ingat/tidak ada catatan'], ['5', 'Tidak'],
            ]],
            ['508', 'r508', 'Sumber data NIK', 'code', '', [
                ['1', 'Kartu Keluarga'], ['2', 'KTP-el'], ['3', 'Kartu Identitas Anak'], ['9', 'Lainnya'],
            ]],
            ['509', 'r509', 'Memiliki akta kelahiran', 'code', '', [
                ['1', 'Ya, dapat ditunjukkan'], ['2', 'Ya, tidak dapat ditunjukkan'],
                ['3', 'Ya, dicatat pemeriksa'], ['5', 'Tidak'],
            ]],
            ['610', 'r610', 'Partisipasi sekolah', 'code', '', [
                ['1', 'Tidak/belum pernah bersekolah'], ['2', 'Masih bersekolah'],
                ['3', 'Tidak bersekolah lagi'],
            ]],
            ['613', 'r613', 'Kelas tertinggi yang pernah/sedang diduduki', 'int', '', []],
            ['619', 'r619', 'Menerima Kartu Indonesia Pintar (KIP)', 'code', '', [
                ['1', 'Ya'], ['5', 'Tidak'],
            ]],
            // Blok VII — inklusi keuangan & ketenagakerjaan (ringkas)
            ['701', 'r701', 'Memiliki rekening di lembaga keuangan', 'code', '', [
                ['1', 'Ya'], ['5', 'Tidak'],
            ]],
            ['702', 'r702', 'Menggunakan jasa keuangan (transfer, tarik/setor, dll.)', 'code', '', [
                ['1', 'Ya'], ['5', 'Tidak'],
            ]],
            ['704', 'r704', 'Bekerja/membantu bekerja seminggu terakhir', 'code', '', [
                ['1', 'Ya, punya pekerjaan sementara tidak bekerja'], ['2', 'Ya, bekerja'],
                ['5', 'Tidak bekerja'],
            ]],
            ['706', 'r706', 'Status/kedudukan dalam pekerjaan utama', 'code', '', [
                ['1', 'Berusaha sendiri'], ['2', 'Berusaha dibantu buruh tak tetap'],
                ['3', 'Berusaha dibantu buruh tetap'], ['4', 'Buruh/karyawan/pegawai'],
                ['5', 'Pekerja bebas pertanian'], ['6', 'Pekerja bebas nonpertanian'],
                ['7', 'Pekerja keluarga/tak dibayar'],
            ]],
            // Blok VI (lanjutan) — keaksaraan & pendidikan
            ['608', 'r608', 'Dapat membaca', 'code', '', [
                ['1', 'Huruf latin'], ['2', 'Huruf lainnya'], ['3', 'Huruf braille'],
                ['4', 'Latin & lainnya'], ['5', 'Tidak dapat membaca'],
            ]],
            ['609', 'r609', 'Dapat menulis', 'code', '', [
                ['1', 'Huruf latin'], ['2', 'Huruf lainnya'], ['3', 'Huruf braille'],
                ['4', 'Latin & lainnya'], ['5', 'Tidak dapat menulis'],
            ]],
            ['611', 'r611', 'Status sekolah (negeri/swasta)', 'code', '', [['1', 'Negeri'], ['2', 'Swasta']]],
            ['612', 'r612', 'Jenjang pendidikan yang sedang diduduki', 'int', '', []],
            ['614', 'r614', 'Jenjang ijazah/STTB tertinggi', 'int', '', []],
            ['615', 'r615', 'Kelas tertinggi pada jenjang ijazah', 'int', '', []],
            // Blok VII (lanjutan) — kegiatan & ketenagakerjaan
            ['703A', 'r703a', 'Kegiatan seminggu: Bekerja', 'code', '', [['1', 'Dilingkari (ya)'], ['5', 'Tidak']]],
            ['703B', 'r703b', 'Kegiatan seminggu: Sekolah', 'code', '', [['1', 'Dilingkari (ya)'], ['5', 'Tidak']]],
            ['705', 'r705', 'Kategori lapangan usaha pekerjaan utama (kode 1–26)', 'int', '', []],
            ['708', 'r708', 'Jumlah jam kerja seluruh pekerjaan seminggu', 'int', 'jam', []],
            // Blok VIII — teknologi informasi & komunikasi
            ['801', 'r801', 'Menggunakan telepon seluler/HP dalam 3 bulan terakhir', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['802', 'r802', 'Menguasai/memiliki telepon seluler/HP', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['803A', 'r803a', 'Mengakses internet menggunakan komputer/PC/laptop', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['806A', 'r806a', 'Lokasi akses internet: rumah sendiri (dilingkari)', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['806B', 'r806b', 'Lokasi akses internet: rumah orang lain (dilingkari)', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['806C', 'r806c', 'Lokasi akses internet: tempat kerja (dilingkari)', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['806D', 'r806d', 'Lokasi akses internet: sekolah/kampus (dilingkari)', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['806E', 'r806e', 'Lokasi akses internet: fasilitas publik (dilingkari)', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['806F', 'r806f', 'Lokasi akses internet: saat bergerak/dalam perjalanan (dilingkari)', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['811F', 'r811f', 'Kegiatan internet: transaksi keuangan/internet banking (dilingkari)', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            // Blok X — gangguan fungsional (untuk ART umur ≥ 2 tahun)
            ['1002', 'r1002', 'Gangguan penglihatan', 'code', '', [
                ['1', 'Sama sekali tidak bisa'], ['2', 'Banyak kesulitan'],
                ['3', 'Sedikit kesulitan'], ['4', 'Tidak ada kesulitan'],
            ]],
            ['1006', 'r1006', 'Gangguan mengingat/berkonsentrasi', 'code', '', [
                ['1', 'Selalu'], ['2', 'Sering'], ['3', 'Sedikit'], ['4', 'Tidak ada'],
            ]],
            // Blok XI–XII — rawat jalan & merokok
            ['1108', 'r1108', 'Frekuensi rawat jalan sebulan terakhir', 'int', 'kali', []],
            ['1206', 'r1206', 'Merokok elektrik', 'code', '', [
                ['1', 'Ya, setiap hari'], ['2', 'Ya, kadang-kadang'], ['3', 'Tidak pernah lagi'],
                ['5', 'Tidak pernah'], ['8', 'Tidak tahu'],
            ]],
            ['1207', 'r1207', 'Merokok tembakau (rokok/cerutu/cangklong)', 'code', '', [
                ['1', 'Ya, setiap hari'], ['2', 'Ya, kadang-kadang'], ['3', 'Tidak pernah lagi'],
                ['5', 'Tidak pernah'], ['8', 'Tidak tahu'],
            ]],
            ['1208', 'r1208', 'Mengunyah tembakau', 'code', '', [
                ['1', 'Ya, setiap hari'], ['2', 'Ya, kadang-kadang'], ['3', 'Tidak pernah lagi'],
                ['5', 'Tidak pernah'], ['8', 'Tidak tahu'],
            ]],
            // Blok XIII — imunisasi, ASI, MP-ASI (untuk ART umur 0–59 bulan)
            ['1301', 'r1301', 'Umur balita dalam bulan', 'int', 'bulan', []],
            ['1305G', 'r1305g', 'Tempat imunisasi: lainnya (dilingkari)', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1306F', 'r1306f', 'Pemberi imunisasi: lainnya (dilingkari)', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1308A', 'r1308a', 'Apakah balita pernah/sedang diberi ASI', 'code', '', [
                ['1', 'Ya, masih'], ['2', 'Ya, sudah tidak'], ['5', 'Tidak pernah'], ['8', 'Tidak tahu'],
            ]],
            ['1311', 'r1311', 'Kemarin baduta diberi MP-ASI (makanan padat/lunak)', 'code', '', [
                ['1', 'Ya'], ['5', 'Tidak'], ['8', 'Tidak tahu'],
            ]],
            ['1312A', 'r1312a', 'MP-ASI kemarin: makanan pokok', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1312B', 'r1312b', 'MP-ASI kemarin: kacang-kacangan', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1312C', 'r1312c', 'MP-ASI kemarin: hasil olahan susu', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1312D', 'r1312d', 'MP-ASI kemarin: daging/unggas/ikan/hati', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1312E', 'r1312e', 'MP-ASI kemarin: telur', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1312F', 'r1312f', 'MP-ASI kemarin: sayur/buah kaya vitamin A', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1312G', 'r1312g', 'MP-ASI kemarin: sayur/buah lainnya', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1312H', 'r1312h', 'MP-ASI kemarin: makanan manis/berminyak/berlemak', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            // Blok XIV — penolong persalinan (untuk ART perempuan pernah kawin 10–54 th)
            ['1401A', 'r1401a', 'Apakah pernah/sedang hamil', 'code', '', [
                ['1', 'Ya, pernah hamil'], ['2', 'Ya, sedang hamil'], ['5', 'Tidak'],
            ]],
            ['1402A', 'r1402a', 'Apakah pernah melahirkan anak lahir hidup', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1404A', 'r1404a', 'Tempat melahirkan anak lahir hidup terakhir (kode 1–9)', 'int', '', []],
            ['1404B', 'r1404b', 'Penolong persalinan terakhir (kode 1–8)', 'int', '', []],
            ['1404C', 'r1404c', 'Bayi terakhir ditimbang saat lahir', 'code', '', [['1', 'Ya'], ['5', 'Tidak'], ['8', 'Tidak tahu']]],
        ]);

        // --- Blok II: Keterangan Pendataan (per rumah tangga) ---
        $b2k = Block::create(['qversion_id' => $qv->id, 'code' => 'II',
            'title' => 'Keterangan Pendataan', 'order' => 0, 'repeat' => 'ruta']);
        $this->addQuestions($b2k, [
            ['B2_JAM1', 'r_jam_mulai', 'Jam mulai wawancara (0–23)', 'int', 'jam', []],
            ['B2_JAM2', 'r_jam_selesai', 'Jam selesai wawancara (0–23)', 'int', 'jam', []],
            ['203', 'r203', 'Hasil pendataan rumah tangga', 'code', '', [
                ['1', 'Terisi lengkap'], ['2', 'Terisi tidak lengkap'],
                ['3', 'Tidak ada ART/responden'], ['4', 'Responden menolak'],
                ['5', 'Rumah tangga pindah/bangunan tidak ada'],
            ]],
        ]);

        // --- Blok XVI: Perumahan (per rumah tangga) ---
        $b16 = Block::create(['qversion_id' => $qv->id, 'code' => 'XVI',
            'title' => 'Keterangan Perumahan', 'order' => 3, 'repeat' => 'ruta']);
        $this->addQuestions($b16, [
            ['1601', 'r1601', 'Banyaknya keluarga dalam rumah tangga', 'int', 'keluarga', []],
            ['1602', 'r1602', 'Status penguasaan bangunan tempat tinggal', 'code', '', [
                ['1', 'Milik sendiri'], ['2', 'Kontrak'], ['3', 'Sewa'],
                ['4', 'Bebas sewa'], ['5', 'Dinas/lainnya'],
            ]],
            ['1604', 'r1604', 'Luas lantai bangunan (m²)', 'int', 'm²', []],
            ['1606', 'r1606', 'Jenis atap terluas', 'code', '', [
                ['1', 'Beton'], ['2', 'Genteng'], ['3', 'Seng'], ['4', 'Asbes'],
                ['5', 'Sirap'], ['6', 'Ijuk/rumbia'], ['7', 'Lainnya'],
            ]],
            ['1607', 'r1607', 'Jenis dinding terluas', 'code', '', [
                ['1', 'Tembok'], ['2', 'Plesteran anyaman bambu/kawat'], ['3', 'Kayu/papan'],
                ['4', 'Anyaman bambu'], ['5', 'Batang kayu'], ['6', 'Bambu'], ['7', 'Lainnya'],
            ]],
            ['1608', 'r1608', 'Jenis lantai terluas', 'code', '', [
                ['1', 'Marmer/granit/keramik'], ['2', 'Ubin/tegel/teraso'], ['3', 'Kayu/karpet/lainnya'],
            ]],
            ['1605', 'r1605', 'Memiliki rumah/bangunan tempat tinggal di tempat lain', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1609A', 'r1609a', 'Fasilitas tempat buang air besar', 'code', '', [
                ['1', 'Sendiri'], ['2', 'Bersama'], ['3', 'Umum/MCK komunal'], ['4', 'Tidak ada'],
            ]],
            ['1609B', 'r1609b', 'Jenis kloset', 'code', '', [
                ['1', 'Leher angsa'], ['2', 'Plengsengan'], ['3', 'Cemplung/cubluk'], ['4', 'Tidak pakai'],
            ]],
            ['1609C', 'r1609c', 'Tempat pembuangan akhir tinja', 'code', '', [
                ['1', 'Tangki septik'], ['2', 'IPAL'], ['3', 'Kolam/sawah/kebun'],
                ['4', 'Sungai/danau/laut'], ['5', 'Lubang tanah'], ['6', 'Lainnya'],
            ]],
            ['1609E', 'r1609e', 'Pengurasan tangki septik 5 tahun terakhir', 'code', '', [
                ['1', 'Belum pernah'], ['2', '1 kali'], ['3', '2 kali'], ['4', '3 kali'],
                ['5', '4 kali'], ['6', '≥ 5 kali'],
            ]],
            ['1610A', 'r1610a', 'Sumber air minum utama (kode 1–11)', 'int', '', []],
            ['1610C', 'r1610c', 'Jarak sumber air ke pencemaran terdekat', 'code', '', [
                ['1', '< 10 m'], ['2', '≥ 10 m'], ['3', 'Tidak tahu'],
            ]],
            ['1611A', 'r1611a', 'Lokasi sumber air minum', 'code', '', [['1', 'Dalam kawasan rumah'], ['2', 'Luar kawasan rumah']]],
            ['1611B', 'r1611b', 'Waktu tempuh mengambil air minum (menit, PP)', 'int', 'menit', []],
            ['1612', 'r1612', 'Pernah mengalami kekeringan/kesulitan air setahun terakhir', 'code', '', [['1', 'Ya'], ['5', 'Tidak']]],
            ['1614A', 'r1614a', 'Sumber air utama untuk mandi/cuci (kode 1–11)', 'int', '', []],
            ['1616A', 'r1616a', 'Sumber penerangan utama', 'code', '', [
                ['1', 'Listrik PLN meteran'], ['2', 'Listrik PLN nonmeteran'],
                ['3', 'Listrik non-PLN'], ['4', 'Bukan listrik'],
            ]],
            ['1617', 'r1617', 'Bahan bakar/energi utama untuk memasak (kode 1–11)', 'int', '', []],
            ['1622', 'r1622', 'Perasaan aman berjalan sendirian di area tempat tinggal saat malam', 'code', '', [
                ['1', 'Sangat aman'], ['2', 'Aman'], ['3', 'Tidak aman'], ['4', 'Sangat tidak aman'],
                ['5', 'Tidak pernah keluar malam'], ['7', 'Lainnya'],
            ]],
        ]);

        // --- Blok XVII: Kepemilikan aset finansial & akses kredit (per rumah tangga) ---
        $b17 = Block::create(['qversion_id' => $qv->id, 'code' => 'XVII',
            'title' => 'Kepemilikan Aset Finansial dan Kredit', 'order' => 4, 'repeat' => 'ruta']);
        $yn17 = [['1', 'Ya'], ['5', 'Tidak']];
        $this->addQuestions($b17, [
            ['1701A', 'r1701a', 'Rumah tangga memiliki kredit dari bank', 'code', '', $yn17],
            ['1701B', 'r1701b', 'Rumah tangga memiliki kredit dari koperasi', 'code', '', $yn17],
            ['1701C', 'r1701c', 'Rumah tangga memiliki kredit dari perorangan/lainnya', 'code', '', $yn17],
            ['1701D', 'r1701d', 'Rumah tangga memiliki kredit dari pinjaman online', 'code', '', $yn17],
            ['1702A', 'r1702a', 'Rumah tangga memiliki dompet digital (e-wallet)', 'code', '', $yn17],
            ['1702B', 'r1702b', 'Rumah tangga menggunakan dompet digital (e-wallet)', 'code', '', $yn17],
        ]);

        // --- Blok XVIII: Kepemilikan barang tahan lama & aset (per rumah tangga, ringkas) ---
        $b18 = Block::create(['qversion_id' => $qv->id, 'code' => 'XVIII',
            'title' => 'Kepemilikan Barang dan Aset', 'order' => 5, 'repeat' => 'ruta']);
        $this->addQuestions($b18, [
            ['1801D', 'r1801d', 'Memiliki pemanas air (water heater)', 'code', '', $yn17],
            ['1801E', 'r1801e', 'Memiliki telepon rumah (PSTN)', 'code', '', $yn17],
            ['1801M', 'r1801m', 'Memiliki lahan/tanah', 'code', '', $yn17],
            ['1802', 'r1802_anak', 'Ada aset atas nama anak', 'code', '', [['1', 'Ya'], ['3', 'Sebagian'], ['5', 'Tidak']]],
        ]);

        // --- Blok XX: Perlindungan Sosial (per rumah tangga, ringkas) ---
        $b20 = Block::create(['qversion_id' => $qv->id, 'code' => 'XX',
            'title' => 'Perlindungan Sosial', 'order' => 6, 'repeat' => 'ruta']);
        $this->addQuestions($b20, [
            ['2001A', 'r2001a_iii', 'Jumlah ART penerima Program Indonesia Pintar (PIP)', 'int', 'orang', []],
            ['2003', 'r2003', 'Rumah tangga menerima Program Keluarga Harapan (PKH)', 'code', '', $yn17],
            ['2005', 'r2005', 'Rumah tangga menerima BPNT/Program Sembako', 'code', '', $yn17],
        ]);

        // --- Aturan anomali (terjemahan Sidona; ekspresi TRUE = ada masalah) ---
        $rules = [
            // [kode, scope, row_block, target, expr, severity, pesan]  — semua warning (deteksi anomali)
            ['SDN-03', 'anomali', 'ART', 'r409',
                'n(r404) > 1 and n(r409) > 0 and n(r409) < 13',
                'Umur saat perkawinan pertama < 13 tahun — tidak wajar.'],
            ['SDN-04', 'anomali', 'ART', 'r410',
                'n(r407) < 7 and n(r410) == _row_index',
                'Usia < 7 tahun tetapi menjadi pemberi informasi untuk dirinya sendiri.'],
            ['SDN-06', 'anomali', 'ART', 'r502',
                'n(r404) > 1 and n(r502) == 0',
                'ART berstatus kawin tetapi no. urut keluarga (R502) berkode 0.'],
            ['SDN-07', 'anomali', 'ART', 'r502',
                '(n(r403) == 3 or n(r403) == 4) and n(r404) == 1 and n(r502) != 1',
                'Anak belum kawin tetapi no. keluarga (R502) berbeda dengan KRT.'],
            ['SDN-08', 'anomali', 'ART', 'r502',
                '(n(r403) == 3 or n(r403) == 4) and n(r404) > 1 and n(r502) == 1',
                'Anak sudah kawin tetapi no. keluarga (R502) sama dengan KRT.'],
            ['SDN-09', 'anomali', 'ART', 'r502',
                'n(r403) > 4 and n(r502) == 1',
                'ART bukan istri/anak tetapi no. keluarga (R502) sama dengan KRT.'],
            ['SDN-14', 'anomali', 'ART', 'r503',
                'n(r403) == 7 and (n(r503) != 0 or n(r504) != 0)',
                'ART orang tua/mertua — no. ayah/ibu kandung (R503/R504) seharusnya 0.'],
            ['SDN-15', 'anomali', 'ART', 'r503',
                '(n(r403) == 5 or n(r403) == 8) and (n(r503) != 0 or n(r504) != 0)',
                'ART menantu/pembantu/sopir — no. ayah/ibu kandung (R503/R504) seharusnya 0.'],
            ['SDN-18', 'anomali', 'ART', 'r505',
                '(n(r501) == 1 or n(r501) == 2) and n(r505) == 5',
                'Memiliki akta nikah (R501) tetapi tidak memiliki NIK (R505).'],
            ['SDN-19', 'anomali', 'ART', 'r508',
                'n(r407) < 17 and n(r404) == 1 and n(r508) == 2',
                'NIK bersumber dari KTP-el (R508) tetapi usia belum 17 tahun.'],
            ['SDN-20', 'anomali', 'ART', 'r505',
                'n(r509) == 1 and n(r505) == 5',
                'Memiliki akta kelahiran (R509) tetapi tidak memiliki NIK (R505).'],
            ['SDN-21', 'anomali', 'ART', 'r610',
                'n(r509) > 4 and (n(r610) == 2 or n(r610) == 3)',
                'Tidak memiliki akta kelahiran (R509) tetapi sudah/pernah bersekolah (R610).'],
            ['SDN-22', 'anomali', 'ART', 'r610',
                'n(r505) == 5 and (n(r610) == 2 or n(r610) == 3)',
                'Tidak memiliki NIK (R505) tetapi sudah/pernah bersekolah (R610).'],
            ['SDN-23', 'anomali', 'ART', 'r610',
                'n(r407) >= 7 and n(r407) < 11 and n(r610) == 1',
                'Usia 7–10 tahun tetapi belum pernah bersekolah (R610).'],
            // --- gelombang 2: referensi antar-ART (fungsi roster_*) ---
            ['SDN-05', 'anomali', '', 'r501',
                'roster_count(r403, 1) == 1 and filled(roster_first(r501, r403, 2)) '
                . 'and roster_first(r501, r403, 1) != roster_first(r501, r403, 2)',
                'Isian akta nikah (R501) KRT berbeda dengan pasangannya.'],
            ['SDN-10', 'anomali', '', 'r502',
                'n(r301) == 1 and roster_first(r404, r403, 1) == 1 and n(roster_first(r502, r403, 1)) > 0',
                'ART tunggal belum kawin tetapi no. urut keluarga (R502) tidak 0.'],
            ['SDN-11', 'anomali', 'ART', 'r503',
                'n(r503) > 0 and (n(roster(r404, r503)) == 1 or n(roster(r405, r503)) == 1)',
                'ART yang ditunjuk sebagai ibu kandung (R503) belum kawin atau berjenis kelamin laki-laki.'],
            ['SDN-12', 'anomali', 'ART', 'r504',
                'n(r504) > 0 and (n(roster(r404, r504)) == 1 or n(roster(r405, r504)) == 2)',
                'ART yang ditunjuk sebagai ayah kandung (R504) belum kawin atau berjenis kelamin perempuan.'],
            ['SDN-13', 'anomali', 'ART', 'r503',
                'n(r403) == 3 and (n(r503) > 2 or n(r504) > 2)',
                'Anak kandung tetapi ibu/ayah kandung (R503/R504) bukan KRT atau pasangannya.'],
            ['SDN-16', 'anomali', 'ART', 'r503',
                'n(r403) == 3 and filled(r503) and n(r503) != 1 and n(r503) != 2',
                'Anak kandung tetapi no. ibu kandung (R503) bukan 1 atau 2.'],
            ['SDN-17', 'anomali', 'ART', 'r504',
                'n(r403) == 3 and filled(r504) and n(r504) != 1 and n(r504) != 2',
                'Anak kandung tetapi no. ayah kandung (R504) bukan 1 atau 2.'],
            ['SDN-34', 'anomali', 'ART', 'r619',
                'n(r619) == 1 and roster_all(r701, 5)',
                'ART menerima KIP (R619) tetapi tidak ada ART yang memiliki rekening (R701).'],
            ['SDN-35', 'anomali', 'ART', 'r702',
                'n(r701) == 1 and n(r702) == 5',
                'Memiliki rekening (R701) tetapi tidak menggunakan jasa keuangan (R702).'],
            ['SDN-67', 'anomali', '', 'r1601',
                'filled(r1601) and roster_max(r502) > n(r1601)',
                'No. urut keluarga terbesar (R502) melebihi jumlah keluarga (R1601).'],
            // Blok XVI (per rumah tangga)
            ['SDN-66', 'anomali', '', 'r1601',
                'filled(r1601) and filled(r301) and n(r1601) == n(r301) and n(r301) > 2',
                'Jumlah keluarga (R1601) sama persis dengan jumlah ART — apakah benar?'],
            ['SDN-68', 'anomali', '', 'r1602',
                'n(r1602) == 5',
                'Status penguasaan bangunan berkode "Dinas/lainnya" (R1602) — periksa.'],
            ['SDN-69', 'anomali', '', 'r1604',
                'filled(r1604) and (n(r1604) < 6 or n(r1604) > 500)',
                'Luas lantai bangunan (R1604) < 6 m² atau > 500 m² — periksa.'],
            ['SDN-70', 'anomali', '', 'r1604',
                'filled(r1604) and n(r301) > 0 and (n(r1604) / n(r301)) < 2',
                'Luas lantai per kapita < 2 m² (R1604 ÷ R301) — periksa.'],
            ['SDN-72', 'anomali', '', 'r1606',
                'n(r1606) == 7', 'Jenis atap berkode "Lainnya" (R1606) — periksa.'],
            ['SDN-73', 'anomali', '', 'r1607',
                'n(r1606) == 1 and n(r1607) != 1',
                'Atap beton (R1606) tetapi dinding bukan tembok (R1607) — periksa.'],
            ['SDN-76', 'anomali', '', 'r1607',
                'n(r1607) == 2', 'Dinding "plesteran anyaman bambu/kawat" (R1607) — periksa.'],
            ['SDN-77', 'anomali', '', 'r1608',
                'n(r1608) == 3', 'Lantai berkode "Kayu/karpet/lainnya" (R1608) — periksa.'],
            ['SDN-79', 'anomali', '', 'r1608',
                'n(r1608) == 1 and n(r1607) != 1',
                'Lantai marmer/granit/keramik (R1608) tetapi dinding bukan tembok (R1607) — periksa.'],

            // --- Gelombang 3: keaksaraan/pendidikan, ketenagakerjaan, TIK, kesehatan, rokok ---
            ['SDN-01', 'anomali', '', 'r_jam_mulai',
                'filled(r_jam_mulai) and (n(r_jam_mulai) < 6 or n(r_jam_mulai) > 22)',
                'Jam mulai wawancara (Blok II) tidak wajar (< 6 atau > 22).'],
            ['SDN-02', 'anomali', '', 'r_jam_selesai',
                'filled(r_jam_selesai) and (n(r_jam_selesai) < 6 or n(r_jam_selesai) > 23)',
                'Jam selesai wawancara (Blok II) tidak wajar (< 6 atau > 23).'],
            ['SDN-24', 'anomali', 'ART', 'r608',
                'n(r614) > 5 and n(r608) == 5 and n(r609) == 5',
                'Jenjang ijazah di atas SD (R614) tetapi tidak dapat baca-tulis (R608/R609) — periksa.'],
            ['SDN-25', 'anomali', 'ART', 'r611',
                '(n(r612) == 1 or n(r612) == 6 or n(r612) == 11) and filled(r611) and n(r611) != 1',
                'Sekolah di jenjang Paket A/B/C (R612) tetapi bukan negeri (R611) — periksa.'],
            ['SDN-27', 'anomali', 'ART', 'r613',
                'n(r614) == 8 and filled(r613) and filled(r615) and n(r613) != n(r615)',
                'Berstatus lulus (R614) tetapi kelas tertinggi (R613) tak sama dengan kelas ijazah (R615).'],
            ['SDN-36', 'anomali', 'ART', 'r704',
                'n(r407) > 9 and n(r403) == 8 and blank(r703a) and n(r704) == 5',
                'Status ART sebagai pembantu (R403=8) tetapi tidak bekerja (R703A kosong & R704=5).'],
            ['SDN-39', 'anomali', 'ART', 'r705',
                'n(r705) == 26',
                'Kategori lapangan usaha (R705) berkode 26 — tidak wajar, periksa.'],
            ['SDN-41', 'anomali', 'ART', 'r706',
                'n(r705) == 20 and filled(r706) and n(r706) != 4',
                'Lapangan usaha administrasi pemerintahan (R705=20) tetapi status bukan buruh/karyawan (R706≠4).'],
            ['SDN-43', 'anomali', 'ART', 'r407',
                'n(r705) == 20 and filled(r407) and n(r407) < 17',
                'Bekerja di administrasi pemerintahan (R705=20) tetapi umur < 17 tahun.'],
            ['SDN-44', 'anomali', 'ART', 'r708',
                'n(r704) == 1 and filled(r708) and n(r708) != 0',
                'Sementara tidak bekerja (R704=1) tetapi jam kerja seminggu (R708) bukan 0.'],
            ['SDN-45', 'anomali', 'ART', 'r801',
                'n(r802) == 1 and n(r801) == 5',
                'Menguasai/memiliki HP (R802=1) tetapi tidak menggunakannya (R801=5).'],
            ['SDN-53', 'anomali', 'ART', 'r1108',
                'filled(r1108) and n(r1108) > 20',
                'Frekuensi rawat jalan sebulan (R1108) lebih dari 20 kali — periksa.'],
            ['SDN-55', 'anomali', 'ART', 'r1206',
                'n(r1206) == 1 and n(r1207) == 1',
                'Merokok elektrik dan tembakau sama-sama setiap hari (R1206=R1207=1) — periksa.'],
            ['SDN-56', 'anomali', 'ART', 'r1206',
                'n(r410) == _row_index and (n(r1206) == 8 or n(r1207) == 8 or n(r1208) == 8)',
                'Pemberi informasi adalah ART ybs (R410) tetapi jawaban rokok "tidak tahu" (R1206/R1207/R1208=8).'],
            ['SDN-52', 'anomali', 'ART', 'r1002',
                'n(r1002) == 1 and n(r1006) == 1',
                'Sama sekali tidak bisa melihat (R1002=1) dan selalu sulit berkonsentrasi (R1006=1) — konfirmasi konsep.'],

            // --- Gelombang 4: konsistensi kualitas rumah & sanitasi (Blok XVI) ---
            ['SDN-71', 'anomali', '', 'r1605',
                'n(roster_first(r407, r403, 1)) > 0 and n(roster_first(r407, r403, 1)) < 17 and n(r1605) == 1',
                'KRT berumur < 17 tahun tetapi memiliki rumah/bangunan di tempat lain (R1605=1).'],
            ['SDN-74', 'anomali', '', 'r1607',
                'n(r1606) == 2 and n(r1607) == 5',
                'Atap genteng (R1606=2) tetapi dinding batang kayu (R1607=5) — periksa.'],
            ['SDN-75', 'anomali', '', 'r1607',
                'n(r1607) == 1 and n(r1606) > 5',
                'Dinding tembok (R1607=1) tetapi atap ijuk/rumbia/lainnya (R1606>5) — periksa.'],
            ['SDN-78', 'anomali', '', 'r1608',
                'n(r1608) == 1 and n(r1606) > 3',
                'Lantai marmer/keramik (R1608=1) tetapi atap asbes/sirap/lainnya (R1606>3) — periksa.'],
            ['SDN-80', 'anomali', '', 'r1609a',
                'n(r1608) == 1 and filled(r1609a) and n(r1609a) > 2',
                'Lantai marmer/keramik (R1608=1) tetapi fasilitas BAB umum/tidak ada (R1609A>2).'],
            ['SDN-81', 'anomali', '', 'r1609b',
                'n(r1608) == 1 and filled(r1609b) and n(r1609b) != 1',
                'Lantai marmer/keramik (R1608=1) tetapi kloset bukan leher angsa (R1609B≠1).'],
            ['SDN-82', 'anomali', '', 'r1609c',
                'n(r1608) == 1 and filled(r1609c) and n(r1609c) > 2',
                'Lantai marmer/keramik (R1608=1) tetapi pembuangan tinja tidak layak (R1609C>2).'],
            ['SDN-83', 'anomali', '', 'r1609c',
                'n(r1609c) == 2',
                'Pembuangan akhir tinja berkode IPAL (R1609C=2) — konfirmasi konsep.'],
            ['SDN-84', 'anomali', '', 'r1609e',
                'n(r1609e) > 3',
                'Tangki septik dikuras lebih dari 3 kali dalam 5 tahun (R1609E) — periksa.'],
            ['SDN-85', 'anomali', '', 'r1610a',
                'n(r1610a) == 11',
                'Sumber air minum utama berkode lainnya (R1610A=11) — periksa.'],
            ['SDN-86', 'anomali', '', 'r1611a',
                '(n(r1610a) == 1 or n(r1610a) == 2) and n(r1611a) == 1',
                'Air minum bermerek/isi ulang (R1610A) tetapi sumbernya dalam kawasan rumah (R1611A=1).'],
            ['SDN-87', 'anomali', '', 'r1610c',
                '(n(r1610a) == 7 or n(r1610a) == 8) and n(r1610c) == 1',
                'Air minum dari mata air (R1610A) tetapi jarak ke pencemaran < 10 m (R1610C=1).'],
            ['SDN-88', 'anomali', '', 'r1611b',
                'filled(r1611b) and n(r1611b) > 120',
                'Waktu mengambil air minum lebih dari 120 menit (R1611B) — periksa.'],
            ['SDN-89', 'anomali', '', 'r1612',
                'n(r1612) == 1',
                'Rumah tangga pernah mengalami kekeringan (R1612=1) — konfirmasi konsep.'],
            ['SDN-91', 'anomali', '', 'r1614a',
                'n(r1614a) == 1 or n(r1614a) == 2 or n(r1614a) == 11',
                'Sumber air mandi/cuci berkode kemasan/isi ulang/lainnya (R1614A) — periksa.'],
            ['SDN-92', 'anomali', '', 'r1616a',
                'n(r1616a) == 4',
                'Sumber penerangan utama bukan listrik (R1616A=4) — konfirmasi.'],
            ['SDN-93', 'anomali', '', 'r1617',
                'n(r1617) == 1 or n(r1617) == 5 or n(r1617) == 6 or n(r1617) == 8 or n(r1617) == 11',
                'Bahan bakar utama memasak tidak wajar (R1617) — periksa.'],
            ['SDN-96', 'anomali', '', 'r1622',
                'n(r1622) == 7',
                'Perasaan aman berjalan malam berkode lainnya (R1622=7) — periksa.'],
            ['SDN-108', 'anomali', 'ART', 'r405',
                'n(r403) == 2 and filled(r405) and n(r405) == n(roster_first(r405, r403, 1))',
                'Jenis kelamin istri/suami sama dengan KRT (R405) — kemungkinan salah isi / pernikahan sejenis.'],

            // --- Gelombang 5: inklusi keuangan rumah tangga, aset, perlindungan sosial ---
            ['SDN-97', 'anomali', '', 'r1701a',
                '(n(r1701a) == 1 or n(r1701b) == 1 or n(r1701c) == 1 or n(r1701d) == 1) and roster_all(r701, 5)',
                'Rumah tangga memiliki kredit (Blok XVII) tetapi tidak ada ART yang memiliki rekening (R701).'],
            ['SDN-98', 'anomali', '', 'r1702a',
                '(n(r1702a) == 1 or n(r1702b) == 1) and roster_all(r701, 5)',
                'Rumah tangga memiliki/menggunakan e-wallet (Blok XVII) tetapi tidak ada ART yang memiliki rekening (R701).'],
            ['SDN-99', 'anomali', '', 'r1801d',
                'n(r1801d) == 1',
                'Rumah tangga memiliki pemanas air/water heater (R1801D) — konfirmasi kewajaran.'],
            ['SDN-100', 'anomali', '', 'r1801e',
                'n(r1801e) == 1',
                'Rumah tangga memiliki telepon rumah/PSTN (R1801E) — konfirmasi kewajaran.'],
            ['SDN-101', 'anomali', '', 'r1801m',
                'n(r1602) == 1 and n(r1801m) == 5',
                'Rumah milik sendiri (R1602=1) tetapi rumah tangga tidak memiliki lahan/tanah (R1801M=5).'],
            ['SDN-105', 'anomali', '', 'r2001a_iii',
                'filled(r2001a_iii) and n(r2001a_iii) > 2',
                'Jumlah ART penerima PIP lebih dari 2 (R2001) — periksa.'],
            ['SDN-106', 'anomali', '', 'r2003',
                'n(r2003) == 1 and roster_all(r701, 5)',
                'Rumah tangga menerima PKH (R2003=1) tetapi tidak ada ART yang memiliki rekening (R701).'],
            ['SDN-107', 'anomali', '', 'r2005',
                'n(r2005) == 1 and roster_all(r701, 5)',
                'Rumah tangga menerima BPNT/Sembako (R2005=1) tetapi tidak ada ART yang memiliki rekening (R701).'],

            // --- Gelombang 8: teknologi informasi & komunikasi (Blok VIII) ---
            ['SDN-46', 'anomali', 'ART', 'r806c',
                'n(r806c) == 1 and filled(r703a) and n(r703a) != 1',
                'Mengakses internet di tempat kerja (R806C) tetapi tidak bekerja seminggu terakhir (R703A≠1).'],
            ['SDN-47', 'anomali', 'ART', 'r806d',
                'n(r407) > 4 and n(r806d) == 1 and filled(r610) and n(r610) != 2',
                'Mengakses internet di sekolah/kampus (R806D) tetapi tidak sedang bersekolah (R610≠2).'],
            ['SDN-48', 'anomali', 'ART', 'r806f',
                'n(r803a) == 1 and n(r806a) != 1 and n(r806b) != 1 and n(r806c) != 1 '
                . 'and n(r806d) != 1 and n(r806e) != 1 and n(r806f) == 1',
                'Mengakses internet dengan komputer/PC (R803A) tetapi hanya saat bergerak (R806F).'],
            ['SDN-49', 'anomali', 'ART', 'r806c',
                '(n(r407) < 10 and n(r806c) == 1) or (n(r407) < 5 and n(r806d) == 1)',
                'ART berumur sangat muda tetapi mengakses internet di tempat kerja/sekolah (R806).'],
            ['SDN-50', 'anomali', 'ART', 'r811f',
                'n(r811f) == 1 and roster_all(r701, 5)',
                'Menggunakan internet untuk transaksi keuangan (R811F) tetapi tidak ada ART yang memiliki rekening (R701).'],

            // --- Gelombang 7: imunisasi, ASI, MP-ASI, persalinan ---
            ['SDN-57', 'anomali', 'ART', 'r1305g',
                'n(r1305g) == 1',
                'Tempat imunisasi terisi "lainnya" (R1305G) — periksa.'],
            ['SDN-58', 'anomali', 'ART', 'r1306f',
                'n(r1306f) == 1',
                'Pemberi imunisasi terisi "lainnya" (R1306F) — periksa.'],
            ['SDN-59', 'anomali', 'ART', 'r1308a',
                'filled(r503) and n(r503) != 0 and n(r1308a) == 8',
                'Ibu kandung ada di rumah tangga (R503) tetapi "tidak tahu" apakah balita diberi ASI (R1308A=8).'],
            ['SDN-60', 'anomali', 'ART', 'r1312h',
                'filled(r1301) and n(r1301) < 6 and n(r1312h) == 1',
                'Balita umur < 6 bulan (R1301) tetapi sudah diberi makanan manis/berminyak (R1312H=1).'],
            ['SDN-61', 'anomali', 'ART', 'r1311',
                'n(r1311) == 1 and n(r1312a) != 1 and n(r1312b) != 1 and n(r1312c) != 1 and n(r1312d) != 1 '
                . 'and n(r1312e) != 1 and n(r1312f) != 1 and n(r1312g) != 1 and n(r1312h) != 1',
                'Baduta diberi MP-ASI (R1311=1) tetapi semua jenis makanan R1312 terisi "tidak".'],
            ['SDN-63', 'anomali', 'ART', 'r1404a',
                'n(r1404a) == 8 or n(r1404a) == 9',
                'Tempat melahirkan anak terakhir berkode rumah/lainnya (R1404A) — periksa.'],
            ['SDN-64', 'anomali', 'ART', 'r1404b',
                'n(r1404b) == 8',
                'Penolong persalinan terakhir berkode "tidak ada" (R1404B=8) — periksa.'],
            ['SDN-65', 'anomali', 'ART', 'r1404c',
                'filled(r1404a) and n(r1404a) > 0 and n(r1404a) < 4 and n(r1404c) == 5',
                'Melahirkan di RS/Puskesmas (R1404A) tetapi bayi tidak ditimbang saat lahir (R1404C=5).'],

            // --- Gelombang 6: pendidikan & ketenagakerjaan lanjutan ---
            ['SDN-26', 'anomali', 'ART', 'r612',
                'n(r612) == 2 or n(r612) == 7 or n(r612) == 12',
                'Jenjang pendidikan yang sedang diduduki (R612) berkode SLB — konfirmasi.'],
            ['SDN-37', 'anomali', 'ART', 'r703b',
                'n(r407) > 9 and n(r703b) == 1 and n(r610) != 2',
                'Ada kegiatan sekolah seminggu terakhir (R703B) tetapi tidak sedang bersekolah (R610≠2).'],
            ['SDN-40', 'anomali', 'ART', 'r705',
                'n(r407) > 9 and n(r403) == 8 and filled(r705) and n(r705) != 25',
                'Status ART sebagai pembantu (R403=8) tetapi lapangan usaha (R705) bukan 25 (aktivitas RT sebagai pemberi kerja).'],
            ['SDN-42', 'anomali', 'ART', 'r614',
                'n(r705) == 20 and filled(r614) and n(r614) < 6',
                'Bekerja di administrasi pemerintahan (R705=20) tetapi jenjang ijazah rendah (R614 < 6).'],
        ];
        foreach ($rules as [$code, $scope, $rowBlock, $target, $expr, $msg]) {
            ValidationRule::create(['qversion_id' => $qv->id, 'code' => $code, 'scope' => $scope,
                'row_block' => $rowBlock, 'target_var' => $target, 'expr' => $expr,
                'severity' => 'warning', 'message' => $msg, 'active' => true]);
        }

        $this->korDocuments($qv, $cycle, $users);
    }

    private function korDocuments(QuestionnaireVersion $qv, SurveyCycle $cycle, array $users): void
    {
        $editor = $users['siti_editor'];
        $qmap = Question::whereIn('block_id', Block::where('qversion_id', $qv->id)->pluck('id'))
            ->get()->keyBy('var_name');

        // 2 rumah tangga NYATA (dari contoh terisi Sidona) + 1 sintetis dengan anomali
        $ht = [
            [ // 51008 — YUDI (4 ART) — data nyata
                'krt' => 'YUDI', 'wil' => '3526010001', 'bs' => '010B', 'ruta' => '006',
                'ruta_ans' => ['r301' => '4', 'r1601' => '1', 'r1602' => '1', 'r1604' => '54',
                    'r1606' => '2', 'r1607' => '1', 'r1608' => '2'],
                'art' => [
                    ['r403' => '1', 'r404' => '2', 'r405' => '1', 'r407' => '46', 'r409' => '29', 'r410' => '1',
                     'r501' => '1', 'r502' => '1', 'r503' => '0', 'r504' => '0', 'r505' => '1', 'r509' => '1', 'r610' => '3'],
                    ['r403' => '2', 'r404' => '2', 'r405' => '2', 'r407' => '40', 'r409' => '23', 'r410' => '1',
                     'r501' => '1', 'r502' => '1', 'r503' => '0', 'r504' => '0', 'r505' => '1', 'r509' => '1', 'r610' => '3'],
                    ['r403' => '3', 'r404' => '1', 'r405' => '2', 'r407' => '14', 'r410' => '1',
                     'r502' => '1', 'r503' => '2', 'r504' => '1', 'r505' => '1', 'r509' => '1', 'r610' => '2', 'r613' => '8'],
                    ['r403' => '3', 'r404' => '1', 'r405' => '1', 'r407' => '13', 'r410' => '1',
                     'r502' => '1', 'r503' => '2', 'r504' => '1', 'r505' => '1', 'r509' => '1', 'r610' => '2', 'r613' => '7'],
                ],
            ],
            [ // 53906 — SUPINI (1 ART, janda) — data nyata
                'krt' => 'SUPINI', 'wil' => '3526010002', 'bs' => '011B', 'ruta' => '007',
                'ruta_ans' => ['r301' => '1', 'r1601' => '1', 'r1602' => '1', 'r1604' => '36',
                    'r1606' => '2', 'r1607' => '1', 'r1608' => '2'],
                'art' => [
                    ['r403' => '1', 'r404' => '4', 'r405' => '2', 'r407' => '69', 'r409' => '17', 'r410' => '1',
                     'r501' => '1', 'r502' => '1', 'r503' => '0', 'r504' => '0', 'r505' => '1', 'r509' => '1', 'r610' => '3'],
                ],
            ],
            [ // sintetis — sengaja memicu beberapa anomali
                'krt' => 'CONTOH ANOMALI', 'wil' => '3526020001', 'bs' => '012B', 'ruta' => '001',
                'ruta_ans' => ['r301' => '4', 'r1601' => '4', 'r1602' => '5', 'r1604' => '4',
                    'r1606' => '7', 'r1607' => '2', 'r1608' => '3',
                    'r_jam_mulai' => '3', 'r_jam_selesai' => '2',                                  // SDN-01, SDN-02
                    'r1609c' => '2', 'r1609e' => '5', 'r1610a' => '11', 'r1611b' => '150',
                    'r1612' => '1', 'r1614a' => '2', 'r1616a' => '4', 'r1617' => '8', 'r1622' => '7',   // SDN-83..96
                    'r1801d' => '1', 'r1801e' => '1', 'r2001a_iii' => '4',                             // SDN-99, SDN-100, SDN-105
                    'r1701a' => '1', 'r1702a' => '1', 'r2003' => '1', 'r2005' => '1'],                 // SDN-97, SDN-98, SDN-106, SDN-107 (r701 semua = 5)
                'art' => [
                    ['r403' => '1', 'r404' => '2', 'r405' => '1', 'r407' => '30', 'r409' => '25', 'r410' => '1',
                     'r501' => '1', 'r502' => '1', 'r505' => '5', 'r509' => '1', 'r610' => '3',
                     'r701' => '5', 'r702' => '5', 'r619' => '1',
                     'r801' => '5', 'r802' => '1', 'r705' => '20', 'r706' => '1', 'r1108' => '25',
                     'r703b' => '1', 'r614' => '3',
                     'r703a' => '5', 'r806c' => '1', 'r811f' => '1'],                                // +SDN-45,41,53,34,37,42,46,50
                    ['r403' => '2', 'r404' => '2', 'r405' => '1', 'r407' => '28', 'r409' => '11', 'r410' => '1',
                     'r501' => '5', 'r502' => '1', 'r505' => '1', 'r610' => '3', 'r701' => '5',
                     'r704' => '1', 'r708' => '40', 'r1206' => '1', 'r1207' => '1',
                     'r803a' => '1', 'r806f' => '1', 'r806a' => '5', 'r806b' => '5',
                     'r806c' => '5', 'r806d' => '5', 'r806e' => '5'],                                // +SDN-44,55,48
                    ['r403' => '3', 'r404' => '2', 'r405' => '2', 'r407' => '15', 'r410' => '1',
                     'r502' => '1', 'r503' => '3', 'r505' => '1', 'r610' => '2', 'r613' => '9', 'r701' => '5',
                     'r614' => '8', 'r615' => '3', 'r608' => '5', 'r609' => '5',
                     'r611' => '2', 'r612' => '6', 'r1002' => '1', 'r1006' => '1'],                  // +SDN-27, SDN-24, SDN-25, SDN-52
                    ['r403' => '7', 'r404' => '4', 'r405' => '2', 'r407' => '60', 'r410' => '4', 'r701' => '5',
                     'r502' => '5', 'r503' => '1', 'r505' => '1', 'r610' => '3',
                     'r705' => '26', 'r1207' => '8', 'r612' => '2', 'r806d' => '1'],                // +SDN-39,56,26,47
                    // ART 5 — pembantu (SDN-40) + persalinan tempat/penolong "lainnya" (SDN-63/64)
                    ['r403' => '8', 'r404' => '1', 'r405' => '2', 'r407' => '22', 'r410' => '1',
                     'r502' => '5', 'r505' => '1', 'r610' => '3', 'r701' => '5',
                     'r704' => '2', 'r705' => '24', 'r706' => '4',
                     'r1401a' => '1', 'r1402a' => '1', 'r1404a' => '8', 'r1404b' => '8'],           // +SDN-40, SDN-63, SDN-64
                    // ART 6 — balita: imunisasi/ASI/MP-ASI
                    ['r403' => '6', 'r404' => '1', 'r405' => '1', 'r407' => '0', 'r410' => '1',
                     'r502' => '1', 'r503' => '2', 'r505' => '5', 'r610' => '1', 'r701' => '5',
                     'r1301' => '3', 'r1305g' => '1', 'r1306f' => '1', 'r1308a' => '8', 'r1312h' => '1',
                     'r806c' => '1'],                                                              // +SDN-57,58,59,60,49
                    // ART 7 — baduta: MP-ASI tanpa jenis makanan
                    ['r403' => '6', 'r404' => '1', 'r405' => '2', 'r407' => '1', 'r410' => '1',
                     'r502' => '1', 'r505' => '5', 'r610' => '1', 'r701' => '5',
                     'r1301' => '18', 'r1311' => '1',
                     'r1312a' => '5', 'r1312b' => '5', 'r1312c' => '5', 'r1312d' => '5',
                     'r1312e' => '5', 'r1312f' => '5', 'r1312g' => '5', 'r1312h' => '5'],           // +SDN-61
                    // ART 8 — perempuan pernah kawin: lahir di faskes tapi bayi tidak ditimbang
                    ['r403' => '9', 'r404' => '2', 'r405' => '2', 'r407' => '27', 'r410' => '1',
                     'r502' => '1', 'r505' => '1', 'r610' => '3', 'r701' => '5',
                     'r1401a' => '1', 'r1402a' => '1', 'r1404a' => '2', 'r1404c' => '5'],           // +SDN-65
                ],
            ],
        ];

        foreach ($ht as $h) {
            $sample = SampleHousehold::create([
                'cycle_id' => $cycle->id, 'wilayah_code' => $h['wil'], 'no_bs' => $h['bs'],
                'no_ruta' => $h['ruta'], 'krt_name' => $h['krt'],
                'pml_id' => $users['rahmawati']->id, 'petugas_id' => $users['ppl_ani']->id,
                'status' => 'selesai',
            ]);
            $doc = Document::create(['sample_id' => $sample->id, 'qversion_id' => $qv->id,
                'status' => 'selesai', 'created_by' => $editor->id]);
            foreach ($h['ruta_ans'] as $var => $val) {
                if ($q = $qmap->get($var)) {
                    Answer::create(['document_id' => $doc->id, 'question_id' => $q->id,
                        'art_index' => 0, 'raw_value' => (string) $val]);
                }
            }
            foreach ($h['art'] as $ai => $art) {
                foreach ($art as $var => $val) {
                    if ($q = $qmap->get($var)) {
                        Answer::create(['document_id' => $doc->id, 'question_id' => $q->id,
                            'art_index' => $ai + 1, 'raw_value' => (string) $val]);
                    }
                }
            }
            app(RuleEngine::class)->run($doc);
            AuditLog::record('document', $doc->id, 'impor-kor',
                "rumah tangga {$h['krt']}", $editor->id);
        }
    }

    private function addQuestions(Block $block, array $defs): void
    {
        foreach ($defs as $i => [$rc, $var, $label, $qt, $unit, $opts]) {
            $q = Question::create(['block_id' => $block->id, 'code_rincian' => $rc, 'var_name' => $var,
                'label' => $label, 'qtype' => $qt, 'unit' => $unit, 'order' => $i + 1]);
            foreach ($opts as $j => [$code, $olabel]) {
                ResponseOption::create(['question_id' => $q->id, 'code' => $code, 'label' => $olabel, 'order' => $j + 1]);
            }
        }
    }

    private function ruleDefs(): array
    {
        return [
            ['POKOK-ART', 'dokumen', 'banyak_art', 'blank(banyak_art) or n(banyak_art) == 0',
                'error', 'Jumlah ART belum diisi atau bernilai 0.'],
            ['POKOK-WIL', 'dokumen', 'kode_wilayah', 'blank(kode_wilayah)',
                'error', 'Kode klasifikasi wilayah harus diisi.'],
            ['IV2-LISTRIK', 'konsistensi', 'iv2_listrik', 'blank(iv2_listrik) or n(iv2_listrik) == 0',
                'warning', 'Pengeluaran listrik kosong — hampir semua ruta punya. Cek kembali.'],
            ['IV2-SPP', 'konsistensi', 'iv2_spp',
                'n(ada_art_sekolah) == 1 and (blank(iv2_spp) or n(iv2_spp) == 0)',
                'error', 'Ada ART bersekolah — biaya SPP/UKT harus diisi.'],
            ['IV2-BUKU', 'konsistensi', 'iv2_buku',
                'n(ada_art_sekolah) == 1 and (blank(iv2_buku) or n(iv2_buku) == 0)',
                'warning', 'Ada ART bersekolah — cek biaya buku/alat tulis.'],
            ['IV2-PBB', 'konsistensi', 'iv2_pbb',
                'n(status_rumah) == 1 and (blank(iv2_pbb) or n(iv2_pbb) == 0)',
                'error', 'Rumah milik sendiri — PBB harus diisi.'],
            ['IV2-ASKES', 'konsistensi', 'iv2_asuransi_kesehatan',
                'n(ada_asuransi) == 1 and (blank(iv2_asuransi_kesehatan) or n(iv2_asuransi_kesehatan) == 0)',
                'warning', 'Punya asuransi kesehatan — cek premi/iuran setahun terakhir.'],
            ['IV2-PAKAIAN-ANAK', 'konsistensi', 'iv2_pakaian_anak',
                'n(ada_balita) == 1 and (blank(iv2_pakaian_anak) or n(iv2_pakaian_anak) == 0)',
                'warning', 'Ada balita — benarkah tidak ada pembelian pakaian anak selama setahun?'],
            ['IV2-INTERNET', 'konsistensi', 'iv2_internet',
                'n(iv2_internet) > 0 and n(iv2_pulsa_hp) == 0',
                'warning', 'Ada biaya internet tetapi tidak ada pulsa HP — cek konsistensi.'],
            ['QC-MISKIN', 'dokumen', '',
                'filled(banyak_art) and n(banyak_art) > 0 and pengeluaran_per_kapita > 0 and pengeluaran_per_kapita < n(garis_kemiskinan_kab)',
                'warning', 'Pengeluaran (subset) per kapita di bawah garis kemiskinan — ruta terindikasi miskin, verifikasi isian.'],
            ['ART-JUMLAH', 'roster', 'art_umur',
                'filled(banyak_art) and art_umur__n > 0 and n(banyak_art) != art_umur__n',
                'error', 'Banyaknya ART (R301) tidak sama dengan jumlah baris ART terisi di roster.'],
            ['ART-KRT', 'roster', 'art_hub_krt', 'art_hub_krt__n > 0 and art_hub_krt__count1 != 1',
                'error', 'Harus tepat satu ART dengan hubungan = 1 (kepala rumah tangga).'],
            ['ART-SEKOLAH-A', 'roster', 'art_sekolah',
                'n(ada_art_sekolah) == 1 and art_sekolah__n > 0 and art_sekolah__count2 == 0',
                'warning', 'Blok Pokok: ada ART bersekolah, tapi tidak ada ART dengan partisipasi = 2 di roster.'],
            ['ART-SEKOLAH-B', 'roster', 'art_sekolah',
                'n(ada_art_sekolah) != 1 and art_sekolah__count2 > 0',
                'warning', 'Ada ART masih bersekolah di roster, tapi Blok Pokok menyatakan tidak ada.'],
        ];
    }

    private function samples(SurveyCycle $cycle, array $users, array $desaCodes): array
    {
        $petugas = [$users['ppl_ani'], $users['ppl_dodi'], $users['ppl_eka']];
        $pmlByKec = ['3526010' => $users['rahmawati'], '3526020' => $users['santoso']];
        $krt = ['Sugianto', 'Hj. Maryam', 'Abdul Rahman', 'Suparno', 'Nur Hidayah',
            'Slamet Riyadi', 'Endang S.', 'Muhaimin', 'Rukiyah', 'Fauzan',
            'Painem', 'Hartono', 'Siti Aisyah', 'Bambang W.', 'Ngatinah', 'Yusuf'];
        $statuses = ['selesai', 'selesai', 'proses', 'proses', 'belum', 'belum', 'belum', 'non_respons'];

        $made = [];
        $idx = 0;
        foreach ($desaCodes as $desa) {
            $kec = substr($desa, 0, 7);
            foreach (['001B', '002B'] as $bs) {
                for ($r = 1; $r <= 4; $r++) {
                    $made[] = SampleHousehold::create([
                        'cycle_id' => $cycle->id, 'wilayah_code' => $desa, 'no_bs' => $bs,
                        'no_ruta' => sprintf('%03d', $r),
                        'krt_name' => $krt[$idx % count($krt)],
                        'petugas_id' => $petugas[$idx % 3]->id,
                        'pml_id' => $pmlByKec[$kec]->id,
                        'status' => $statuses[$idx % count($statuses)],
                    ]);
                    $idx++;
                }
            }
        }
        return $made;
    }

    private function documents(QuestionnaireVersion $qv, array $samples, array $users): void
    {
        $operator = $users['ppl_ani'];
        $engine = app(RuleEngine::class);

        $fixtures = [
            ['kode_wilayah' => '3526010001', 'banyak_art' => '4', 'ada_balita' => '1',
                'ada_art_sekolah' => '1', 'ada_art_usaha' => '5', 'ada_asuransi' => '1',
                'ada_merokok' => '1', 'status_rumah' => '1',
                'iv2_listrik' => '150000', 'iv2_air' => '40000', 'iv2_pulsa_hp' => '100000',
                'iv2_internet' => '0', 'iv2_buku' => '50000',
                'iv2_pakaian_anak' => '0', 'iv2_pbb' => '0', 'iv2_asuransi_kesehatan' => '0',
                'v_produksi_dikonsumsi_setahun' => '0', 'v_transfer_barang_setahun' => '0',
                'v_total_penerimaan' => '8000000', 'v_total_pengeluaran' => '8000000'],
            ['kode_wilayah' => '3526010001', 'banyak_art' => '2', 'ada_balita' => '5',
                'ada_art_sekolah' => '5', 'ada_art_usaha' => '1', 'ada_asuransi' => '5',
                'ada_merokok' => '5', 'status_rumah' => '2',
                'iv2_listrik' => '90000', 'iv2_air' => '25000', 'iv2_pulsa_hp' => '60000',
                'iv2_internet' => '0', 'iv2_buku' => '0', 'iv2_pakaian_anak' => '120000',
                'iv2_asuransi_kesehatan' => '0',
                'v_produksi_dikonsumsi_setahun' => '0', 'v_transfer_barang_setahun' => '0',
                'v_total_penerimaan' => '5000000', 'v_total_pengeluaran' => '5100000'],
            ['kode_wilayah' => '', 'banyak_art' => '0',
                'ada_balita' => '5', 'ada_art_sekolah' => '5', 'ada_art_usaha' => '5',
                'ada_asuransi' => '5', 'ada_merokok' => '5', 'status_rumah' => '3',
                'iv2_listrik' => '0', 'iv2_air' => '0', 'iv2_pulsa_hp' => '0',
                'v_produksi_dikonsumsi_setahun' => '0', 'v_transfer_barang_setahun' => '0',
                'v_total_penerimaan' => '5000000', 'v_total_pengeluaran' => '8000000'],
            ['kode_wilayah' => '3526020001', 'banyak_art' => '5', 'ada_balita' => '1',
                'ada_art_sekolah' => '1', 'ada_art_usaha' => '1', 'ada_asuransi' => '1',
                'ada_merokok' => '1', 'status_rumah' => '1',
                'iv2_listrik' => '200000', 'iv2_air' => '55000', 'iv2_pulsa_hp' => '150000',
                'iv2_internet' => '200000', 'iv2_spp' => '300000', 'iv2_buku' => '80000',
                'iv2_pakaian_anak' => '250000', 'iv2_pbb' => '180000', 'iv2_asuransi_kesehatan' => '600000',
                'v_produksi_dikonsumsi_setahun' => '0', 'v_transfer_barang_setahun' => '500000',
                'v_total_penerimaan' => '10000000', 'v_total_pengeluaran' => '10200000'],
        ];

        // Blok IV.1 (repeat='list'): row_index => [kolom => nilai]
        // baris: 1 Beras, 2 Telur, 3 Daging ayam, 4 Bayam, 6 Tempe, 8 Minyak, 9 Gula,
        //        10 Cabai merah, 13 Ikan tongkol, 15 Mie instan, 16 Rokok
        $iv1 = [
            [ // doc 1 — lengkap & wajar
                1 => ['iv1_kuantitas' => '14', 'iv1_nilai_beli' => '196000'],
                2 => ['iv1_kuantitas' => '2', 'iv1_nilai_beli' => '56000'],
                3 => ['iv1_kuantitas' => '1', 'iv1_nilai_beli' => '36000'],
                6 => ['iv1_kuantitas' => '2', 'iv1_nilai_beli' => '30000'],
                8 => ['iv1_kuantitas' => '1.5', 'iv1_nilai_beli' => '27000'],
                9 => ['iv1_kuantitas' => '1', 'iv1_nilai_beli' => '16000'],
                15 => ['iv1_kuantitas' => '7', 'iv1_nilai_beli' => '24500'],
            ],
            [ // doc 2 — harga rokok di luar rentang
                1 => ['iv1_kuantitas' => '7', 'iv1_nilai_beli' => '98000'],
                8 => ['iv1_kuantitas' => '1', 'iv1_nilai_beli' => '17000'],
                9 => ['iv1_kuantitas' => '0.5', 'iv1_nilai_beli' => '8000'],
                16 => ['iv1_kuantitas' => '4', 'iv1_nilai_beli' => '140000'],
            ],
            [ // doc 3 — nilai terisi tapi kuantitas kosong; ada produksi sendiri
                1 => ['iv1_kuantitas' => '0', 'iv1_nilai_beli' => '30000', 'iv1_nilai_lain' => '50000', 'iv1_asal_lain' => '1'],
                4 => ['iv1_nilai_beli' => '0', 'iv1_nilai_lain' => '10000', 'iv1_asal_lain' => '1'],
            ],
            [ // doc 4 — harga cabai di luar rentang; ada transfer barang
                1 => ['iv1_kuantitas' => '17', 'iv1_nilai_beli' => '238000'],
                2 => ['iv1_nilai_beli' => '0', 'iv1_nilai_lain' => '30000', 'iv1_asal_lain' => '2'],
                8 => ['iv1_kuantitas' => '2', 'iv1_nilai_beli' => '36000'],
                10 => ['iv1_kuantitas' => '0.5', 'iv1_nilai_beli' => '50000'],
                13 => ['iv1_kuantitas' => '2', 'iv1_nilai_beli' => '64000'],
            ],
        ];
        $rosters = [
            [
                ['art_nama' => 'Sugianto', 'art_hub_krt' => '1', 'art_jk' => '1', 'art_umur' => '41', 'art_sekolah' => '3', 'art_bekerja' => '1'],
                ['art_nama' => 'Wati', 'art_hub_krt' => '2', 'art_jk' => '2', 'art_umur' => '38', 'art_sekolah' => '3', 'art_bekerja' => '2'],
                ['art_nama' => 'Rina', 'art_hub_krt' => '3', 'art_jk' => '2', 'art_umur' => '9', 'art_sekolah' => '2', 'art_bekerja' => '2'],
                ['art_nama' => 'Bayu', 'art_hub_krt' => '3', 'art_jk' => '1', 'art_umur' => '1', 'art_sekolah' => '1', 'art_bekerja' => '2'],
            ],
            [
                ['art_nama' => 'Hartono', 'art_hub_krt' => '1', 'art_jk' => '1', 'art_umur' => '55', 'art_sekolah' => '3', 'art_bekerja' => '1'],
                ['art_nama' => 'Endang', 'art_hub_krt' => '2', 'art_jk' => '2', 'art_umur' => '52', 'art_sekolah' => '3', 'art_bekerja' => '1'],
            ],
            [
                ['art_nama' => 'Painem', 'art_hub_krt' => '1', 'art_jk' => '2', 'art_umur' => '67', 'art_sekolah' => '1', 'art_bekerja' => '2'],
            ],
            [
                ['art_nama' => 'Yusuf', 'art_hub_krt' => '1', 'art_jk' => '1', 'art_umur' => '45', 'art_sekolah' => '3', 'art_bekerja' => '1'],
                ['art_nama' => 'Aisyah', 'art_hub_krt' => '1', 'art_jk' => '2', 'art_umur' => '42', 'art_sekolah' => '3', 'art_bekerja' => '2'],
                ['art_nama' => 'Bima', 'art_hub_krt' => '3', 'art_jk' => '1', 'art_umur' => '17', 'art_sekolah' => '2', 'art_bekerja' => '2'],
                ['art_nama' => 'Sari', 'art_hub_krt' => '3', 'art_jk' => '2', 'art_umur' => '14', 'art_sekolah' => '2', 'art_bekerja' => '2'],
                ['art_nama' => 'Adi', 'art_hub_krt' => '3', 'art_jk' => '1', 'art_umur' => '8', 'art_sekolah' => '2', 'art_bekerja' => '2'],
            ],
        ];

        $targets = array_slice(array_values(array_filter($samples,
            fn ($s) => in_array($s->status, ['selesai', 'proses']))), 0, count($fixtures));
        $qmap = Question::whereIn('block_id',
            Block::where('qversion_id', $qv->id)->pluck('id'))->get()->keyBy('var_name');

        foreach ($targets as $di => $sample) {
            $doc = Document::create(['sample_id' => $sample->id, 'qversion_id' => $qv->id,
                'status' => 'draft', 'created_by' => $operator->id]);
            foreach ($fixtures[$di] as $var => $val) {
                if ($q = $qmap->get($var)) {
                    Answer::create(['document_id' => $doc->id, 'question_id' => $q->id,
                        'art_index' => 0, 'raw_value' => (string) $val]);
                }
            }
            foreach ($rosters[$di] as $ai => $art) {
                foreach ($art as $var => $val) {
                    if ($q = $qmap->get($var)) {
                        Answer::create(['document_id' => $doc->id, 'question_id' => $q->id,
                            'art_index' => $ai + 1, 'raw_value' => (string) $val]);
                    }
                }
            }
            foreach ($iv1[$di] ?? [] as $rowIdx => $cells) {
                foreach ($cells as $var => $val) {
                    if ($q = $qmap->get($var)) {
                        Answer::create(['document_id' => $doc->id, 'question_id' => $q->id,
                            'art_index' => $rowIdx, 'raw_value' => (string) $val]);
                    }
                }
            }
            $sum = $engine->run($doc);
            if ($sample->status === 'selesai' && $sum['errors'] === 0) {
                $doc->update(['status' => 'selesai']);
            }
            // dok 1 disimulasikan sudah difinalisasi lalu aturan berubah -> masuk antrean pengolahan
            if ($di === 0) {
                $doc->update(['status' => 'selesai']);
            }
            AuditLog::record('document', $doc->id, 'entri-awal',
                "{$sum['errors']} error, {$sum['warnings']} warning", $operator->id);
        }

        // konsistensi: sampel 'proses'/'selesai' tanpa dokumen tidak masuk akal —
        // kembalikan ke 'belum' (dokumen fixture hanya dibuat untuk sebagian).
        $withDoc = Document::whereIn('sample_id', collect($samples)->pluck('id'))->pluck('sample_id');
        SampleHousehold::whereIn('id', collect($samples)->pluck('id'))
            ->whereNotIn('id', $withDoc)
            ->whereIn('status', ['proses', 'selesai'])
            ->update(['status' => 'belum']);
    }
}

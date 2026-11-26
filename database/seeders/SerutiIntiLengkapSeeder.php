<?php

namespace Database\Seeders;

use App\Models\Aggregate;
use App\Models\Block;
use App\Models\BlockRow;
use App\Models\DerivedField;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\ResponseOption;
use App\Models\ValidationRule;
use Illuminate\Database\Seeder;

/**
 * Inc 156 — melengkapi kuesioner SERUTI INTI (VSERUTI26.INTI) dari PDF
 * "Kuesioner Seruti Inti 2026 (VSERUTI26.INTI)": Blok III, IV.2.B, IV.3, V (501–505), VI, VII.
 * Blok IV.1 tidak dibuat: di PDF, TW I & III terintegrasi dengan SUSENAS KP, TW II & IV memakai
 * kuesioner SERUTI MAK (terpisah).
 *
 * Idempoten & aman diulang: hanya menambah blok yang BELUM ada (per kode blok) ke setiap versi
 * berkode VSERUTI26.INTI* (termasuk -TW2/-TW3/-TW4), versi milik siklus terkunci dilewati.
 *   php artisan db:seed --class=Database\\Seeders\\SerutiIntiLengkapSeeder
 *
 * Catatan pemodelan (perlu dicek dengan kuesioner cetak/CAPI resmi):
 *  - 501 & 502 dimodelkan per anggota rumah tangga (satu baris per ART); di kertas satu ART bisa
 *    punya lebih dari satu usaha — sistem belum mendukung daftar dinamis selain ART.
 *  - Kolom tabel 505 ditafsirkan dari PDF: bangunan tempat tinggal · bangunan bukan tempat tinggal
 *    untuk usaha · non-bangunan untuk usaha (kendaraan, mesin, dsb) · lahan.
 *  - Baris JUMLAH tidak diisi petugas: dihitung sebagai variabel turunan.
 */
class SerutiIntiLengkapSeeder extends Seeder
{
    public function run(): void
    {
        foreach (QuestionnaireVersion::where('code', 'like', 'VSERUTI26.INTI%')->get() as $qv) {
            if ($qv->locked()) {
                continue;
            }
            // satu transaksi per versi: gagal di tengah = tidak ada blok setengah jadi
            \Illuminate\Support\Facades\DB::transaction(fn () => $this->complete($qv));
        }
    }

    private function complete(QuestionnaireVersion $qv): void
    {
        $have = Block::where('qversion_id', $qv->id)->pluck('code')->flip();
        if (isset($have['IV2B'])) {
            return;   // sudah dilengkapi
        }
        // urutan blok mengikuti kuesioner
        $order = ['POKOK' => 0, 'III' => 1, 'IV2A' => 2, 'IV2B' => 3, 'IV3' => 4, 'V1' => 5, 'V2' => 6,
            'V3' => 7, 'V4' => 8, 'V5' => 9, 'VI' => 10, 'VII' => 11];
        Block::where('qversion_id', $qv->id)->where('code', 'IV2A')->update(['order' => $order['IV2A']]);

        $mkBlock = fn (string $code, string $title, string $repeat) => Block::create([
            'qversion_id' => $qv->id, 'code' => $code, 'title' => $title, 'order' => $order[$code], 'repeat' => $repeat]);
        $q = function (Block $b, int $ord, string $kode, string $var, string $label, string $type = 'decimal',
            ?string $unit = 'Rp', array $opts = [], ?string $skip = null, ?string $help = null) {
            $question = Question::create(['block_id' => $b->id, 'code_rincian' => $kode, 'var_name' => $var,
                'label' => $label, 'qtype' => $type, 'unit' => $unit ?? '', 'order' => $ord, 'skip_expr' => $skip ?? '',
                'help_text' => $help ?? '']);
            $i = 0;
            foreach ($opts as $code => $lbl) {
                ResponseOption::create(['question_id' => $question->id, 'code' => (string) $code, 'label' => $lbl, 'order' => ++$i]);
            }

            return $question;
        };
        $rows = function (Block $b, array $list) {
            foreach ($list as $i => $r) {
                BlockRow::create(['block_id' => $b->id, 'row_index' => $i + 1, 'code' => $r[0], 'label' => $r[1],
                    'order' => $i + 1, 'ref' => $r[2] ?? null]);
            }
        };
        $yaTidak = [1 => 'Ya', 5 => 'Tidak'];
        $lapus = [1 => 'Pertanian tanaman padi dan palawija', 2 => 'Hortikultura', 3 => 'Perkebunan', 4 => 'Perikanan',
            5 => 'Peternakan', 6 => 'Kehutanan dan pertanian lainnya', 7 => 'Pertambangan dan penggalian',
            8 => 'Industri pengolahan', 9 => 'Pengadaan listrik, gas, uap/air panas, dan udara dingin',
            10 => 'Pengelolaan air, air limbah, sampah, dan remediasi', 11 => 'Konstruksi',
            12 => 'Perdagangan besar dan eceran, reparasi mobil dan sepeda motor', 13 => 'Pengangkutan dan pergudangan',
            14 => 'Penyediaan akomodasi dan makan minum', 15 => 'Informasi dan komunikasi',
            16 => 'Aktivitas keuangan dan asuransi', 17 => 'Real estate', 18 => 'Aktivitas profesional, ilmiah, dan teknis',
            19 => 'Penyewaan, ketenagakerjaan, agen perjalanan, penunjang usaha', 20 => 'Administrasi pemerintahan, pertahanan, jaminan sosial wajib',
            21 => 'Pendidikan', 22 => 'Kesehatan manusia dan aktivitas sosial', 23 => 'Kesenian, hiburan, dan rekreasi',
            24 => 'Aktivitas jasa lainnya', 25 => 'Rumah tangga sebagai pemberi kerja', 26 => 'Badan internasional/ekstra internasional'];
        $jenis = [0 => 'TNI dan POLRI', 1 => 'Manajer', 2 => 'Tenaga profesional', 3 => 'Teknisi dan asisten profesional',
            4 => 'Tenaga tata usaha', 5 => 'Tenaga usaha jasa dan penjualan', 6 => 'Pekerja terampil pertanian, kehutanan, perikanan',
            7 => 'Pekerja pengolahan dan kerajinan', 8 => 'Operator dan perakitan mesin', 9 => 'Pekerja kasar'];

        // ---------- Blok III: keterangan anggota rumah tangga
        $b = $mkBlock('III', 'Keterangan Anggota Rumah Tangga', 'art');
        $q($b, 1, '(2)', 'sart_nama', 'Nama anggota rumah tangga', 'text', null);
        $q($b, 2, '(3)', 'sart_hub', 'Hubungan dengan kepala rumah tangga', 'code', null, [1 => 'Kepala rumah tangga',
            2 => 'Istri/suami', 3 => 'Anak kandung/tiri', 4 => 'Anak angkat', 5 => 'Menantu', 6 => 'Cucu',
            7 => 'Orang tua/mertua', 8 => 'Pembantu/sopir/pekerja domestik', 9 => 'Lainnya']);
        $q($b, 3, '(4)', 'sart_jk', 'Jenis kelamin', 'code', null, [1 => 'Laki-laki', 2 => 'Perempuan']);
        $q($b, 4, '(5)', 'sart_umur', 'Umur (tahun; ≥97 tulis 97)', 'int', 'tahun');
        $q($b, 5, '(6)', 'sart_kawin', 'Status perkawinan', 'code', null, [1 => 'Belum kawin', 2 => 'Kawin', 3 => 'Cerai hidup', 4 => 'Cerai mati']);
        $q($b, 6, '(7)', 'sart_ijazah', 'Ijazah/STTB tertinggi (umur 5 tahun ke atas)', 'code', null, [
            '01' => 'Paket A', '02' => 'SDLB', '03' => 'SD', '04' => 'MI', '05' => 'SPM/PDF Ula', '06' => 'Paket B',
            '07' => 'SMP LB', '08' => 'SMP', '09' => 'MTs', '10' => 'SPM/PDF Wustha', '11' => 'Paket C', '12' => 'SMLB',
            '13' => 'SMA', '14' => 'MA', '15' => 'SMK', '16' => 'MAK', '17' => 'SPM/PDF Ulya', '18' => 'D1/D2',
            '19' => 'D3', '20' => 'D4', '21' => 'S1', '22' => 'Profesi', '23' => 'S2', '24' => 'S3', '25' => 'Tidak punya ijazah SD']);

        // ---------- Blok IV.2.B: kesehatan & pendidikan tiga bulan
        $b = $mkBlock('IV2B', 'Pengeluaran Kesehatan dan Pendidikan Tiga Bulan Terakhir', 'list');
        $rows($b, [
            ['452', 'Kesehatan [453 + 454 + 455]', ['kel' => 'Kesehatan', 'level' => 'subtotal']],
            ['453', 'Obat-obatan dan produk kesehatan (obat resep/bebas, vitamin, jamu, kacamata, kursi roda, alat KB, dsb)', ['kel' => 'Kesehatan', 'level' => 'detail']],
            ['454', 'Biaya rawat jalan dan laboratorium (konsultasi, ambulans, pengobatan tradisional, imunisasi, dsb)', ['kel' => 'Kesehatan', 'level' => 'detail']],
            ['455', 'Biaya rawat inap (biaya kamar, konsultasi, obat, dsb yang tidak dapat dipisahkan)', ['kel' => 'Kesehatan', 'level' => 'detail']],
            ['456', 'Pendidikan [457 + 458 + 459 + 460 + 461 + 462]', ['kel' => 'Pendidikan', 'level' => 'subtotal']],
            ['457', 'Biaya pra sekolah', ['kel' => 'Pendidikan', 'level' => 'detail']],
            ['458', 'Biaya sekolah dasar (SD)/sederajat', ['kel' => 'Pendidikan', 'level' => 'detail']],
            ['459', 'Biaya sekolah menengah pertama (SMP)/sederajat', ['kel' => 'Pendidikan', 'level' => 'detail']],
            ['460', 'Biaya sekolah menengah atas (SMA)/sederajat', ['kel' => 'Pendidikan', 'level' => 'detail']],
            ['461', 'Biaya pendidikan tinggi', ['kel' => 'Pendidikan', 'level' => 'detail']],
            ['462', 'Biaya kursus dan bimbingan belajar di luar sekolah', ['kel' => 'Pendidikan', 'level' => 'detail']],
            ['463', 'TOTAL PENGELUARAN KESEHATAN DAN PENDIDIKAN [452 + 456]', ['kel' => 'Total', 'level' => 'total']],
        ]);
        $q($b, 1, '(3)', 'siv2b_total', 'Total (Rp, tiga bulan)');
        $q($b, 2, '(4)', 'siv2b_oop', 'Out of Pocket / dibayar sendiri (Rp, tiga bulan)');

        // ---------- Blok IV.3: persepsi kondisi ekonomi
        $b = $mkBlock('IV3', 'Persepsi terhadap Kondisi Ekonomi Rumah Tangga Triwulan Ini', 'ruta');
        $q($b, 1, '470a', 'sv3_470a', 'Apakah pendapatan rumah tangga pada triwulan ini dapat memenuhi konsumsi selama triwulan ini?', 'code', null, $yaTidak);
        $skip470 = 'n(sv3_470a) != 5';
        $q($b, 2, '470b.i', 'sv3_470b1', 'Sumber pendanaan: mengambil sisa simpanan triwulan sebelumnya?', 'code', null, $yaTidak, $skip470);
        $q($b, 3, '470b.ii', 'sv3_470b2', 'Sumber pendanaan: melakukan kredit/pinjaman?', 'code', null, $yaTidak, $skip470);
        $q($b, 4, '470b.iii', 'sv3_470b3', 'Sumber pendanaan: menjual aset/barang tahan lama?', 'code', null, $yaTidak, $skip470);
        $ubah = [1 => 'Meningkat', 2 => 'Tetap', 3 => 'Menurun', 5 => 'Tidak relevan'];
        $ord = 5;
        foreach ([
            '464' => 'Kecenderungan pengeluaran konsumsi rumah tangga dibanding triwulan sebelumnya',
            '465' => 'Kecenderungan konsumsi bahan makanan/minuman dibanding triwulan sebelumnya',
            '466' => 'Kecenderungan konsumsi makanan/minuman jadi dari restoran/warung dibanding triwulan sebelumnya',
            '467' => 'Kecenderungan belanja online dibanding triwulan sebelumnya',
            '468' => 'Kecenderungan upah/gaji yang diterima dibanding triwulan sebelumnya',
            '469' => 'Kecenderungan keuntungan usaha rumah tangga dibanding triwulan sebelumnya',
        ] as $no => $lbl) {
            $opts = $no === '464' ? array_slice($ubah, 0, 3, true) : $ubah;
            $q($b, $ord++, $no, "sv3_{$no}", $lbl, 'code', null, $opts);
            $q($b, $ord++, "{$no}(4)", "sv3_{$no}_pct", "Persentase perubahan R{$no}", 'decimal', '%', [],
                "not (n(sv3_{$no}) == 1 or n(sv3_{$no}) == 3)");
        }

        // ---------- Blok V: 501 upah/gaji per ART, 502 usaha per ART
        $b = $mkBlock('V1', 'V.501 Pekerjaan dengan Upah/Gaji Tiga Bulan Terakhir (ART 10 tahun ke atas)', 'art');
        $q($b, 1, '(2)', 'sv1_uraian', 'Uraian pekerjaan', 'text', null);
        $q($b, 2, '(3)', 'sv1_lapus', 'Kategori lapangan usaha', 'code', null, $lapus);
        $q($b, 3, '(4)', 'sv1_jenis', 'Jenis pekerjaan', 'code', null, $jenis);
        $q($b, 4, '(5)', 'sv1_uang', 'Upah/gaji dalam bentuk uang (gaji, tunjangan, uang makan, dsb)');
        $q($b, 5, '(6)', 'sv1_barang', 'Upah/gaji dalam bentuk barang/jasa (rumah dinas, kendaraan dinas, makanan, dsb)');
        $q($b, 6, '(7)', 'sv1_lembur', 'Lembur, honorarium, THR, dsb');

        $b = $mkBlock('V2', 'V.502 Usaha Rumah Tangga Tiga Bulan Terakhir (ART 10 tahun ke atas)', 'art');
        $q($b, 1, '(2)', 'sv2_uraian', 'Uraian kegiatan usaha', 'text', null);
        $q($b, 2, '(3)', 'sv2_lapus', 'Kategori lapangan usaha', 'code', null, $lapus);
        $q($b, 3, '(4)', 'sv2_jenis', 'Jenis pekerjaan (kode 0 tidak berlaku untuk usaha)', 'code', null, array_slice($jenis, 1, null, true));
        $q($b, 4, '(5)', 'sv2_produksi', 'Nilai produksi');
        $q($b, 5, '(6)', 'sv2_biaya', 'Biaya produksi (bahan baku/penolong, listrik, transportasi, upah, sewa, dsb)');
        $q($b, 6, '(7)', 'sv2_pct_upah', 'Persentase biaya produksi yang dibayarkan untuk upah/gaji', 'decimal', '%');
        $q($b, 7, '(8)', 'sv2_stok', 'Stok akhir: produksi belum terjual + bahan baku/penolong belum digunakan');

        // ---------- 503 pendapatan kepemilikan
        $b = $mkBlock('V3', 'V.503 Pendapatan Kepemilikan Tiga Bulan Terakhir', 'list');
        $rows($b, [
            ['1', 'Sewa lahan'],
            ['2', 'Keuntungan atas kepemilikan modal pada usaha rumah tangga dan usaha berbentuk CV, Firma, UD, PD, dsj'],
            ['3', 'Keuntungan atas kepemilikan saham pada PT, Yayasan, PT Persero, dan Koperasi (dividen)'],
            ['4', 'Bunga (bunga simpanan, bunga pinjaman)'],
        ]);
        $q($b, 1, '(3)', 'sv3_terima', 'Diterima (Rp)');
        $q($b, 2, '(4)', 'sv3_bayar', 'Dibayar (Rp)');

        // ---------- 504 transfer berjalan
        $b = $mkBlock('V4', 'V.504 Transfer Berjalan Tiga Bulan Terakhir', 'list');
        $rows($b, [
            ['1a', 'Uang pensiun pemerintah'],
            ['1b', 'Bantuan pemerintah (BPJS PBI, BLT, PKH, BOS, MBG, dll), pembayaran zakat ke BAZNAS'],
            ['2', 'Rumah tangga lain'],
            ['3', 'Lainnya (klaim asuransi, penerimaan/iuran pensiun, sumbangan dari/ke lembaga nirlaba dan luar negeri)'],
        ]);
        $q($b, 1, '(3)', 'sv4_terima_uang', 'Diterima: uang (Rp)');
        $q($b, 2, '(4)', 'sv4_terima_barang', 'Diterima: barang/jasa (Rp)');
        $q($b, 3, '(5)', 'sv4_beri_uang', 'Dibayar/diberikan: uang (Rp)');
        $q($b, 4, '(6)', 'sv4_beri_barang', 'Dibayar/diberikan: barang/jasa (Rp)');

        // ---------- 505 aset rumah tangga/usaha rumah tangga
        $b = $mkBlock('V5', 'V.505 Aset Rumah Tangga/Usaha Rumah Tangga', 'list');
        $rows($b, [
            ['1', 'Penambahan: pembelian/pembuatan sendiri (tiga bulan terakhir)'],
            ['2', 'Penambahan: pemberian dari pihak lain (tiga bulan terakhir)'],
            ['3', 'Perbaikan/pengurukan dan biaya pemindahan kepemilikan lahan (tiga bulan terakhir)'],
            ['4', 'Pengurangan: penjualan (tiga bulan terakhir)'],
            ['5', 'Pengurangan: memberikan kepada pihak lain (tiga bulan terakhir)'],
            ['6', 'Nilai seluruh aset yang dimiliki saat pendataan (harga saat pendataan)'],
            ['7', 'Nilai pembelian/perolehan dari aset yang dimiliki (saat dimiliki/dibeli/diterima)'],
        ]);
        $q($b, 1, '(3)', 'sv5_rumah', 'Bangunan tempat tinggal (rumah, apartemen, rusun, kontrakan, kos-kosan, vila, dll)');
        $q($b, 2, '(4)', 'sv5_bangunan_usaha', 'Bangunan bukan tempat tinggal untuk usaha rumah tangga (toko, warung, bengkel, warnet, dll)');
        $q($b, 3, '(5)', 'sv5_nonbangunan_usaha', 'Non-bangunan untuk usaha rumah tangga (kendaraan, mesin, peralatan, tanaman/hewan menghasilkan berulang, dll)');
        $q($b, 4, '(6)', 'sv5_lahan', 'Lahan (tempat tinggal, usaha, dan lainnya)');

        // ---------- Blok VI: posisi aset & kewajiban keuangan
        $b = $mkBlock('VI', 'Posisi Aset dan Kewajiban Keuangan Rumah Tangga saat Pencacahan', 'ruta');
        $aset = [
            ['601', 'sv6_601', 'Uang tunai (dompet, brankas, e-money/e-wallet)'],
            ['602a', 'sv6_602a', 'Simpanan (tabungan, giro, deposito): bank & lembaga keuangan lainnya'],
            ['602b', 'sv6_602b', 'Simpanan: koperasi simpan pinjam/credit union'],
            ['602c', 'sv6_602c', 'Simpanan: lainnya (tabungan di sekolah, tabungan RT, dll)'],
            ['603', 'sv6_603', 'Sisa piutang'],
            ['604a', 'sv6_604a', 'Surat berharga: saham terdaftar di Bursa Efek'],
            ['604b', 'sv6_604b', 'Surat berharga: saham tidak terdaftar di Bursa Efek'],
            ['604c', 'sv6_604c', 'Surat berharga lainnya (SUN, obligasi, reksadana, dll)'],
            ['605a', 'sv6_605a', 'Penyertaan modal usaha: di perusahaan tidak berbadan hukum (CV, Firma, UD, PD, dsb)'],
            ['605b', 'sv6_605b', 'Penyertaan modal usaha: di usaha rumah tangga milik pihak lain'],
            ['606', 'sv6_606', 'Sisa kredit barang yang belum diterima (jika memiliki usaha rumah tangga)'],
            ['607', 'sv6_607', 'Jumlah iuran arisan yang telah dibayar dari arisan yang sedang aktif diikuti'],
        ];
        $kewajiban = [
            ['608a', 'sv6_608a', 'Sisa pinjaman belum dibayar: bank dan lembaga pembiayaan'],
            ['608b', 'sv6_608b', 'Sisa pinjaman belum dibayar: koperasi simpan pinjam/credit union'],
            ['608c', 'sv6_608c', 'Sisa pinjaman belum dibayar: lainnya (rentenir, pinjaman online, dsb)'],
            ['609', 'sv6_609', 'Nilai modal usaha milik pihak lain (jika memiliki usaha rumah tangga)'],
            ['610', 'sv6_610', 'Sisa kredit barang yang belum dibayar'],
            ['611a', 'sv6_611a', 'Sisa nilai gadaian belum dibayar: di PT Pegadaian (Persero)'],
            ['611b', 'sv6_611b', 'Sisa nilai gadaian belum dibayar: di tempat gadai barang lainnya'],
            ['612', 'sv6_612', 'Jumlah uang arisan yang telah diterima dari arisan yang sedang aktif diikuti'],
        ];
        $ord = 0;
        foreach (array_merge($aset, $kewajiban) as [$no, $var, $lbl]) {
            $q($b, ++$ord, $no, $var, $lbl);
        }

        // ---------- Blok VII: catatan
        $b = $mkBlock('VII', 'Catatan', 'ruta');
        $q($b, 1, 'VII.1', 'sv7_kunjungan1', 'Tanggal kunjungan I (dd/mm/2026)', 'text', null);
        $q($b, 2, 'VII.2', 'sv7_kunjungan2', 'Tanggal kunjungan II (dd/mm/2026)', 'text', null);
        $q($b, 3, 'VII.3', 'sv7_catatan', 'Catatan pencacahan', 'text', null);

        // ---------- bahan hitungan & aturan awal
        foreach ([
            ['siv2b_detail_total', 'Σ rincian rinci IV.2.B · total', 'siv2b_total', 'detail'],
            ['siv2b_input_total', 'Isian TOTAL (R463) · total', 'siv2b_total', 'total'],
            ['siv2b_detail_oop', 'Σ rincian rinci IV.2.B · out of pocket', 'siv2b_oop', 'detail'],
            ['siv2b_input_oop', 'Isian TOTAL (R463) · out of pocket', 'siv2b_oop', 'total'],
        ] as $i => [$name, $label, $var, $lvl]) {
            Aggregate::create(['qversion_id' => $qv->id, 'name' => $name, 'label' => $label, 'block_code' => 'IV2B',
                'value_var' => $var, 'op' => 'sum', 'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '',
                'filter_ref' => 'level', 'filter_ref_val' => $lvl, 'order' => 20 + $i]);
        }
        $sumOf = fn (array $list) => 'sum('.implode(', ', array_map(fn ($x) => $x[1], $list)).')';
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'srt_posisi_aset',
            'label' => 'Blok VI · jumlah posisi aset [601 + … + 607] (Rp)', 'expr' => $sumOf($aset), 'order' => 50]);
        DerivedField::create(['qversion_id' => $qv->id, 'var_name' => 'srt_posisi_kewajiban',
            'label' => 'Blok VI · jumlah posisi kewajiban [608 + … + 612] (Rp)', 'expr' => $sumOf($kewajiban), 'order' => 51]);

        foreach ([
            ['SRT-IV2B-TOTAL', 'konsistensi', '', 'siv2b_input_total',
                'siv2b_input_total > 0 and abs(siv2b_input_total - siv2b_detail_total) > 1000', 'warning',
                'TOTAL kesehatan & pendidikan (R463) tidak sama dengan jumlah rinciannya (kolom total).'],
            ['SRT-IV2B-OOP-TOTAL', 'konsistensi', '', 'siv2b_input_oop',
                'siv2b_input_oop > 0 and abs(siv2b_input_oop - siv2b_detail_oop) > 1000', 'warning',
                'TOTAL out of pocket (R463) tidak sama dengan jumlah rinciannya.'],
            ['SRT-IV2B-OOP', 'konsistensi', 'IV2B', 'siv2b_oop',
                'filled(siv2b_oop) and filled(siv2b_total) and n(siv2b_oop) > n(siv2b_total)', 'warning',
                'Pengeluaran out of pocket lebih besar dari total pada rincian yang sama.'],
            ['SRT-IV3-470B', 'dokumen', '', 'sv3_470a',
                'n(sv3_470a) == 5 and blank(sv3_470b1) and blank(sv3_470b2) and blank(sv3_470b3)', 'warning',
                'Pendapatan tidak memenuhi konsumsi (470.a = Tidak), tetapi sumber pendanaan 470.b belum diisi.'],
            ['SRT-V2-PCT', 'anomali', 'V2', 'sv2_pct_upah',
                'filled(sv2_pct_upah) and (n(sv2_pct_upah) < 0 or n(sv2_pct_upah) > 100)', 'error',
                'Persentase biaya produksi untuk upah/gaji harus 0–100%.'],
        ] as [$code, $scope, $rowBlock, $target, $expr, $sev, $msg]) {
            ValidationRule::create(['qversion_id' => $qv->id, 'code' => $code, 'scope' => $scope, 'row_block' => $rowBlock,
                'target_var' => $target, 'expr' => $expr, 'severity' => $sev, 'message' => $msg, 'active' => true]);
        }
    }
}

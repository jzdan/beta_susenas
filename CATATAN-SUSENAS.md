# Sistem SUSENAS — versi Laravel

Port dari prototipe Python (FastAPI). Aplikasi internal BPS untuk **monitoring
lapangan** dan **entri + validasi data** SUSENAS. Struktur kuesioner & aturan
validasi disimpan sebagai **metadata di basis data** — kuesioner periode baru
cukup menambah versi, bukan mengubah kode.

## Menjalankan / tes di browser

```bash
cd C:\laragon\www\susenas
php artisan migrate:fresh --seed     # sekali saja / saat mau reset data
php artisan serve                    # → http://127.0.0.1:8000
```

Atau: **Laragon → Start All** → `http://susenas.test` (Laragon auto-serve folder `www`).

Login `admin` / `admin123`. Tiga kuesioner nyata tersedia:
`VSEN27.KP` (demo bertingkat), `VSEN26.K` (KOR — 33 aturan Sidona),
`VSEN26.KP` (KP — Blok IV.1 **194 komoditas** nyata).
Buka **Pengolahan** untuk melihat dokumen contoh + anomali.

**Basis data:** SQLite (`database/database.sqlite`). Untuk MySQL, ubah `DB_*` di `.env`
lalu `php artisan migrate:fresh --seed` — kode tidak berubah.

**Catatan Laragon:** `pdo_sqlite` & `sqlite3` sudah diaktifkan di
`C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.ini` (backup: `php.ini.bak-susenas`).

## Akun demo

| Username    | Sandi         | Peran                                |
|-------------|---------------|---------------------------------------|
| `admin`     | `admin123`    | Administrator                        |
| `pj_kamal`  | `pj123`       | PJ Kecamatan Kamal, wilayah 3526010   |
| `rahmawati` | `pengawas123` | Pengawas (PML), wilayah 3526         |
| `ppl_ani`   | `operator123` | Operator entri                       |
| `viewer`    | `viewer123`   | Peninjau                             |

## Yang sudah diport (setara prototipe Python) + Increment 6

- **Monitoring:** dashboard, peta progres skematik, daftar sampel + filter,
  ubah status ruta, log audit, impor Wilkerstat & daftar sampel dari CSV
- **Entri + validasi:** 5 blok contoh (Pokok ringkas + roster ART + IV.2 subset
  + **IV.1 daftar komoditas** + **Blok V neraca**), form dari metadata, skip
  pattern, autosave per rincian/sel, panel warning + nilai turunan langsung,
  `answer_histories`, finalisasi diblokir bila ada error
- **Admin:** CRUD blok/rincian/aturan/parameter/**agregat**, impor rincian CSV,
  uji ekspresi terhadap dokumen, duplikasi versi kuesioner

### Increment 6 — cek-silang gaya NERACA

- `blocks.repeat = 'list'` — blok dengan baris tetap (`block_rows`), mis. daftar komoditas
- Tabel `aggregates` — konfigurasi **agregasi terkelompok** atas blok berulang:
  "jumlah kolom X yang kolom filter-nya = kode tertentu". Hasil disuntik ke
  konteks sebagai skalar → aturan NERACA cukup ditulis sebagai ekspresi biasa.
- `RuleEngine::computeAggregate()` — sum/count/max/min dengan filter eq/in/ne/any
- Contoh: `iv1_produksi_sendiri` = Σ `iv1_nilai_lain` dari IV.1 dengan `iv1_asal_lain = 1`;
  turunan `neraca_min_produksi_sendiri` = `round(iv1_produksi_sendiri * 30/7*12, 0)`;
  aturan `NERACA-PRODUKSI` = `n(v_produksi_dikonsumsi_setahun) < neraca_min_produksi_sendiri`.
- **Terbukti:** pola 600-baris rumus NERACA di Excel bisa dinyatakan sebagai
  metadata (list block + aggregate + derived + rule). Menskalakan ke versi
  penuh = tambah komoditas (impor CSV) + tambah agregat (form admin), bukan kode.

### Increment 5 — komoditas IV.1 + kalori/harga

- `block_rows.ref` (JSON) — data referensi per komoditas (`kalori`, `harga_min`, `harga_max`)
- **Impor baris CSV** di editor blok list (`row_index,label` + opsional `code,kalori,harga_min,harga_max`)
  — daftar 186 komoditas cukup ditempel, bukan kode. Contoh seed = 16 komoditas.
- Agregat **`weighted_sum`** dengan `weight_ref` — `kalori_total` = Σ (`iv1_kuantitas` × `ref.kalori`)
- Turunan `kalori_per_kapita_hari`; aturan `KALORI-UNDER` / `KALORI-OVER` (ambang dari parameter)
- **Aturan per-baris** (`validation_rules.row_block`) — dievaluasi sekali per baris
  blok, konteks lokal = nilai kolom baris + `ref_<kunci>`. Contoh:
  `IV1-HARGA` (harga satuan `nilai ÷ kuantitas` di luar `ref_harga_min..ref_harga_max`),
  `IV1-KUANTITAS` (nilai terisi tapi kuantitas kosong). Warning muncul di sel/baris terkait.

### Increment 8 — pengolahan (cleaning) + kesiapan produksi

- **Modul Pengolahan** (`/pengolahan`) — peran `editor`/pml/admin: antrean dokumen,
  tabel rincian bermasalah (termasuk yang belum ada jawaban), koreksi pada
  **`clean_value`** dengan **alasan wajib** (nilai mentah tetap), tandai `bersih`
  (terkunci, `cleaned_by`/`cleaned_at`), Admin dapat buka kunci.
- Peran baru **`editor`**; `User::ROLE_LABELS`.
- **Header keamanan** (`SecurityHeaders` middleware) — CSP, X-Frame-Options DENY,
  nosniff, Referrer-Policy, HSTS saat HTTPS.
- **Rate-limit login** — `throttle:8,1`.
- **Uji otomatis** — `tests/Unit/ExpressionEvaluatorTest` (12 kasus benar + 9 tolakan
  ekspresi berbahaya), `tests/Feature/RuleEngineTest` (baseline hasil aturan,
  NERACA/per-baris, koreksi clean_value, snapshot). `php artisan test` → 27 lulus.
- **Seeder produksi** — `php artisan db:seed --class=MetadataSeeder` (struktur + 1
  admin, tanpa data dummy).
- **Manajemen pengguna** (`/admin/pengguna`) — tambah/sunting/aktif-nonaktifkan
  akun (username, nama, peran, wilayah scope, sandi). Akun nonaktif ditolak saat
  login; admin tak bisa menonaktifkan diri sendiri.
- Dokumen: `docs/SRS-ringkas.md`, `docs/matriks-rbac.md`, `docs/skenario-uat.md`.

### Increment 9 — impor dokumen massal (format lebar / ala ekspor CAPI)

- `/admin/impor-dokumen` — satu baris CSV = satu rumah tangga. Kolom kunci
  `wilayah_code,no_bs,no_ruta`; sisanya nama variabel. Blok berulang pakai
  akhiran `var__1`, `var__2` (nomor ART / baris).
- Cocokkan/buat `sample_household` + `document`, buat/`updateOrCreate` seluruh
  `answers`, jalankan `RuleEngine` (hasil error/warning + snapshot per dokumen).
- Laporan: dibuat / diperbarui / dilewati (alasan) + total error/warning.
- Jalur ini yang dipakai untuk memuat ekspor CAPI/FASIH ke sistem tanpa entri ulang.

### Increment 10 — kuesioner KOR nyata (VSEN26.K) + aturan anomali Sidona

- **Versi kuesioner ke-2: `VSEN26.K`** (SUSENAS Maret 2026 KOR) berdampingan
  dengan versi demo KP. Blok nyata: III Ringkasan, IV–VI Keterangan ART (roster
  16 rincian: demografi + dokumen kependudukan + pendidikan), XVI Perumahan.
- **23 aturan anomali** hasil terjemahan dari sheet *Anomali* tool **Sidona**
  (108 aturan sumber) — 14 per-ART (`row_block = 'ART'`, pakai `_row_index`) +
  9 per-rumah-tangga. Pemetaan lengkap: `docs/pemetaan-aturan-sidona.md`.
- **3 rumah tangga contoh**: 2 data **nyata** dari berkas contoh terisi Sidona
  (YUDI 4 ART, SUPINI 1 ART) — keduanya bersih dari anomali; 1 sintetis
  (`CONTOH ANOMALI`) yang sengaja memicu 13 anomali (per-ART & per-ruta).
- Membuktikan: sistem menangani **beberapa kuesioner nyata sekaligus**, dan
  aturan validasi BPS yang sesungguhnya dapat dinyatakan sebagai ekspresi data.
- `php artisan test` → 28 lulus (termasuk uji anomali nyata vs sintetis).

### Increment 11 — referensi antar-ART + aturan Sidona gelombang 2

- **Fungsi helper roster di ekspresi** (`RuleEngine::ROSTER_FUNCS`):
  `roster(var, idx)` (nilai `var` untuk ART ke-`idx` — untuk pointer R503/R504),
  `roster_count(var, kode)`, `roster_all/any(var, kode)`,
  `roster_first(nilaiVar, kunciVar, kode)`, `roster_max/sum(var)`.
  Argumen nama variabel ditulis polos (`roster_count(r403, 1)`) — evaluator
  meneruskannya sebagai string, bukan mengevaluasi (`ID_ARG_POSITIONS`).
- `ExpressionEvaluator::evaluate($expr, $ctx, $extraFuncs)` — fungsi tambahan
  per-evaluasi; `checkSyntax` menerima daftar nama fungsi sah.
- **+10 aturan Sidona** (SDN-05, 10, 11, 12, 13, 16, 17, 34, 35, 67) — total
  **33 aturan KOR**. Termasuk cek "ibu kandung yang ditunjuk R503 berjenis
  kelamin laki-laki", "akta nikah KRT ≠ pasangan", dll.
- **+Blok VII** (ringkas: R701 rekening, R702 jasa keuangan, R704 bekerja, R706 status).
- Rumah tangga sintetis kini memicu 19 jenis anomali; YUDI & SUPINI tetap bersih.
- `php artisan test` → **29 lulus, 55 assertion** (+ uji fungsi roster).

### Increment 12 — Blok IV.1 lengkap (194 komoditas nyata)

- **Versi kuesioner ke-3: `VSEN26.KP`** (SUSENAS Maret 2026 KP).
- **Blok IV.1 = 194 komoditas nyata** — nama, kelompok, **kalori (kkal/satuan)**,
  dan **rentang harga wajar** diekstrak dari sheet KALORI & RH tool WTF ke
  `database/data/iv1_komoditas.csv`, lalu dimuat lewat impor CSV (bukan kode).
- Importer `block_rows` digeneralisasi: **semua kolom CSV** selain
  `row_index/code/label` disimpan sebagai data referensi.
- Agregat `kalori_total` (`weighted_sum` × `ref.kalori`), turunan
  `kalori_per_kapita_hari`, aturan `KALORI-UNDER/OVER` + `IV1-HARGA`/`IV1-KUANTITAS` per-baris.
- Dokumen contoh: form entri 194 baris × 4 kolom render < 0,5 dtk; harga telur
  di luar rentang → warning per-baris menyala.

### Increment 13 — rekap Blok IV.1 per kelompok + tema gelap/terang

- **Tombol tema** sistem→terang→gelap di topbar (+ tombol mengambang untuk tamu);
  `data-theme` pada `<html>`, `localStorage` `susenas-theme`, skrip pra-render di
  `<head>` mencegah kedip. Palet gelap di `@media (prefers-color-scheme)` **dan**
  `:root[data-theme="dark"]`.
- **Agregat bisa disaring lewat nilai referensi baris** — kolom baru
  `aggregates.filter_ref` / `filter_ref_val` (mis. `group = "IKAN"`).
  `RuleEngine::computeAggregate()` mencocokkan `block_rows.ref[filter_ref]`.
- **Rekap otomatis 14 kelompok komoditas** (PADI-PADIAN … ROKOK DAN TEMBAKAU):
  per kelompok subtotal **nilai** (`sum iv1_nilai_beli`) & **kalori**
  (`weighted_sum × ref.kalori`) → 28 agregat dibuat dari daftar kelompok CSV, bukan kode.
- Turunan `iv1_nilai_rekap_kelompok` + aturan **`REKAP-IV1-NILAI`** (error bila
  Σ subtotal ≠ total belanja) dan **`REKAP-IV1-ROKOK`** (warning bila belanja
  rokok > 25% belanja makanan).
- Form entri Blok IV.1 kini menampilkan **baris header kelompok** di tabel daftar;
  sidebar entri menampilkan panel **"Rekap per kelompok"** yang ikut diperbarui autosave.

### Increment 14 — Blok IV.3.2 Rekapitulasi Makanan (kuesioner nyata VSEN26.KP)

- Struktur diambil dari PDF kuesioner **`2. 2025.11.02 VSEN26.KP Maret 2026.pdf`**
  (ekstraktor `pdftext.php`). Blok IV.3.2 = rekap pengeluaran makanan:
  kolom (3) pembelian ← IV.1, kolom (4) produksi sendiri/pemberian ← IV.1,
  kolom (5) = (3)+(4), R.15 SUB JUMLAH, R.16 = R.15 × 30/7.
- Agregat baru: `iv1_lain_minggu` (Σ `iv1_nilai_lain`) + 14 subtotal
  `iv1_lain_<kelompok>` (disaring `ref.group`).
- Turunan: `iv1_lain_rekap_kelompok`, `rekap_iv32_subjumlah`
  (= `iv1_belanja_minggu` + `iv1_lain_minggu`), `rekap_makanan_sebulan`
  (`rekap_iv32_subjumlah × 30/7`).
- Aturan `REKAP-IV1-LAIN` (error bila Σ subtotal kolom-4 ≠ total produksi sendiri).
- Total agregat versi KP = 45; 30 tes lulus (tes rekap diperluas untuk IV.3.2).

### Increment 15 — Blok IV.2 (bukan makanan) + rekap IV.3.3 + garis kemiskinan

- **Blok IV.2 = ~95 rincian nyata** (R.228–R.347, 6 seksi A–F) diekstrak dari PDF
  kuesioner VSEN26.KP ke `database/data/iv2_komoditas.csv` (kode COICOP + periode
  bulan/tahun + seksi), digenerate ulang lewat `scripts/build_iv2_csv.php`,
  dimuat sebagai blok `list` dengan header seksi (reuse mekanisme Inc 13).
- 2 kolom: `iv2_rupiah_bulan`, `iv2_rupiah_tahun`.
- **12 agregat subtotal seksi** (`iv2_bulan_<seksi>`, `iv2_tahun_<seksi>`) +
  2 total. **Blok IV.3.3**: 6 turunan `iv33_<seksi>` = sebulan + setahun/12,
  lalu `rekap_bukan_makanan_sebulan` (R.8), `rekap_ruta_sebulan` (R.9 =
  makanan + bukan makanan), `pengeluaran_per_kapita_bulan`.
- Aturan `IV2-PERIODE` (per-baris: nilai di kolom periode yang salah) dan
  `QC-MISKIN-KP` (per kapita < garis kemiskinan kabupaten). Dokumen contoh:
  per kapita 492.828 < 550.000 → QC-MISKIN-KP menyala.
- Versi KP kini: 3 blok (IV.1, IV.2, POKOK), 59 agregat, 15 turunan, 8 aturan.
  31 tes lulus.

### Increment 16 — Blok V (baris jumlah) + Blok VI Rekapitulasi + sidebar tetap

- **Blok V** dimodelkan ringkas sebagai blok `ruta` berisi 10 baris jumlah
  (V.A upah/gaji, V.B surplus usaha, V.C produksi dikonsumsi sendiri,
  V.D–V.F diterima/dibayar, V.G aset neto) — cukup untuk menyuapi Blok VI.
- **Blok VI** = turunan: `vi_konsumsi_setahun` (= Blok IV.3.3 R.9 × 12),
  `vi_penerimaan`, `vi_pengeluaran`, `vi_selisih`.
- Aturan `NERACA-VI` (warning bila |selisih| > 10% penerimaan). Dokumen contoh
  seimbang: penerimaan 24,8 jt vs pengeluaran 24,16 jt → selisih 2,6% (tidak menyala).
- **Sidebar navigasi kiri kini tetap** (`position: sticky; top: 45px` + scroll
  internal) — sebelumnya ikut ter-scroll di semua halaman termasuk admin.
- Versi KP: 4 blok (POKOK, IV.1, IV.2, V), 59 agregat, 19 turunan, 9 aturan.
  32 tes lulus.

### Increment 17 — penimbang & estimasi tertimbang

- Kolom `sample_households.weight` (design weight, nullable). Importer sampel
  menerima kolom `weight`/`penimbang`; seeder mengisi penimbang dummy.
- Tabulasi: 3 agregasi baru — **Estimasi populasi (Σ penimbang)**,
  **Rata-rata tertimbang** (Σwx/Σw), **Total tertimbang** (Σwx). Dokumen tanpa
  penimbang dikecualikan + diberi tahu jumlahnya.
- Ekspor data datar menambah kolom `penimbang`.
- Peringatan eksplisit: design weight belum dikalibrasi/raking → hanya untuk
  pemantauan arah angka, bukan estimasi resmi. 34 tes lulus.

### Increment 18 — jadwal lapangan & pemantauan kecepatan

- Kolom `survey_cycles.field_start` / `field_end`. Halaman **Admin · Data →
  Jadwal Lapangan** untuk mengatur tanggal + status siklus.
- Dashboard: panel **Jadwal & kecepatan pencacahan** per siklus —
  target kemajuan (= proporsi hari lapangan berjalan) vs realisasi,
  selisih poin, hari tersisa, sampel **butuh/hari** untuk tepat waktu,
  penanda **Tertinggal** bila realisasi < target − 5 poin.
- Status siklus: persiapan → lapangan → pengolahan → selesai.
- 35 tes lulus (tes baru: siklus tertinggal ditandai + butuh/hari benar).

### Increment 19 — worklist validasi lintas dokumen + revalidasi massal

- Halaman **Pengolahan** dapat panel **Rekap temuan validasi**: setiap kode
  aturan → jumlah dokumen kena + total temuan, error di atas warning.
- Filter `?rule=KODE` → daftar dokumen dipangkas ke yang kena aturan itu
  (worklist sistematis untuk PML).
- Tombol **↻ Jalankan ulang aturan** (pml/admin) — `RuleEngine::run()` untuk
  semua dokumen non-bersih dalam cakupan; idempoten untuk data yang tak berubah.
- Relasi `RuleResult::rule()` ditambahkan.
- 38 tes lulus (3 tes baru: rekap, filter aturan, revalidasi pml-only).

### Increment 20 — impor dokumen: mode pratinjau (dry-run) + laporan kolom

- Impor dokumen massal (ekspor CAPI) kini punya **Pratinjau**: menjalankan
  seluruh proses (buat sampel/dokumen, isi jawaban, jalankan validasi) di dalam
  transaksi lalu **rollback** — tidak ada yang tersimpan.
- Laporan: jumlah baris, dokumen baru vs diperbarui, total error/warning,
  daftar **kolom dikenal & terpakai** dan **kolom TIDAK dikenal** (+ berapa baris).
- Tombol terpisah **Pratinjau** / **Impor & simpan**; CSV & siklus dipertahankan
  setelah pratinjau supaya bisa langsung commit.
- 40 tes lulus (2 tes baru: pratinjau tidak persist + lapor mapping, commit persist).

### Increment 21 — Blok VII Transaksi Keuangan + identitas neraca VI↔VII

- **Blok VII** (ruta): 5 baris penerimaan + 5 pengeluaran transaksi keuangan
  (tabungan, pinjaman, kredit barang, dll.) dari kuesioner VSEN26.KP.
- Turunan `vii_penerimaan`, `vii_pengeluaran`, `vii_selisih`
  (= pengeluaran − penerimaan = tabungan neto).
- Aturan `NERACA-VII`: identitas akuntansi — surplus pendapatan Blok VI harus
  terserap oleh transaksi keuangan neto Blok VII (toleransi 15% dari magnitudo
  terbesar). Dokumen contoh: selisih VI 644.280 vs VII 645.000 → cocok.
- Kuesioner KP kini 5 blok isian (POKOK, IV.1, IV.2, V, VII) + rekap IV.3.2/3.3/VI.
  41 tes lulus.

### Increment 22 — Blok I & II KP + cek identitas + fungsi tanggal

- **Metadata sampel di konteks aturan**: `sampel_wilayah`, `sampel_bs`,
  `sampel_ruta`, `sampel_krt` (dari `document.sample`).
- Fungsi baru **`ymd("2026-03-10")` → 20260310** untuk membandingkan tanggal
  (evaluator meng-koersi string tanggal ke angka, jadi butuh helper ini).
- **Blok I** (Keterangan Tempat) + **Blok II** (Keterangan Pencacahan: kode
  PCL/PML, tanggal cacah & periksa, jumlah kunjungan, hasil pencacahan, moda).
- 8 aturan tingkat dokumen: `IDENT-WILAYAH/BS/RUTA` (Blok I ≠ sampel),
  `II-TANGGAL` (periksa < cacah), `II-KUNJUNGAN`, `II-HASIL-ART`, `II-PCL`,
  `II-NONRESPON` (hasil ≠ lengkap tapi Blok IV terisi).
- Kuesioner KP: 7 blok (I, II, POKOK, IV.1, IV.2, V, VII). 46 tes lulus.

### Increment 23 — Blok III roster ART untuk KP + cek demografi

- **Blok III** (roster `art`): nama, hubungan dengan KRT (9 kode), jenis kelamin,
  umur, status perkawinan — blok roster pertama di kuesioner KP.
- 6 aturan: `III-JML-ART` (R301 = jumlah baris roster terisi),
  `III-KRT-TUNGGAL` (tepat 1 kepala rumah tangga), `III-UMUR` (0–110),
  `III-KAWIN-ANAK` (< 10 th tidak boleh pernah kawin), `III-KRT-MUDA`,
  `III-PASANGAN-JK` (jk istri/suami = jk KRT → periksa; pakai `roster_first`).
- Kuesioner KP: 8 blok (I, II, III, POKOK, IV.1, IV.2, V, VII). 47 tes lulus.

### Increment 24 — navigasi & lipat blok di layar entri

- `formState` menambah `n_err` / `n_warn` per blok.
- Sidebar entri: daftar **Navigasi blok** — nama blok + badge error/warning,
  klik → buka blok & scroll ke sana. Blok yang tampak disorot (IntersectionObserver).
- Setiap blok bisa **dilipat** (klik judul); status lipat disimpan di
  `localStorage` per kode blok. Badge blok ikut diperbarui saat autosave.
- Judul blok menampilkan jumlah error/warning-nya.
- 47 tes lulus (perubahan UI; struktur blade diverifikasi via smoke test).

### Increment 25 — alur "kembalikan dokumen ke petugas"

- Status dokumen baru: **`dikembalikan`**. Kolom `documents.return_reason`,
  `returned_by`, `returned_at`.
- Halaman pengolahan: pemeriksa (pml/editor/admin) bisa **mengembalikan**
  dokumen dengan alasan/instruksi (min. 5 karakter) bila butuh kunjungan ulang
  atau perbaikan lapangan — dokumen keluar dari antrean cleaning, status sampel
  kembali ke *proses*.
- Layar entri petugas menampilkan **banner merah** berisi alasan; tombol
  finalisasi berubah jadi "Kirim ulang ke pemeriksa". Saat dikirim ulang,
  status → `selesai` dan field pengembalian dibersihkan (audit: `finalisasi-ulang`).
- Filter antrean pengolahan + chip status + 1 dokumen contoh berstatus dikembalikan.
- 49 tes lulus (2 tes baru: siklus kembalikan→perbaiki→kirim ulang, validasi alasan & tolak pada dok bersih).

### Increment 26 — skrip menjalankan + README proyek

- **`run.ps1`** di root Laravel: cari PHP (Laragon 8.3), `composer install` bila
  `vendor/` kosong, `key:generate` bila `APP_KEY` kosong, buat
  `database/database.sqlite`, migrasi (`-Fresh` untuk reset+seed), lalu
  `php artisan serve`. Opsi `-Port`.
- **README.md** proyek (ganti README bawaan Laravel): cara menjalankan, akun
  contoh, alur kerja, arsitektur metadata, mesin ekspresi, seed produksi, tes.
- Diuji: `.\run.ps1` menyalakan aplikasi, `/login` → HTTP 200.

### Increment 28 — `run.ps1` folder lama meneruskan ke Laravel

- `c:\Users\User\OneDrive\Documents\susenas\run.ps1` (folder prototipe Python)
  kini **meneruskan** ke `C:\laragon\www\susenas\run.ps1` beserta argumennya.
- Runner Python lama dipindah ke `run-python-lama.ps1`; README prototipe ditandai ARSIP.
- Diuji: menjalankan `run.ps1` dari folder lama menyalakan aplikasi Laravel, `/login` 200.

### Increment 27 — template & kamus data impor dokumen

- **Template CSV impor** per siklus: header lengkap yang diharapkan importer
  (`wilayah_code,no_bs,no_ruta,krt_name` + semua var ruta + `rostervar__1..__N`,
  N dari `?art=`) + satu baris contoh. Tim CAPI tinggal mengisi.
- **Kamus data CSV** per siklus: satu baris per rincian — blok, tipe blok,
  kode rincian, var_name, kolom impor (`var__<n>` untuk roster), label, tipe, opsi.
- Keduanya di halaman Admin · Impor Dokumen, hanya admin (403 untuk lain).
- 52 tes lulus (3 tes baru: kolom template + akhiran roster, isi kamus, akses admin-only).

### Increment 29 — generator dokumen KP realistis + indikator kesejahteraan

- **Generator** ~28 rumah tangga KP (7 per desa) di seeder: kuantitas Blok IV.1
  ditentukan dari target kalori (≈ 2000 kkal/kapita/hari, padi-padian & minyak
  dominan), harga satuan di rentang wajar; Blok IV.2 non-pangan ~porsi dari
  pengeluaran makanan; Blok V/VII menyeimbangkan neraca; identitas Blok I/II
  konsisten. `mt_srand` tetap → deterministik. ~15% dokumen sengaja bermasalah
  (harga tak wajar / kode wilayah salah). Saat `runningUnitTests` hanya 8 dokumen
  agar suite cepat.
  - Hasil: pengeluaran/kapita/bulan Rp 0,49–6,7 jt (median ~1,1 jt), porsi
    makanan ~57%, ~17% di bawah garis kemiskinan.
- **Halaman `/tabulasi/indikator`**: P0 (headcount ratio) tertimbang &
  tak tertimbang, rata-rata & median pengeluaran per kapita, porsi makanan,
  estimasi populasi, sebaran kelas — per kecamatan + total. Label besar
  "**internal QC — bukan estimasi resmi**".
- 53 tes lulus.

### Increment 30 — uji alur entri + perbaikan hitung baris roster

- **Bug ditemukan & diperbaiki**: `EntryController::artCount` menghitung
  `art_index` dari SEMUA jawaban, termasuk blok daftar (row_index s.d. 194/347),
  sehingga blok roster ART selalu menampilkan 15 baris begitu Blok IV terisi.
  Kini hanya jawaban blok `repeat='art'` yang dihitung.
- **`EntryFlowTest`** (6 kasus): simpan jawaban → state & `answer_histories`,
  roster mengembang ke `banyak_art`, header kelompok di blok daftar,
  simpan ditolak saat dokumen bersih (409), viewer boleh lihat tak boleh simpan (403),
  finalisasi ditolak selama masih ada error.
- 59 tes lulus.

### Increment 31 — KOR: Blok II + kolom roster + 15 aturan Sidona (gelombang 3)

- Ekstraktor sheet xlsx baru `scripts/xlsx_sheet.php` → membaca sheet "Anomali"
  & "Entri" berkas Sidona untuk struktur blok + 108 aturan.
- KOR (VSEN26.K): **Blok II** (jam mulai/selesai wawancara, hasil pendataan) +
  **20 kolom roster baru** (r608/609/611/612/614/615 pendidikan, r703a/703b/705/708
  ketenagakerjaan, r801/802 TIK, r1002/1006 gangguan fungsional, r1108 rawat jalan,
  r1206/1207/1208 rokok).
- **15 aturan Sidona baru** (SDN-01,02,24,25,27,36,39,41,43,44,45,52,53,55,56) →
  **48 dari 108 diterjemahkan**. Dokumen "CONTOH ANOMALI" memicu 32 aturan,
  YUDI & SUPINI (data nyata) tetap 0.
- 59 tes lulus (tes Sidona diperluas untuk 13 aturan gelombang 3 + SUPINI bersih).

### Increment 32 — KOR Blok XVI lengkap + 19 aturan Sidona (gelombang 4)

- Blok XVI ditambah 15 kolom sanitasi/air/energi (r1605, r1609a/b/c/e,
  r1610a/c, r1611a/b, r1612, r1614a, r1616a, r1617, r1622).
- **19 aturan Sidona baru** (SDN-71,74,75,78,80..96,108) →
  **67 dari 108 diterjemahkan**. CONTOH ANOMALI memicu 42 aturan berbeda;
  YUDI & SUPINI tetap 0.
- 59 tes lulus (tes Sidona diperluas untuk 8 aturan gelombang 4).

### Increment 33 — KOR Blok XVII/XVIII/XX + 8 aturan Sidona (gelombang 5)

- 3 blok ruta baru: **XVII** (kredit & e-wallet), **XVIII** (aset: water heater,
  telepon rumah, lahan), **XX** (perlinsos: PIP, PKH, BPNT).
- **8 aturan Sidona** (SDN-97..101, 105..107) — kebanyakan
  "menerima bantuan/punya kredit tetapi tidak ada ART yang punya rekening"
  (`roster_all(r701, 5)`). → **75 dari 108 diterjemahkan**.
- KOR: 7 blok, **75 aturan validasi**. CONTOH ANOMALI memicu 49 aturan berbeda;
  YUDI & SUPINI tetap 0. 59 tes lulus.

### Increment 34 — 4 aturan Sidona lanjutan (gelombang 6)

- `SDN-26/37/40/42` (pendidikan SLB, kegiatan sekolah vs partisipasi,
  lapangan usaha pembantu, jenjang ijazah vs lapangan usaha pemerintahan).
  → **79 dari 108 diterjemahkan**.
- CONTOH ANOMALI: ART ke-5 (pembantu) ditambahkan; memicu 53 aturan berbeda.
  YUDI & SUPINI tetap 0. 59 tes lulus.

### Increment 35 — kesiapan produksi: env, deploy, health, portabilitas DB

- Tes anomali Sidona **dibuat kokoh**: invarian (data nyata = 0) + ambang
  (sintetis ≥ 40 aturan berbeda) + 1 perwakilan tiap gelombang, alih-alih
  daftar ~40 kode. Menambah/menghapus satu aturan tak lagi mengubah tes.
- `.env.production.example` (PostgreSQL/MySQL, sesi aman, HSTS, proxy).
- `docs/deployment.md` — langkah rilis PDN, worker antrean, scheduler, HTTPS di
  belakang proxy, backup, daftar keputusan BPS yang tertunda.
- `bootstrap/app.php` membaca `TRUSTED_PROXIES` (HTTPS & cookie aman di belakang proxy).
- Migrasi `questions.skip_expr`: `text` → `string(500)` (kompatibel MySQL < 8.0.13).
- Perintah **`php artisan susenas:health`** — ringkas jumlah entitas + deteksi
  dokumen tanpa snapshot / aturan sintaks rusak / versi tanpa blok.
- 61 tes lulus.

### Increment 36 — histogram sebaran pengeluaran per kapita

- Halaman `/tabulasi/indikator` dapat **histogram SVG** (12 bin s.d. P95) dengan
  penanda **garis kemiskinan** merah putus-putus. Satu seri → tanpa legenda,
  label angka langsung di tiap batang, hover per batang (`<title>`).
- Geometri dihitung di controller (`$hist`), blade hanya merender — menghindari
  blok `@php` dalam blade (yang bentrok dengan `@php($nav=...)` di atas file).
- Palet dari referensi tervalidasi skill dataviz (biru slot-1 + status merah),
  lolos `validate_palette.js` di mode terang & gelap; tema-aware via `.viz-root`
  di `public/css/app.css`.
- 61 tes lulus (assertion histogram: Σ batang + luar-skala = n).

### Increment 37 — ekspor jawaban format lebar (round-trip impor↔ekspor)

- Tombol **Ekspor jawaban** (nilai mentah / nilai bersih) per siklus di
  Admin · Impor Dokumen. Format kolom = persis format impor
  (`wilayah_code,no_bs,no_ruta,krt_name` + var ruta + `var__1..__N` roster).
- **Bug diperbaiki** (pola sama Inc 30): `docColumns` menghitung `__n` dari
  semua blok non-ruta termasuk `list` → ratusan kolom. Kini blok `list`
  (daftar komoditas) **dikecualikan** dari format lebar; kamus data menandainya.
- Round-trip: ekspor KOR → impor pratinjau → 0 kolom tak dikenal, semua dokumen
  "diperbarui" (bukan baru), nilai roster terjaga.
- 63 tes lulus (2 tes round-trip baru).

### Increment 38 — uji admin editor + perbaikan duplikasi versi

- **`duplicateVersion` diperbaiki**: sebelumnya TIDAK menyalin `block_rows`
  (194+95 komoditas), `aggregates` (59), dan `row_block` pada aturan per-baris —
  versi hasil duplikat KP jadi rusak. Kini utuh.
- **`AdminMetadataTest`** (5 kasus): non-admin ditolak (403), tambah
  blok→rincian→opsi, `saveRule` menolak ekspresi rusak (400), simpan aturan &
  parameter valid, duplikasi versi menyalin baris/agregat/aturan-per-baris.
- 68 tes lulus.

### Increment 39 — penugasan petugas (assignment) + reassign

- Halaman detail sampel: form **penugasan petugas & pengawas** (pml/admin,
  hanya untuk sampel dalam cakupan wilayah).
- Daftar sampel: **filter per petugas** (+ "belum ditugaskan") + **penugasan
  massal** — centang beberapa sampel, pilih petugas, "Tugaskan terpilih".
- `setStatus` kini juga cek cakupan wilayah PML (sebelumnya tidak).
- Audit: `tugaskan` / `tugaskan-massal`.
- 72 tes lulus (`SampleAssignmentTest`: 4 kasus — dalam/luar cakupan, massal, operator ditolak).

### Increment 40 — audit login gagal + penguncian akun sementara

- Percobaan login gagal dicatat di audit (`entity=auth`, `action=login-gagal`,
  detail `ip=...`). Login sukses juga menyimpan IP.
- **Penguncian akun sementara**: 5 kegagalan dalam 15 menit → akun ditolak
  (meski sandi benar) sampai 15 menit berlalu; login sukses me-reset hitungan.
  Terpisah dari `throttle:8,1` (batas per-IP framework).
- Log Audit: filter **entitas** + preset **Keamanan** (login gagal/terkunci),
  badge "N percobaan gagal 24 jam terakhir".
- 76 tes lulus (`LoginSecurityTest`: 4 kasus).

### Increment 41 — Blok XIII/XIV + 8 aturan Sidona (gelombang 7)

- Kolom roster Blok XIII (imunisasi/ASI/MP-ASI: r1301, r1305g, r1306f, r1308a,
  r1311, r1312a–h) + Blok XIV (persalinan: r1401a, r1402a, r1404a/b/c).
- **8 aturan** (SDN-57..61, 63..65) → **87 dari 108 diterjemahkan**.
  Termasuk SDN-61 (checklist R1312: MP-ASI tapi semua jenis makanan "tidak").
- CONTOH ANOMALI kini punya 8 ART (2 balita, 2 PPK); memicu 62 aturan berbeda.
  YUDI & SUPINI tetap 0. KOR = **87 aturan**. 76 tes lulus.

### Increment 42 — Blok VIII TIK + 5 aturan Sidona (gelombang 8)

- Kolom roster Blok VIII: r803a, r806a–f (lokasi akses internet), r811f
  (internet banking).
- **5 aturan** (SDN-46..50) → **92 dari 108 diterjemahkan**. Termasuk SDN-48
  (checklist R806: akses via PC tapi hanya saat bergerak) & SDN-50 (internet
  banking tapi `roster_all(r701, 5)`).
- CONTOH ANOMALI memicu 67 aturan berbeda; YUDI & SUPINI tetap 0. KOR = **92 aturan**.
- 76 tes lulus. Sisa Sidona ~16 (pola cek-list multi-kolom & shorthand ambigu).

### Increment 43 — paket Docker (PHP-FPM + nginx + PostgreSQL)

- `Dockerfile` (php:8.3-fpm-alpine + pdo_pgsql/pdo_mysql/intl/zip/bcmath/opcache,
  composer install 2-layer), `docker-compose.yml` (app / web nginx / db postgres),
  `docker/{nginx.conf,php.ini,entrypoint.sh}`, `.dockerignore`.
- `entrypoint.sh`: key:generate bila perlu → tunggu DB → `migrate --force` →
  seed `MetadataSeeder` bila belum ada versi kuesioner → cache config/route/view.
- `docs/deployment.md` + bagian Docker Compose.
- Tidak menyentuh kode aplikasi; 76 tes tetap lulus. (Build belum diuji —
  tidak ada Docker di lingkungan dev ini.)

### Increment 44 — dashboard: alur pengolahan + aturan tersering

- Panel **Alur pengolahan dokumen**: corong dokumen per tahap
  (Entri/draft → Menunggu cleaning → Dikembalikan → Bersih).
- **Aturan tersering menyala** (6 teratas, error di atas warning) dengan
  tautan "Lihat" ke worklist `/pengolahan?rule=…` (pml/editor/admin).
- 77 tes lulus.

### Increment 45 — notifikasi dalam aplikasi

- Tabel `notifications` (skema bawaan Laravel), `User` sudah `Notifiable`.
- **`DocumentReturned`** → dikirim ke petugas saat pemeriksa mengembalikan dokumen.
- **`SampleAssigned`** → dikirim ke petugas baru saat penugasan (satu/massal).
- **Lonceng di topbar** dengan jumlah belum-terbaca; halaman `/notifikasi`
  (buka → tandai terbaca + arahkan ke tautan; "tandai semua terbaca").
- **Bug diperbaiki**: `assign()` mengakses `$d['pml_id']` yang absen saat field
  nullable tak dikirim (`Undefined array key`) → pakai `?? null`.
- 81 tes lulus (`NotificationTest`: 4 kasus).

### Increment 46 — alias kolom impor (pemetaan nama kolom CAPI)

- Tabel `column_aliases` (qversion_id, source_col → target_col).
- `docImport` menerjemahkan kolom lewat alias sebelum mencocokkan var_name;
  kolom yang tak dikenal & tak punya alias tetap dilaporkan.
- Admin · Impor Dokumen: kelola alias per siklus (satuan + tempel massal
  `sumber,target`). Laporan pratinjau menandai kolom yang diterjemahkan.
- 83 tes lulus (`ColumnAliasTest`: simpan massal + impor menerjemahkan).

### Increment 47 — ganti kata sandi sendiri + kebijakan sandi

- Halaman **`/akun`** (klik nama di topbar): lihat keterangan akun + ganti
  kata sandi sendiri. Sebelumnya hanya admin yang bisa set sandi.
- Kebijakan: verifikasi sandi lama (`current_password`), sandi baru min 8 +
  huruf + angka, berbeda dari lama, tidak memuat username. Audit `ganti-sandi-sendiri`.
- 86 tes lulus (`AccountPasswordTest`: 3 kasus).

### Increment 48 — rentang harga wajar per wilayah

- Tabel `reference_prices` (qversion_id, code komoditas, wilayah_prefix,
  harga_min/max). `RuleEngine::rowContexts` menimpa `ref_harga_min/max`
  default berdasarkan wilayah sampel (prefiks terpanjang menang; kosong = nasional).
- Aturan `IV1-HARGA` kini pakai rentang khusus kabupaten bila ada.
  Seed: rentang Bangkalan (3526) untuk beras/telur/minyak/gula, lebih ketat
  dari default nasional yang longgar.
- Admin · Data → **Harga Referensi**: impor CSV `code,wilayah_prefix,harga_min,harga_max`.
- 88 tes lulus (`ReferencePriceTest`: rentang wilayah lebih ketat dari nasional; impor).

### Increment 49 — tampilan cetak / arsip dokumen

- `GET /entri/{doc}/cetak` — salinan satu dokumen: semua blok, jawaban
  (mentah + bersih bila dikoreksi), nilai turunan, daftar temuan validasi,
  metadata sampel. Baris roster kosong tidak dicetak.
- Print CSS (`@media print`): sembunyikan nav/sidebar, tabel padat, tombol
  "Cetak / simpan PDF". Tautan dari layar entri & pengolahan.
- Semua peran terautentikasi boleh membuka (viewer termasuk).
- 91 tes lulus (`PrintViewTest`: 3 kasus).

### Increment 50 — tabulasi silang 2 dimensi + ekspor hasil

- Tabulasi umum: pilihan **Kolom (silang)** kedua → matriks baris×kolom,
  tiap sel = agregat variabel (hover untuk n).
- Tombol **⬇ CSV** — ekspor hasil tabulasi (1-dim atau matriks silang) apa adanya.
- `aggValue()` diekstrak jadi helper dipakai 1-dim & sel matriks.
- 93 tes lulus (`TabulationTest`: matriks lengkap per baris, ekspor CSV).

### Increment 51 — manajemen siklus survei

- Halaman **Admin · Data → Siklus & Jadwal** kini bisa **membuat siklus baru**
  (nama, periode, status, tanggal lapangan) — sebelumnya hanya lewat seeder.
- Edit nama + status + jadwal tiap siklus; menampilkan jumlah versi kuesioner & sampel.
- Status siklus divalidasi (persiapan/lapangan/pengolahan/selesai). Audit `buat`/`ubah`.
- 97 tes lulus (`CycleManagementTest`: 4 kasus).

### Increment 52 — impor Wilkerstat: validasi hierarki + pratinjau

- Impor master wilayah kini: urutkan otomatis (kode pendek/induk dulu),
  validasi **panjang kode per jenjang** (kab 4, kec 7, desa 10),
  `parent_code` **harus awalan** dari `code` & sudah ada (di sistem/berkas).
- Mode **Pratinjau** (dry-run dalam transaksi) + Impor & simpan; laporan baris dilewati per alasan.
- 100 tes lulus (`WilayahImportTest`: urut induk-dulu, pratinjau tak persist, kode salah & induk yatim dilewati).

### Increment 53 — CLAUDE.md: konteks proyek untuk agen

- `CLAUDE.md` ditulis ulang: dulu hanya stub bootstrap Laravel Boost generik,
  kini panduan proyek — cara jalan (path PHP Laragon, `run.ps1`, tes, seed,
  `susenas:health`), akun login seed, arsitektur metadata
  (`survey_cycle → questionnaire_version → block → question`), mesin ekspresi &
  aturan (`ExpressionEvaluator` daftar-putih tanpa `eval()`, konvensi TRUE = anomali),
  alur kerja monitoring→entri→pengolahan, jebakan berulang (validate nullable,
  Blade inline `@if`, roster `art` vs `list`), dan peta dokumentasi.
- Sesi baru langsung paham konteks tanpa menelusuri kode dari nol.
- 100 tes tetap lulus (tanpa perubahan kode aplikasi).

### Increment 54 — "Terima warning": peninjauan peringatan saat cleaning

- Tabel `warning_acks` (dokumen × kode aturan × var × baris + catatan + petugas).
  Pemeriksa menandai sebuah *warning* sudah ditinjau (bukan anomali) dengan
  catatan wajib — mis. "sudah dikonfirmasi ke responden, nilainya wajar".
- Layar pengolahan: tiap warning kini punya tombol **Terima** + kolom catatan;
  yang sudah diterima tampil hijau + "✔ Ditinjau — <nama>: <catatan>" dengan
  tautan *batalkan*. Ringkasan menampilkan "N sudah ditinjau, M belum".
- Koreksi nilai pada rincian yang sama otomatis menghapus peninjauan lama
  (nilai berubah → tinjauan tak berlaku). `markClean` mencatat jumlah yang ditinjau.
- Route `pengolahan.terima-warning` / `.batal-terima-warning`; partial
  `pengolahan/_warnflag`. Terkunci saat dokumen `bersih`/`dikembalikan`.
- 103 tes lulus (`WarningAckTest`: terima/batal + tampil di layar, catatan wajib
  & warning harus nyata, koreksi menghapus ack basi).

### Increment 55 — indikator kelengkapan entri

- `formState` kini menghitung `progress`: rincian yang *seharusnya* diisi
  (ruta tak ter-skip + seluruh sel roster ART; blok daftar komoditas dikecualikan
  karena opsional) vs yang sudah terisi → `filled/expected/pct`.
- Daftar `missing` (maks 60) berisi rincian ruta wajib yang masih kosong, dengan
  tautan lompat ke bloknya; `missing_total` bila lebih dari 60.
- Panel ringkasan entri: bar kelengkapan hijau + "<pct>% · <filled>/<total>" +
  `<details>` "Belum diisi (N)". Diperbarui live tiap simpan jawaban
  (`paintProgress` di `app.js`, ikut payload `state`).
- 106 tes lulus (`EntryProgressTest`: pct konsisten, mengisi field menaikkan
  hitungan & menurunkan missing, bar tampil di form).

### Increment 56 — catatan dokumen (utas operator ↔ pemeriksa)

- Tabel `document_notes` (dokumen, body, penulis, waktu). Komunikasi ringan
  **tanpa mengubah status** dokumen — beda dari "kembalikan".
- Panel "Catatan dokumen" di layar entri (sidebar) dan pengolahan (bawah);
  partial bersama `resources/views/_catatan.blade.php`. Terkunci saat `bersih`.
- `DocumentNoteController::store` (`POST /dokumen/{document}/catatan`,
  `role:operator,pml,editor,admin`, cek cakupan wilayah). Menotifikasi pihak
  terkait: petugas, PML, pembuat/pembersih/pengembali dokumen, + semua penulis
  catatan sebelumnya (kecuali penulis saat ini) — `DocumentNoteAdded`
  (URL ke layar entri utk operator, pengolahan utk lainnya).
- 109 tes lulus (`DocumentNoteTest`: tersimpan + tampil + notif ke operator,
  status tak berubah, body wajib & viewer 403).

### Increment 57 — umur antrean pengolahan

- Kolom `documents.submitted_at` (kapan dokumen keluar dari tahap entri).
  Diisi di `finalize()` dan lewat hook `Document::saving` (menstempel now() saat
  status jadi selesai/bersih/dikembalikan bila belum ada) → seragam utk seeder & app.
- `Document::waitingSince()` / `waitingDays()` — sejak kapan menunggu di tahap
  saat ini (selesai → submitted_at, dikembalikan → returned_at).
- Antrean cleaning (`/pengolahan`): kolom **"Menunggu N hari"** (kuning bila
  ≥7 hari), diurutkan **tertua dulu** dalam tiap status, + ringkasan atas
  "N dokumen menunggu · rata-rata X hari · terlama Y hari · Z mengendap ≥7 hari".
  Layar detail dokumen menampilkan "menunggu N hari" di header.
- Seed KP menaruh `submitted_at` acak 0–18 hari lampau agar antrean bervariasi.
- 112 tes lulus (`QueueAgingTest`: finalize menstempel, antrean melaporkan umur &
  menandai yang mengendap & urut tertua-dulu, dikembalikan dihitung dari returned_at).

### Increment 58 — laporan kinerja tim

- Halaman baru `/monitoring/kinerja` (`Kinerja Tim`, role pml/admin, terikat
  cakupan wilayah). Dua tabel indikatif untuk pembinaan:
  - **Entri per petugas**: dokumen, difinalisasi, bersih, dikembalikan,
    rata² hari entri (created→submitted), rata² warning saat finalisasi
    (dari snapshot `_n_warn`).
  - **Pengolahan per pemeriksa**: dibersihkan (`cleaned_by`), koreksi
    (`answer_histories` reason `CLEANING:%`), warning ditinjau (`warning_acks`),
    dikembalikan (`returned_by`), rata² hari tunggu→bersih (submitted→cleaned).
- Link nav "Kinerja Tim" di atas "Log Audit".
- 115 tes lulus (`TeamPerformanceTest`: akses pml-only, kredit pemeriksa +
  lead time, baris entri konsisten submit ≤ total).

### Increment 59 — pratinjau dampak aturan validasi

- `RuleEngine::previewExpression($expr, $rowBlock, $qversionId)` — jalankan ekspresi
  kandidat terhadap **semua dokumen** versi aktif (maks 500) tanpa menulis apa pun;
  laporkan berapa dokumen/baris menyala, berapa gagal dievaluasi, + 15 contoh.
- `POST /admin/aturan/dampak` (`AdminController::ruleImpact`, admin) — cek sintaks
  dulu, lalu panggil preview. Tombol **"Pratinjau dampak (semua dokumen)"** di
  panel Uji Ekspresi editor aturan: "MENYALA di N dokumen (X%)" + daftar contoh.
- Membantu kalibrasi ambang (mis. rentang harga anomali) sebelum aturan diaktifkan
  supaya tidak membanjiri antrean cleaning.
- 118 tes lulus (`RuleImpactTest`: menghitung tanpa menulis, 0 utk ekspresi selalu
  salah, tolak sintaks buruk & admin-only).

### Increment 60 — non-respons & penggantian sampel

- Kolom `sample_households.nonresponse_reason` / `status_note` / `replaces_id`
  (FK ke sampel asal).
- `setStatus` kini **mewajibkan alasan** (daftar baku: menolak, tidak ditemukan,
  pindah, bangunan kosong, ruta ganda, akses/bencana, lainnya) + catatan opsional
  saat status → non_respons / ganti_sampel; alasan dibersihkan saat kembali ke
  status lain.
- `MonitoringController::replace` (`POST /monitoring/sampel/{sample}/ganti`,
  role pml/admin) — buat sampel pengganti (salin wilayah/BS/petugas/PML/penimbang,
  `no_ruta` + "P", status belum, `replaces_id` → asal), tandai asal `ganti_sampel`.
  Tolak bila sampel sudah punya pengganti / adalah pengganti (409).
- Layar sampel: tampilkan alasan + catatan, tautan dua arah asal ⇄ pengganti,
  form "Buat sampel pengganti".
- 122 tes lulus (`SampleReplacementTest`: alasan wajib, alasan dibersihkan saat
  kembali proses, pengganti menaut dua sampel & tolak pengganti kedua, operator 403).

### Increment 61 — response rate memperhitungkan sampel diganti

- Dashboard & Peta: sampel berstatus `ganti_sampel` (sudah diserahkan ke
  pengganti) **dikeluarkan** dari total & perhitungan progres — kuotanya
  ditempati oleh sampel pengganti, bukan dihitung dobel.
- Panel baru **"Respons & disposisi"**: terespons / non-respons / belum selesai /
  tidak eligible (bangunan kosong · ruta ganda) / diganti. KPI "Response rate
  (eligible)" = selesai ÷ ruta eligible (kasus tidak-eligible dikeluarkan dari penyebut).
- 124 tes lulus (`ResponseRateTest`: eligible = responded+nonresp+pending,
  sampel asal yang diganti tak menambah total aktif).

### Increment 62 — paket metadata kuesioner (ekspor/impor JSON)

- `App\Services\QuestionnairePackage` — `export(QuestionnaireVersion): array` &
  `import(array, cycleId, ?code): QuestionnaireVersion`. Paket berformat
  `susenas.qversion/1` memuat seluruh pohon metadata (blok, rincian, opsi,
  parameter, aturan, agregat, nilai turunan, baris tetap). Impor dalam transaksi;
  tolak format asing / kode bentrok.
- CLI: `php artisan susenas:qversion:export <code> [--out=]` dan
  `susenas:qversion:import <file> [--cycle=] [--code=]`.
- UI: tautan **"Ekspor JSON"** per versi di `/admin/metadata`
  (`GET /admin/versi/{qv}/ekspor`, unduhan `application/json`).
- Alur: BPS pusat merancang versi → ekspor → distribusikan JSON → daerah impor.
- 128 tes lulus (`QuestionnairePackageTest`: round-trip mereproduksi jumlah
  blok/rincian/opsi/aturan/agregat/turunan/baris; tolak kode duplikat & format
  buruk; round-trip artisan; unduhan admin).

### Increment 63 — indikator catatan & peninjauan di antrean pengolahan

- `/pengolahan`: kolom **Warning** kini "blm/total" (belum ditinjau vs total,
  `warn_open` = warning − warning_acks) dan kolom **Catatan** (💬 N dari
  `document_notes`). Menyatukan info Inc 54 (terima warning) & Inc 56 (catatan)
  di daftar antrean supaya pemeriksa tahu dokumen mana yang butuh perhatian.
- Dua query grouped-count tambahan (notes, acks) per halaman.
- 129 tes lulus (`CleaningWorklistTest`: baris antrean menampilkan jumlah catatan
  & warning belum-ditinjau).

### Increment 64 — lint + impor web paket kuesioner

- `QuestionnairePackage::lint(array): {errors,warnings}` — cek format, kode blok
  ganda, var_name ganda, sintaks ekspresi (aturan/turunan/agregat/skip_expr via
  `ExpressionEvaluator::checkSyntax`), `row_block` aturan menunjuk blok yang ada,
  `block_code` agregat (warning), kode versi bentrok (warning).
- CLI `susenas:qversion:import`: `--check` (lint saja), abort bila ada galat,
  `--force` untuk lewati peringatan.
- **Impor via web**: `/admin/versi-impor` (tempel JSON / unggah berkas, pilih
  siklus & kode, "Periksa" lalu "Impor sekarang"; peringatan butuh centang
  "tetap impor"). Tautan dari `/admin/metadata`.
- 132 tes lulus (`QuestionnairePackageTest` +3: lint menandai ekspresi rusak &
  row_block hilang, paket bersih lolos, impor web check→commit).

### Increment 65 — buku kode (codebook) versi kuesioner

- Halaman `/admin/versi/{qv}/kodebook` — referensi lengkap & dapat dicetak satu
  versi kuesioner: per blok (tipe + tabel rincian: kode, var_name, label, tipe,
  unit, kode nilai, skip_expr), baris tetap blok daftar (`<details>`), tabel
  parameter, nilai turunan (ekspresi), agregat, dan aturan validasi.
- Tautan "Buku kode" per versi di `/admin/metadata`; tombol Cetak + Ekspor JSON.
- 134 tes lulus (`CodebookTest`: memuat blok/variabel/aturan/turunan, admin-only).

### Increment 66 — tugas terjadwal & perintah cadangan

- `routes/console.php` mendaftarkan 3 jadwal (aktif bila `schedule:run` dipanggil
  cron / Task Scheduler): `susenas:backup` harian 01:30, pangkas notifikasi
  terbaca > 30 hari mingguan, `susenas:health` mingguan.
- Perintah `susenas:backup` — salin berkas SQLite ke `storage/app/backups/`
  (WAL checkpoint dulu), simpan `--keep` (default 14) terbaru, `--dir`/`--source`
  opsional. Menolak driver non-sqlite (arahkan ke pg_dump/mysqldump).
- `docs/deployment.md` diperbarui: tabel jadwal + bagian backup SQLite.
- 136 tes lulus (`BackupCommandTest`: salin + pangkas ke `--keep`, tolak non-sqlite).

### Increment 67 — kesiapan & penguncian siklus

- `/admin/siklus/{cycle}` — rekap kesiapan penutupan: sampel per status +
  "belum tuntas", dokumen per status + "belum bersih" + "masih ada error",
  daftar versi. Tombol **Kunci siklus** (status → selesai) & **Buka kembali**
  (→ pengolahan). Tautan dari `/admin/jadwal` + badge "terkunci".
- `SurveyCycle::isLocked()` (status === 'selesai'), `Document::cycleLocked()`,
  `SampleHousehold::cycle()`.
- Guard **423 Locked** pada tulis data siklus terkunci: `EntryController`
  saveAnswer/finalize, `CleaningController` correct/markClean,
  `MonitoringController` createDocument.
- 139 tes lulus (`CycleLockTest`: kunci/buka, tulis entri & pengolahan ditolak
  423 saat terkunci, rekap kesiapan).

### Increment 68 — cari dokumen di antrean pengolahan

- `/pengolahan`: kotak **cari** bebas — cocokkan wilayah_code / no_bs / no_ruta /
  krt_name (LIKE) + `#id` dokumen (bila angka). Digabung dengan filter status &
  aturan; dibawa serta oleh tombol "Jalankan ulang aturan".
- Jebakan Blade dicatat: `kata@if(...)` tidak dikenali sbagai direktif
  (lookbehind word-char) → `@endif` jadi yatim; pakai ekspresi `{{ }}` sebagai gantinya.
- 140 tes lulus (`CleaningWorklistTest`: cari per nama KRT & #id, query tak cocok
  mengosongkan daftar).

### Increment 69 — halaman "Tugas Saya"

- `/monitoring/tugas` (`monitoring.tasks`, semua peran, terikat cakupan) — daftar
  aksi: (1) **dokumen dikembalikan** untuk perbaikan (alasan + tanggal + tautan
  entri), (2) **catatan menunggu tanggapan** (catatan terakhir bukan dari saya),
  (3) **sampel belum tuntas** (belum/proses) dengan tombol Mulai/Lanjut entri
  (operator) atau Buka sampel (PML).
- Link nav "Tugas Saya" di bawah Dashboard.
- Operator hanya melihat sampel `petugas_id` = dirinya (via `visibleSamples`).
- 143 tes lulus (`MyTasksTest`: dokumen dikembalikan & sampel pending tampil,
  catatan orang lain muncul / catatan sendiri tidak, operator hanya sampel sendiri).

### Increment 70 — ekspor jejak koreksi cleaning

- `GET /tabulasi/ekspor-koreksi` (`tabulasi.export.corrections`, pml/admin) — CSV
  dari `answer_histories` dengan reason `CLEANING:%`: doc_id, wilayah, BS, ruta,
  blok, var_name, baris, nilai_lama, nilai_baru, alasan, oleh, waktu. Melengkapi
  ekspor data-datar & daftar-warning untuk laporan kualitas data.
- Tautan "⬇ Jejak koreksi (CSV)" di panel Ekspor `/tabulasi`.
- 145 tes lulus (`CorrectionsExportTest`: memuat var/alasan/petugas, pml-only).

### Increment 71 — navigasi "dokumen berikutnya" di pengolahan

- `CleaningController::nextDoc()` — dokumen non-bersih berikutnya dalam antrean
  tercakup (urutan sama dengan `index`: status → umur → wilayah).
- Layar detail: tautan "Lewati ke #N →" di header + tombol **"bersih & lanjut →"**
  di sisi tombol "Tandai bersih". `markClean` menghitung `next` sebelum status
  berubah, lalu redirect ke `pengolahan.show` dokumen berikutnya (atau ke antrean
  bila habis).
- Alur cleaning berturut-turut tanpa balik ke daftar tiap dokumen.
- 146 tes lulus (`CleaningWorklistTest`: "bersih & lanjut" melompat ke dokumen
  antrean berikutnya).

### Increment 74 — tandai bersih massal di antrean pengolahan

- `POST /pengolahan/bersih-massal` (`CleaningController::bulkClean`, pml/editor/admin,
  ber-scope) — tandai bersih beberapa dokumen sekaligus; per dokumen mesin aturan
  dijalankan ulang, dilewati bila masih ada error atau siklus terkunci.
- Antrean: kolom centang untuk dokumen selesai/dikembalikan tanpa error +
  "pilih semua yang bisa" + tombol "Tandai bersih terpilih (N)" (JS `@push`).
- Audit `bersih-massal` (N bersih, M dilewati) + `bersih` per dokumen.
- 151 tes lulus (`CleaningWorklistTest`: massal membersihkan yang layak &
  melewati yang error; operator 403).

### Increment 75 — arsip cetak dokumen: jejak koreksi + catatan

- Halaman `entri.print` kini juga menampilkan **Jejak koreksi cleaning**
  (variabel, baris, semula→menjadi, alasan, oleh/waktu — dari `answer_histories`
  reason `CLEANING:%`) dan **Catatan dokumen** (`document_notes`).
- Salinan cetak jadi rekaman arsip lengkap: isian mentah+bersih, temuan, turunan,
  koreksi, dan komunikasi.
- 152 tes lulus (`PrintViewTest`: cetak memuat jejak koreksi & catatan).

### Increment 76 — `susenas:health` diperluas + perbaikan seed

- 5 pemeriksaan baru: aturan per-baris `row_block` menunjuk blok tak ada, agregat
  `block_code` tak ada, dokumen `bersih` yang masih ada error, sampel di siklus
  `lapangan` tanpa petugas, jawaban yatim (question_id terhapus).
- Pemeriksaan #6 menemukan bug seed: `kpFullQuestionnaire` menandai sebagian
  dokumen `bersih` walau punya error `IDENT-WILAYAH` (identitas sengaja salah).
  Diperbaiki: dokumen dengan error diturunkan ke `selesai` saat seed.
- 154 tes lulus (`HealthCheckTest` +2: bersih-tapi-error, agregat blok hantu).

### Increment 77 — filter & ekspor log audit

- `/monitoring/audit` kini bisa disaring per **pelaku**, **rentang tanggal**
  (`from`/`to`), dan **teks bebas** (detail / aksi / #entity_id) — di samping
  filter entitas & preset "Keamanan".
- Tautan **⬇ CSV** mengekspor log ter-filter (maks 20.000 baris, BOM UTF-8).
- Batas tampilan dinaikkan 200 → 300 baris terbaru.
- 157 tes lulus (`AuditFilterTest`: filter pelaku/tanggal/teks, ekspor ikut filter,
  akses pml/admin).

### Increment 78 — editor metadata: urutkan & ubah tipe blok

- **Tambah blok** kini mendukung tipe `list` (daftar baris tetap) — sebelumnya
  diam-diam jadi `ruta`.
- **Urutkan blok** ↑/↓ di `/admin/metadata` (`blok.order`, tukar `order` dgn
  tetangga — pola sama seperti reorder rincian).
- **Ubah blok** (judul + tipe berulang) di editor blok (`blok.update`). Tipe
  berulang ditolak **409** bila blok sudah punya jawaban.
- 159 tes lulus (`AdminMetadataTest` +2: tambah blok list & reorder, tipe tak
  bisa diubah saat ada jawaban).

### Increment 79 — editor variabel turunan (derived fields)

- Bagian "Variabel turunan" di `/admin/metadata` kini bisa disunting (dulu
  read-only "lewat basis data"). Baris edit (urut, var_name, label, ekspresi) +
  form tambah + hapus.
- `saveDerived` / `delDerived` (`turunan.save` / `.del`, admin). Ekspresi
  di-cek sintaks (`ExpressionEvaluator::checkSyntax`) — sintaks buruk → error form.
- 160 tes lulus (`AdminMetadataTest` +1: tolak sintaks buruk, simpan/update/hapus).

### Increment 80 — pilih kolom untuk ekspor data datar

- `tabulasi.export.raw` menerima `cols[]` — batasi ekspor ke variabel terpilih
  (kolom metadata tetap ada, urutan konsisten). Tanpa `cols` = semua kolom (perilaku lama).
- `/tabulasi` panel Ekspor: `<details>` daftar centang semua variabel snapshot +
  "semua"/"kosongkan", form GET ke rute ekspor.
- 161 tes lulus (`TabulationTest` +1: header ekspor hanya kolom terpilih + metadata).

### Increment 81 — entri: lompat ke "isu berikutnya"

- Tombol **"↧ isu berikutnya (n/N)"** di panel ringkasan entri (operator/admin).
  Siklus melalui semua `.field`/`.rcell` bermasalah dalam urutan dokumen,
  scroll + sorot + fokus input. Menghitung ulang tiap klik (ikut perbaikan live).
- `app.js`: seed `.has-error`/`.has-warning` dari flag render-server saat init,
  lalu handler `#next-issue`. CSS `.issue-flash`.
- 162 tes lulus (`EntryFlowTest` +1: tombol tampil utk operator, tidak utk viewer).

### Increment 82 — editor agregat: sunting inline + urutan

- Bagian "Agregat cek-silang" di `/admin/metadata`: tiap agregat kini `<details>`
  yang bisa dibuka → form sunting lengkap (nama, label, blok, kolom nilai, op,
  ref pembobot, filter, ref penyaring, urutan). Sebelumnya hanya tambah + hapus.
- `saveAggregate` kini menerima `order` (+ `label` yang sudah ada). Diurutkan `order`.
- 163 tes lulus (`AdminMetadataTest` +1: sunting agregat inline mengubah op/kolom/urutan).

### Increment 83 — daftar aturan: filter + aktif/nonaktif massal

- `/admin/aturan` (138 aturan aktif) kini bisa disaring: severity, scope,
  aktif/nonaktif, teks (kode/pesan/ekspresi/target).
- Kolom centang + tombol **aktifkan / nonaktifkan terpilih** (`aturan.toggle-massal`).
  Kolom baru Scope (+ row_block). Judul: "N dari TOTAL".
- Toggle & hapus per baris kini `formaction` di dalam form massal.
- 164 tes lulus (`AdminMetadataTest` +1: filter severity + toggle massal).

### Increment 84 — telusuri master wilayah + ubah nama

- `/admin/impor-wilayah` kini juga: hitungan per jenjang, **pencarian** (awalan
  kode / nama, maks 300), tautan "anak →" (telusuri turunan), dan **ubah nama**
  inline per wilayah (`wilayah.rename`, audit `ubah-nama`).
- Halaman dinamai ulang "Master Wilkerstat" (impor CSV tetap di bawah).
- 167 tes lulus (`WilayahBrowseTest` +3: cari kode/nama, rename, halaman tetap
  render setelah pratinjau impor).

### Increment 85 — laporan siklus (cetak) di halaman kesiapan

- `/admin/siklus/{cycle}` diperluas jadi laporan ringkas satu-halaman & dapat
  dicetak: **respons & disposisi** (response rate eligible, non-respons, tidak
  eligible, diganti), **alur pengolahan** (corong draft→bersih), **temuan
  tersering** (10 aturan, per dokumen). Panel penutupan disembunyikan saat cetak.
- Angka respons memakai logika yang sama dgn dashboard (ganti_sampel &
  kosong/ganda dikeluarkan dari penyebut).
- 168 tes lulus (`CycleLockTest` +1: laporan memuat response/funnel/top-rules).

### Increment 86 — editor rincian: pilihan jawaban massal & urutan

- Editor rincian tipe `code`: **impor banyak pilihan** (textarea `kode,label`
  per baris; duplikat & baris rusak dilewati, `opsi.bulk`) + tombol **↑/↓**
  urutkan pilihan (`opsi.order`).
- 169 tes lulus (`AdminMetadataTest` +1: impor massal lewati duplikat/rusak, reorder).

### Increment 87 — suite tes 10× lebih cepat (seed sekali per kelas)

- 35 kelas tes mengganti `setUp(){ $this->seed(DatabaseSeeder::class); }` dengan
  `protected $seed = true; protected $seeder = DatabaseSeeder::class;` — Laravel
  menjalankan seeder **sekali saat migrate** (di luar transaksi per-tes), tiap tes
  tetap dibungkus transaksi yang di-rollback. Seed berat (~1 dtk) tak lagi diulang
  per tes.
- `HealthCheckTest` (seed di tiap method), `LoginSecurityTest` (withoutMiddleware),
  `SerutiModuleTest` (SerutiSeeder tambahan) disesuaikan manual.
- **169 tes: ~13 dtk** (dari ~100-230 dtk). Tanpa perubahan kode aplikasi.

### Increment 88 — revalidasi massal (CLI + per-siklus)

- Perintah `susenas:revalidate {--cycle=} {--all}` — jalankan ulang RuleEngine
  (rule_results + snapshots) untuk banyak dokumen; `--all` ikutkan yang `bersih`.
  Progress bar + ringkasan error/warning.
- Tombol **"↻ Revalidasi seluruh siklus"** di `/admin/siklus/{cycle}`
  (`siklus.revalidate`) — dipakai setelah menyunting aturan/agregat/turunan.
- 172 tes lulus (`RevalidateTest`: CLI membangun ulang snapshot, `--all` ikut
  bersih, tombol per-siklus).

### Increment 89 — roster ART: baris bertambah otomatis

- Saat operator menaikkan Banyaknya ART (R301), baris roster blok `art`
  ditambahkan langsung di layar (dibangun dari `state.blocks[].columns/rows`
  di `app.js` `repaint`, input di-`bind`) — tak perlu muat ulang halaman.
- Mengurangi baris tetap perlu muat ulang (data baris lama dipertahankan).
- 172 tes lulus (server-side sudah dicakup `EntryFlowTest::test_roster_form_expands_rows`).

### Increment 90 — poles tabel: scroll & header lengket

- `.tablewrap` sekarang benar-benar `overflow-x: auto` (dulu no-op) — tabel lebar
  scroll di dalam wadahnya, bukan menggeser seluruh halaman.
- `.tbl thead th` **lengket** (`position: sticky; top: 44px`) — header tetap
  terlihat saat scroll panjang. Varian `.tablewrap.tall` (maks 76vh + scroll-y +
  header lengket di dalam wadah) untuk daftar panjang.
- Tabel dibungkus: antrean pengolahan, daftar aturan, daftar sampel, log audit
  (`.tall`), daftar pengguna, master wilayah.
- 172 tes lulus. Fokus increment berikutnya: poles UX (bukan kuesioner).

### Increment 91 — sunting baris tetap (komoditas) langsung di admin

- Editor blok `list` (IV.1 dll.): tiap baris kini **form sunting inline** — kode,
  label, + kolom referensi (kalori, harga_min, harga_max, atau apa pun yang ada
  di `ref`). Ubah angka → klik Simpan pada baris itu. Tak perlu CSV.
- Kotak **cari** (kode/nama, 194 baris jadi terkelola) + collapsible "Tambah baris
  baru" + form impor CSV massal tetap ada.
- `saveRow` / `delRow` (`admin.baris.save` / `.del`). Kolom `ref` dibangun dari
  input `ref[<nama>]`; nilai kosong dibuang; nomor baris bentrok ditolak.
  Pola HTML: `<form hidden id="rsN">` + `<input form="rsN">` (form di luar `<tr>`).
- 174 tes lulus (`AdminMetadataTest` +2: sunting ref inline + kolom baru
  `max_kons`, tambah/hapus baris + tolak nomor bentrok).

### Increment 92 — MAX KONS + aturan IV1-KUANTITAS-MAX

- Kolom `max_kons` ditambahkan ke `database/data/iv1_komoditas.csv` (194 nilai
  dari sheet `KALORI` file `6203_...MISKU - Salin - Salin.xlsx`, dipetakan lewat
  kode = No KALORI). Seeder menyimpannya ke `block_rows.ref` → `ref_max_kons`.
- Aturan baru **`IV1-KUANTITAS-MAX`** (per baris IV.1, warning):
  `filled(iv1_kuantitas) and n(iv1_kuantitas) > 0 and filled(ref_max_kons) and n(iv1_kuantitas) > n(ref_max_kons)`
  — **per rumah tangga per minggu** (sesuai permintaan, tanpa × jumlah ART).
- ⚠ Dengan basis per-ruta, aturan menyala di **25 dari 36 dokumen seed** (rumah
  tangga normal 4+ orang melampaui beras 7 kg/mgg). Nilai MAX KONS tampak
  **per kapita**. Untuk ubah ke per-kapita: sunting ekspresi di admin →
  `... n(iv1_kuantitas) > n(ref_max_kons) * n(banyak_art)`.
- 175 tes lulus (`ReferencePriceTest` +1: 5 kg aman, 40 kg menyala).

### Increment 93 — fondasi blok `list_art` (daftar baris tetap × per ART) untuk MBG

- Tipe blok berulang baru **`list_art`**: tiap baris tetap (komoditas) × tiap ART.
  `answers.art_index = row_index * 1000 + no_art` (`Block::packRowArt` /
  `unpackRowArt`; `Block::REPEATS`, `isRowBlock()`).
- `RuleEngine::rowContexts` membuka index — konteks per (komoditas, ART) dengan
  `ref_<key>` dari baris komoditas + `_art_no`. `EntryController` (formState +
  printView), `CleaningController` (row_label), `AdminController` (block editor,
  add-block, agregat dropdown, docExport skip) menangani `list_art`.
- Seed **Blok IV.1M** di VSEN26.KP (`list_art`): 6 komoditas makanan/minuman jadi
  (188 Roti tawar … 196 Nasi rames … 217 Es krim, kolom `ref.satuan`), 3 rincian
  (`mj_kuantitas`, `mj_nilai`, `mj_sumber` dgn opsi 4=MBG), 3 aturan per-baris
  (`IV1M-NEGATIF`, `IV1M-NILAI-KOSONG`, `IV1M-MBG-KUANTITAS`).
- Entri: blok tampil sebagai tabel — header kelompok per komoditas, baris "ART N".
- 179 tes lulus (`ListArtBlockTest` ×4: pack/unpack, blok terseed, aturan
  per-(komoditas,ART) menyala/bersih, form entri render 6×N baris).
- **Belum:** lapisan penuh R.187–225, kolom `satuan` untuk seluruh IV.1,
  cek silang MBG antar-blok (flag Pokok ↔ IV.1M), tabulasi/ekspor `list_art`.

### Increment 94 — list_art ikut Banyaknya ART + perbaikan clamp art_index

- **Bug**: `saveAnswer` meng-clamp `art_index` ke `MAX_ROW=500` — untuk `list_art`
  (`art_index = row*1000 + no_art`, mis. 217004) semua isian jadi tertimpa ke 500.
  `MAX_ROW` dinaikkan → 999.999.
- **Bug**: `MAX_ART=15` → naik R301 ke 18 tak menambah baris. Cap dinaikkan → 30
  (roster & list_art ikut Banyaknya ART s.d. 30).
- **JS**: `repaint` kini membangun ulang seluruh isi tabel `list_art` saat jumlah
  baris berubah (menangani header kelompok), bukan cuma menambah 1 baris seperti `art`.
- 181 tes lulus (`ListArtBlockTest` +2: art_index tersimpan utuh, form ikut R301=18).

### Increment 95 — tombol "Terapkan jumlah ART" + urutan blok POKOK→III

- Urutan blok VSEN26.KP diperbaiki: **POKOK** (R301) sekarang **sebelum III**
  (roster) — dulu kebalik. IV.1M diselipkan urut 2, IV.2/V/VII digeser.
- Tombol **"Terapkan jumlah ART"** di sebelah R301 (`POST /entri/{doc}/jumlah-art`,
  `setArtCount`): setel R301 → sesuaikan baris roster (`art`) & makanan-jadi
  (`list_art`) **naik atau turun**. Saat mengurangi, konfirmasi + **hapus isian
  ART di atas jumlah baru** (roster: `art_index > n`; list_art: `art_index % 1000 > n`).
- JS `repaint`: baris roster/list_art dibangun ulang saat jumlahnya berubah
  (grow & shrink), bukan cuma menambah.
- 182 tes lulus (`ListArtBlockTest` +1: shrink menghapus isian yatim di `art` & `list_art`).

### Increment 96 — "Terapkan jumlah ART" berlaku di semua kuesioner

- Var "Banyaknya ART" beda antar-kuesioner: KP pakai `banyak_art`, KOR pakai
  `r301`. `EntryController::ART_COUNT_VARS = ['banyak_art','r301','jml_art','jumlah_art']`.
- `artCount` & `setArtCount` mengenali semua var itu; `formState` mengembalikan
  `art_var` (var yang resolve), view menampilkan tombol di field itu, JS mencari
  input lewat `.closest('.field')` (tak lagi hardcode `banyak_art`).
- Hasil: tombol **Terapkan jumlah ART** + sinkron baris roster/list_art kini
  aktif di **semua 36 dokumen** (KP, KOR, demo), bukan cuma VSEN26.KP.
- 183 tes lulus (`ListArtBlockTest` +1: tombol & set-art jalan utk 3 versi).

### Increment 97 — perbaiki header lengket yang menutupi baris pertama

- Inc 90 memasang `.tbl thead th { position: sticky; top: 44px }` untuk SEMUA
  tabel. Pada tabel di `.tablewrap` biasa (mis. "Tugas Saya" → Sampel belum
  tuntas) header jadi menutupi baris pertama.
- Perbaikan: header lengket **hanya di dalam `.tablewrap.tall`** (kontainer
  scroll sendiri, `overflow-y: auto` + `max-height`, `top: 0`). Tabel lain
  memakai header biasa (tak lengket).
- 183 tes lulus (CSS-only, tanpa perubahan logika).

### Increment 98 — impor harga & kalori lewat Excel (.xlsx) / CSV

- Permintaan: "untuk data harga bahan bisa diupload lewat excel dan jangan lupa
  yang kalori itu".
- Baru `app/Services/XlsxReader.php` — pembaca `.xlsx` tanpa dependency
  (`ZipArchive` + regex `sharedStrings.xml` + XML sheet). `rows($path, $sheet)`
  mengembalikan baris asosiatif berkunci header (baris pertama non-kosong,
  di-lowercase). `fromUpload()` mendeteksi `.csv`/`.txt` vs `.xlsx`;
  `csvRows()` untuk data tempelan.
- **Harga Referensi** (`/admin/harga`) — form kini `multipart/form-data` dengan
  input berkas (`.xlsx`/`.csv`) + isian nama *sheet* opsional; tempel CSV tetap
  ada sebagai alternatif. `hargaImport` menerima `file` atau `data`, kolom wajib
  `code, harga_min, harga_max` (+`wilayah_prefix` opsional).
- **Editor blok → "Impor / ganti banyak baris sekaligus"** — idem: unggah
  `.xlsx`/`.csv` untuk `block_rows`. Kolom wajib `row_index, label`; kolom lain
  (`kalori, harga_min, harga_max, satuan, max_kons`, dll.) tersimpan sebagai
  `ref` per baris. Inilah jalur data **kalori / MAX KONS** untuk Blok IV.1.
- Tes baru `XlsxImportTest` (4): parser xlsx per-header, impor harga dari xlsx,
  impor baris blok + data kalori dari xlsx, impor baris dari unggahan CSV.
  **187 tes lulus (~16 dtk).** Reseed + `susenas:health` bersih.

### Increment 99 — Blok IV.1 kolom lengkap sesuai WTF (tahap 1)

- Permintaan: "coba lengkapi sesuai dengan yang di excel" — struktur WTF dipilih.
- **Temuan** (dari WTF `6203_...MISKU` + PDF VSEN26.KP/K):
  - Struktur WTF IV.1 per komoditas: (5)(6) dari pembelian, (6a) asal;
    (7)(8) dari produksi sendiri/pemberian, (8a) asal; (9)=(5)+(7) &
    (10)=(6)+(8) jumlah konsumsi; kolom subsidi (nilai dibayar, sumber).
  - WTF berbasis **VSEN24.KP** (sheet Menu menyebut "VSEN24.KP halaman 2-3").
    Kuesioner VSEN26.KP cetak lebih ramping (R.1–186 tanpa kode "asal";
    R.187–225 pakai kode Sumber Perolehan {0,1,2,3} + Banyak/Nilai).
  - Sheet `IV.1_ART1…12` = makanan & minuman jadi R.187–225 **per ART**.
  - MBG per-ART sebenarnya di **VSEN26.K KOR Blok VI R.617–618** (menu MBG
    A Nasi … J Lainnya, X Tdk tahu).
- **Tahap 1 (increment ini)** — Blok IV.1 VSEN26.KP: pisah kuantitas jadi
  `iv1_kuantitas_beli` (5) & `iv1_kuantitas_lain` (7); relabel `iv1_nilai_beli`
  (6) & `iv1_nilai_lain` (8); tambah `iv1_kuantitas` (9)=(5)+(7) & baru
  `iv1_nilai` (10)=(6)+(8). Aturan cek-silang baru `IV1-JUMLAH-BANYAK` &
  `IV1-JUMLAH-NILAI` (warning, hanya menyala bila (9)/(10) ≠ jumlah). Seeder
  dokumen contoh mengisi set kolom penuh secara konsisten. Aturan lama
  (IV1-HARGA, IV1-KUANTITAS, IV1-KUANTITAS-MAX, rekap kalori/neraca) tetap
  jalan — `iv1_kuantitas`/`iv1_nilai_beli` tak berubah maknanya.
- Tes baru `Iv1ColumnsTest` (2). **189 tes lulus (~15 dtk).** Reseed +
  `susenas:health` bersih (11 error / 155 warning contoh).
- **Belum (tahap berikutnya, perlu masukan):** kode+label kolom "Asal" (6a/8a)
  & "Sumber subsidi"; makanan jadi R.187–225 jadi `list_art` penuh + kode
  Sumber Perolehan; keputusan rokok R.220–225 per-ART atau rumah tangga;
  VSEN27.KP (crosscheck) masih struktur lama.

### Increment 100 — halaman "Aturan Validasi" dirombak untuk non-teknis

- Keluhan user: halaman aturan membingungkan (ekspresi mentah + jargon di depan,
  145 aturan dalam satu tabel datar).
- **Tampilan baru** (`resources/views/admin/rules.blade.php`): daftar
  dikelompokkan **per blok / topik** (accordion `<details>`), tiap aturan tampil
  = **pesan ke pemeriksa** + tingkat (error/warning) + tombol nyalakan/matikan.
  Ekspresi disembunyikan.
- **Sunting cepat per baris**: kolom "keterangan" (bebas, kolom baru
  `validation_rules.note`) + kotak **"ambang"** untuk aturan yang punya tepat
  satu angka ambang perbandingan (`ValidationRule::soleThreshold()` /
  `withThreshold()` — regex, `0` diabaikan sbg penjaga tanda). Route
  `admin.aturan.cepat` (`POST /admin/aturan/{rule}/cepat`) → `quickRule()`;
  menyimpan `note` + mengganti angka ambang tanpa menyentuh ekspresi (dicek
  sintaks dulu).
- Editor ekspresi + penguji + pratinjau dampak + referensi fungsi → pindah ke
  panel **"Mode lanjutan"** (`<details>`, auto-terbuka saat menyunting 1 aturan),
  plus tabel "Ekspresi mentah" untuk yang mau lihat semua.
- Migrasi `2026_09_04_120000_add_note_to_validation_rules`. Model +fillable +2
  helper. `rulesView()` bikin `groups` (groupBy blok/scope) + `blockTitles`.
  `saveRule()` terima `note`.
- Tes `AdminMetadataTest::test_rules_page_groups_and_quick_edit`. **190 tes lulus.**
  Reseed + health bersih.

### Increment 101 — peran "editor" dilebur ke "pml"

- Permintaan user: "kalo sudah ada pml yang bisa nanganin semua editor ngapain
  masih pake editor" → hapus peran editor.
- `User::ROLE_LABELS` tinggal 4: admin, **pml (Pengawas/Pengolah)**, operator,
  viewer. Middleware `role:pml,editor,admin` → `role:pml,admin` (pengolahan,
  tabulasi, dokumen.catatan, bulkClean, dashboard/pengolahan blade guards).
  `assignableUsers()` PML list tak lagi menyertakan editor.
- Migrasi `2026_09_04_130000_merge_editor_role_into_pml` — `users` role editor →
  pml. Seed `siti_editor` kini role `pml` tanpa `wilayah_scope` (pengolah tingkat
  kantor, lihat semua kecamatan). Login demo di halaman login dirapikan.
- Semua tes lulus tanpa perubahan (akun `siti_editor` tetap ada, kini pml).
  **190 tes.** Reseed + health bersih.

### Increment 102 — Blok IV.1M: daftar makanan jadi + rokok lengkap

- Blok IV.1M (`list_art`, per ART) diperluas dari 6 komoditas contoh → **37
  rincian penuh** R.188–225 sesuai VSEN26.KP: makanan & minuman jadi (188–219)
  + rokok/tembakau (221–225). Tiap baris punya `ref.satuan` + `ref.group`
  (MAKANAN & MINUMAN JADI / ROKOK & TEMBAKAU); `ref.kalori` diambil otomatis
  dari daftar komoditas Blok IV.1 (cocokkan lewat `code`).
- Pertanyaan & aturan blok tidak berubah (`mj_kuantitas`, `mj_nilai`,
  `mj_sumber` dgn opsi 4 = Program pemerintah/MBG; rules IV1M-*). Blok IV.1
  (rumah tangga) dibiarkan utuh — IV.1M = lapisan per-ART untuk analisis MBG.
- `ListArtBlockTest` disesuaikan (jumlah komoditas dinamis, bukan hardcode 6).
- **190 tes lulus.** Reseed + `susenas:health` bersih.
- Menu Admin: **Impor Wilkerstat** dipindah ke bagian "Setup awal (sekali)" di
  bawah; halaman impor wilayah diberi catatan setup.

### Increment 103 — Aturan Validasi: sembunyikan ekspresi mentah dari tampilan utama

- Keluhan user: tabel "Ekspresi mentah" (`filled(b1_no_bs) and b1_no_bs != ...`)
  masih bikin bingung meski sudah di Mode lanjutan.
- Intro halaman ditulis ulang: "untuk sehari-hari cukup nyalakan/matikan +
  ubah ambang; ekspresi hanya untuk Admin/IPDS di Mode lanjutan".
- Tiap baris di daftar berblok kini punya tautan **⋯** ke editor aturan —
  navigasi ke Mode lanjutan tak lagi butuh tabel ekspresi.
- Tabel "Daftar semua ekspresi" dibungkus `<details>` bertingkat di dalam Mode
  lanjutan (label "referensi teknis"), tertutup default.
- Placeholder pencarian: "cari kode / pesan" (bukan "…/ ekspresi").
- **190 tes lulus.**

### Increment 104 — form terpandu untuk membuat aturan validasi baru

- Pertanyaan user: "gimana kalau admin mau bikin validasi baru" — sebelumnya
  satu-satunya cara adalah menulis ekspresi mentah di Mode lanjutan.
- Panel baru **"Buat aturan baru — form terpandu"** di `/admin/aturan`:
  pilih kode, tingkat, blok, lalu **jenis pemeriksaan**:
  1. Rincian wajib diisi → `blank(A)`
  2. Rincian B wajib jika A memenuhi syarat → `(cond A) and (blank(B) or n(B)==0)`
  3. Nilai harus di rentang min–maks → `filled(A) and (n(A)<min or n(A)>max)`
  4. Nilai tidak boleh lebih/kurang dari angka → `filled(A) and n(A) OP val`
  5. Dua rincian harus sama / berbeda → `filled(A) and filled(B) and A !=/== B`
  Dropdown rincian diisi dari daftar blok/pertanyaan versi aktif
  (`resources/views/admin/partials/_rulefield.blade.php`). JS
  mengaktif/menonaktifkan bagian sesuai jenis (input nonaktif tidak ikut
  terkirim). Blok tipe daftar → `scope=baris` + `row_block` otomatis.
- `AdminController::assembleRuleExpr()` + `buildRule()`; route
  `admin.aturan.terpandu` (`POST /admin/aturan-terpandu`). Sintaks dicek,
  kode duplikat ditolak. Ekspresi hasil ditampilkan di pesan sukses.
- Editor ekspresi mentah tetap ada di Mode lanjutan untuk kasus di luar pola.
- Tes `AdminMetadataTest::test_guided_rule_builder_creates_working_rules`.
  **191 tes lulus.**

### Increment 105 — Kamus variabel di halaman Aturan Validasi

- Lanjutan keluhan: form terpandu belum menjawab "variabel validasinya apa ya?".
- Panel baru **"Kamus variabel"** (`<details>`, di atas form terpandu):
  tabel dengan kotak cari berisi SEMUA yang bisa dipakai di ekspresi —
  metadata sampel (`sampel_wilayah/bs/ruta/krt`), tiap rincian kuesioner
  (var_name · "Blok X · Rxxx" · label), nilai turunan (DerivedField),
  parameter (Parameter), referensi baris (`ref_<key>` dari `block_rows.ref`
  blok daftar), plus catatan roster (`<var>__n/__sum/__count1/...`,
  `roster_count(...)`) dan daftar fungsi.
- Intro memuat contoh terpandu untuk `IDENT-BS`: jenis "Dua rincian harus
  sama/berbeda", A = nomor BS Blok I, B = `sampel_bs`.
- Dropdown rincian di form terpandu (`_rulefield.blade.php`) kini juga
  memuat optgroup **Metadata sampel** + **Nilai turunan** — jadi
  perbandingan lawan `sampel_bs` bisa dipilih.
- `rulesView()` mengirim `derivedFields`, `ruleParams`, `refKeys`, `hasRoster`.
- Framing: "membuat aturan dari nol jarang di kabupaten — biasanya dari paket
  Sidona BPS / disiapkan IPDS".
- **191 tes lulus.**

### Increment 106 — Harga Referensi: tampilkan nama komoditas, bukan cuma kode

- Keluhan: tabel "Terdaftar" hanya menampilkan kode (`002`, `075`) tanpa
  keterangan komoditasnya.
- `hargaForm()` kini mengirim `$labels` (map `qversion_id:code → label` dari
  `block_rows` blok IV.1 + IV.1M semua versi) dan `$komoditas` (daftar IV.1
  versi aktif dengan harga bawaan).
- Tabel "Terdaftar" dapat kolom **Komoditas**.
- Panel lipat baru **"Daftar kode komoditas Blok IV.1"** di bawah form impor:
  tabel kode + nama + Min/Max bawaan, dengan kotak cari — untuk melihat kode
  saat menyusun berkas impor.
- **191 tes lulus.**

### Increment 107 — hapus override harga per wilayah; satu rentang per komoditas

- Permintaan user: "mending rentang override dihapus dan buat rentang nasional
  bisa diedit — kode, nama komoditas, dan range harga".
- **Tabel `reference_prices` dihapus** (migrasi `drop_reference_prices`), model
  `ReferencePrice` dihapus, `RuleEngine::rowContexts()` tak lagi mencari override
  — `ref_harga_min`/`ref_harga_max`/`ref_kalori` murni dari `block_rows.ref`.
- Menu **"Harga Referensi" → "Harga & Kalori Komoditas"**. Halaman kini =
  tabel komoditas Blok IV.1 yang **bisa disunting langsung**: kode, nama,
  harga_min, harga_max, kalori — per baris + kotak cari. Impor massal Excel/CSV
  (`code, harga_min, harga_max, kalori`) mencocokkan ke `block_rows` lewat `code`
  dan **merge** ke `ref` (kunci lain seperti `satuan`/`max_kons`/`group` aman).
- Route `admin.harga.del` → `admin.harga.save` (`POST /admin/harga/simpan`).
- Seeder: 4 rentang Bangkalan (beras/telur/minyak/gula) kini di-merge langsung
  ke `block_rows.ref`, bukan bikin baris override.
- `ReferencePriceTest` ditulis ulang (edit inline + impor by code + aturan pakai
  ref block_rows). **192 tes lulus.** Reseed + health bersih.

### Increment 108 — versi kuesioner: pilih aktif + hapus versi

- Keluhan user: versi "asd" (salah input via Duplikasi) tak bisa dihapus, dan
  statusnya "aktif" (karena `activeQv()` = id terbesar).
- Kolom baru `questionnaire_versions.is_active`. `activeQv()` =
  `where('is_active', true)` dengan fallback id terbesar. Seeder tandai
  VSEN26.KP aktif.
- Menu Metadata → tabel versi:
  - **"Jadikan aktif"** per versi non-aktif → `activateVersion()`, route
    `admin.versi.aktifkan`.
  - **"Hapus"** (merah) hanya untuk versi **0 dokumen** & bukan satu-satunya →
    `deleteVersion()`, route `admin.versi.hapus`. Cascade hapus blok/rincian/
    opsi/aturan/parameter/turunan/agregat/baris. Kalau yang dihapus sedang
    aktif, aktif pindah otomatis ke id terbesar.
  - Kolom jumlah dokumen ditampilkan.
- Tes `test_version_activate_and_delete`. **193 tes lulus.**

### Increment 109 — versi kuesioner: kode & tanggal berlaku bisa disunting

- `effective_date` dulu cuma label yang tidak bisa diedit (ikut nyalin saat
  duplikasi). Kini tabel versi di Metadata punya input **kode** + pemilih
  **tanggal berlaku** per baris + tombol Simpan.
- `saveVersion()` — validasi kode unik antar versi, tanggal opsional. Route
  `admin.versi.simpan` (`POST /admin/versi/{qv}/simpan`).
- Tes `test_version_code_and_effective_date_editable`. **194 tes lulus.**

### Increment 110 — poles tampilan (CSS-only, tanpa ubah logika)

- Permintaan: "buat tampilan agar terlihat modern tanpa mengubah logika/back-end".
- `public/css/app.css` saja. Tidak ada perubahan blade/controller/route.
- Token: tambah `--surface-2`, `--row-hover`, `--accent-strong`, `--on-accent`,
  `--radius-lg` (12px), `--shadow-sm`/`--shadow-md`; `--radius` 6→8px; palet
  netral & aksen sedikit disegarkan; bayangan gelap untuk tema gelap.
- Panel: sudut lebih bulat + bayangan lembut (jadi kartu yang "mengambang").
- Tipografi: h1 1.5→1.7rem, hierarki & letter-spacing dirapikan, antialias.
- Sidebar: item aktif dapat bilah aksen kiri; pemisah bagian dapat garis.
- Tabel: padding baris lebih lega, hover baris, header pakai `--surface-2`.
- Tombol: primary pakai `--accent-strong` + bayangan; ghost hover jelas;
  transisi 0.14s (dihormati `prefers-reduced-motion`).
- Input/textarea: focus ring lembut (box-shadow) bukan outline keras.
- KPI, chip, alert, filter-bar, login-card, rule-group ikut dipoles.
- **194 tes lulus** (CSS tidak menyentuh logika/tes).

### Increment 111 — app-shell + chrome gelap (tampilan lebih modern)

- Lanjutan poles: user minta lebih modern. Tetap tanpa ubah logika —
  `public/css/app.css` + 1 baris `<meta name="color-scheme">` di layout.
- **App shell**: topbar & sidebar sekarang **tetap (fixed)**, hanya area
  konten yang scroll (`height: calc(100vh - --topbar-h); overflow-y:auto`).
  `body:has(.topbar)` dikunci — layout terasa seperti aplikasi, bukan halaman.
- **Chrome gelap**: topbar + sidebar pakai `--nav-bg` (slate-navy pekat) dengan
  teks terang — di tema terang maupun gelap. Token baru `--nav-*`. Item menu
  aktif dapat bilah aksen; pemisah bagian dapat garis; kontrol topbar
  (notifikasi/tema/keluar) disesuaikan untuk latar gelap.
- `color-scheme` diset per tema (light/dark) — kontrol native & scrollbar ikut
  tema, dan browser tidak meng-auto-dark halaman.
- h1 1.7→1.85rem; intro halaman (`.content > h1 + p`) diberi gaya; scrollbar
  tipis; warna seleksi teks; `.sticky` entri disesuaikan ke shell baru.
- Mobile (<821px): shell kembali menumpuk, sidebar horizontal.
- **194 tes lulus** (CSS/markup only).

### Increment 112 — perbaiki seed: sampel proses/selesai tanpa dokumen

- User temukan: di "Tugas Saya" ada sampel STATUS `proses` tapi kolom Dokumen
  "belum ada". Penyebab: `documents()` (siklus VSEN27.KP contoh) hanya bikin
  dokumen fixture untuk sebagian sampel `proses`/`selesai`, sisanya
  (12 sampel) status-nya tak konsisten.
- Fix di `DatabaseSeeder::documents()`: sampel `proses`/`selesai` yang tak
  punya dokumen dikembalikan ke `belum`.
- `susenas:health` cek #9: peringatkan sampel proses/selesai tanpa dokumen.
- **194 tes lulus.** Reseed + health bersih.

### Increment 113 — tampilan: kurangi kesan datar/monoton

- User: terlalu flat — warna & terlalu banyak teks dengan font serupa.
- **Tiga suara tipografi**: **IBM Plex Serif** untuk judul (h1, judul panel,
  angka KPI, summary `<details>`), Plex Sans untuk teks, Plex Mono untuk
  data/kode/label. Font-link layout +Serif +weight 450/700.
- **Kartu statistik**: angka KPI jadi serif besar warna aksen; label mono
  uppercase; kartu error/warning dapat gradien tipis.
- **Header panel**: judul serif + garis bawah membentang lebar panel
  (`.panel > h2:first-child` di-bleed ke tepi).
- **Warna**: aksen biru diperdalam; tambah `--accent-2` teal untuk kode inline
  di paragraf (jadi "chip" kecil) + tick judul halaman (gradien biru→teal).
- **Kedalaman**: glow radial halus di kanan-atas konten; sidebar dapat
  gradien + item aktif terisi penuh; chip status jadi mono-uppercase + titik.
- CSS + 1 baris `<link>` font. Tanpa ubah logika. **194 tes lulus.**

### Increment 114 — avatar inisial pengguna

- Komponen `<x-avatar :user / :name :size>` — lingkaran berisi inisial 1–2
  huruf, warna HSL deterministik dari nama/id. Fallback ke `<img>` bila kolom
  `users.avatar` diisi nanti (belum ada — untuk Inc mendatang).
- `User::initials()` + `User::avatarHue()`.
- Dipasang: topbar (samping nama), Daftar Pengguna, Kinerja Tim (entri &
  pengolahan per orang).
- Tanpa upload / migrasi / penyimpanan. **194 tes lulus.**

### Increment 115 — modul "bukti kunjungan" (opsional, bisa dimatikan)

- Foto per rumah tangga sampel: diunggah operator, dilihat pengawas.
- **Sakelar**: `config/susenas.php` → `bukti_kunjungan` (default true),
  `bukti_kunjungan_wajib` (default false; kalau true → tak bisa finalisasi
  tanpa ≥1 foto). Override lewat `.env` (`SUSENAS_BUKTI_KUNJUNGAN=false`).
- Semua UI (panel di form entri sisi kanan + panel di detail sampel utk PML)
  dan rute dibungkus `@if(config('susenas.bukti_kunjungan'))` /
  `abort_unless(config(...), 404)`. Matikan sakelar → semua hilang, data aman.
- Berkas: `config/susenas.php`, migrasi `create_visit_photos`, model
  `VisitPhoto`, `VisitPhotoController`, partial
  `entri/_bukti_kunjungan.blade.php`, blok di `monitoring/sample.blade.php`,
  CSS `.foto-grid`. Foto di `storage/app/kunjungan/{sample_id}/` (di-.gitignore).
  Disajikan lewat rute ber-otorisasi (`kunjungan.foto`), bukan symlink publik.
- `susenas:backup` diperluas: folder foto diarsipkan jadi `*-foto.zip`
  (di-skip bila sakelar off / tak ada foto).
- Tes `VisitPhotoTest` (5). **199 tes lulus.** Tak menyentuh alur inti
  (finalisasi & kelengkapan tetap jalan tanpa foto kecuali `_wajib` on).

**Cara menghapus modul sepenuhnya** (bila BPS minta, karena FASIH sudah punya):
1. `SUSENAS_BUKTI_KUNJUNGAN=false` di `.env` — cukup untuk mematikan.
2. Hapus total: `php artisan migrate:rollback --step=1` (drop `visit_photos`),
   hapus `config/susenas.php` bagian bukti_kunjungan, hapus berkas modul di
   atas, `rm -rf storage/app/kunjungan`, cabut baris di route/EntryController/
   MonitoringController/Backup/blade. Sisa aplikasi tak tersentuh.

### Increment 116 — naikkan ukuran font/angka yang terlalu kecil

- User: beberapa font/angka masih kelihatan kecil.
- `body` 15 → 15.5px; hampir semua `font-size` di bawah ~13.8px dinaikkan
  ~1 pt: tabel `.89→.92rem`, header tabel `.66→.72rem`, `.small .82→.86`,
  chip `.67→.72`, label KPI `.68→.73`, `q-code .72→.77`, roster `.85→.89`,
  `nav-sep .6→.67`, dll. Judul/heading tidak diubah.
- CSS-only. **199 tes lulus.**

### Increment 117 — peringatan harga IV.1: "kemahalan"/"kemurahan" + rentang wajar

- User (lihat form entri IV.1): "alertnya bisa diubah jadi kemahalan dan
  kemurahan dan berikan indikator range harganya".
- `RuleEngine::interpolate()` baru — pesan aturan bisa memuat placeholder:
  `{nama_var}` → nilai konteks, `{=ekspresi}` → hasil hitung. Angka diformat
  gaya Indonesia (`number_format(.., ',', '.')`). Berlaku untuk semua aturan.
- Aturan `IV1-HARGA` (satu, dua arah) dipecah jadi:
  - `IV1-HARGA-MAHAL` — harga satuan `>` `ref_harga_max`
  - `IV1-HARGA-MURAH` — harga satuan `<` `ref_harga_min`
  - Pesan: *"Harga satuan Rp35.000 kemahalan — batas wajar Rp11.000–Rp15.000
    per satuan komoditas."*
- `IV1-KUANTITAS-MAX` — pesan kini menampilkan kuantitas terhitung + batas
  (`max_kons`): *"Kuantitas 10 melebihi batas maksimum wajar (7 per rumah
  tangga per minggu)."*
- Ketiganya diberi `note` (keterangan bahasa manusia) di halaman Aturan
  Validasi; halaman itu diberi 1 baris penjelasan placeholder `{..}`/`{=..}`.
- Migrasi `2026_09_10_140000_split_iv1_harga_kemahalan_kemurahan` — memecah
  aturan lama di DB yang sudah jalan (idempoten, ada `down()`).
- Seed diperbarui (blok `kpFullQuestionnaire` & `crosscheck`); helper
  `DatabaseSeeder::ruleNotes()`.
- `ReferencePriceTest` + `RuleEngineTest` disesuaikan; tes baru untuk
  bedakan mahal/murah + cek angka muncul di pesan. **200 tes lulus**,
  `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 118 — CRUD wilayah (bukan cuma impor CSV)

- User: "yang impor wilkerstat tidak bisa ditambahkan selain impor csv apakah
  bisa ditambahkan crud saja?"
- Halaman **Impor Wilkerstat** (`/admin/impor-wilayah`) sekarang punya CRUD
  satu-per-satu selain impor massal:
  - **Tambah** — form kode/nama/jenjang/induk baru di atas halaman
    (`wilayahStore`, `POST /admin/wilayah` → `admin.wilayah.simpan`). Validasi
    sama seperti impor: panjang kode sesuai jenjang, `parent_code` harus
    awalan dari kode & sudah ada di sistem, kode tak boleh duplikat.
  - **Sunting** — form di hasil Telusuri diperluas: sekarang bisa ubah nama
    **dan** kode induk (pindah ke induk lain), tidak cuma nama
    (`wilayahRename` diperluas, route lama `admin.wilayah.rename` tetap sama
    — kompatibel dengan pemanggilan lama yang cuma kirim `name`).
  - **Hapus** — tombol per baris (`wilayahDestroy`, `DELETE /admin/wilayah/{id}`
    → `admin.wilayah.hapus`), ditolak dengan pesan jelas bila wilayah masih
    punya anak (`parent_code` menunjuk ke situ) atau masih dipakai
    `sample_households.wilayah_code`.
  - Kode wilayah sendiri tidak bisa diubah setelah dibuat (dipakai bebas
    sebagai string referensi di `sample_households`, bukan FK — mengubahnya
    akan memutus tautan sampel yang sudah ada).
- Impor CSV tetap ada untuk perubahan massal (banyak baris sekaligus).
- 5 tes baru (`WilayahBrowseTest`): tambah, tolak panjang kode salah/
  duplikat/induk tak ada, sunting pindah induk, hapus diblokir oleh anak lalu
  berhasil setelah anak dihapus, hapus diblokir bila dipakai sampel.
- **205 tes lulus**, `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 119 — hapus jenjang "provinsi" dari master wilayah

- User: "di project susenas ini hanya wilayah kabupaten tidak ada provinsi".
- Aplikasi ini untuk 1 kabupaten saja — jenjang provinsi di master wilayah
  tidak relevan (dari awal cuma numpang jadi induk kabupaten, tak pernah
  dipakai filter/scope apa pun).
- **Kabupaten sekarang jenjang teratas** (tanpa induk): `WIL_LEN` &
  `LEVELS` di `AdminController` tak lagi punya `provinsi`; semua
  pengecekan `level !== 'provinsi'` → `level !== 'kabupaten'`. Form
  Tambah/impor CSV: pilihan jenjang cuma kabupaten/kecamatan/desa.
- Migrasi `2026_09_14_090000_drop_provinsi_wilayah_level` — hapus baris
  `level='provinsi'` yang ada & kosongkan `parent_code` kabupaten di DB yang
  sudah jalan.
- Seed (`wilayah()`): baris "35 Jawa Timur provinsi" dihapus; "3526 Kabupaten
  Bangkalan" sekarang `parent_code` kosong.
- `docs/wilkerstat-bangkalan-kecamatan.csv` & `docs/panduan-admin.pdf`/`.html`
  diperbarui (kode contoh & tabel jenjang tak lagi menyebut provinsi).
- `WilayahImportTest` disesuaikan (baris uji "panjang kode salah" ganti dari
  level provinsi jadi kecamatan berkode pendek, karena provinsi kini
  bukan level yang dikenal).
- **205 tes lulus**, `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 120 — urutkan menu sidebar sesuai alur kerja

- User: "di sidebar kayaknya tidak urut sesuai fungsi".
- Bagian Admin di `layouts/app.blade.php` sebelumnya berurutan "Admin ·
  Kuesioner" → "Admin · Data" → "Setup awal (sekali)" — jadi hal yang
  pertama kali dikerjakan admin (Impor Wilkerstat) malah tampil **paling
  bawah**, dan Pengguna (bikin akun PML/PCL) terselip di tengah "Data".
  Disusun ulang mengikuti urutan kerja nyata (lih. `docs/panduan-admin.pdf`):
  1. **Setup awal (sekali)** — Impor Wilkerstat, Pengguna
  2. **Siklus & sampel (tiap periode)** — Jadwal Lapangan, Impor Sampel,
     Impor Dokumen
  3. **Konten & validasi kuesioner** — Metadata, Aturan Validasi,
     Impor Rincian, Harga & Kalori Komoditas
- Susulan (user: "metadata bukannya masuk ke konten dan validasi?"): Metadata
  sebenarnya editor blok/rincian/parameter/**versi kuesioner** — bukan tugas
  per-periode seperti Jadwal Lapangan/Impor Sampel — jadi dipindah dari
  grup "Siklus & sampel" ke "Konten & validasi kuesioner".
- CSS-only/markup-only (tidak ada logika yang berubah). **205 tes lulus**,
  `susenas:health` bersih.

### Increment 121 — peran baru "PJ" (Penanggung Jawab Kecamatan)

- User menjelaskan struktur yang diinginkan: tiap desa ada petugas lapangan
  (PCL) yang dinaungi PML, tiap PML dinaungi PJ, dan **satu PJ per
  kecamatan**. Diklarifikasi via pertanyaan: PJ perlu peran terpisah
  (bukan sekadar akun PML berwilayah lebih luas), kewenangannya setara PML
  tapi lingkup kecamatan.
- `User::ROLE_LABELS` — tambah `'pj' => 'Penanggung Jawab (PJ) Kecamatan'`.
  Tidak perlu kolom/tabel baru: cakupan PJ pakai mekanisme `wilayah_scope`
  yang sudah ada (pencocokan awalan kode) — PJ dengan scope `3526010` otomatis
  melihat **semua** desa di kecamatan itu, tanpa perubahan pada query
  manapun.
- Setiap gerbang izin yang sebelumnya `['pml','admin']` diperluas jadi
  `['pj','pml','admin']` (dan yang menyertakan `viewer`/`operator`
  disesuaikan juga) — routes/web.php, `CleaningController`,
  `MonitoringController`, `VisitPhotoController`, nav sidebar, halaman
  Pengolahan/Dashboard/Entri/Bukti kunjungan/Catatan. PJ jadi bisa: lihat &
  tugaskan sampel, bersihkan dokumen, lihat Kinerja Tim & Log Audit,
  Tabulasi & Ekspor — semua dibatasi wilayah_scope-nya sendiri.
  (Dibersihkan sekalian: sisa role `'editor'` yang sudah dilebur ke `pml`
  sejak Inc 101 tapi masih nongol di satu gate `_catatan.blade.php`.)
- `AdminController::saveUser` — validasi khusus role `pj`: wilayah_scope
  wajib kode kecamatan 7 digit yang ada di master wilayah, dan **ditolak**
  bila kecamatan itu sudah punya PJ aktif lain (satu PJ per kecamatan).
  PJ **tidak** dimasukkan ke daftar "PML yang bisa ditugaskan" pada sampel
  (`assignableUsers` tetap `role=pml` saja) — PJ mengawasi lewat cakupan
  wilayah, bukan sebagai PML per sampel.
- `susenas:health` — cek baru #10: lebih dari satu PJ aktif untuk kecamatan
  yang sama (jaga-jaga bila data masuk lewat jalur lain).
- Seed: akun demo `pj_kamal` / `pj123`, wilayah 3526010 (Kamal). Ditambahkan
  ke tabel "Akun demo" di halaman login & catatan ini.
- Tes baru `PjRoleTest` (9 tes: validasi scope kecamatan/kode tak
  dikenal/dobel PJ, sunting akun sendiri tak kena tolak-dobel, PJ melihat
  sampel lintas-desa dalam satu kecamatan, PJ punya akses setara PML di
  Pengolahan/Kinerja/Audit/Tabulasi, PJ bisa tugaskan petugas, PJ tak
  ditawarkan sebagai PML assignable) + 1 tes health-check dobel-PJ.
  **215 tes lulus**, `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 122 — "query" bebas di form terpandu Aturan Validasi

- User: "gimana kalau diberi semacam query juga untuk validasi?" — form
  terpandu sebelumnya cuma 5 jenis pemeriksaan tetap (wajib/wajib
  jika/rentang/batas/banding), masing-masing **satu syarat**. Tidak bisa
  menggabungkan dua sinyal sekaligus (mis. "aset banyak TAPI belanja
  makanan dilaporkan rendah" — persis kasus deteksi bawah-lapor yang
  dibahas sebelumnya).
- Jenis baru **"Gabungan beberapa syarat (query bebas)"** —
  `assembleRuleExpr()` kind `kombinasi`: sampai 5 baris syarat
  (rincian + operator + nilai), digabung **DAN**/**ATAU** sesuai pilihan.
  Tetap dropdown/isian, bukan nulis ekspresi. Minimal 2 syarat (kalau cuma
  1, pakai jenis lain yang sudah ada).
- **"Uji dulu"** — tombol baru di form terpandu (berlaku utk semua jenis,
  bukan cuma kombinasi): menyusun ekspresi dari isian saat ini lewat
  endpoint baru `POST /admin/aturan-terpandu/uji` (`wizardImpact()`, tidak
  menyimpan apa pun) lalu tampilkan **berapa dokumen/baris yang akan
  menyala** — dipakai `RuleEngine::previewExpression()` yang sudah ada.
  Sekarang admin bisa "coba dulu sebelum aktif" tanpa harus buka Mode
  lanjutan.
- UI: 5 baris syarat dirender sekali (hemat JS — tak perlu clone DOM),
  baris ke-3..5 disembunyikan awal + tombol "+ Tambah syarat" membuka
  satu-satu. **Bug kecil ketemu & diperbaiki saat verifikasi**: atribut
  `hidden` HTML kalah oleh `.row-form{display:flex}`/`.btn-ghost{display:
  inline-block}` (aturan penulis selalu menang atas default user-agent) —
  ditambah `.kombi-row[hidden]`/`#kombi-add[hidden]{display:none}` supaya
  baris ekstra benar-benar tersembunyi.
- 3 tes baru (`AdminMetadataTest`): kombinasi ditolak jika <2 syarat,
  kombinasi 2-syarat tersimpan & sintaksnya valid, `wizardImpact` mengembalikan
  pratinjau tanpa menulis baris aturan apa pun. **217 tes lulus**,
  `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 123 — kerangka siklus Susenas + Seruti 2026 (4 triwulan)

- User menjelaskan hubungan Susenas (2x/tahun: Maret, September) dan Seruti
  (4x/tahun: tiap triwulan, terintegrasi dengan Susenas di TW I & TW III) +
  tabel instrumen per periode:
  | | Maret | Juni | September | November |
  |---|---|---|---|---|
  | Susenas | K + KP | — | Modul + KP | — |
  | Seruti | INTI | INTI + MAK | INTI | INTI + MAK |
- Web search **tidak berhasil** dapat PDF/struktur asli 2026 — dokumennya
  hidup di portal SIRUSA (login) & Google Drive pelatihan internal per
  kabupaten, tidak terindeks publik. Ditemukan 1 koreksi dari situs resmi
  *.bps.go.id: SERUTI = **Survei Ekonomi Rumah Tangga Triwulanan** (bukan
  "Survei Konsumsi & Investasi Rumah Tangga" seperti tertulis sebelumnya di
  `docs/modul-seruti.md` — sudah diperbaiki).
- **Temuan penting sebelum membangun apa pun**: sistem mengasumsikan
  **1 siklus = 1 kuesioner** (`createDocument()` ambil qversion ber-id
  terbesar di `cycle_id` sampel — tidak ada cara pilih instrumen per
  sampel). Kalau 1 siklus "TW I 2026" diisi 3 qversion (K, KP, INTI)
  sekaligus, cuma satu yang kepakai. Ditanyakan ke user, dipilih:
  **1 siklus per instrumen** (bukan per triwulan) — tanpa ubah kode inti,
  sama seperti `VSEN26.KP` yang sudah begini.
- **`Susenas2026CycleSeeder`** (baru, terpisah dari `DatabaseSeeder` —
  tidak jalan otomatis, seperti `SerutiSeeder`; jalankan manual
  `php artisan db:seed --class=Database\\Seeders\\Susenas2026CycleSeeder`):
  - Reuse apa adanya: `VSEN26.K`, `VSEN26.KP` (Maret, sudah ada),
    `VSERUTI26.INTI` (TW I, panggil `SerutiSeeder` kalau belum ada).
  - Duplikasi struktur (blok/rincian/pilihan/aturan — helper
    `duplicateInto()`, sama logikanya dengan `AdminController::
    duplicateVersion()` tapi lintas-siklus) ke instrumen yang diasumsikan
    isinya sama sampai ada revisi nyata: `VSEN26.KP-TW3` (dari KP Maret),
    `VSERUTI26.INTI-TW2/-TW3/-TW4` (dari INTI TW I).
  - **Placeholder kosong** (sengaja, bukan konten karangan) untuk yang
    strukturnya belum diketahui sama sekali: `VSEN26.M`,
    `VSERUTI26.MAK-TW2`, `VSERUTI26.MAK-TW4` — qversion tanpa blok, muncul
    sebagai warning nyata di `susenas:health` ("versi kuesioner tanpa
    blok") sampai isinya benar-benar dikirim.
  - Hasil: 10 siklus (3 reuse + 7 baru), 11 qversion total kalau dijalankan.
    **Tidak menyentuh dataset demo/tes utama** — `migrate:fresh --seed`
    tanpa seeder ini tetap 3 qversion, bersih seperti sebelumnya.
- 5 tes baru (`Susenas2026CycleSeederTest`): tidak jalan otomatis di seed
  default, 10 siklus × 1 qversion masing-masing terbentuk benar, duplikat
  punya struktur sama dengan sumbernya, placeholder benar-benar kosong,
  `susenas:health` menandai placeholder, seeder idempoten (jalan 2x tidak
  dobel). **222 tes lulus**, `migrate:fresh --seed` (default) +
  `susenas:health` tetap bersih.

### Increment 124 — Jadwal Lapangan dibungkus per tahun

- User: "di jadwal lapangan bungkus pertahun biar ga numpuk begitu" — 10
  siklus baru 2026 (Inc 123) bikin halaman jadi satu tumpukan panjang.
- `AdminController::jadwalForm()` — siklus dikelompokkan per tahun (4 digit
  awal `period`, format `YYYY-MM`), tahun terbaru dulu; dalam satu tahun
  diurutkan kronologis per `period` (Maret → Juni → September → November),
  bukan lagi id terbalik.
- View: tiap tahun jadi `<details class="rule-group">` (dipinjam dari
  gaya "Blok" di Aturan Validasi) — bisa dilipat/dibuka, tahun pertama
  otomatis terbuka.
- Murni tampilan + urutan, tidak ada perubahan data. **222 tes lulus**,
  `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 125 — perbaiki panjang kode kecamatan (bug, 6→7 digit)

- User meluruskan format kode wilayah BPS asli: kabupaten `3526` + kecamatan
  **3 digit** (`3526060`, bukan `352606`) + desa 3 digit lagi (total 10).
  Sistem yang dibangun Inc 118/119 salah — kecamatan divalidasi **6** digit
  (asumsi kecamatan 2 digit lokal). Kalau tidak diperbaiki, impor Wilkerstat
  Bangkalan **asli** nanti akan ditolak sistem ("panjang kode salah").
- Format benar: kode wilayah statistik BPS = provinsi(2)+kabupaten(2)=4,
  +kecamatan(3)=**7**, +desa(3)=10. `AdminController::WIL_LEN`:
  `['kabupaten'=>4,'kecamatan'=>7,'desa'=>10]` (sebelumnya kecamatan=6).
- **Bug lain ikut ketemu** saat menelusuri dampaknya — 3 tempat lain
  menghitung "6 karakter pertama = kode kecamatan" secara terpisah dari
  `WIL_LEN`, jadi tidak ikut kebawa kalau cuma ganti konstanta itu:
  - `AdminController::saveUser` — validasi panjang scope PJ (Inc 121) punya
    angka `6` sendiri → diubah pakai `self::WIL_LEN['kecamatan']` (bukan
    angka lepas lagi).
  - `MonitoringController::peta()`/`samples()` dan `TabulationController`
    (3 tempat) — `substr($code, 0, 6)` untuk kelompokkan per kecamatan di
    Peta Progres & Tabulasi → jadi `substr(..., 0, 7)`.
  - `DatabaseSeeder::samples()` — `substr($desa, 0, 6)` untuk cari PML per
    kecamatan → jadi `substr(..., 0, 7)`.
  - Dropdown jenjang di `admin/import_wilayah.blade.php` (Tambah satu
    wilayah) punya salinan `['kabupaten'=>4,'kecamatan'=>6,...]` sendiri,
    lepas dari `WIL_LEN` juga — disamakan.
- Data dummy diselaraskan ke format baru: kecamatan contoh Kamal/Socah
  `352610`/`352620` → `3526010`/`3526020`; desa turunannya juga digeser
  (`3526101001`→`3526010001`, dst.) — di `DatabaseSeeder`, semua tes yang
  mereferensikan kode itu, contoh CSV di halaman impor, `docs/panduan-
  admin.pdf`/`.html`, `docs/wilkerstat-bangkalan-kecamatan.csv` (18
  kecamatan `3526001`–`3526018`).
- Ditambahkan catatan "jebakan" di `CLAUDE.md`: jangan tulis ulang angka
  panjang kode wilayah di tempat lain — selalu rujuk `WIL_LEN`.
- **222 tes lulus** (banyak disesuaikan ke kode 7-digit), `migrate:fresh
  --seed` + `susenas:health` bersih (termasuk dicoba dengan
  `Susenas2026CycleSeeder` aktif).

### Increment 126 — "kabupaten" dihapus dari jenjang yang bisa ditambah

- User (screenshot form Tambah wilayah): "kok ada jenjang kabupaten? untuk
  provinsi dan kabupaten default 35 dan 26" — aplikasi ini cuma untuk satu
  kabupaten (Bangkalan/`3526`, sudah ada sejak seed), jadi "kabupaten"
  seharusnya tidak ditawarkan sebagai pilihan Jenjang saat **menambah**
  wilayah baru (beda dari `provinsi` yang dihapus total di Inc 119 —
  `kabupaten` tetap ada sebagai konsep/jenjang teratas, cuma tidak boleh
  nambah *yang kedua*).
- `admin/import_wilayah.blade.php` — dropdown Jenjang di form "Tambah satu
  wilayah" sekarang cuma `kecamatan`/`desa` (kabupaten dihapus dari opsi).
- `AdminController::wilayahStore` — validasi `level` jadi
  `required|in:kecamatan,desa` (sebelumnya termasuk `kabupaten`); cabang
  `$level !== 'kabupaten'` yang sekarang selalu benar disederhanakan.
  Impor CSV massal **tetap** menerima baris `kabupaten` (untuk kasus
  *update nama* kabupaten yang sudah ada — tidak lewat form tambah-satu).
- 1 tes baru: `POST /admin/wilayah` dengan `level=kabupaten` ditolak
  (`assertSessionHasErrors('level')`), tidak ada baris baru tersimpan.
  **223 tes lulus**, `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 127 — tampilkan nama siklus di Tugas Saya/Daftar Sampel/Entri

- User: "misal sekarang bulan Maret 2027, gimana nanti PCL bisa tau ini survey
  untuk bulan Maret?" — dicek: **tidak ada satu pun** halaman (Tugas Saya,
  Daftar Sampel, header Entri Kuesioner) yang menampilkan nama siklus/
  periode. Cuma wilayah/ruta/status. Dengan sekarang 9+ siklus 2026 berjalan
  (Inc 123), PCL yang kebagian sampel dari beberapa siklus sekaligus tidak
  bisa membedakan.
- `MonitoringController::myTasks()` — eager-load relasi `cycle`, tambah
  `'siklus' => $s->cycle->name` ke `$pending`/`$returned`/`$noteRows`.
- `MonitoringController::samples()` — tambah `cycleNames` (map) + filter
  `?cycle=` (mirip filter `wil`/`status`/`petugas` yang sudah ada) +
  `cycles` (daftar buat dropdown filter).
- View: kolom **Siklus** di tabel Daftar Sampel & Tugas Saya, dropdown
  filter "Semua siklus" di Daftar Sampel, chip nama siklus di judul
  halaman Sampel & Entri Kuesioner.
- **Bug ketemu saat verifikasi**: closure `noteRows` lupa `use ($siklus)`
  → error 500 di Tugas Saya kalau ada catatan menunggu tanggapan. Diperbaiki
  sebelum dilaporkan.
- **223 tes lulus**, `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 128 — urutan menu Admin ikuti alur diagram proses

- User mengirim diagram alur admin (bikin siklus → tentukan PCL/daerah/
  responden → aktifkan kuesioner & validator) dan minta menu diurutkan
  persis begitu, bukan dikelompokkan per "seberapa sering dikerjakan"
  seperti sebelumnya (Inc 120).
- `layouts/app.blade.php` — 3 grup Admin diurutkan ulang & diberi nomor
  eksplisit sesuai diagram:
  1. **Buat siklus** — Jadwal Lapangan
  2. **Wilayah, PCL & sampel** — Impor Wilkerstat, Pengguna, Impor Sampel,
     Impor Dokumen (gabungan grup "Setup awal" & "Siklus & sampel" lama,
     karena di diagram langkah "tentukan PCL ditugaskan ke daerah siapa
     dan respondennya siapa" itu satu langkah, bukan dipisah setup-awal
     vs tiap-periode)
  3. **Kuesioner & validasi** — Metadata, Aturan Validasi, Impor Rincian,
     Harga & Kalori Komoditas
- CSS-only/markup-only. **223 tes lulus**, `migrate:fresh --seed` +
  `susenas:health` bersih.

### Increment 129 — bug: Blok IV.1M (makanan jadi & rokok) tak ikut agregat mingguan

- Ditemukan saat mengecek daftar "kurang apa" (item #5, dikerjakan duluan
  karena tak ada blocker): rincian di Blok **IV.1M** (list_art — makanan
  jadi & rokok per ART) tervalidasi per-baris, tapi kalori/belanjanya
  **tidak pernah** ikut masuk ke `kalori_total`/pengeluaran mingguan
  gabungan — walau agregat `weighted_sum`/`filter_ref` sudah dikonfigurasi.
- Akar masalah: blok `list_art` menyimpan `Answer.art_index` sebagai indeks
  terpaket `row*1000+art` (`Block::packRowArt`), tapi `block_rows.ref`
  (tempat `kalori`/harga rujukan komoditas disimpan) diindeks pakai
  `row_index` polos. `RuleEngine::computeAggregate()` lama langsung
  memakai `art_index` terpaket itu untuk mencari `$rowRefs[...]` →
  selalu meleset (miss diam-diam, hasil agregat 0).
- Fix: `buildContext()` kini juga membangun `$blockRepeatByCode` (kode
  blok → tipe repeat); `computeAggregate()` menerima flag `$isListArt` dan
  membongkar indeks via `Block::unpackRowArt()` sebelum lookup
  `filter_ref`/`weight_ref`.
- Agregat baru `iv1m_kalori_minggu`/`iv1m_belanja_minggu`; turunan baru
  `kalori_total_gabungan`; `kalori_per_kapita_hari` & `belanja_makanan_
  kapita_bulan` diperbarui supaya ikut menjumlahkan kontribusi IV.1M.
- Tes baru: `ListArtBlockTest::test_iv1m_kalori_and_belanja_feed_into_
  weekly_totals` (pakai baris "Roti tawar", row_index 188).
- 224 tes lulus, `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 130 — Blok I & II diluruskan ke struktur VSEN26.KP asli

- Lanjutan permintaan "cari apalagi yang ngasal": PDF kuesioner asli
  (`WTF Susenas Seruti/2. Kuesioner/2. 2025.11.02 VSEN26.KP Maret
  2026.pdf`) diekstrak via `scripts/pdftext.php` dan dibandingkan ke
  metadata yang dibangun — **Blok I & II ternyata banyak dikarang**:
  nomor rincian salah, field asli hilang, sebagian field (kode PCL/PML,
  moda kunjungan) tidak ada padanannya di form nyata sama sekali.
- **Blok I "Keterangan Tempat"** (rincian 101–111 asli) — ditambah field
  yang tadinya hilang: `b1_kode_sls` (ganti nama dari `b1_no_bs`), `b1_sls`
  (nama SLS/sub-SLS, baru), `b1_no_bangunan` (nomor urut bangunan, baru),
  `b1_alamat` (baru). `b1_klasifikasi`/`b1_no_ruta`/`b1_nama_krt` tetap.
  Kode wilayah (provinsi–kab–kec–desa, R101–104) sengaja **tetap 1 field
  gabungan** `b1_kode_wilayah` (bukan dipecah 4 kotak terpisah) — app ini
  scope-nya satu kabupaten dan tabel `Wilayah` sudah simpan kode gabungan,
  jadi dipecah tidak menambah nilai untuk sistem ini.
- **Blok II "Keterangan Pendataan"** (rincian 201–203 asli) — field lama
  yang dikarang (`b2_pcl_kode`, `b2_pml_kode`, `b2_kunjungan`, `b2_moda`)
  **dihapus total**, diganti struktur asli: `b2_pendata`/`b2_pendata_
  jabatan`/`b2_tgl_cacah` (R201, pendata), `b2_pemeriksa`/`b2_pemeriksa_
  jabatan`/`b2_tgl_periksa` (R202, pemeriksa), `b2_hasil` (R203, hasil
  pendataan ruta — kodelist asli 1–5: lengkap/tidak lengkap/tak ada ART
  responden/menolak/ruta pindah).
- Aturan validasi disesuaikan: `IDENT-BS` cek `b1_kode_sls` (bukan
  `b1_no_bs`); `II-KUNJUNGAN` **dihapus** (fieldnya tak ada di form asli);
  `II-PCL` cek `blank(b2_pendata)`; pesan `II-TANGGAL` diubah dari
  "pencacahan" jadi "pendataan". Help-text contoh di `rules.blade.php`
  ikut disamakan.
- **Efek samping RNG di fixture** — seeder memakai `mt_srand(20260301)`
  supaya data contoh reproducible. Menghapus 1 panggilan `mt_rand()` (bekas
  `b2_kunjungan`) menggeser urutan draw acak sesudahnya, sampai bikin
  `TabulationTest::test_welfare_indicators_compute_headcount_and_food_
  share` gagal (properti mean>median kepengeluaran per kapita kebalik) —
  **bukan bug logika**, murni pergeseran urutan RNG (dibuktikan lewat 3x
  rerun deterministik + eksperimen sisip-ulang). Fix: taruh 1 pemanggilan
  `mt_rand(1,3)` "spacer" persis di posisi lama (dikomentari jelas
  alasannya) supaya urutan draw sesudahnya sama seperti sebelum field
  dihapus.
- **Belum dikerjakan (sengaja ditunda)**: Blok III di form asli ternyata
  cuma **5 rincian ringkas** (301–305: banyak ART, no. urut & nama pemberi
  informasi, jumlah komoditas makanan/bukan-makanan terisi) — **bukan**
  roster demografi ART lengkap (nama/hub-KRT/JK/umur/kawin per ART)
  seperti yang terlanjur dibangun sekarang. Butuh audit terpisah + cross-
  check ke PDF `VSEN26.K` asli (KOR mungkin beda dari KP) sebelum diubah,
  karena dampaknya lebih besar (banyak fitur bergantung ke roster ART).
- 224 tes lulus, `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 131 — Metadata/Aturan/Harga bisa disunting tanpa "Jadikan aktif"

- Item #2 dari daftar prioritas: sebelumnya `metadata()`, `rulesView()`,
  `hargaForm()`, `importForm()` (dan semua form "Tambah ..." di baliknya)
  selalu baca/tulis ke `activeQv()` — admin terpaksa klik "Jadikan aktif"
  cuma untuk menyunting versi kuesioner LAIN, padahal itu juga langsung
  mengganti versi yang dipakai PCL untuk entri data baru (efek samping
  operasional yang tidak diinginkan cuma untuk mengedit metadata).
- Helper baru `AdminController::selectedQv(Request)` — baca `?qv=<id>`
  (GET) / field tersembunyi `qv` (POST), fallback ke `activeQv()` bila
  kosong/tidak valid. Beda dari `activeQv()`: yang itu tetap satu-satunya
  versi yang dipakai PCL untuk entri data baru, **tidak berubah** oleh
  selector ini.
- Diterapkan di 3 halaman: **Metadata** (`admin.metadata`), **Aturan
  Validasi** (`admin.aturan` — termasuk wizard terpandu, "Uji dulu",
  toggle massal, tester "Pratinjau dampak"), **Harga & Kalori Komoditas**
  (`admin.harga` — termasuk impor massal). Juga **Impor Rincian**
  (`admin.impor`, dropdown blok tujuan). Semua tempat yang bikin baris
  metadata baru (blok/parameter/agregat/turunan/aturan) sekarang nempel
  ke versi yang **sedang dilihat**, bukan selalu versi aktif; redirect
  sesudah simpan/hapus ikut membawa `?qv=` supaya admin tidak "terlempar"
  balik ke versi aktif.
- Partial baru `admin/partials/_qv_switch.blade.php` — dropdown ganti
  versi (auto-submit lewat `URLSearchParams`, jadi filter GET lain di
  halaman Aturan tetap ikut terbawa), plus badge peringatan kalau sedang
  menyunting versi **tidak aktif**. Tabel versi di Metadata sekarang
  bedakan chip "aktif" (global) vs "dilihat" (konteks halaman saat ini).
- `ValidationRule` dapat relasi `qversion()` baru (dipakai supaya link
  "Sunting" sebuah aturan selalu buka versi aturan itu sendiri, bukan
  ikut selector `?qv=` yang mungkin basi di URL).
- 2 tes baru di `AdminMetadataTest`: duplikasi versi lalu tambah blok/
  parameter lewat `?qv=`/`qv` tanpa mengaktifkannya (pastikan versi aktif
  TIDAK berubah dan item baru nempel ke versi yang dipilih), dan cek
  Aturan/Harga ter-scope ke versi non-aktif yang dipilih.
- 226 tes lulus, `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 132 — Blok III VSEN26.KP diluruskan (bukan roster demografi)

- Lanjutan audit "cari apalagi yang ngasal": PDF asli VSEN26.K
  (`1. 20251107_VSEN26.K_GAB.pdf`) diekstrak & dibandingkan ke VSEN26.KP.
  Ternyata **VSEN26.K sudah benar** — Blok III kode `III` = "RINGKASAN"
  (301–305: banyak ART, ART umur 0–4/5+/10+ th, perempuan 10–54 th pernah
  kawin), roster demografi lengkap ada di blok **terpisah** kode `ART`.
  **VSEN26.KP salah** — Blok III-nya (`III`) dibangun meniru struktur
  roster K (nama/hub-KRT/JK/umur/kawin per ART), padahal field itu **sama
  sekali tidak ada** di form KP asli. Blok III KP asli cuma 5 rincian
  ringkas: "BANYAKNYA ART, PEMBERI INFORMASI, DAN JUMLAH KOMODITAS YANG
  TERISI" (301 banyaknya ART, 302 no. urut pemberi informasi, 303 nama
  pemberi informasi, 304 jumlah komoditas makanan/minuman/rokok terisi,
  305 jumlah komoditas bukan makanan terisi).
- Ditanya ke user apakah data roster ART itu (walau salah tempat) tetap
  mau dipertahankan di KP — dikonfirmasi **tidak**, cukup dihapus ("bisa
  ditambah manual" kalau nanti benar-benar dibutuhkan).
- `kpFullQuestionnaire()`: blok palsu `POKOK` (cuma berisi `banyak_art`,
  dibuat supaya tidak perlu menyentuh `III`) **dihapus**; `banyak_art`
  sekarang jadi rincian 301 asli di dalam blok `III` itu sendiri. Field
  roster lama (`art_nama`, `art_hub_krt`, `art_jk`, `art_umur`,
  `art_kawin`) **dihapus total**, diganti 5 field asli (`banyak_art`,
  `b3_no_urut_informasi`, `b3_nama_informasi`, `b3_jml_komoditas_makanan`,
  `b3_jml_komoditas_nonmakanan`); `repeat` blok `III` berubah dari `art`
  ke `ruta` (bukan roster lagi).
- 6 aturan validasi demografi yang nempel di roster lama (`III-JML-ART`,
  `III-KRT-TUNGGAL`, `III-UMUR`, `III-KAWIN-ANAK`, `III-KRT-MUDA`,
  `III-PASANGAN-JK`) **dihapus** — datanya sendiri sudah tidak ada di KP.
  Tidak dipindah ke K (K juga belum punya aturan roster sama sekali,
  sengaja ditunda — item terpisah kalau dibutuhkan nanti).
- Efek RNG di seeder (lagi): melepas loop roster (banyak `mt_rand()` per
  ART) menggeser urutan draw acak sesudahnya — kali ini jauh lebih besar
  dari Inc 130. Spacer `mt_rand(1,3)` dari Inc 130 **dihapus** (sudah
  tidak relevan); dicoba tanpa kompensasi apa pun dan properti mean>median
  `TabulationTest::test_welfare_indicators_...` **tetap lolos** — tidak
  perlu spacer baru.
- Dampak di 4 file tes: `test_blok_iii_roster_consistency_rules`
  (RuleEngineTest) dihapus total (mengetes 3 dari 6 aturan yang baru
  dihapus); `test_roster_form_expands_rows_to_banyak_art` (EntryFlowTest)
  diganti `test_blok_iii_is_a_flat_summary_not_a_roster` (verifikasi Blok
  III **tidak** ikut berkembang mengikuti `banyak_art` — regresi guard
  biar bug ini tidak balik lagi); `test_finalize_refuses_while_errors_
  remain` dan `test_block_repeat_cannot_change_when_answers_exist`
  dialihkan ke aturan/blok lain yang masih ada (`IDENT-WILAYAH`, blok
  `ART` milik K); `test_set_art_count_shrinks_and_drops_orphaned_answers`
  (ListArtBlockTest) dipangkas ke skenario `list_art` saja + tes baru
  `test_set_art_count_shrinks_roster_answers_on_kor` menutup sisi roster
  ('art'-repeat) pakai VSEN26.K. `ImportTemplateTest` (kolom lebar
  `__<n>` utk roster) dialihkan dari VSEN26.KP ke VSEN26.K (`r407`) —
  KP sekarang tidak punya blok roster sama sekali untuk didemonstrasikan.
  Placeholder contoh di `import_dokumen.blade.php` (`art_umur__1` dst.)
  disamakan ke `r407__1` (var nyata) supaya tidak menunjuk ke field yang
  sudah tidak ada.
- 226 tes lulus, `migrate:fresh --seed` + `susenas:health` bersih.
- **Belum dikerjakan**: item #1 (model identitas sampel SLS/sub-SLS,
  DSRT) dan item #3 (isi VSEN26.M/VSERUTI26.MAK) — masih terblokir
  seperti sebelumnya.

### Increment 133 — panel "Aksi Cepat" di halaman Siklus (hub navigasi)

- User mengusulkan navigasi Admin per-tahun: klik tahun → klik siklus →
  kelola instrumen/wilayah/PCL/responden/validator dari satu tempat.
  Sebelum dikerjakan, dibuatkan **PDF rancangan UI** dulu (`docs/
  rancangan-ui-hub-siklus.pdf`, mockup HTML→PDF pakai Edge headless
  `--print-to-pdf`) untuk ditinjau — user setuju, lanjut implementasi.
- Diklarifikasi dulu: **Wilayah (kecamatan/desa) dan Pengguna (PCL/PML)
  adalah data bersama** (tidak berubah tiap tahun, dipakai lintas siklus)
  — jadi TIDAK dijadikan data per-tahun, cuma pintasan/tautan dari hub.
  Yang genuinely per-siklus (instrumen, sampel, aturan/harga) sudah benar
  strukturnya lewat "Duplikasi versi" — tinggal kurang jalan pintas
  navigasinya.
- Implementasi numpang di halaman **`/admin/siklus/{cycle}`** yang sudah
  ada (dashboard monitoring — respons, corong pengolahan, temuan
  tersering — tidak diubah), ditambah 1 panel baru di atasnya: **"⚡ Aksi
  cepat — untuk siklus ini"**, isi 4 kartu:
  1. **Instrumen & validasi** — tiap versi kuesioner milik siklus ini
     dapat tautan Metadata/Aturan Validasi/Harga & Kalori, masing-masing
     dengan `?qv=<id>` (pakai selector versi dari Inc 131 — bukan
     endpoint baru).
  2. **Sampel & PCL** — Daftar Sampel dengan `?cycle=<id>` (filter yang
     sudah ada), + Impor Sampel.
  3. **Wilayah kerja** — tautan ke Impor Wilkerstat, dengan keterangan
     eksplisit "dipakai bersama, tidak khusus siklus ini".
  4. **Pengguna** — tautan ke Kelola Pengguna, keterangan sama.
- `AdminController::cycleShow()` — tambah `versionObjs` (id+code per
  versi kuesioner siklus ini) ke view data; `versions` (daftar kode saja,
  dipakai baris judul) tetap seperti semula.
- Jadwal Lapangan tidak perlu diubah — tombol "Kesiapan & penutupan →"
  ke halaman Siklus **sudah ada** sejak sebelumnya, sudah berfungsi
  sebagai pintu masuk yang dimaksud.
- 1 tes baru di `CycleLockTest`: cek panel "Aksi cepat" muncul dan
  tautannya benar-benar mengarah ke qversion/cycle milik siklus itu
  sendiri (bukan versi aktif global).
- 227 tes lulus, `migrate:fresh --seed` + `susenas:health` bersih.

### Increment 134 — kode induk wilayah diturunkan otomatis, tidak diisi manual

- User nambah kecamatan Galis lewat "Tambah satu wilayah", lalu tanya kok
  ada input "Induk" segala — dijelaskan fungsinya (validasi hierarki kode
  wilayah), lalu user menunjukkan itu **redundan**: kode kecamatan selalu
  7 digit berawalan `3526` (kabupaten Bangkalan, satu-satunya di app ini),
  kode desa selalu 10 digit berawalan kode kecamatannya — jadi induk bisa
  dihitung langsung dari kode, tidak perlu diketik manual.
- `AdminController::deriveWilayahParent(code, level)` (baru) — induk =
  awalan `code` sepanjang `WIL_LEN[jenjang di atasnya]` (kecamatan→4,
  desa→7). Dipakai di 3 tempat:
  - `wilayahStore()` (Tambah satu wilayah) — field `parent_code` **dihapus
    dari form & validasi**, diturunkan otomatis. Pesan error "induk belum
    ada" sekarang muncul di key `code` (bukan `parent_code` yang sudah
    tidak ada).
  - `wilayahImport()` (impor CSV massal) — kolom `parent_code` di CSV
    **tidak dibaca lagi** (diabaikan kalau ada, demi kompatibilitas file
    lama); selalu diturunkan dari `code`.
  - `wilayahRename()` (sunting nama di Telusuri) — kemampuan mengubah
    `parent_code` manual **dihapus total**; sekarang cuma urus nama.
    Tidak ada skenario sah untuk `parent_code` beda dari hasil turunan
    kode, jadi tidak ada yang hilang.
- `import_wilayah.blade.php` — input "Induk" di form Tambah & di baris
  sunting Telusuri dihapus; kolom "Induk" di tabel hasil pencarian tetap
  tampil (cuma display, sekarang selalu benar by construction). Contoh
  CSV & placeholder diperbarui (kolom `parent_code` dibuang).
- Efek ke `WilayahBrowseTest`/`WilayahImportTest`: beberapa skenario uji
  "induk salah/hilang" disesuaikan (sekarang dipicu lewat kode yang
  awalannya memang tak ada di sistem, bukan lewat isian `parent_code`
  yang salah ketik — karena isian itu sudah tidak ada). Tes
  `test_crud_edit_updates_parent_code` diganti
  `test_rename_ignores_parent_code_input` (kalau field itu tetap dikirim
  di request lama/klien lain, harus diam-diam diabaikan, bukan error).
- 227 tes lulus, `migrate:fresh --seed` + `susenas:health` bersih.
- **Catatan untuk user**: `migrate:fresh --seed` yang dijalankan buat
  verifikasi increment ini **menghapus** kecamatan Galis yang tadi
  ditambahkan manual (itu memang bukan bagian dari seed default) — perlu
  ditambahkan ulang kalau masih dibutuhkan.

### Increment 135 — operator dikunci ke periode kuesioner yang aktif

- User menemukan (lewat 2 screenshot): di admin, versi aktif di Metadata
  Kuesioner `VSEN26.KP`, tapi operator (`ppl_ani`) sedang mengentri dokumen
  `VSEN27.KP (CONTOH)`. Dijelaskan itu bukan bug — `is_active` cuma
  menentukan versi yang sedang disunting di menu admin, dokumen memakai
  qversion sesuai `cycle_id` sampelnya sendiri, terlepas dari `is_active`.
  User lalu minta: operator harus terkunci ke periode yang aktif — begitu
  admin mengaktifkan satu versi, siklus lain tertutup bagi operator.
- Awalnya dikunci per **satu `cycle_id`** (versi aktif → 1 siklus). Ternyata
  ini salah: `VSEN26.K` (KOR) dan `VSEN26.KP` (KP) periode yang sama sengaja
  disimpan sebagai **dua `survey_cycle` terpisah**, padahal di lapangan
  nyata KOR & KP dicacah **bersamaan** oleh PPL yang sama. Dikoreksi jadi
  dikunci per **periode** (`survey_cycle.period`), bukan per `cycle_id`
  tunggal — semua siklus seperiode dengan versi aktif tetap sama-sama
  terbuka (ketahuan dari 2 tes yang gagal setelah versi "1 cycle" diterapkan:
  `ListArtBlockTest` kombinasi KOR+KP, `SerutiModuleTest`).
- `QuestionnaireVersion::active()` (baru, dipakai ulang oleh
  `AdminController::activeQv()` yang lama) + `QuestionnaireVersion::activeCycleIds()`
  — daftar ID `survey_cycle` yang periodenya sama dengan versi aktif.
- Diterapkan di 3 titik, **hanya utk role `operator`** (pml/pj/admin/viewer
  tetap lintas periode — perlu utk pengolahan data lama):
  - `MonitoringController::visibleSamples()` — dasar dashboard/tugas/peta/daftar
    sampel operator, ditambah `whereIn('cycle_id', activeCycleIds())`.
  - `MonitoringController::createDocument()` — abort 403 kalau operator
    coba buat dokumen di sampel luar periode aktif.
  - `EntryController::assertActiveCycle()` (baru, dipanggil di `form/saveAnswer/
    setArtCount/finalize`) — abort 403 kalau operator coba buka/isi dokumen
    di siklus luar periode aktif.
- Dropdown filter siklus di Daftar Sampel juga disaring utk operator (cuma
  tawarkan siklus yang memang bisa dibuka).
- 2 tes disesuaikan ke asumsi baru (bukan melemahkan pengujian, keduanya
  sengaja lintas-kuesioner/periode untuk cek mekanisme generik):
  `ListArtBlockTest::test_apply_art_button_works_across_questionnaires`
  mengaktifkan versi yang sedang diuji tiap giliran loop;
  `VisitPhotoTest::operatorDoc()` disaring ke `activeCycleIds()`.
- User bandingkan screenshot: di admin (Jadwal Lapangan) siklus dikelompokkan
  per tahun (`<details class="rule-group">`), tapi di Tugas Saya operator
  masih daftar rata tanpa pengelompokan — "di operator tidak terbungkus
  seperti ini". `monitoring/tugas.blade.php` — bagian "Dikembalikan untuk
  perbaikan" & "Sampel belum tuntas" dikelompokkan per siklus
  (`$pending->groupBy('siklus')` / `$returned->groupBy('siklus')`) dengan
  `<details open>` yang sama, kolom "Siklus" pada tabel dibuang (sudah jadi
  judul kelompok).
- 227 tes lulus.

### Increment 136 — Master Wilkerstat: kode ganda & daftar dikelompokkan per kecamatan

- User coba tambah desa `3526020001` "Kesek" lewat Master Wilkerstat, ditolak
  "sudah ada" — tapi dicari nama "Kesek" tidak ketemu. Ditelusuri: kode itu
  memang sudah dipakai desa **"Socah"** di data demo, bukan Kesek — jadi
  penolakannya benar, cuma nama yang salah diasumsikan. Ketahuan juga: kode
  kecamatan demo (Kamal=3526010, Socah=3526020) beda dari kode BPS resmi di
  `docs/wilkerstat-bangkalan-kecamatan.csv` yang user baru tambahkan
  (Kamal=3526001, Socah=3526010) — diputuskan **dibiarkan** (data demo,
  bukan mesti sama persis BPS asli).
- Ketahuan juga bug UX terpisah: setelah "Tambah satu wilayah" berhasil,
  halaman `back()` ke form yang sama tanpa kotak pencarian terisi — hasil
  tambahan tidak kelihatan sampai dicari manual.
  `AdminController::wilayahStore()` sekarang redirect dengan `q` = kode yang
  baru ditambahkan, jadi langsung tampil.
- User lalu minta lebih jauh: daftar wilayah harusnya **selalu tampil semua**
  (bukan cuma muncul kalau dicari) + tetap ada fitur cari, karena desa nanti
  bisa banyak. `wilayahForm()`/`wilayahImport()` dirombak: `AdminController::
  wilayahGroups()` (baru, dipakai bersama keduanya) mengelompokkan SEMUA
  kecamatan + desanya (bukan lagi query `limit(300)` yang cuma jalan kalau
  ada `q`). `q` menyaring: kecamatan yang cocok kode/nama menampilkan semua
  desanya, kecamatan yang tidak cocok cuma menampilkan desa yang cocok.
  Kabupaten (cuma 1, selalu ada) ditampilkan terpisah, tak ikut disaring.
  Tampilan pakai `<details class="rule-group" open>` per kecamatan — pola
  yang sama seperti pengelompokan Tugas Saya (Inc 135) & Jadwal Lapangan.
- `WilayahBrowseTest::test_search_by_code_prefix_and_name` disesuaikan ke
  struktur `groups` (kec+desa bersarang) dari `list` (flat) yang lama.
- 227 tes lulus.

### Increment 137 — form "Tambah satu sampel" di Impor Sampel

- User tanya alur menambah responden baru (habis nambah desa Blega): ternyata
  halaman **Impor Sampel** cuma ada CSV, tidak ada input langsung utk 1 baris
  — sama seperti keluhan wilkerstat di Inc 136.
- `AdminController::sampelStore()` (baru) + route `POST /admin/sampel`
  (`admin.sampel.simpan`). Validasi: `wilayah_code` harus desa yang ada di
  master wilayah, kombinasi cycle+wilayah+no_bs+no_ruta tidak boleh duplikat
  (pesan error inline, bukan 422 generik). Dropdown **Petugas**/**PML** pakai
  `sampelFormExtras()` (operator & pml/pj aktif) — dipakai bersama
  `sampelForm()` & `sampelImport()` supaya konsisten.
- Sukses → redirect ke halaman detail sampel (`monitoring.sample`) supaya
  langsung kelihatan hasilnya, bukan cuma pesan sukses tanpa bukti di layar
  (pola yang sama dengan `wilayahStore` di Inc 136).
- Ternyata dropdown Petugas **belum** disaring per wilayah (baik yang baru
  ini maupun fitur "Tugaskan" lama, `MonitoringController::assignableUsers()`)
  — cuma filter role+aktif. Sengaja belum diubah, ditawarkan ke user (belum
  diputuskan perlu atau tidak, karena di data contoh operator memang tidak
  diberi wilayah_scope sama sekali).
- Tes baru `SampleAddSingleTest` (4 skenario: sukses, kode desa tidak ada,
  duplikat, ditolak untuk non-admin). 231 tes lulus.

### Increment 138 — wilayah scope pengguna selalu satu kecamatan (dropdown)

- User: "untuk menambahkan pengguna itu wilayah scope menggunakan kecamatan
  saja". Ditelusuri: `wilayah_scope` di form Manajemen Pengguna selama ini
  cuma `<input>` teks bebas — divalidasi ketat (wajib kode kecamatan) HANYA
  utk role `pj`; PML/operator boleh diisi apa saja (kode desa, kabupaten,
  bahkan typo) tanpa ditolak. Data contoh pun sudah kepakai tidak konsisten:
  `rahmawati`/`santoso` (pml) scope-nya `3526` (kabupaten, bukan kecamatan).
- `admin/users.blade.php` — input diganti `<select>` isi daftar kecamatan
  dari master wilayah + opsi "— (semua kecamatan)". Tidak ada lagi opsi
  desa/kabupaten/teks bebas.
- `AdminController::saveUser()` — validasi kecamatan-valid digeneralisasi ke
  SEMUA peran (sebelumnya cuma blok khusus `role==='pj'`); aturan tambahan
  "wajib diisi" + "satu PJ per kecamatan" tetap eksklusif utk `pj`.
  Scope kabupaten (`3526`) di data lama tidak diubah paksa di DB — cuma
  tervalidasi ketat lagi kalau disunting ulang lewat form ini (dan itu pun
  setara kosong di aplikasi 1-kabupaten ini, jadi tak ada fungsi yang hilang).
- `editUser()` (JS) — kalau scope tersimpan bukan kode kecamatan valid (data
  lama), dropdown jatuh ke "semua" alih-alih nyangkut kosong tak terpilih.
- Tes baru `UserWilayahScopeTest` (terima kecamatan valid & kosong, tolak
  kode desa & kabupaten — utk role `pml`, bukan cuma `pj` yang sudah dites
  `PjRoleTest`). 235 tes lulus.

### Increment 139 — form sampel: Kode desa jadi dropdown + Petugas tersaring per kecamatan

- Lanjutan Inc 137/138. User: "untuk kode desa juga tambahkan bisa di
  dropdown dan penambahan petugas sesuai dengan kode kecamatan agar langsung
  terfilter biar di kecamatan lain tidak masuk".
- **Kode desa** — dari `<input>` teks bebas jadi `<select>` dikelompokkan per
  kecamatan (`<optgroup>`), sumbernya `AdminController::wilayahGroups('')`
  (dipakai ulang dari Inc 136, sama seperti di Master Wilkerstat).
- **Petugas** — disaring JS begitu Kode desa dipilih: cuma operator yang
  `wilayah_scope`-nya kosong ("bisa kemana saja") atau persis sama dengan
  kecamatan desa itu yang muncul. `sampelFormExtras()` sekarang mengirim
  `operators` lengkap (`id`,`name`,`scope`), bukan cuma `pluck('name','id')`,
  supaya JS bisa menyaring tanpa round-trip server.
- **Pertahanan sisi server** (bukan cuma kosmetik JS) — `sampelStore()`
  menolak kalau `petugas_id` yang dikirim scope-nya tidak cocok dgn
  kecamatan desa yang dipilih, meski request dikirim manual di luar form.
- 2 tes baru (`test_rejects_petugas_scoped_to_a_different_kecamatan`,
  `test_allows_petugas_scoped_to_the_matching_kecamatan`). 237 tes lulus.
- **Catatan**: operator di data contoh (`ppl_ani` dst.) defaultnya TIDAK
  punya `wilayah_scope` (kosong) — jadi semuanya masih muncul di semua
  kecamatan sampai admin mengisi scope mereka lewat Manajemen Pengguna
  (dropdown kecamatan, Inc 138).

### Increment 141 — konsolidasi menu admin: Metadata hanya kuesioner

- Permintaan: "di metadata ada neraca dan harga dan kalori ada bagiannya sendiri;
  saya ingin memindahkan semua itu di aturan validasi — sehingga metadata hanya
  tempat membuat kuesioner".
- **Metadata** (`/admin/metadata`) sekarang **hanya** kuesioner: versi (duplikat/aktif/
  hapus/impor paket), blok, rincian, opsi, dan baris tetap. Section **Parameter**,
  **Agregat cek-silang (NERACA)**, **Variabel turunan**, dan ringkasan aturan
  dipindah ke halaman **Aturan Validasi**.
- **Aturan Validasi** (`/admin/aturan`) jadi 3 tab (`?tab=`):
  - `aturan` — daftar aturan + mode lanjutan (seperti sebelumnya),
  - `neraca` — parameter + agregat (NERACA) + variabel turunan (partial
    `admin/partials/_neraca.blade.php`),
  - `harga` — patokan harga wajar & Calories per komoditas (partial
    `admin/partials/_harga.blade.php`).
- **Menu "Harga & Kalori Komoditas" dihapus** dari sidebar admin. URL lama
  `/admin/harga` diarahkan ke `/admin/aturan?tab=harga` (bookmark lama tetap jalan);
  POST `admin.harga.save` / `admin.harga.import` tetap seperti semula.
- **Satu angka satu tempat** — harga & Calories tidak lagi diedit dari editor baris
  di Metadata: `AdminController::HARGA_REF_KEYS = ['harga_min','harga_max','kalori']`
  (1) disembunyikan dari kolom ref di editor baris, (2) dibuang dari `saveRow()` &
  `importRows()` (impor baris memberi tahu kolom mana yang diabaikan — impor harga
  lewat tab Harga & Kalori), (3) dipanggil `refKeys` blok di UI Aturan.
  Kolom ref lain (`max_kons`, `satuan`, `group`) tetap di Metadata.
  **Penyimpanan tidak berubah** — tetap `block_rows.ref`, dibaca mesin aturan lewat
  `ref_harga_min`/`ref_harga_max`/`kalori` → tidak ada perubahan hasil/rules.
- Sidebar admin: "Metadata" → "Metadata Kuesioner", dan **group kuesioner dipindah ke
  paling atas** (1. Kuesioner (inti): Metadata Kuesioner, Impor Rincian, Aturan Validasi
  → 2. Siklus lapangan → 3. Wilayah, PCL & sampel) — kuesioner adalah inti sistem.
- Tes: `test_edit_a_fixed_row_reference_inline` (disesuaikan: editor baris tak
  menampilkan harga/kalori), `test_harga_kalori_edited_from_aturan_tab` (baru),
  `test_aturan_tabs_neraca_and_harga_render` (baru),
  `test_rules_and_harga_pages_scope_to_selected_non_active_version` (URL lama → redirect),
  `test_admin_imports_block_rows_with_calorie_data_from_xlsx` (kolom harga/kalori
  diabaikan di impor baris). 238 tes lulus.

### Increment 163 — jadwal lapangan mengikuti kalender

- Masalah: kotak samping menampilkan "TW1 2027 (jadwal lewat)" di November 2026 — siklus contoh berperiode Maret 2027
  tetapi berjadwal relatif hari ini & sudah berstatus Lapangan. Sistem tidak mencegah jadwal di luar periodenya.
- `SurveyCycle::quarterRange()`, `scheduleProblem()` (mulai harus di triwulan periode; selesai paling lambat
  `FIELD_END_GRACE_MONTHS`=2 bulan sesudah triwulan berakhir — SERUTI Maret s.d. 15 April tetap sah), `quarterNotStarted()`,
  `fieldOverdue()`, `fieldShouldHaveStarted()`. Dicek di `cycleCreate`/`jadwalSave`; butir wajib "Jadwal lapangan sesuai
  periode" + butir peringatan "Triwulan periode ini sudah dimulai" di Persiapan periode (konfirmasi buka lapangan ikut menyebut).
- Kotak "Triwulan berjalan": catatan "Jadwal lapangan sudah mulai …, masih Persiapan" / "N siklus jadwalnya selesai, belum
  ditutup"; "Juga di lapangan" menandai "belum waktunya" atau "jadwal selesai, belum ditutup".
- `php artisan susenas:ingat-jadwal` (terjadwal 07:00) → Notifikasi admin `CycleScheduleReminder`; tidak dobel selama belum
  dibaca; status TIDAK diubah otomatis.
- Data contoh `DatabaseSeeder`: siklus "SUSENAS <bulan ini> (contoh)" berperiode bulan berjalan, jadwal di dalam triwulannya.
  Basis data pengembangan disesuaikan untuk baris contoh itu saja (tercatat di Log Audit).
- Waktu relatif ("1 menit yang lalu") kini berbahasa Indonesia (`Carbon::setLocale('id')`).
- Tes: `PeriodeSurveiTest` (+1, kotak samping diperluas), `ScheduleReminderTest` (2).

### Increment 165 — data contoh: 18 kecamatan Bangkalan, tim lapangan, semua sampel berisi isian lengkap

- `database/data/wilayah_bangkalan.csv` (format Impor Wilayah) + `WilayahBangkalanSeeder`: 1 kabupaten, 18 kecamatan (kode
  statistik BPS 010 Kamal … 180 Klampis), 281 desa/kelurahan — nama dari GADM 4.1 (`scripts/build_wilayah_bangkalan.php`).
  **Kode desa sementara** (urut abjad per kecamatan); timpa dengan master BPS lewat Admin → Wilayah → Impor.
- `PetugasBangkalanSeeder`: 18 Penanggung Jawab (satu per kecamatan), 6 Pengawas (3 kecamatan masing-masing), 36 Pencacah
  (2 per kecamatan; akun bawaan ppl_ani/dodi/eka, rahmawati/santoso, pj_kamal ikut dipakai). Nama rekaan, sandi pola lama.
- `PeriodeSurveiSeeder`: kerangka per kecamatan — SUSENAS 2 blok sensus × 10 rumah tangga (360/siklus), SERUTI 1 × 10
  (180/siklus); satu blok sensus = satu Pencacah. Semua sampel berisi isian kecuali ±3% non-respons (dengan alasan);
  siklus contoh November yang dulu 28/32 "belum dikunjungi" ikut diisi.
- `IsianSintetis`: melengkapi blok yang tidak punya isian sumber (SERUTI III–VII, IV.1M, dll.) — pilihan sah, rupiah menurut
  kata kunci label, roster sebanyak anggota rumah tangga dengan hubungan/umur/jenis kelamin konsisten, baris subtotal
  `[453 + 454]`/TOTAL = jumlah rinciannya; syarat lewati dihormati (sel yang dilewati dihapus lagi).
- Dataset tes tidak berubah (4 desa, 3 petugas). `WilayahPetugasBangkalanTest` (2), `PeriodeSurveiSeederTest` (+1).

### Increment 164 — seeder Periode Survei 2026 & 2027 + sampel contoh; opsi kosongkan aturan

- `PeriodeSurveiSeeder` menggantikan `Susenas2026CycleSeeder` (dan tesnya). Pola tiap tahun: Maret SUSENAS KOR/KP + SERUTI INTI,
  Juni SERUTI INTI/MAK, September SUSENAS Modul/KP + SERUTI INTI, November SERUTI INTI/MAK — 2026 & 2027 (`YEARS`).
- Jadwal sebulan penuh di dalam triwulannya (SERUTI Maret s.d. 15 April). Status dihitung dari kalender saat seeding
  (`calendarStatus`): belum mulai → persiapan, dalam jadwal → lapangan, lewat ≤ 2 bulan → pengolahan, > 2 bulan → selesai.
  Tiga siklus Maret 2026 bawaan (data contoh utama) tidak diubah statusnya.
- Versi disalin lewat paket metadata dari versi instrumen yang sama sebelumnya (kode `VSEN27.KP`, `VSERUTI27.INTI-TW2`, …);
  Modul & MAK tetap kosong. Kode versi siklus contoh diganti `VSEN26.KP-CONTOH` (dulu `VSEN27.KP`, bentrok dengan Maret 2027).
- Sampel contoh (desa × 2 blok sensus × 3 rumah tangga) untuk siklus yang sudah mulai lapangan & kuesionernya berisi; isian
  disalin dari isian Maret 2026 instrumen yang sama, rupiah dikali satu faktor per isian (jumlah = rincian tetap cocok). Tahap
  mengikuti status siklus (selesai = semua bersih; pengolahan = menunggu pemeriksaan/bersih/dikembalikan; lapangan = campur).
- `.env` `SEED_ATURAN=false` → `KosongkanAturanSeeder` dipanggil sesudah seeding: semua aturan validasi + hasil, riwayat,
  konfirmasi peringatan dihapus, isian dihitung ulang. Blok, parameter, agregat, turunan, patokan Harga & Kalori tetap.
- DB pengembangan dimuat ulang (cadangan `storage/app/backups/susenas-20261126-041950.sqlite`). `PeriodeSurveiSeederTest` (7).

### Increment 162 — aplikasi terpasang (PWA) Android & iPhone + isian tanpa sinyal

- `public/manifest.webmanifest`, ikon `public/icons/` (192/512/maskable + `apple-touch-icon` 180), tag `apple-mobile-web-app-*`
  di `layouts/app`. Android: pita "Pasang" (beforeinstallprompt). iPhone: petunjuk Bagikan → Tambah ke Layar Utama.
- `public/sw.js`: CSS/JS/ikon/huruf = stale-while-revalidate; halaman petugas (`/monitoring`, `/monitoring/tugas`,
  `/monitoring/sampel/{id}`, `/entri/{id}`) = jaringan dulu, salinan terakhir bila tanpa sinyal, `offline.html` bila belum pernah
  dibuka. POST tidak pernah lewat cache. Keluar akun → salinan halaman dihapus (`clear-pages`).
- Antrean isian di HP (`window.SusenasOutbox`, localStorage per pengguna): isian yang gagal terkirim (tanpa sinyal / sesi habis)
  disimpan, ditandai garis putus, dikirim ulang otomatis saat `online` / tiap 20 dtk / saat aplikasi dibuka lagi. Ditolak server
  (periode ditutup, isian bersih) → daftar "ditolak" yang bisa dilihat petugas, tidak diulang. Pita status di bawah layar.
- Tugas Saya (petugas): tombol **Simpan untuk dibuka tanpa sinyal** — menyimpan halaman entri sampel yang sudah pernah dibuka.
- Formulir POST saat tanpa sinyal ditahan dengan pesan. Keluar akun saat antrean belum kosong → konfirmasi.
- **Syarat**: service worker hanya jalan di `https` (atau localhost). Lewat `http://192.168.x.x` di HP fitur tanpa sinyal tidak aktif.
- Diuji ujung-ke-ujung (Playwright + Edge): antre saat putus, tampil lagi setelah muat ulang, terkirim saat sinyal kembali,
  halaman tersimpan terbuka tanpa jaringan, petunjuk iPhone muncul. `PwaTest` (3).

### Increment 161 — periksa ulang & impor besar lewat antrean latar belakang

- Tabel `background_tasks` + `App\Models\BackgroundTask` + job `App\Jobs\RecheckDocuments` (kemajuan tiap 20 sampel).
- Periksa ulang > `BackgroundTask::$inlineMax` (50) sampel → antrean; ≤ 50 tetap langsung. Impor isian CAPI > 50 baris:
  data disimpan langsung, aturan dihitung di latar belakang; pratinjau besar menghitung aturan 200 isian pertama (perkiraan).
- Partial `admin.partials._tasks` (bilah kemajuan, diperbarui tiap 3 dtk lewat `/admin/tugas-latar/{task}`) di Aturan Validasi
  & Impor Isian. Antre > 5 menit → pesan "jalankan `php artisan queue:work`"; `susenas:health` ikut memperingatkan.
- `.env` pengembang `QUEUE_CONNECTION=sync` (langsung jalan); produksi `database` + worker (docs/deployment.md).

### Increment 160 — bandingkan versi kuesioner

- `App\Services\VersionDiff`: tambah/hapus/ubah per blok, rincian, pilihan jawaban, baris tetap, aturan, parameter, agregat,
  turunan — dicocokkan lewat kode/nama, jadi versi salinan bisa dibandingkan. Bawaan pembanding = versi siklus survei &
  instrumen sama yang periodenya tepat sebelumnya (`previousOf`).
- Halaman `/admin/versi/{qv}/bandingkan?dengan=` (tombol **Bandingkan** di tab Kuesioner).

### Increment 159 — contoh kasus uji per aturan

- Kolom `validation_rules.test_cases` (JSON `[{name, isian:"var=nilai; …", expect}]`), `App\Services\RuleTester`
  (di atas parameter versi saja, tanpa menyentuh sampel). Panel **Contoh kasus uji** di halaman ubah aturan; periksa langsung
  saat mengetik rumus ikut menjalankan contoh; simpan aturan yang membuat contoh gagal → diberi tahu; daftar aturan
  menampilkan tanda "uji x/y" bila ada yang gagal.
- Paket versi (ekspor/impor, "Siapkan triwulan") & duplikasi versi kini ikut membawa `note` (keterangan — sebelumnya hilang
  saat disalin) dan `test_cases`.

### Increment 158 — riwayat aturan + kembalikan, dampak ambang langsung

- Tabel `rule_revisions` + `App\Models\RuleRevision`; event model `ValidationRule` merekam setiap perubahan (buat, ubah,
  sunting cepat, nyalakan/matikan, kembalikan). Aturan bawaan dapat revisi `awal` sebelum perubahan pertama.
  Update massal query builder (nyalakan massal, ganti kode blok) tidak terekam.
- Panel **Riwayat perubahan** (beda per kolom) + tombol **Kembalikan** di halaman ubah aturan.
- Daftar aturan: mengubah angka ambang langsung menampilkan "Dengan X: kena N sampel (sekarang M)" sebelum disimpan.

### Increment 157 — syarat "lewati" per baris (anggota rumah tangga / daftar)

- `skip_expr` rincian di blok `art`/`list`/`list_art` kini dihitung **per baris** (konteks dokumen + isian baris itu +
  `ref_*` + `_row_index`/`_art_no`). Sel yang dilewati: dikunci di layar entri (arsir), **diabaikan** — dibuang dari nilai
  berulang sebelum agregat/turunan dihitung, dan aturan per-baris yang merujuknya tidak diperiksa di baris itu; tidak ikut
  hitungan kelengkapan. `RuleEngine::rowCtx()` dipakai bersama aturan per-baris, syarat lewati, dan layar entri.
- `VersionChecker` menerima nama konteks baris di syarat lewati blok berulang. `RowSkipTest` (3).

### Increment 156 — pemantauan per siklus, versi sumber "Siapkan", Bahan hitungan, panduan, SERUTI INTI lengkap

- **Dashboard & Kinerja Tim** memakai pemilih siklus (bawaan triwulan berjalan, `?cycle=semua` = gabungan,
  `MonitoringController::pickCycle`); **Daftar Sampel** pilihan siklusnya diseragamkan (dikelompokkan per
  tahun/TW) tapi bawaannya tetap semua siklus (petugas perlu melihat isian dikembalikan dari triwulan lalu).
  Tes lama yang memeriksa angka gabungan kini memakai `?cycle=semua`.
- **"Siapkan triwulan" memilih versi sumber**: tiap survei punya pilihan semua versinya (blok · baris ·
  aturan), bawaan versi terbaru yang tidak kosong. Kiriman form: `pick[]=SURVEI|INSTRUMEN`,
  `src[SURVEI|INSTRUMEN]=qversion_id` (ditolak 422 bila versi bukan milik survei itu).
- Tab "Neraca & Turunan" → **"Bahan hitungan"** (agregat · turunan · parameter).
- **Panduan admin** (`docs/panduan-admin.html` + PDF) ditulis ulang mengikuti alur Periode Survei;
  **skenario UAT** (`docs/skenario-uat.md`) diperbarui (Periode, hub, akses petugas per siklus, tahap,
  keterhubungan blok, tampilan perangkat).
- **SERUTI INTI lengkap** dari PDF (Drive/OneDrive): Blok III, IV.2.B, IV.3, V.501–505, VI, VII lewat
  `SerutiIntiLengkapSeeder` (idempoten, per versi dalam transaksi). Detail & hal yang perlu dicek di
  `docs/modul-seruti.md`. Catatan: kolom `questions.unit/skip_expr/help_text` NOT NULL — isi `''`, bukan null.
- Tes: `SerutiIntiLengkapTest`, tambahan di `PeriodeSurveiTest`.

### Increment 155 — keterhubungan kuesioner ↔ aturan, neraca, turunan

- Permintaan user: dari aturan/neraca/turunan harus kelihatan blok kuesioner mana yang dipakai.
- `App\Services\BlockLinks::for($qv)`: nama di rumus dibaca (`ExpressionEvaluator::referencedNames`) lalu
  dipetakan ke blok asal — rincian → bloknya (+ akhiran ringkasan daftar anggota `__n` dst.), agregat →
  `block_code`, turunan → blok semua nama di rumusnya (turunan bertingkat ditelusuri, aman dari putaran),
  aturan per-baris → `row_block` + rumus. Juga "dipakai oleh" (aturan/turunan yang memakai suatu agregat,
  turunan, parameter) dan rangkuman per blok.
- Tampilan: chip **Blok X** (tautan ke daftar aturan tersaring) di tiap aturan, agregat, turunan;
  keterangan "dipakai oleh …" di agregat/turunan/parameter; filter **blok** di daftar aturan (`?blok=`, juga
  menyaring agregat & turunan di tab Neraca; parameter disembunyikan saat tersaring); di tab Kuesioner tiap
  blok: "Terhubung: N aturan · N agregat · N turunan".
- Tes: `BlockLinksTest`.

### Increment 154 — Periode Survei: tahun → triwulan → survei → hub (ala Google Classroom)

- Rancangan disetujui user lewat prototipe `docs/rancangan-ui-periode-triwulan.html` (mode terang).
  Keputusan: susun **waktu dulu, baru survei** (SERUTI tiap triwulan, SUSENAS TW I & III — petugas
  sering sama, jadi beban gabungan per triwulan terlihat); Jan–Mar = TW1 dst.; akun & wilayah tetap
  data bersama; nama aplikasi tetap; SERUTI masuk data contoh.
- **Data**: kolom `survey_cycles.survey` (SUSENAS/SERUTI) & `instrument` (KOR/KP/INTI/MAK/MODUL).
  Data lama diisi dari nama + kode versi (`SurveyCycle::inferIdentity`); siklus baru tanpa isian ini
  ikut ditebak (event `creating`). `year()/quarter()/label()/monthName()`, scope `inQuarter()`.
  Form buat siklus: `period` wajib `YYYY-MM`.
- **Halaman** (`PeriodeController`, `role:pj,pml,admin`): `/periode` kartu tahun → `/periode/{tahun}`
  4 kartu triwulan (kosong = "+ Siapkan triwulan ini") → `/periode/{tahun}/tw/{n}` kartu per survei +
  tabel beban petugas gabungan → hub `/periode/siklus/{cycle}` (Ringkasan: "Langkah berikutnya"
  mengikuti status, angka, tahap sampel, aturan tersering) + `/periode/siklus/{cycle}/peserta`
  (Pencacah, Pengawas, Penanggung Jawab dari penugasan sampel, progres, dikembalikan).
- **Tab hub memakai halaman lama**, tidak diduplikasi: Kuesioner (`admin.metadata?qv`), Aturan
  (`admin.aturan?qv`), Sampel (`monitoring.samples?cycle`, + tombol Impor sampel/isian untuk admin),
  Pengolahan (`pengolahan.index?cycle` — filter siklus baru di `CleaningController::scoped`),
  Persiapan & Penutupan (persiapan saat persiapan/lapangan, laporan siklus saat pengolahan/selesai).
  Kepala hub `periode/_hub_header` (jejak, pemilih survei sejawat, tab per peran via
  `PeriodeController::hubTabs`) tampil di halaman-halaman itu bila terikat satu siklus; pemilih
  versi lama `_qv_switch` hanya tampil bila versi tanpa siklus.
- **Siapkan triwulan**: `POST /periode/{tahun}/tw/{n}/siapkan` (admin) — survei terpilih disalin dari
  siklus terbaru survei+instrumen yang sama lewat `QuestionnairePackage` export→import (blok, rincian,
  opsi, baris tetap + harga, parameter, agregat, turunan, aturan) ke **siklus baru** berstatus
  persiapan; kode versi disarankan `PeriodeController::suggestCode` (mis. VSEN26.KP → VSEN26.KP-TW2).
  Menutup celah lama: duplikasi versi selalu masuk siklus yang sama.
- **Versi siklus terkunci = hanya-lihat**: trait `Models\Concerns\GuardsLockedVersion` pada Block,
  Question, ResponseOption, BlockRow, ValidationRule, Parameter, Aggregate, DerivedField menolak
  simpan/hapus (423) bila siklusnya `selesai`; toggle massal dicek manual. Tampilan: partial
  `_ro_version` (catatan + formulir pengubah disembunyikan; yang tetap boleh diberi `.ro-allow`).
- **Menu samping**: kotak "Triwulan berjalan" (siklus lapangan terbaru, view composer) + "Periode
  Survei"; Metadata, Impor Rincian, Aturan Validasi, Jadwal, Impor Sampel, Impor Isian keluar dari
  menu (alamat lama tetap jalan); tersisa "Data bersama": Wilayah, Pengguna. Petugas tak berubah.
- Data contoh: `DatabaseSeeder` memanggil `Susenas2026CycleSeeder` (SERUTI TW1–TW4, SUSENAS
  September) **kecuali saat tes** — dataset tes tetap 3 versi.
- CSS: kotak centang tidak lagi teregang oleh aturan lebar penuh formulir; tab hub turun baris di
  ≥600px, strip geser di HP. Cek 13 halaman × 9 ukuran: bersih.
- Lanjutan (masukan user): halaman tahun memakai **baris triwulan** memanjang ke samping, tersusun
  ke bawah (pita status di kiri); di tab Kuesioner hub, tabel versi hanya berisi versi siklus itu
  (dulu semua versi semua siklus) dan duplikasi diganti tautan "Siapkan triwulan".
- **Tombol kembali** seragam: komponen `<x-back href label [smart]>` (gaya `.back-link`, warna aksen
  tema supaya kontras di terang/gelap). `smart` = kembali ke halaman sebelumnya di aplikasi bila ada
  (filter ikut terbawa), selain itu ke halaman induk. Dipasang di tingkat Periode, kepala hub, Sunting
  blok/rincian/aturan, Buku kode, Pratinjau, Impor Rincian/Sampel/Isian/Paket, Jadwal, Akun, Notifikasi,
  Rincian sampel, Entri, Cetak, Pengolahan per sampel.
- **Peta Progres per siklus**: pemilih siklus (`monitoring/_cycle_pick`, `MonitoringController::pickCycle`),
  bawaan siklus lapangan terbaru; "Semua siklus (gabungan)" tetap ada. Dulu semua siklus dijumlah jadi satu.
- Perbaikan dari uji coba user: tombol **Pratinjau** blok membuka blok yang dituju (dulu tetap
  terlipat); lompatan ke jangkar tidak lagi menggeser `<body>` yang terkunci (tombol kembali tertutup
  bilah atas, tak bisa digulir balik — penjaga di `app.js`); di tab Kuesioner, blok daftar baris tetap
  menampilkan **Baris / rincian** (komoditas) + **Kolom isian** (dulu hanya kolom, label "Rincian"
  menyesatkan); **tambah komoditas** langsung dari tab Harga & Kalori (`hargaAdd`, beserta harga &
  kalori), petunjuk lama "Metadata → Blok IV.1" diganti tautan Sunting blok.
- Tes: `PeriodeSurveiTest` (13).

### Increment 153 — Aturan Validasi lebih ringkas: formulir sendiri, periksa langsung, periksa ulang

- **Halaman daftar** (`/admin/aturan`) kini hanya untuk pekerjaan harian: nyalakan/matikan, ubah ambang,
  tombol **+ Buat aturan baru**. Kamus variabel, formulir terpandu, mode lanjutan, dan daftar ekspresi
  dipindah dari halaman ini.
- **Formulir sendiri** `admin/rule_form.blade.php`: `/admin/aturan/{rule}` (ubah) dan `/admin/aturan-baru`
  (buat; dibuka dengan formulir terpandu, `?mode=rumus` = tulis rumus sendiri). Tiga bagian:
  1 Apa yang diperiksa (kode, tingkat, "Diperiksa": sekali per sampel / tiap baris blok — pilihan,
  bukan ketik kode), 2 Rumus (kotak beberapa baris), 3 Pesan. Jenis aturan, variabel sasaran,
  keterangan masuk "Pengaturan lain". Bantuan rumus + kamus variabel tertutup bawaan. Hapus aturan
  di panel terpisah paling bawah.
- **Periksa langsung** saat mengetik: `POST /admin/aturan-pratinjau` (`rulePreview`) → rumus terbaca /
  tidak, kena di N dari M sampel, dan **contoh pesan** yang placeholder-nya sudah terisi dari sampel
  pertama yang kena (`RuleEngine::previewExpression(..., $message)` → `example`).
- **Jumlah sampel yang kena** per aturan di daftar (dari `rule_results` terakhir), tautan ke
  Pengolahan `?rule=KODE`.
- **Periksa ulang setelah aturan berubah**: kolom baru `questionnaire_versions.rules_changed_at`
  (diisi trait `Models\Concerns\MarksRulesChanged` pada ValidationRule/Parameter/Aggregate/DerivedField,
  plus manual di toggle massal & simpan/impor patokan harga). `QuestionnaireVersion::staleDocuments()` =
  isian yang snapshot-nya lebih tua (siklus terkunci dilewati). Daftar aturan menampilkan kotak
  "N sampel belum diperiksa ulang" + tombol **Periksa ulang sekarang** (`POST /admin/aturan-periksa-ulang`).
- `saveRule`: rumus rusak / kode ganda → kembali ke formulir dengan pesan (dulu 400), sukses kembali ke
  daftar versi yang benar.
- CSS: `.stack-form` kolom `minmax(0,1fr)` + select/input `max-width:100%` — opsi select yang panjang
  tidak lagi melebarkan formulir di HP. Cek ulang 40 halaman × 4 lebar: bersih.
- Tes: `RuleWorkflowTest`.

### Increment 152 — halaman "Persiapan periode" + pemeriksaan versi pindah ke buka lapangan

- `App\Services\CyclePrep::check($cycle)` — daftar periksa sebelum siklus dibuka untuk lapangan.
  **Wajib** (menahan): versi kuesioner ada; versi utuh (`VersionChecker`, tanpa rujukan putus);
  sampel sudah dimuat; semua sampel punya Pencacah Lapangan. **Disarankan**: aturan menyala,
  patokan harga komoditas terisi (blok bertanda `harga_kalori`), pengawas, akun petugas aktif,
  kode wilayah sampel terdaftar, tanggal lapangan. Versi yang diperiksa = versi terbaru siklus
  (`CyclePrep::versionFor`, sama dengan `createDocument`).
- Halaman `/admin/siklus/{cycle}/persiapan` + tombol **Buka periode lapangan**
  (`POST …/buka-lapangan`, `force=1` = paksa, tercatat `buka-lapangan` di log audit dengan `DIPAKSA`).
  Ditautkan dari Jadwal (siklus berstatus persiapan) dan laporan siklus.
- Form Jadwal: mengubah status ke `lapangan` ikut ditahan daftar periksa; siklus **baru** tidak boleh
  langsung dibuat berstatus lapangan.
- **"Jadikan aktif" → "Jadikan bawaan"** tidak lagi ditahan pemeriksaan versi (sejak Inc 148 versi
  bawaan tidak memengaruhi petugas); hasil pemeriksaan tetap ditampilkan sebagai informasi.
  Penahan pindah ke pembukaan lapangan — di situlah versi rusak bisa sampai ke petugas.
- Tes: `CyclePrepTest`, `VersionFlexibilityTest::test_rujukan_putus_menahan_buka_lapangan_bukan_versi_bawaan`.

### Increment 151 — tampilan dirapikan: satu keluarga huruf, teks bantu dipangkas

- Judul memakai IBM Plex Sans (Serif dibuang dari pemuatan font); monospace hanya untuk kode
  (`.mono`, `textarea.mono`). Label tabel, KPI, chip, `h3`, pemisah menu: tanpa huruf kapital
  berjarak.
- Teks bantu panjang dipangkas (Aturan Validasi: 4 paragraf → 1 baris per tab; Wilayah; Sunting
  blok; Pengguna). Panel "Aturan, neraca…" di Metadata → satu baris tautan. Panel "⚡ Aksi cepat"
  di laporan siklus → "Pintasan" biasa. Tanda pisah "—" di kalimat diganti titik/koma.
- Tugas Saya: panel kosong disembunyikan; bila semuanya kosong tampil satu kalimat.
- Chip dikurangi: chip jenis blok di halaman entri dibuang; nama siklus di judul jadi teks kecil.
- Perbaikan tampilan HP: tabel tahap laporan siklus dibungkus `.tablewrap`; nama variabel panjang
  di ringkasan/label boleh patah baris. Cek ulang 39 halaman × 360/412/768/1024 px: semua bersih.

### Increment 150 — bahasa antarmuka: label, bukan kode

- `App\Support\Istilah` — terjemahan nilai DB ke teks: tingkat aturan (`error` → Kesalahan,
  `warning` → Peringatan), status siklus (+ dampak ke petugas, `CYCLE_STATUS_HELP`), jenis blok,
  jenis isian, jenis aturan (`scope`), aksi & jenis data di Log Audit/Riwayat. Alias Blade
  `Istilah::` dan `Tahap::` didaftarkan di `AppServiceProvider`.
- Istilah diganti: response rate → angka respons, eligible → memenuhi syarat, Wilayah scope →
  Cakupan wilayah, Username → Nama pengguna, cleaning → pengolahan/pemeriksaan, Sev/Dok →
  Tingkat/Sampel, Row block → Periksa per baris blok, var_name → Variabel, Skip bila → Lewati bila.
  Penyebutan "Inc NNN" dibuang dari teks antarmuka.
- Kolom **Variabel** & **Lewati bila** di Metadata/Sunting blok disembunyikan; muncul lewat centang
  "Tampilkan detail teknis" (diingat per peramban, `localStorage['susenas.tech']`).
- Pemilih versi: "(aktif)" → "(bawaan)" + keterangan siklus pemakai versi itu (peringatan lama
  "entri pencacah tetap pakai versi aktif" sudah salah sejak Inc 148, dibuang).
- Tes: `IstilahTest`.

### Increment 149 — satu urutan tahap sampel

- `App\Support\Tahap` menggabungkan `sample_households.status` + `documents.status` menjadi satu
  tahap yang dilihat pengguna: Belum dikunjungi → Sedang diisi → Menunggu pemeriksaan →
  Dikembalikan ke petugas → Bersih, plus Non-respons / Diganti sampel lain (tanda manual,
  mengalahkan status isian). Kolom DB tetap dua.
- Daftar Sampel, Tugas Saya, Rincian sampel versi: dua kolom status → satu kolom **Tahap**; filter
  `?tahap=` (`Tahap::filter`). Dashboard & laporan siklus: tabel "Status pencacahan" + "Alur
  pengolahan" → satu tabel **Tahap sampel** (tautan ke daftar terfilter). Chip isian mentah
  (`draft`, `selesai`, …) di entri/pengolahan/cetak → label tahap. Warna `.chip.t-*`.
- Tanda manual pengawas dibatasi: `setStatus` hanya menerima `non_respons` (wajib alasan) atau
  `batal` (kembali ke tahap otomatis, `Tahap::autoSampleStatus`; ditolak bila sampel sudah punya
  pengganti). Ganti sampel tetap lewat "Buat sampel pengganti". Status lain berjalan otomatis.
- Tes: `TahapTest`, `SampleReplacementTest` (diperbarui).

### Increment 148 — akses petugas diatur status siklus, bukan versi aktif

- Jebakan Inc 135: mengaktifkan versi periode berikutnya mengunci petugas yang masih di lapangan.
  Sekarang `questionnaire_version.is_active` hanya versi bawaan menu admin.
- `SurveyCycle::isFieldOpen()` (status `lapangan`), `Document::editableByPetugas()` (lapangan,
  atau isian `dikembalikan` selama siklus belum `selesai`). Di luar itu petugas hanya melihat
  (banner "Periode lapangan sampel ini sudah ditutup"). Isian baru hanya saat lapangan.
- **Celah akses ditutup**: sebelumnya petugas bisa membuka/mengubah `/entri/{id}`, `/entri/{id}/cetak`,
  dan `/monitoring/sampel/{id}` milik petugas lain lewat alamat. Sekarang dicek kepemilikan
  (`petugas_id`) & cakupan wilayah (`EntryController::assertCanView/assertCanEdit`,
  `MonitoringController::sample`, `createDocument`, unggah foto kunjungan).
- Finalisasi isian yang sudah `bersih` kini ditolak 409 (buka kunci lewat Pengolahan).
- Tes: `PetugasAccessTest`; helper `TestCase::openForPetugas()`.

### Increment 147 — singkatan ditulis lengkap di antarmuka; PPL/PCL diseragamkan

- Permintaan: seragamkan PPL & PCL, dan singkatan jangan disingkat.
- Teks antarmuka (view, pesan sistem, label peran, JavaScript) kini ditulis lengkap:
  PCL/PPL → Pencacah Lapangan; PML → Pengawas Lapangan; PJ → Penanggung Jawab;
  BS → Blok Sensus; Ruta → Rumah Tangga (kolom nomor: "Nomor Rumah Tangga");
  KRT → Kepala Rumah Tangga; ART → anggota rumah tangga (label baris roster
  "Anggota 1, 2, …"; tombol "Terapkan jumlah anggota"); SLS → Satuan Lingkungan
  Setempat; DSRT → Daftar Sampel Rumah Tangga; IPDS → Seksi Integrasi Pengolahan dan
  Diseminasi Statistik; QC → pemeriksaan mutu; P0 → Persentase miskin; MBG → Makan
  Bergizi Gratis; "Kec." → Kecamatan; "dok #" → "isian #".
- `User::ROLE_LABELS`: "Penanggung Jawab Kecamatan", "Pengawas Lapangan / Pengolah".
- Kalimat developer "Skip pattern tidak berlaku di blok berulang pada MVP ini" (Sunting
  blok) diganti "Syarat lompatan (skip) belum berlaku untuk blok berulang — hanya untuk
  blok per rumah tangga". Bantuan roster di layar entri tak lagi menyebut "R301 / Blok
  POKOK" secara tetap (bertentangan dengan Inc 145).
- **Tidak diubah**: nama variabel/kode (`art`, `no_bs`, `IDENT-BS`), nama format berkas
  (CSV, JSON, PDF), nama lembaga (BPS), singkatan umum ("mis."), dan **isi kuesioner**
  (judul blok, label rincian, pesan aturan) — itu data yang disunting admin di Metadata/
  Aturan Validasi.
- Tes `PjRoleTest` disesuaikan dengan label peran baru. 252 tes lulus.

### Increment 146 — total sampel & rincian sampel per versi kuesioner

- Permintaan: di tabel Versi kuesioner (Metadata), selain "sampel terisi" tampilkan
  **total sampel**, plus rincian sampel per kecamatan & desa beserta petugasnya.
- Kolom "Sampel" di Metadata: "**N terisi** dari M" + tautan **Rincian sampel →**.
  Total = sampel di siklus versi itu (`cycle_id`), tanpa yang berstatus `ganti_sampel`
  (sama dengan Dashboard). Terisi = jumlah dokumen versi itu (seperti sebelumnya).
- Halaman baru `/admin/versi/{qv}/sampel` (`AdminController::versionSamples`, view
  `admin/versi_sampel.blade.php`, admin saja): KPI (total, terisi, belum diisi, jumlah
  kecamatan & desa); tabel **per kecamatan** (desa, total, terisi, selesai dicacah, PCL,
  PML); tabel **per petugas** (jumlah sampel, terisi, desa yang dipegang); lalu rincian
  per kecamatan (dapat dilipat) → per desa → tiap ruta (BS, ruta, KRT, PCL, PML, status
  sampel, status isian, tautan ke halaman sampel). Kecamatan = 7 digit pertama kode wilayah
  (`WIL_LEN['kecamatan']`).
- Tes `test_rincian_sampel_per_versi`. 252 tes lulus.

### Increment 145 — struktur kuesioner tidak terikat kode blok / nama variabel

- Pertanyaan user: "100 tahun ke depan blok komoditas bisa saja pindah jadi Blok V —
  apakah sistem fleksibel?" Jawaban audit: hampir, tapi 4 titik masih tertulis di program.
  Keempatnya ditutup:
- **Tanda blok Harga & Kalori** — kolom baru `blocks.harga_kalori` (migrasi
  `2026_10_28_090000`; blok `IV.1` lama ditandai otomatis). `AdminController::hargaBlocks()`
  memakai tanda ini, bukan `code = 'IV.1'`. Dicentang di **Sunting blok** (hanya untuk
  jenis `list`). Ikut di `duplicateVersion`, `Susenas2026CycleSeeder`, paket JSON
  (`QuestionnairePackage`; paket lama tanpa tanda → blok `IV.1` dianggap blok harga).
- **Kode blok bisa diganti** di Sunting blok (`updateBlock`): unik per versi, format
  huruf/angka/`.`/`_`/`-`. Dalam satu transaksi `validation_rules.row_block` &
  `aggregates.block_code` yang menunjuk kode lama ikut diganti.
- **Parameter sistem** (`Parameter::SYSTEM` + `Parameter::systemVars()`): nama variabel
  kunci jadi parameter per versi, bawaan = perilaku lama. `var_jumlah_art` (dulu
  `EntryController::ART_COUNT_VARS`), `var_pengeluaran_per_kapita`, `var_makanan_sebulan`,
  `var_ruta_sebulan` (dulu tertulis di `TabulationController::indicators`; kini per versi
  baris — `scopedRows` membawa `documents.qversion_id`). Daftarnya tampil di tab
  Neraca & Turunan → "Parameter sistem".
- **Pengecek versi** `App\Services\VersionChecker` — error: aturan/agregat menunjuk blok
  yang tak ada, kolom agregat tak ada di bloknya, ekspresi aturan/turunan merujuk variabel
  yang tak ada (aturan diam-diam tak pernah menyala), sintaks rusak, parameter sistem
  menunjuk variabel yang tak ada. Peringatan: `ref_<kunci>` belum diisi di baris mana pun,
  ref pembobot/penyaring agregat tak ada, skip merujuk variabel tak ada, blok berpatokan
  harga belum ditandai. Nama yang "tersedia" meniru `RuleEngine::buildContext()` /
  `rowContexts()`. Dipakai di: **Jadikan aktif** (error → versi ditolak + daftar alasan;
  "Aktifkan tetap" = `force=1`, tercatat "DIPAKSA" di log audit), tombol **Periksa** per
  versi, dan `susenas:health` (#11, versi aktif).
- `ExpressionEvaluator::referencedNames()` — baca-saja: urai ekspresi → daftar nama
  variabel (termasuk argumen-nama `roster_*`). Tidak mengevaluasi.
- Metadata: label jenis kini tampil untuk **semua** blok (termasuk "per rumah tangga"),
  plus label "harga & kalori" pada blok bertanda.
- Temuan pada data seed: VSEN27.KP aturan `IV1-KUANTITAS-MAX` memakai `ref_max_kons` yang
  belum diisi di komoditas mana pun → peringatan (bukan error).
- Tes baru `VersionFlexibilityTest` (10 tes: ganti kode blok + cascade, kode ganda ditolak,
  tanda harga ikut duplikasi & menentukan isi tab, paket JSON lama/baru, aktivasi ditolak/
  dipaksa, versi seed lolos, parameter sistem, referencedNames, label jenis). 251 tes lulus.

### Increment 144 — validasi var_name rincian (format + unik per versi)

- Temuan: admin menambah rincian Blok III dengan var_name `harga rumah` (berspasi) dan
  formulir menerimanya. Nama berspasi tidak bisa dirujuk mesin aturan/turunan/ekspor.
- `AdminController::VAR_NAME_RE` = `^[a-z][a-z0-9_]*$` ditegakkan di `addQuestion`,
  `updateQuestion` (lewat `questionData`) dan `importCsv` (baris dilewati + alasan).
- `varNameTaken()` — var_name wajib unik di **seluruh versi** (bukan cuma per blok):
  semua var satu versi berbagi satu konteks aturan, nama ganda saling menimpa diam-diam.
  Menyunting rincian sendiri dengan nama tetap tidak dianggap ganda.
- Catatan pertanyaan user "kenapa tampilan Blok III beda dengan Blok IV": bentuk layar
  entri ditentukan **jenis blok** (`ruta` = isian ke bawah; `list` = tabel baris tetap),
  bukan tipe rincian (`decimal`). Bukan bug.
- Data lama tidak diubah otomatis: rincian 306 (`harga rumah`, VSEN26.KP) perlu diganti
  namanya oleh admin lewat Sunting. 241 tes lulus.

### Increment 143 — pratinjau tampilan entri di sisi admin

- Permintaan: "apakah bisa tampil sisi admin tampilan sensus di sisi operator entri?"
  → **Pratinjau Tampilan Entri** di menu admin: `/admin/pratinjau/{qv}`.
- **Bukan salinan** — view entri dipecah jadi partial `entri/_form.blade.php` (isi
  formulir) & `entri/_ringkasan.blade.php` (panel samping), lalu dipakai oleh dua
  halaman: `entri/document.blade.php` (petugas) & `admin/pratinjau.blade.php` (admin).
  Pratinjau dirender dengan `EntryController::formState()` yang sama → yang dilihat
  admin **dijamin identik** dengan layar petugas.
- Pratinjau **tidak menyimpan apa pun**: `Document` & `SampleHousehold` dibuat di memori
  (belum disimpan) → isian kosong, tapi baris/baris komoditas tetap tampil utuh
  (Blok IV.1 = 194 baris × 7 kolom, ±1.670 sel).
- `EntryController::previewForm()` — read-only (`canEdit=false`): tombol finalisasi,
  catatan, dan bukti kunjungan disembunyikan; tidak ada POST simpan jawaban.
- Tautan: di halaman **Metadata** — tombol "Pratinjau" per blok (membuka Blok tsb)
  dan "Pratinjau entri" per baris versi. Route `admin.pratinjau` (role admin).
- Tes: `test_pratinjau_entri_menampilkan_tampilan_petugas` (200, `data-can-edit="0"`,
  baris komoditas utuh, tak ada dokumen tersimpan, operator Forbidden). 240 tes lulus.

### Increment 142 — istilah "dokumen" diselaraskan jadi "sampel" di antarmuka

- Klarifikasi istilah: **sampel** (`sample_households`) = satu rumah tangga (KRT) dalam
  kerangka sampel; **dokumen** (`documents`) = satu **pengisian kuesioner** atas sampel itu
  — dibuat saat petugas membuka sampel (`MonitoringController::createDocument`, status
  `draft`), lalu `selesai` → `dikembalikan` → `bersih`. Jadi 1 sampel = 1 dokumen, dan
  "dokumen" = "sampel yang sedang/baru diisi".
- Permintaan: ganti kata "dokumen" dengan "total sampel" & "sampel yang telah diisi".
  Yang berubah **hanya teks antarmuka** — nama tabel/model/route (`documents`, `Document`,
  `entri.form`) tidak disentuh, dan nilai `scope` di DB tetap `'dokumen'`.
- **Dashboard** — KPI: "Ruta sampel aktif" → **Total sampel**; ditambah KPI baru
  **Sampel terisi** (`$nDocs` = jumlah dokumen dalam cakupan); "Dokumen ada error/warning"
  → "Sampel ada error/warning"; tabel corong & aturan: kolom "Dokumen" → "Sampel".
- Halaman lain: Kinerja Tim, Daftar Sampel, Tugas Saya, halaman detail sampel,
  Pengolan (index & detail), Tabulasi & Indikator, Jadwal Siklus (→ "Sampel terisi"),
  Metadata (daftar versi → "sampel terisi"), Entri & cetak (→ "Cetak sampel #id",
  "Catatan isian"), Aturan Validasi (label scope "Tingkat sampel", tester & pratinjau
  dampak), sidebar **"Impor Dokumen" → "Impor Isian (CAPI)"** (bukan "Impor Sampel" —
  itu sudah dipakai untuk kerangka sampel).
- Pesan sistem (flash/abort) ikut: "Sampel sudah bersih (terkunci)", "Sampel #id ditandai
  bersih", "Dokumen dikembalikan ke petugas" → "Sampel …", "n dokumen" → "n sampel".
- Tes: `PrintViewTest` disesuaikan ("Catatan isian"). 239 tes lulus.

### Increment 73 — SERUTI ditunda dari seed default

- Atas permintaan: fokus SUSENAS dulu. `$this->call(SerutiSeeder::class)`
  dinonaktifkan di `DatabaseSeeder` (komentar + cara jalan manual). Berkas
  `SerutiSeeder`, `database/data/seruti_iv2a.csv`, `docs/modul-seruti.md`, dan
  `SerutiModuleTest` tetap ada. `SerutiModuleTest::setUp` kini memanggil
  `SerutiSeeder` sendiri.
- Seed default kembali 3 versi kuesioner (VSEN26.KP / .K / VSEN27.KP). 149 tes lulus.

### Increment 72 — modul SERUTI (kerangka): VSERUTI26.INTI + Blok IV.2.A

- `database/seeders/SerutiSeeder.php` + `database/data/seruti_iv2a.csv` (52 rincian
  dari `WTF Susenas Seruti Maret 2026.xlsx`, sheet `SERUTI IV.2`).
- Siklus **SERUTI Maret 2026**, versi **VSERUTI26.INTI**. Blok POKOK (banyak_art)
  + **IV2A** (`list`, 53 baris): rincian 401–451 pengeluaran bukan makanan,
  kolom `siv2a_sebulan` & `siv2a_tribulan`. `ref` baris: `{kel, level}`
  (detail/subtotal/total).
- 4 agregat (Σ rincian detail vs isian TOTAL R451, sebulan & tiga bulan) + 4 aturan:
  `SRT-IV2A-NEG` (negatif), `SRT-IV2A-TRIBULAN` (3-bln < 1-bln), `SRT-IV2A-TOTAL-1/-3`
  (TOTAL R451 ≠ Σ rincian, ambang Rp1.000).
- 3 dokumen contoh (2 konsisten → bersih, 1 TOTAL tak cocok → menyala).
- Belum: Blok IV.2.B (kesehatan/pendidikan), Blok V (pendapatan/penerimaan,
  roster ART), Blok VI–VII (posisi aset & kewajiban).
- 149 tes lulus (`SerutiModuleTest`: versi terseed, aturan TOTAL menyala/bersih
  setelah koreksi, form entri Seruti render).

### Increment 7 — tabulasi & ekspor

- `document_snapshots` (JSON) — seluruh konteks skalar dokumen (jawaban + parameter
  + agregat + turunan + kalori + `_n_err`/`_n_warn`) disimpan tiap `RuleEngine::run()`.
  Tabulasi & ekspor membaca snapshot, bukan menelusuri `answers`.
- **Tabulasi** (`/tabulasi`) — pilih variabel × kelompok (kecamatan/desa/petugas/status/none)
  × agregasi (count/mean/sum/min/max/distribusi). Rekap **internal untuk pra-pengolahan &
  QC — bukan estimasi resmi** (tanpa penimbang).
- **Ekspor CSV** — data datar (1 baris/dokumen, kolom = semua variabel snapshot) &
  daftar warning (1 baris/temuan aturan). Hanya peran pml/admin; operator diblokir (403).

## Mesin ekspresi — `app/Services/ExpressionEvaluator.php`

Tokenizer + parser recursive-descent + tree-walker. Daftar putih ketat:
angka, string, nama (dari konteks), aritmetika, perbandingan (termasuk berantai
`a < b < c`), and/or/not, daftar, dan fungsi:
`blank filled num n coalesce abs round floor min max sum any_filled all_filled count_filled`.

**Tanpa** akses properti/method, indexing, atau `eval()` PHP. Diuji menolak
`phpinfo()`, `system(...)`, `x.foo`, `a["b"]`, `1;2`. Semantik (koersi angka,
koma desimal, TRUE = ada masalah) disamakan dengan prototipe Python — hasil
aturan atas data seed identik.

## Struktur

```
app/Models/            16 model Eloquent
app/Services/          ExpressionEvaluator, RuleEngine, RuleException
app/Http/Controllers/  Auth, Monitoring, Entry, Admin
app/Http/Middleware/   EnsureRole  (alias 'role', dipakai 'role:admin' dst.)
database/migrations/   3 migrasi (users+, metadata, operasional)
database/seeders/      DatabaseSeeder  (port seed.py)
resources/views/       Blade (layout + auth + monitoring + entri + admin)
public/css|js/         app.css, app.js (dari prototipe, + header CSRF)
routes/web.php
```

## Produksi (belum dikerjakan)

- Ganti login lokal → SSO BPS / Keycloak
- MySQL/PostgreSQL, HTTPS, rate limit, header keamanan
- Queue untuk impor besar & tabulasi, Scheduler untuk rekap
- Data wilayah/sampel/garis kemiskinan di seed adalah **dummy**

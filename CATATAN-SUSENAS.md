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

| Username    | Sandi         | Peran                        |
|-------------|---------------|------------------------------|
| `admin`     | `admin123`    | Administrator                |
| `rahmawati` | `pengawas123` | Pengawas (PML), wilayah 3526 |
| `ppl_ani`   | `operator123` | Operator entri               |
| `viewer`    | `viewer123`   | Peninjau                     |

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
  validasi **panjang kode per jenjang** (prov 2, kab 4, kec 6, desa 10),
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

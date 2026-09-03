# Sistem SUSENAS — panduan agen

Aplikasi web internal BPS: **pemantauan lapangan** + **entri, pengolahan, validasi**
data SUSENAS. Laravel 13, PHP 8.3, SQLite (dev). **Metadata-driven** — kuesioner
tiap semester = `questionnaire_version` baru (data), bukan perubahan kode.

## Menjalankan

- PHP tidak selalu di PATH. Laragon: `C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php`.
  Di bash prefiks: `export PATH="/c/laragon/bin/php/php-8.3.33-Win32-vs16-x64:$PATH"`.
- `.\run.ps1` (opsi `-Fresh`, `-Port`) menyiapkan + menyalakan di :8000.
- Tes: `php artisan test` (~13 dtk). Kelas tes pakai
  `protected $seed = true; protected $seeder = DatabaseSeeder::class;`
  (seed sekali per kelas) — **jangan** `$this->seed()` di `setUp`.
  Seed manual: `php artisan migrate:fresh --seed`.
  Seed produksi (tanpa dummy): `php artisan db:seed --class=MetadataSeeder`.
- Cek kondisi data: `php artisan susenas:health`. Cadangan: `php artisan susenas:backup`.
  Jadwal (backup harian, prune notif, health mingguan) di `routes/console.php` —
  jalan bila `php artisan schedule:run` dipanggil cron/Task Scheduler.
- Paket metadata: `php artisan susenas:qversion:export <code>` / `:import <file> [--check]`.
- Revalidasi massal setelah sunting aturan/agregat/turunan: `php artisan susenas:revalidate [--cycle=] [--all]`.
- Login seed: admin/admin123, rahmawati/pengawas123 (pml), ppl_ani/operator123
  (operator), siti_editor/editor123, viewer/viewer123.

## Arsitektur metadata

```
survey_cycle → questionnaire_version → block → question → response_option
                                     → block_row     (baris tetap: komoditas, dll.)
                                     → parameter / derived_field / aggregate / validation_rule
```

Semua bertag `qversion_id`. `block.repeat`: `ruta` (1 jawaban/ruta), `art` (roster
dinamis per ART), `list` (baris tetap dari `block_row`, mis. 194 komoditas).
Jawaban di `answers` (raw_value + clean_value; `art_index` = 0 utk ruta, nomor ART,
atau `block_row.row_index` utk list).

## Mesin ekspresi & aturan (kritis-keamanan)

- `app/Services/ExpressionEvaluator.php` — evaluator **daftar-putih ketat**:
  tokenizer + parser recursive-descent + tree-walker. **Tanpa `eval()`**, tanpa
  akses properti/method/indexing. Fungsi: `blank filled num n coalesce abs round
  floor min max sum any_filled all_filled count_filled ymd` + roster
  (`roster roster_count roster_all roster_any roster_first roster_max roster_sum`).
  Konvensi: **ekspresi TRUE = ada masalah**.
- `app/Services/RuleEngine.php` — `buildContext()` menyusun konteks dokumen
  (jawaban ruta, parameter, metadata sampel `sampel_wilayah/bs/ruta/krt`, agregat
  roster & NERACA, nilai turunan). `run()` menjalankan aturan tingkat-dokumen &
  per-baris, menulis `rule_results` + `document_snapshots` (dipakai tabulasi/ekspor).
- Aturan: `scope` = `dokumen`/`konsistensi`/`neraca`/`kalori`/`anomali`;
  `row_block` = kode blok utk aturan per-baris (konteks lokal + `_row_index` + `ref_<key>`).
- `IV1-HARGA` memakai `reference_prices` (rentang harga per wilayah, prefiks
  terpanjang menang) yang menimpa `block_row.ref` default.

## Alur kerja

Monitoring → Entri → Finalisasi → Pengolahan (koreksi ke *clean_value*, bukan
mentah) ⇄ Kembalikan ke petugas (status `dikembalikan` + notifikasi) → Tandai
bersih → Tabulasi/Indikator/Ekspor. Impor CAPI: `/admin/impor-dokumen` (format
lebar `var__n`, pratinjau dry-run, alias kolom, ekspor round-trip).

## Konvensi & jebakan

- `$request->validate(['x' => 'nullable|...'])` **tidak** menaruh `x` di hasil bila
  field absen → pakai `($d['x'] ?? null)`, jangan `$d['x'] ?: null`. (Sudah kena 3×.)
- Blade: jangan `@if...@endif` inline dalam `@foreach` satu baris — pakai `@continue()`.
  Jangan campur blok `@php...@endphp` dengan `@php(...)` inline di file yang sama.
  `kata@if(...)` (huruf tepat sebelum `@`) TIDAK dikenali sbg direktif → `@endif` jadi
  yatim; beri spasi atau pakai ekspresi `{{ $x ? '…' : '' }}`.
- `artCount`/kolom roster: hitung `art_index` HANYA dari blok `repeat='art'`,
  jangan sertakan blok `list` (row_index bisa s.d. 347).
- Blok `list` dikecualikan dari ekspor/impor format-lebar (baris tetap & besar).
- `duplicateVersion` harus menyalin block_rows + aggregates + row_block aturan.
- Impor besar dibungkus `DB::beginTransaction` + rollback saat mode pratinjau.

## Dokumentasi

- `CATATAN-SUSENAS.md` — log tiap increment (fitur, bug, keputusan).
- `docs/deployment.md` — rilis PDN, Docker Compose, worker, backup.
- `docs/pemetaan-aturan-sidona.md` — 92/108 aturan anomali Sidona diterjemahkan.
- `docs/modul-seruti.md` — status modul SERUTI (VSERUTI26.INTI); Blok IV.2.A selesai, IV.2.B/V/VI belum.
- `scripts/` — `pdftext.php`, `xlsx_sheet.php` (baca PDF/xlsx referensi), `build_iv2_csv.php`.
- Materi referensi asli: `c:\Users\User\OneDrive\Documents\susenas\WTF Susenas Seruti\`.

## Belum: modul Seruti, ~16 aturan Sidona ambigu, input sisi BPS (SSO, format ekspor
CAPI, deploy PDN, UAT, penimbang kalibrasi, wilayah/sampel/harga referensi asli).

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
- Login seed: admin/admin123, pj_kamal/pj123 (pj, wilayah 3526010), rahmawati/pengawas123
  (pml), ppl_ani/operator123 (operator), siti_editor/editor123, viewer/viewer123.
- Peran (`User::ROLE_LABELS`): `admin`, `pj` (Penanggung Jawab kecamatan — satu per
  kecamatan, kewenangan setara `pml` tapi wilayah_scope wajib kode kecamatan 7 digit,
  divalidasi di `AdminController::saveUser`), `pml`, `operator`, `viewer`. Gate `pj`
  ditambahkan ke setiap tempat yang mengecek `pml` (routes, controller, nav) — lihat
  Inc 121.

## Arsitektur metadata

```
survey_cycle → questionnaire_version → block → question → response_option
                                     → block_row     (baris tetap: komoditas, dll.)
                                     → parameter / derived_field / aggregate / validation_rule
```

Semua bertag `qversion_id`. `block.repeat`: `ruta` (1 jawaban/ruta), `art` (roster
dinamis per ART), `list` (baris tetap dari `block_row`, mis. 194 komoditas),
`list_art` (baris tetap × per ART — untuk MBG, mis. Blok IV.1M).
Jawaban di `answers` (raw_value + clean_value; `art_index` = 0 utk ruta, nomor ART,
`block_row.row_index` utk list, atau `row*1000 + no_art` utk list_art —
`Block::packRowArt` / `unpackRowArt`).

`questionnaire_version.is_active` **cuma 1 di seluruh sistem**, dan **hanya** menentukan versi
bawaan yang dibuka di menu admin (Metadata/Aturan) — **tidak** memengaruhi petugas maupun
dokumen (dokumen ikut `cycle_id` sampelnya, lihat `MonitoringController::createDocument`).
**Akses petugas (`operator`) diatur status siklus**, bukan versi aktif (Inc 148; dulu Inc 135
mengunci ke periode versi aktif — jebakan: mengaktifkan versi periode berikutnya mengunci
petugas yang sedang di lapangan):
- **mengubah** isian: `Document::editableByPetugas()` — siklus `lapangan` (`SurveyCycle::isFieldOpen`),
  atau isian `dikembalikan` selama siklus belum `selesai`; isian **baru** hanya saat `lapangan`.
- **melihat**: sampel miliknya (`petugas_id`) di siklus yang sudah lewat `persiapan`; di luar
  periode lapangan halaman entri tampil hanya-lihat.
- Kepemilikan & cakupan wilayah dicek di `EntryController::assertCanView` (entri + cetak) dan
  `MonitoringController::sample` (lewat `visibleSamples`) — sebelum Inc 148 `/entri/{id}` bisa
  dibuka/diubah petugas mana pun lewat alamat. Role lain (pml/pj/admin/viewer) tidak dibatasi periode.
- Tes yang butuh petugas mengisi: helper `TestCase::openForPetugas($doc, $user)`.
- Membuka lapangan (status → `lapangan`) melewati daftar periksa `App\Services\CyclePrep`
  (halaman **Persiapan periode** `/admin/siklus/{id}/persiapan`, Inc 152): butir wajib = versi ada &
  utuh (`VersionChecker`), sampel dimuat, semua sampel punya petugas. Form Jadwal ikut ditahan;
  `force=1` di tombol Buka periode lapangan = paksa (log audit). Di antarmuka `is_active` disebut
  **versi bawaan** ("Jadikan bawaan"), bukan "aktif".

## Tahap sampel & label antarmuka (Inc 149–150)

- **Satu tahap** per sampel = `App\Support\Tahap::of($sample, $doc)` (gabungan status sampel + isian):
  belum → diisi → diperiksa → dikembalikan → bersih, plus `non_respons`/`ganti_sampel` (tanda manual,
  mengalahkan isian). Tampilkan `Tahap::label()` + chip `t-{tahap}` — **jangan** cetak
  `$doc->status`/`$sample->status` mentah. Pengawas hanya bisa menandai `non_respons` atau `batal`
  (`MonitoringController::setStatus`); ganti sampel lewat `replace()`; selebihnya otomatis.
- Nilai kode DB → teks lewat `App\Support\Istilah` (`severity`, `cycleStatus`, `repeat`, `qtype`,
  `scope`, `audit`, `entity`). Alias Blade `Istilah::` / `Tahap::` (AppServiceProvider).
  Teks antarmuka: "Kesalahan/Peringatan" (bukan error/warning), "angka respons", "Cakupan wilayah",
  "Nama pengguna"; tanpa "Inc NNN". Kolom teknis (nama variabel, rumus lewati) diberi `class="tech"`
  + `@include('admin.partials._tech_toggle')` — tersembunyi kecuali "Tampilkan detail teknis".
- Huruf: satu keluarga IBM Plex Sans; `.mono` hanya untuk kode. Tanpa label kapital berjarak.

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
- Halaman Aturan Validasi (Inc 153): daftar harian di `/admin/aturan`; ubah/buat di halaman sendiri
  (`admin/rule_form.blade.php`, `/admin/aturan/{rule}`, `/admin/aturan-baru`) dengan periksa langsung
  (`rulePreview`). Perubahan aturan/parameter/agregat/turunan/patokan harga menandai
  `questionnaire_versions.rules_changed_at` (trait `MarksRulesChanged`; **update/delete massal lewat query
  builder tidak memicu event — panggil `QuestionnaireVersion::markRulesChanged()` sendiri**).
  `staleDocuments()` → kotak "belum diperiksa ulang" + tombol Periksa ulang sekarang.
- Aturan: `scope` = `dokumen`/`konsistensi`/`neraca`/`kalori`/`anomali`;
  `row_block` = kode blok utk aturan per-baris (konteks lokal + `_row_index` + `ref_<key>`).
- `IV1-HARGA-MAHAL`/`IV1-HARGA-MURAH` & `KALORI-*` memakai `block_rows.ref`
  (`harga_min`/`harga_max`/`kalori`/`max_kons` per komoditas Blok IV.1), disunting di
  tab **Harga & Kalori** di halaman **Aturan Validasi** (`/admin/aturan?tab=harga`;
  URL lama `/admin/harga` → redirect, Inc 141). Satu rentang per komoditas —
  tidak ada lagi override per wilayah (dihapus Inc 107, aplikasi 1 kabupaten).
  `max_kons`/`satuan`/`group` tetap disunting di Metadata → Blok (baris tetap);
  `harga_min`/`harga_max`/`kalori` **hanya** boleh diubah dari tab Harga & Kalori
  (`AdminController::HARGA_REF_KEYS` — dibuang dari `saveRow()`/`importRows()`).
  Blok yang tampil di tab itu = blok bertanda **`blocks.harga_kalori`** (Inc 145), **bukan**
  kode `IV.1` — jangan tulis ulang kode blok di program.

## Jangan ikat program ke kode blok / nama variabel (Inc 145)

- Kode blok boleh diganti admin (Sunting blok); `row_block` aturan & `block_code` agregat
  ikut diganti otomatis. Fitur yang butuh "blok tertentu" pakai **tanda di tabel `blocks`**
  (contoh: `harga_kalori`), bukan `where('code', ...)`.
- Fitur yang butuh "variabel tertentu" pakai **parameter sistem** `Parameter::SYSTEM` +
  `Parameter::systemVars($qvId, nama)` (bawaan = nama lama), bukan konstanta di controller.
  Tambah fitur baru → tambah entri di `Parameter::SYSTEM`.
- `App\Services\VersionChecker` memeriksa rujukan putus; dipakai saat **membuka lapangan**
  (`CyclePrep`, error → ditahan, `force=1` = paksa), tombol **Periksa**, "Jadikan bawaan"
  (hanya informasi, tidak menahan — Inc 152), dan `susenas:health`. Kalau
  menambah nama baru ke konteks `RuleEngine::buildContext()`/`rowContexts()`, tambahkan
  juga ke `VersionChecker::DOC_CTX`/`ROW_CTX` — kalau tidak, aturan yang memakainya
  dilaporkan "variabel tidak ada" dan lapangan siklusnya tidak bisa dibuka.
- Pesan aturan mendukung placeholder (`RuleEngine::interpolate()`): `{nama_var}` →
  nilai konteks, `{=ekspresi}` → hasil hitung; angka diformat gaya Indonesia. Dipakai
  aturan harga IV.1 untuk menampilkan harga satuan terhitung + rentang wajar.

## Alur kerja

Monitoring → Entri → Finalisasi → Pengolahan (koreksi ke *clean_value*, bukan
mentah) ⇄ Kembalikan ke petugas (status `dikembalikan` + notifikasi) → Tandai
bersih → Tabulasi/Indikator/Ekspor. Impor CAPI: `/admin/impor-dokumen` (format
lebar `var__n`, pratinjau dry-run, alias kolom, ekspor round-trip).

## Konvensi & jebakan

- **Istilah (Inc 142)**: **sampel** = satu rumah tangga (KRT) dalam kerangka sampel
  (`sample_households`); **dokumen** = satu *pengisian kuesioner* atas sampel itu
  (`documents`, 1:1 dengan sampel; dibuat saat petugas membuka sampel, status
  `draft`→`selesai`→`dikembalikan`→`bersih`). Di **teks antarmuka** pakai
  "sampel" (bukan "dokumen") — mis. "Total sampel", "Sampel terisi", "Sampel ada
  error". Nama tabel/model/route (`documents`, `Document`) dan nilai `scope='dokumen'`
  di DB **tetap** — jangan ikut di-rename.
- **Jangan pakai singkatan di teks antarmuka (Inc 147)**: tulis "Pencacah Lapangan"
  (bukan PCL/PPL), "Pengawas Lapangan" (PML), "Penanggung Jawab" (PJ), "Blok Sensus" (BS),
  "Rumah Tangga" (Ruta), "Kepala Rumah Tangga" (KRT), "anggota rumah tangga" (ART), dst.
  Pengecualian: nama variabel/kode, format berkas (CSV/JSON), BPS, dan isi kuesioner (data).
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
- **Kode wilayah statistik BPS**: kabupaten(4) + kecamatan(**+3**=7) + desa(**+3**=10) —
  BUKAN kecamatan(+2)/desa(+4) seperti yang pernah salah dibangun (Inc 118, diperbaiki
  Inc 125). Panjang resmi ada di `AdminController::WIL_LEN` — pakai konstanta itu,
  jangan tulis ulang angka `6`/`7`/`10` di tempat lain (sempat kejadian: pesan error PJ
  di `saveUser` punya angka `6` sendiri, lepas dari `WIL_LEN`).

## Dokumentasi

- `CATATAN-SUSENAS.md` — log tiap increment (fitur, bug, keputusan).
- `docs/deployment.md` — rilis PDN, Docker Compose, worker, backup.
- `docs/pemetaan-aturan-sidona.md` — 92/108 aturan anomali Sidona diterjemahkan.
- `docs/modul-seruti.md` — status modul SERUTI (VSERUTI26.INTI); Blok IV.2.A selesai, IV.2.B/V/VI belum.
- `scripts/` — `pdftext.php`, `xlsx_sheet.php` (baca PDF/xlsx referensi), `build_iv2_csv.php`.
- Materi referensi asli: `c:\Users\User\OneDrive\Documents\susenas\WTF Susenas Seruti\`.

## Belum: modul Seruti, ~16 aturan Sidona ambigu, input sisi BPS (SSO, format ekspor
CAPI, deploy PDN, UAT, penimbang kalibrasi, wilayah/sampel/harga referensi asli).

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- When graphify-out/graph.json exists, run `graphify query "<question>"` before your first grep, search or source-file read for a codebase question, and put this rule in every subagent prompt that explores code. Read raw files only for lines the graph or an earlier read already located. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. When the answer is under a Markdown heading, `graphify section "<heading>"` prints that section's text, so the .md need not be opened. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
- A committed graphify-out/graph.json is rewritten whole on every build, so a large diff to it in a pull request is expected. Do not flag it as churn or suggest skipping the graph rebuild or its hooks.

# Sistem SUSENAS

Aplikasi web internal BPS untuk **pemantauan lapangan** + **entri, pengolahan, dan
validasi data** SUSENAS (Survei Sosial Ekonomi Nasional). Dirancang **metadata-driven**:
kuesioner yang berubah tiap semester ditangani sebagai *versi kuesioner* baru (data),
bukan perubahan kode.

> Data wilayah, sampel, garis kemiskinan, dan seluruh isian pada seed adalah **dummy**
> untuk demo — bukan alokasi sampel atau angka resmi.

## Menjalankan (Windows / Laragon)

```powershell
.\run.ps1            # siapkan bila perlu, lalu jalankan di http://127.0.0.1:8000
.\run.ps1 -Fresh     # reset + seed ulang database
.\run.ps1 -Port 9000 # port lain
```

Manual:

```bash
composer install
php artisan key:generate          # sekali, jika APP_KEY kosong
php artisan migrate:fresh --seed
php artisan serve
```

Prasyarat: PHP 8.2+ (Laragon menyediakan 8.3), ekstensi `pdo_sqlite`. Database = SQLite
(`database/database.sqlite`), tanpa server DB terpisah.

## Akun contoh (dari seeder)

| Username | Sandi | Peran | Cakupan |
|---|---|---|---|
| `admin` | `admin123` | admin | semua |
| `rahmawati` | `pengawas123` | pml (pemeriksa) | 3526 |
| `siti_editor` | `editor123` | editor | 3526 |
| `ppl_ani` | `operator123` | operator (petugas entri) | — |
| `viewer` | `viewer123` | viewer | — |

## Alur kerja

```
Monitoring lapangan  ->  Entri kuesioner  ->  Finalisasi
                                              |
                              Pengolahan (cleaning) <-> Kembalikan ke petugas
                                              |
                                         Tandai bersih  ->  Tabulasi & ekspor
```

- **Entri** (`/entri/{doc}`): blok ruta, roster ART, dan daftar komoditas; autosave +
  validasi langsung; navigasi & lipat blok.
- **Pengolahan** (`/pengolahan`): koreksi pada *nilai bersih* (nilai mentah tetap
  tersimpan), rekap temuan lintas dokumen, revalidasi massal, kembalikan ke petugas.
- **Tabulasi** (`/tabulasi`): rekap per kelompok + agregasi tertimbang (design weight).
  Rekap **internal untuk QC — bukan estimasi resmi**.
- **Admin** (`/admin/...`): editor metadata kuesioner (blok, rincian, opsi, parameter,
  agregat, aturan validasi), impor CSV (wilkerstat, sampel, dokumen format lebar),
  jadwal lapangan, manajemen pengguna, duplikasi versi kuesioner.

## Arsitektur metadata

```
survey_cycle -> questionnaire_version -> block -> question -> response_option
                                       -> block_row (baris tetap: komoditas dsb.)
                                       -> parameter / derived_field / aggregate / validation_rule
```

Semua bertag `qversion_id`. Kuesioner baru = versi baru (lewat impor CSV / duplikasi versi),
tanpa deploy ulang.

### Mesin ekspresi & aturan

`app/Services/ExpressionEvaluator.php` — evaluator aman (tokenizer + parser
recursive-descent + tree-walker, **daftar putih ketat**, tanpa `eval()`, tanpa akses
properti/method). Konvensi aturan: **ekspresi TRUE = ada masalah**.

Fungsi: `blank filled num n coalesce abs round floor min max sum any_filled all_filled
count_filled ymd` + helper roster antar-ART (`roster`, `roster_count`, `roster_all/any`,
`roster_first`, `roster_max/sum`). Konteks juga memuat metadata sampel
(`sampel_wilayah`, `sampel_bs`, `sampel_ruta`, `sampel_krt`).

`app/Services/RuleEngine.php` — menyusun konteks dokumen (jawaban, parameter, agregat
roster/NERACA, nilai turunan), menjalankan aturan tingkat dokumen & per-baris, menulis
`rule_results` + `document_snapshots` (dipakai tabulasi/ekspor).

## Seed produksi (tanpa data dummy)

```bash
php artisan migrate:fresh
php artisan db:seed --class=MetadataSeeder
```

Membuat struktur + satu akun `admin` (sandi acak — atur ulang via `php artisan tinker`).

## Pengujian

```bash
php artisan test
```

Catatan lengkap tiap increment ada di `CATATAN-SUSENAS.md`.
Pemetaan aturan anomali Sidona di `docs/pemetaan-aturan-sidona.md`.

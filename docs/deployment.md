# Deployment Sistem SUSENAS (PDN / server internal BPS)

Aplikasi Laravel biasa. Belum ada SSO — login lokal + manajemen pengguna
(`/admin/pengguna`). Semua data seed adalah dummy.

## Prasyarat server

| Komponen | Versi | Catatan |
|---|---|---|
| PHP | 8.2+ | ekstensi: `pdo_pgsql` atau `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `intl`, `zip` |
| Web server | nginx + PHP-FPM (disarankan) | atau Apache + mod_php |
| Database | PostgreSQL 14+ **atau** MySQL 8.0.13+ | MySQL < 8.0.13 menolak `DEFAULT` pada kolom TEXT |
| Composer | 2.x | untuk `composer install` saat rilis |
| Supervisor / systemd | — | untuk worker antrean & scheduler |

## Langkah rilis pertama

```bash
git clone <repo> /var/www/susenas && cd /var/www/susenas
composer install --no-dev --optimize-autoloader
cp .env.production.example .env
# isi APP_URL, DB_*, TRUSTED_PROXIES, dst.
php artisan key:generate --force

# buat database kosong 'susenas' + user, lalu:
php artisan migrate --force
php artisan db:seed --class=MetadataSeeder --force   # struktur + 1 admin (sandi acak di log)

php artisan config:cache route:cache view:cache
php artisan storage:link

# hak akslah folder tulis
chown -R www-data:www-data storage bootstrap/cache
```

Root web server → `/var/www/susenas/public`. Semua request non-file diarahkan ke
`public/index.php` (`try_files $uri /index.php?$query_string;` di nginx).

## Worker antrean & penjadwal

Pekerjaan berat memakai antrean database (Inc 161): **periksa ulang** lebih dari 50 sampel setelah aturan berubah, dan
**hitung aturan sesudah impor isian** lebih dari 50 baris. Worker **wajib** berjalan terus di server produksi — tanpa worker,
pekerjaan itu berhenti di status "Menunggu giliran" (halaman memberi tahu setelah 5 menit, `php artisan susenas:health`
juga memperingatkan). Di komputer pengembang boleh `QUEUE_CONNECTION=sync` (pekerjaan langsung dijalankan).

```ini
# /etc/supervisor/conf.d/susenas-worker.conf
[program:susenas-worker]
command=php /var/www/susenas/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=www-data
numprocs=1
autostart=true
autorestart=true
```

```cron
# crontab www-data
* * * * * cd /var/www/susenas && php artisan schedule:run >> /dev/null 2>&1
```

Tugas terjadwal yang sudah terdaftar (`routes/console.php`, lihat `php artisan schedule:list`):

| Jadwal | Perintah | Fungsi |
|---|---|---|
| harian 01:30 | `susenas:backup` | salin basis data SQLite ke `storage/app/backups/`, simpan 14 terbaru |
| harian 07:00 | `susenas:ingat-jadwal` | notifikasi admin bila status siklus tidak cocok dengan jadwal lapangan |
| mingguan Min 00:00 | prune-notifications | hapus notifikasi terbaca berumur > 30 hari |
| mingguan Sen 06:00 | `susenas:health` | ringkasan kondisi data ke log |

Di Windows (Laragon/PDN tanpa cron), daftarkan Task Scheduler yang menjalankan
`php artisan schedule:run` tiap menit.

## HTTPS & reverse proxy

> **Wajib untuk aplikasi HP (Inc 162).** Pemasangan di layar utama Android/iPhone dan fitur "isian tetap tersimpan saat
> sinyal hilang" memakai *service worker*, yang oleh peramban hanya diizinkan di `https` (atau `localhost`). Bila petugas
> membuka lewat `http://` (mis. alamat IP kantor), aplikasi tetap berjalan tetapi tanpa fitur tanpa sinyal.
> Setelah memperbarui `public/sw.js` secara besar, naikkan `VERSI` di dalamnya agar HP mengambil berkas baru.

TLS diterminasi di nginx/HAProxy. Set di `.env`:

```
APP_URL=https://susenas.internal.bps.go.id
SESSION_SECURE_COOKIE=true
TRUSTED_PROXIES=10.0.0.0/8          # CIDR proxy internal (atau IP spesifik)
```

`bootstrap/app.php` sudah membaca `TRUSTED_PROXIES` sehingga skema `https` dan
cookie aman dikenali di belakang proxy.

Header keamanan (CSP, X-Frame-Options, HSTS saat `https`) sudah dipasang oleh
`App\Http\Middleware\SecurityHeaders`. Login dibatasi `throttle:8,1`.

## Pembaruan rilis berikutnya

```bash
cd /var/www/susenas && php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache route:cache view:cache
sudo supervisorctl restart susenas-worker
php artisan up
```

## Alternatif: Docker Compose

Berkas `Dockerfile`, `docker-compose.yml`, dan `docker/` sudah disertakan
(image PHP-FPM + nginx + PostgreSQL).

```bash
cp .env.production.example .env       # APP_KEY boleh kosong (dibuat entrypoint)
# set DB_PASSWORD di .env (atau environment)
docker compose up -d --build
# buka http://localhost:8080  — login admin dibuat MetadataSeeder (sandi acak di log)
docker compose logs app | grep 'Sandi admin'
```

`docker/entrypoint.sh` otomatis: `key:generate` bila perlu, tunggu DB, `migrate --force`,
seed `MetadataSeeder` bila belum ada versi kuesioner, lalu `config/route/view:cache`.
Untuk worker antrean: `docker compose exec app php artisan queue:work` atau tambah
service `worker` dengan `command: php artisan queue:work`.

TLS tetap diterminasi di reverse proxy di depan `web` (port 8080); set
`APP_URL`, `SESSION_SECURE_COOKIE=true`, dan `TRUSTED_PROXIES` di `.env`.

## Backup

- **SQLite (default dev/PDN kecil)**: `php artisan susenas:backup` (dijadwalkan harian)
  menyalin berkas DB ke `storage/app/backups/` dan menyimpan 14 terbaru
  (`--keep=N`, `--dir=`). Salin folder itu ke penyimpanan luar secara berkala.
- **Postgres / MySQL**: `pg_dump` / `mysqldump` harian (data cacah + metadata + audit).
- **`storage/app`**: lampiran (belum dipakai) + backup DB + log.
- `.env` disimpan terpisah (berisi `APP_KEY` — tanpa itu sesi & data terenkripsi tak terbaca).

## Kesehatan & pemantauan

- Endpoint `/up` (bawaan Laravel) untuk health check load balancer.
- `php artisan susenas:health` — ringkas jumlah versi kuesioner, dokumen, snapshot,
  dan mendeteksi dokumen tanpa snapshot / aturan dengan sintaks rusak.
- Log aplikasi: `storage/logs/laravel-*.log` (channel `daily`).

## Login SSO Bangdata (Inc 156)

**Saat aktif, SSO adalah satu-satunya jalan masuk** — form login lokal (`AuthController`)
disembunyikan dan `POST /login` membalas 404, bukan sekadar UI yang disembunyikan. Katup
darurat bila ada akun yang gagal tertaut: kosongkan `SSO_CLIENT_ID`/`BANGDATA_CLIENT_ID` di
`.env` + `php artisan config:cache` → form lokal langsung muncul lagi, tanpa redeploy kode.
Login server terpusat `sso.bangdata.web.id` (Laravel Passport, dipakai bersama app lain
di platform "bangdata" ini). Isi di `.env` untuk mengaktifkan:

```
BANGDATA_SSO_URL=https://sso.bangdata.web.id
BANGDATA_CLIENT_ID=
BANGDATA_CLIENT_SECRET=
BANGDATA_REDIRECT_URI=https://<domain-susenas>/auth/sso/callback
```

(nama `SSO_BASE_URL`/`SSO_CLIENT_ID`/`SSO_CLIENT_SECRET`/`SSO_REDIRECT_URI` diterima sebagai
cadangan — itu yang dicontohkan panel admin SSO). Path callback **harus** persis
`/auth/sso/callback`, sesuai redirect URI yang didaftarkan di panel SSO.

SSO di sini **hanya mengautentikasi**, tidak pernah membuat akun baru: akun (peran +
`wilayah_scope`) tetap dikelola admin di `/admin/pengguna`, dicocokkan ke profil SSO lewat
`username` (case-insensitive). Username SSO yang belum ada sebagai akun lokal ditolak dengan
pesan untuk menghubungi admin — supaya peran tak pernah ditebak dari profil SSO.
Lihat `App\Services\SsoBangdata` + `App\Http\Controllers\Auth\SsoBangdataController`.

## Yang belum & perlu keputusan BPS

- **Alokasi sampel & master wilayah** nyata (impor Wilkerstat & DSRT).
- **Garis kemiskinan** kabupaten resmi per periode (kini parameter dummy).
- **Penimbang kalibrasi** untuk estimasi (kini design weight dummy).
- **Format ekspor** ke pipeline pengolahan BPS / CAPI.

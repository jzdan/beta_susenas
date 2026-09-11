# Skenario UAT — Sistem SUSENAS

Data awal: `php artisan migrate:fresh --seed`. Akun demo lihat halaman login.

## A. Autentikasi & akses

| # | Langkah | Hasil diharapkan |
|---|---|---|
| A1 | Login `ppl_ani` / `operator123` | Masuk ke dashboard; menu Admin & Pengolahan tidak tampil |
| A2 | Sebagai operator buka `/admin/metadata` langsung | HTTP 403 |
| A3 | Salah kata sandi 9× berturut-turut | Ditolak; setelah batas → "Too Many Attempts" (rate limit) |
| A4 | Login `rahmawati` (wilayah 3526) | Hanya melihat sampel berkode 3526… |

## B. Monitoring

| # | Langkah | Hasil diharapkan |
|---|---|---|
| B1 | Dashboard sebagai admin | Response rate, status, progres per desa & petugas tampil |
| B2 | Buka Peta Progres | Petak BS berwarna sesuai persen selesai; klik → daftar sampel desa |
| B3 | Admin → Impor Sampel, tempel CSV berisi 1 baris valid + 1 duplikat + 1 desa tak dikenal | "1 ditambahkan, dilewati 2" dengan alasan tepat |
| B4 | PML ubah status sebuah sampel `belum` → `non_respons` | Status berubah; tercatat di Log Audit |

## C. Entri & validasi

| # | Langkah | Hasil diharapkan |
|---|---|---|
| C1 | Operator buka entri dok #1 | Blok Pokok, roster ART, IV.2, IV.1 (16 komoditas), Blok V tampil |
| C2 | Kosongkan "Banyaknya ART" | Error "Jumlah ART belum diisi" muncul langsung di sisi field |
| C3 | Isi roster ART dengan 2 baris `hubungan = 1` | Error "Harus tepat satu kepala rumah tangga" |
| C4 | IV.1 baris Beras: isi nilai beli, kosongkan kuantitas | Error per-baris "Nilai terisi tapi kuantitas kosong" |
| C5 | IV.1 baris Rokok: nilai/kuantitas → harga satuan > batas | Warning per-baris "Harga satuan Rp… kemahalan — batas wajar Rp…–Rp…" |
| C6 | Isi kuantitas seluruh komoditas | Nilai turunan "Kalori per kapita per hari" ter-update |
| C7 | Coba Finalisasi saat masih ada error | Ditolak dengan pesan jumlah error |
| C8 | Perbaiki semua error, Finalisasi | Dokumen → `selesai`; sampel → `selesai` |

## D. Cek-silang NERACA

| # | Langkah | Hasil diharapkan |
|---|---|---|
| D1 | Dok #3: IV.1 ada konsumsi "produksi sendiri", Blok V R502 = 0 | Error "NERACA-PRODUKSI" |
| D2 | Isi Blok V R502 ≥ nilai disetahunkan | Error hilang |
| D3 | Dok dengan selisih penerimaan-pengeluaran > 5% | Warning "NERACA-BALANCE" |

## E. Pengolahan (cleaning)

| # | Langkah | Hasil diharapkan |
|---|---|---|
| E1 | Login `siti_editor`; buka Pengolahan | Antrean dokumen dengan jumlah error/warning |
| E2 | Buka dok #1 | Tabel rincian bermasalah (termasuk yang belum ada jawaban) |
| E3 | Koreksi nilai bersih tanpa mengisi alasan | Ditolak (alasan wajib) |
| E4 | Koreksi dengan alasan | Tersimpan; nilai mentah tetap; warning dihitung ulang |
| E5 | Semua error hilang → "Tandai bersih" | Dokumen `bersih` & terkunci; `cleaned_by`/`cleaned_at` terisi |
| E6 | Coba koreksi dokumen `bersih` | HTTP 409 (terkunci) |
| E7 | Editor coba "Buka kunci" | HTTP 403 (hanya Admin) |

## F. Admin metadata

| # | Langkah | Hasil diharapkan |
|---|---|---|
| F1 | Tambah blok, tambah rincian | Muncul di editor blok |
| F2 | Impor daftar komoditas via CSV ke blok list | Baris bertambah; duplikat dilewati |
| F3 | Tambah aturan dengan ekspresi `system('x')` | Ditolak "Ekspresi tidak valid" |
| F4 | Uji ekspresi valid terhadap dokumen contoh | Menampilkan hasil + konteks variabel |
| F5 | Duplikasi versi kuesioner | Versi baru berisi salinan lengkap; versi jadi aktif |

## G. Tabulasi & ekspor

| # | Langkah | Hasil diharapkan |
|---|---|---|
| G1 | Tabulasi: `pengeluaran_per_kapita`, per kecamatan, rata-rata | Tabel angka per kecamatan |
| G2 | Ekspor "Data datar (CSV)" | Unduhan CSV, 1 baris/dokumen, kolom variabel snapshot |
| G3 | Ekspor "Daftar warning (CSV)" | 1 baris per temuan aturan |
| G4 | Operator coba akses ekspor | HTTP 403 |

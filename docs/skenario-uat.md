# Skenario UAT — Sistem SUSENAS

Data awal: `php artisan migrate:fresh --seed` (memuat juga kerangka 2026: SUSENAS KOR/KP/Modul,
SERUTI INTI/MAK per triwulan). Akun demo lihat halaman login. Diperbarui November 2026 (Inc 148–163).

## A. Autentikasi & akses

| # | Langkah | Hasil diharapkan |
|---|---|---|
| A1 | Masuk `ppl_ani` / `operator123` | Menu hanya Dashboard, Tugas Saya, Peta Progres, Daftar Sampel; tanpa Periode Survei & menu admin |
| A2 | Sebagai petugas buka `/admin/metadata` atau `/periode` langsung | HTTP 403 |
| A3 | Salah kata sandi berulang kali | Ditolak; setelah batas → dikunci sementara (tercatat di Log Audit) |
| A4 | Masuk `pj_kamal` (Penanggung Jawab, cakupan 3526010) | Hanya melihat sampel berkode 3526010… |
| A5 | Sebagai `ppl_ani` buka `/entri/{id}` milik petugas lain (ubah nomor di alamat) | HTTP 403 |

## B. Periode Survei (admin / pengawas / penanggung jawab)

| # | Langkah | Hasil diharapkan |
|---|---|---|
| B1 | Menu Periode Survei | Kartu per tahun (2027, 2026) dengan status & progres |
| B2 | Klik 2026 | Empat baris triwulan; warna pita kiri = status; TW1 berisi SUSENAS KOR, KP, SERUTI INTI |
| B3 | Klik TW1 2026 | Kartu per survei + tabel beban petugas gabungan semua survei |
| B4 | Klik SUSENAS KP | Hub: Ringkasan berisi "Langkah berikutnya", angka, tahap sampel; tab Kuesioner, Aturan Validasi, Peserta, Sampel, Pengolahan, Persiapan & Penutupan |
| B5 | Di hub, klik pemilih survei SERUTI INTI | Pindah ke hub SERUTI di triwulan yang sama |
| B6 | Tombol "← Kembali ke TW1 2026" | Kembali ke halaman triwulan |
| B7 | Admin: 2027 → TW2 → "+ Siapkan triwulan ini", centang SUSENAS KP, salin dari `VSEN26.KP · 194 baris` | Siklus baru "SUSENAS Juni 2027 (KP)" berstatus Persiapan; kuesioner, aturan, harga tersalin (194 komoditas) |
| B8 | Kunci sebuah siklus, lalu coba ubah aturan/blok kuesionernya | Ditolak (HTTP 423); halaman menampilkan catatan "hanya bisa dilihat" |

## C. Persiapan & lapangan

| # | Langkah | Hasil diharapkan |
|---|---|---|
| C1 | Hub siklus Persiapan → tab Persiapan & Penutupan | Daftar periksa; butir wajib ✕ bila ada sampel tanpa petugas |
| C2 | Lengkapi butir wajib → "Buka periode lapangan" | Status Lapangan; tercatat di Log Audit |
| C3 | Petugas pada siklus Lapangan: buka sampel, isi, kirim | Bisa mengisi & mengirim |
| C4 | Siklus diubah ke Pengolahan; petugas buka isiannya | Hanya bisa dilihat (pemberitahuan "periode lapangan sudah ditutup") |
| C6 | Jadwal Lapangan: siklus periode 2027-03 diberi tanggal mulai 6 September 2026 | Ditolak: tanggal mulai harus di triwulan 1 2027 |
| C7 | Siklus masih Persiapan padahal tanggal mulai lapangan sudah lewat; jalankan `php artisan susenas:ingat-jadwal` | Kotak samping "Jadwal lapangan sudah mulai …, masih Persiapan"; admin dapat Notifikasi; status tidak berubah sendiri |
| C5 | Pemeriksa mengembalikan isian; petugas membukanya saat Pengolahan | Isian yang dikembalikan tetap bisa diperbaiki & dikirim ulang |

## D. Pemantauan

| # | Langkah | Hasil diharapkan |
|---|---|---|
| D1 | Dashboard, Peta Progres, Kinerja Tim | Bawaan = triwulan berjalan (judul menyebut TW & survei); pilihan siklus lain & "Semua siklus (gabungan)" tersedia |
| D2 | Peta Progres: klik petak blok sensus | Daftar Sampel tersaring desa & siklus itu |
| D3 | Daftar Sampel | Satu kolom "Tahap" (Belum dikunjungi → Sedang diisi → Menunggu pemeriksaan → Dikembalikan → Bersih) |
| D4 | Pengawas: rincian sampel → "Tandai non-respons" dengan alasan | Tahap Non-respons; "Batalkan tanda" mengembalikan tahap otomatis |

## E. Entri & validasi

| # | Langkah | Hasil diharapkan |
|---|---|---|
| E1 | Petugas buka entri | Blok-blok tampil; Ringkasan menghitung kesalahan & peringatan |
| E2 | Kosongkan "Banyaknya anggota rumah tangga" | Kesalahan muncul langsung di rincian |
| E3 | IV.1 baris Beras: isi nilai beli, kosongkan kuantitas | Kesalahan per baris |
| E4 | IV.1: harga satuan di atas batas wajar | Peringatan "Harga satuan Rp… kemahalan, batas wajar Rp…–Rp…" |
| E5 | Kirim saat masih ada kesalahan | Ditolak dengan jumlah kesalahan |

## F. Pengolahan

| # | Langkah | Hasil diharapkan |
|---|---|---|
| F1 | Masuk `siti_editor` atau pengawas; hub → tab Pengolahan | Antrean tersaring ke siklus itu |
| F2 | Koreksi nilai tanpa alasan | Ditolak (alasan wajib) |
| F3 | Koreksi dengan alasan | Tersimpan; nilai mentah tetap |
| F4 | Semua kesalahan hilang → "Tandai bersih" | Tahap Bersih & terkunci |
| F5 | Coba koreksi isian bersih | HTTP 409 |

## G. Kuesioner & Aturan Validasi (admin)

| # | Langkah | Hasil diharapkan |
|---|---|---|
| G1 | Hub → tab Kuesioner | Hanya versi siklus ini; tiap blok "Terhubung: N aturan · N agregat · N turunan"; Blok IV.1 menampilkan Baris / rincian (komoditas) & Kolom isian |
| G2 | Tombol Pratinjau pada Blok IV.1 | Pratinjau terbuka langsung di Blok IV.1 (tidak terlipat) |
| G3 | Tab Aturan Validasi → filter Blok IV.1 | Hanya aturan yang memakai Blok IV.1; tiap aturan berchip blok |
| G4 | Ubah aturan (Ubah) dengan rumus rusak | Tanda ✕ langsung saat mengetik; simpan ditolak dengan pesan |
| G5 | Matikan sebuah aturan | Kotak "N sampel belum diperiksa ulang" → "Periksa ulang sekarang" menghilangkannya |
| G6 | Tab Angka bantu | Tiap agregat/turunan berchip blok asal & keterangan "dipakai oleh" |
| G7 | Tab Harga & Kalori → "+ Tambah komoditas" | Komoditas baru di baris terakhir; kode ganda / harga min > max ditolak |
| G8 | Rincian di blok anggota rumah tangga: isi "Lewati bila" mis. `n(art_umur) < 5`; di entri isi umur 3 | Sel itu terarsir & terkunci, tidak diperiksa aturan, tidak ikut dihitung |
| G9 | Ubah pesan aturan lalu buka lagi → Riwayat perubahan → "Kembalikan" pada keadaan awal | Pesan kembali seperti semula; riwayat mencatat "Dikembalikan" |
| G10 | Daftar aturan: ubah angka ambang (belum disimpan) | Muncul "Dengan X: kena N sampel (sekarang M)" |
| G11 | Ubah aturan → Contoh kasus uji: isi 2 contoh (harus menyala / tidak) → simpan; lalu rusak rumusnya | Contoh dinilai ✓/✕; saat mengetik rumus tampil "Contoh uji: 1 dari 2 sesuai"; daftar aturan bertanda "uji 1/2" |
| G12 | Tab Kuesioner → "Bandingkan" pada versi triwulan ini | Daftar ditambah / dihapus / diubah dibanding triwulan sebelumnya |
| G13 | Ubah aturan pada versi dengan > 50 sampel → "Periksa ulang sekarang" | Bilah kemajuan "Sedang berjalan … dari … sampel" sampai Selesai (server: `queue:work` harus jalan) |

## H. Tabulasi & ekspor

| # | Langkah | Hasil diharapkan |
|---|---|---|
| H1 | Tabulasi: `pengeluaran_per_kapita`, per kecamatan, rata-rata | Tabel angka per kecamatan |
| H2 | Ekspor data datar (CSV) | Unduhan CSV, 1 baris per sampel |
| H3 | Petugas coba akses ekspor | HTTP 403 |

## I. Tampilan perangkat

| # | Langkah | Hasil diharapkan |
|---|---|---|
| I1 | Buka di HP (360–412 px), HP miring, tablet, laptop, PC lebar | Tidak ada yang melebar keluar layar; menu jadi strip geser di HP |
| I2 | Tema Terang / Gelap / Sistem | Tombol kembali & kartu tetap terbaca |
| I3 | Android (Chrome), alamat https: masuk sebagai petugas | Pita "Pasang Sistem SUSENAS di HP" → Pasang → ikon di layar utama, terbuka layar penuh |
| I4 | iPhone (Safari), alamat https | Petunjuk "ketuk Bagikan → Tambah ke Layar Utama"; setelah dipasang terbuka layar penuh dengan ikon SUSENAS |
| I5 | Petugas: Tugas Saya → "Simpan untuk dibuka tanpa sinyal", lalu mode pesawat → buka sampel | Halaman entri terbuka dari salinan HP |
| I6 | Mode pesawat: isi beberapa rincian | "tersimpan di HP — dikirim saat ada sinyal"; pita bawah "N isian menunggu dikirim"; kolom bergaris putus |
| I7 | Matikan mode pesawat | Isian terkirim otomatis, pita hilang, nilai tersimpan di server (buka dari komputer lain) |
| I8 | Mode pesawat: tekan "Kirim" / tombol formulir lain | Pesan "Tidak ada sinyal…", tidak muncul halaman galat peramban |

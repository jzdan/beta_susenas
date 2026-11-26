# Modul SERUTI — VSERUTI26.INTI

Kuesioner **SERUTI** (Survei Ekonomi Rumah Tangga Triwulanan) — dilaksanakan
4x setahun (tiap triwulan), berdampingan dengan Susenas pada TW I & TW III.
Sumber struktur: `WTF Susenas Seruti Maret 2026.xlsx`
(sheet `SERUTI IV.2`, `SERUTI V-VI`) dan PDF `Kuesioner Seruti Inti 2026`.
Lihat juga `PeriodeSurveiSeeder` untuk kerangka siklus 2026–2027 tiap triwulan (Inc 164; dulu `Susenas2026CycleSeeder`, Inc 123).

Seperti kuesioner lain, ini **data** — di-seed lewat `SerutiSeeder` + CSV, bukan kode.

## Status implementasi

| Blok | Isi | Status |
|---|---|---|
| POKOK | banyaknya ART | ✅ (ringkas) |
| III | Keterangan anggota rumah tangga (hubungan, jenis kelamin, umur, status kawin, ijazah) | ✅ `art` (Inc 156) |
| IV.1 | Konsumsi makanan | — tidak dibuat: TW I & III terintegrasi dgn SUSENAS KP, TW II & IV memakai kuesioner SERUTI MAK |
| IV.2.A | Pengeluaran bukan makanan sebulan & tiga bulan (R401–451) | ✅ `list`, `database/data/seruti_iv2a.csv` |
| IV.2.B | Kesehatan & pendidikan tiga bulan (R452–463; total & out of pocket) | ✅ `list` (Inc 156) |
| IV.3 | Persepsi kondisi ekonomi triwulan ini (R464–470, syarat lewati) | ✅ `ruta` (Inc 156) |
| V.501 | Pekerjaan dengan upah/gaji per ART | ✅ `art` (Inc 156) — satu baris per ART |
| V.502 | Usaha rumah tangga per ART | ✅ `art` (Inc 156) — satu usaha per ART (lihat catatan) |
| V.503 / V.504 / V.505 | Pendapatan kepemilikan · transfer berjalan · aset | ✅ `list` (Inc 156) |
| VI | Posisi aset & kewajiban keuangan (R601–612) | ✅ `ruta` + turunan `srt_posisi_aset`/`srt_posisi_kewajiban` (Inc 156) |
| VII | Catatan & tanggal kunjungan | ✅ `ruta` (Inc 156) |

Dibangun oleh `SerutiIntiLengkapSeeder` (idempoten; menambah blok yang belum ada ke semua versi
`VSERUTI26.INTI*`, melewati siklus terkunci). Sumber: PDF `Kuesioner Seruti Inti 2026 (VSERUTI26.INTI)`.

**Perlu dicek dengan kuesioner cetak/CAPI resmi:**
- V.502: di kertas satu ART bisa punya lebih dari satu usaha; sistem belum punya daftar dinamis selain ART.
- V.505: tafsiran kolom = bangunan tempat tinggal · bangunan bukan tempat tinggal untuk usaha ·
  non-bangunan untuk usaha (kendaraan, mesin, dsb) · lahan.
- Baris JUMLAH (V.503–505, VI) tidak diisi petugas; hanya VI yang sudah punya turunan jumlahnya.
- Aturan awal: `SRT-IV2B-TOTAL`, `SRT-IV2B-OOP-TOTAL`, `SRT-IV2B-OOP`, `SRT-IV3-470B`, `SRT-V2-PCT`.
  Aturan resmi SERUTI (bila ada di Sidona) belum dipetakan.

## Blok IV.2.A — catatan

- `block_rows.ref = {kel, level}`. `level`: `detail` (rincian yg diisi),
  `subtotal` (R401/404/413/417/426/430/437/443/446), `total` (R451).
- Agregat memakai `filter_ref=level`: `siv2a_detail_sebulan` (Σ detail) vs
  `siv2a_input_sebulan` (nilai R451 yang diisi). Aturan `SRT-IV2A-TOTAL-1/-3`
  membandingkan keduanya (ambang Rp1.000).
- Beberapa rincian "disalin dari VSEN.KP R2xx" — integrasi antar-kuesioner
  (auto-copy dari dokumen KP subsampel yang sama) **belum** dibuat; untuk sekarang
  diisi manual.

## Langkah berikutnya

1. Blok VI (paling sederhana: `list` ~20 baris nilai tunggal + JUMLAH ASET/KEWAJIBAN/KEKAYAAN BERSIH).
2. Blok IV.2.B (`list`, kolom Total + Out-of-Pocket + Asal).
3. Blok V (butuh 2 roster `art` + 3 tabel ruta) — paling kompleks.
4. Integrasi salin-otomatis dari VSEN26.KP subsampel.

# Modul SERUTI — VSERUTI26.INTI

Kuesioner **SERUTI** (Survei Ekonomi Rumah Tangga Triwulanan) — dilaksanakan
4x setahun (tiap triwulan), berdampingan dengan Susenas pada TW I & TW III.
Sumber struktur: `WTF Susenas Seruti Maret 2026.xlsx`
(sheet `SERUTI IV.2`, `SERUTI V-VI`) dan PDF `Kuesioner Seruti Inti 2026`.
Lihat juga `Susenas2026CycleSeeder` untuk kerangka siklus 4 triwulan (Inc 123).

Seperti kuesioner lain, ini **data** — di-seed lewat `SerutiSeeder` + CSV, bukan kode.

## Status implementasi

| Blok | Isi | Status |
|---|---|---|
| POKOK | banyaknya ART | ✅ (ringkas) |
| IV.2.A | Pengeluaran bukan makanan sebulan & tiga bulan (R401–451) | ✅ `list`, `database/data/seruti_iv2a.csv` |
| IV.2.B | Pengeluaran kesehatan & pendidikan tiga bulan | ⏳ belum |
| V | Pendapatan, penerimaan, pengeluaran selain konsumsi (R501 roster ART upah, R502 roster usaha, R503–505 tabel ruta) | ⏳ belum |
| VI | Posisi aset & kewajiban keuangan saat pencacahan (R601–611) | ⏳ belum |
| VII | (lanjutan neraca) | ⏳ belum |

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

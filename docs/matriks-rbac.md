# Matriks RBAC — Sistem SUSENAS

`✓` = boleh · `wil` = dibatasi `wilayah_scope` · `own` = hanya sampel yang ditugaskan
· `—` = tidak

| Fungsi | Operator | PML | Editor | Admin | Peninjau |
|---|:--:|:--:|:--:|:--:|:--:|
| Login | ✓ | ✓ | ✓ | ✓ | ✓ |
| Dashboard monitoring | own | wil | wil | ✓ | ✓ |
| Peta progres | own | wil | wil | ✓ | ✓ |
| Daftar sampel | own | wil | wil | ✓ | ✓ |
| Ubah status sampel | — | ✓ | — | ✓ | — |
| Buat dokumen entri | ✓ | — | — | ✓ | — |
| Entri / autosave isian | own | — | — | ✓ | — |
| Finalisasi dokumen | ✓ | ✓ | — | ✓ | — |
| Antrean pengolahan | — | wil | wil | ✓ | — |
| Koreksi nilai bersih | — | ✓ | ✓ | ✓ | — |
| Tandai dokumen "bersih" | — | ✓ | ✓ | ✓ | — |
| Buka kunci dokumen "bersih" | — | — | — | ✓ | — |
| Tabulasi (lihat) | — | ✓ | ✓ | ✓ | ✓ |
| Ekspor data datar / warning (CSV) | — | ✓ | ✓ | ✓ | — |
| Log audit | — | ✓ | — | ✓ | — |
| Kelola metadata (blok/rincian) | — | — | — | ✓ | — |
| Kelola aturan validasi / agregat / parameter | — | — | — | ✓ | — |
| Impor rincian / baris komoditas (CSV) | — | — | — | ✓ | — |
| Impor Wilkerstat / daftar sampel (CSV) | — | — | — | ✓ | — |
| Duplikasi versi kuesioner | — | — | — | ✓ | — |
| Uji ekspresi terhadap dokumen | — | — | — | ✓ | — |

## Catatan

- Pembatasan wilayah diterapkan di lapisan query (`wilayah_code LIKE scope%`).
- Operator hanya melihat sampel dengan `petugas_id = user.id`.
- Middleware `role:<peran,...>` menjaga setiap rute; kegagalan → HTTP 403.
- Ekspor data mentah sengaja **tidak** diberikan ke Operator/Peninjau
  (kerahasiaan responden).
- Produksi: peran & identitas idealnya berasal dari SSO BPS, bukan tabel `users` lokal.

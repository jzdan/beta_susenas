# Pemetaan Aturan Anomali Sidona → Sistem SUSENAS

Sumber: sheet **Anomali** pada berkas contoh terisi Sidona
(`.../Upload WTF dan Sidona/*.xlsx`), 108 aturan.

Kolom `RUMUS PENGECEKAN` Sidona ditulis dalam bahasa semi-formal
(`TETAPI`/`DAN` → AND, `ATAU` → OR, `<>` → `!=`, `blank` → `blank(x)`).
Diterjemahkan ke ekspresi evaluator sistem (konvensi: **TRUE = ada anomali**).

Aturan per-ART memakai `row_block = 'ART'` (dievaluasi sekali per baris ART,
konteks lokal = nilai kolom ART itu + `_row_index` = no. urut ART).
Aturan per-rumah-tangga: `scope = 'anomali'`, tanpa `row_block`.

## Sudah diterjemahkan (92 dari 108)

Gelombang: 1 (23) · 2 (10) · 3 (15) · 4 (19) · 5 (8) · 6 (4) · 7 (8) · 8 (5).
Sisa ~21: Blok VI pendidikan tahun lalu (r620–623), Blok VIII TIK detail
(r803–811), Blok IX perjalanan, Blok XIX pembiayaan, sejumlah pola cek-list
multi-kolom. Rinciannya per gelombang di bawah.


### Gelombang 6 — pendidikan & ketenagakerjaan lanjutan

Increment 34. `SDN-26` (jenjang SLB), `SDN-37` (kegiatan sekolah tapi tidak
sekolah), `SDN-40` (pembantu lapangan usaha ≠ 25), `SDN-42` (pemerintahan tapi
jenjang ijazah rendah).



### Gelombang 5 — inklusi keuangan rumah tangga, aset, perlindungan sosial

Increment 33. Blok XVII (kredit/e-wallet: r1701a–d, r1702a/b), Blok XVIII
(aset: r1801d/e/m, r1802_anak), Blok XX (perlinsos: r2001a_iii, r2003, r2005).

`SDN-97` (kredit tapi tak ada rekening), `SDN-98` (e-wallet tapi tak ada rekening),
`SDN-99/100` (water heater / telepon rumah), `SDN-101` (rumah milik sendiri tapi
tak punya lahan), `SDN-105` (>2 penerima PIP), `SDN-106/107` (PKH / BPNT tapi tak
ada rekening). Semua memakai `roster_all(r701, 5)`.



### Gelombang 4 — konsistensi kualitas rumah & sanitasi (Blok XVI)

Increment 32. Kolom Blok XVI baru: r1605, r1609a/b/c/e, r1610a/c, r1611a/b,
r1612, r1614a, r1616a, r1617, r1622.

`SDN-71` (KRT <17 th punya rumah lain), `SDN-74/75/78` (kombinasi atap–dinding–lantai
tak lazim), `SDN-80/81/82` (lantai keramik vs sanitasi buruk), `SDN-83` (IPAL),
`SDN-84` (kuras septik >3×), `SDN-85` (air minum lainnya), `SDN-86/87` (sumber air
tak konsisten), `SDN-88` (waktu ambil air >120 mnt), `SDN-89` (kekeringan),
`SDN-91` (air mandi kemasan), `SDN-92` (bukan listrik), `SDN-93` (bahan bakar masak
tak wajar), `SDN-96` (keamanan malam "lainnya"), `SDN-108` (jk pasangan = jk KRT).



### Gelombang 3 — keaksaraan/pendidikan, ketenagakerjaan, TIK, kesehatan, rokok

Ditambahkan pada Increment 31. Blok II (jam wawancara) + kolom roster baru:
r608 r609 r611 r612 r614 r615 (pendidikan), r703a r703b r705 r708 (kerja),
r801 r802 (TIK), r1002 r1006 (gangguan fungsional), r1108 (rawat jalan),
r1206 r1207 r1208 (rokok).

| Sidona # | Kode | Cakupan | Ekspresi (ringkas) |
|---|---|---|---|
| 1 | `SDN-01` | ruta | `n(r_jam_mulai) < 6 or n(r_jam_mulai) > 22` |
| 2 | `SDN-02` | ruta | `n(r_jam_selesai) < 6 or n(r_jam_selesai) > 23` |
| 24 | `SDN-24` | ART | `n(r614) > 5 and n(r608) == 5 and n(r609) == 5` |
| 25 | `SDN-25` | ART | `n(r612) in (1,6,11) and n(r611) != 1` |
| 27 | `SDN-27` | ART | `n(r614) == 8 and n(r613) != n(r615)` |
| 36 | `SDN-36` | ART | `n(r403)==8 and blank(r703a) and n(r704)==5` |
| 39 | `SDN-39` | ART | `n(r705) == 26` |
| 41 | `SDN-41` | ART | `n(r705) == 20 and n(r706) != 4` |
| 43 | `SDN-43` | ART | `n(r705) == 20 and n(r407) < 17` |
| 44 | `SDN-44` | ART | `n(r704) == 1 and n(r708) != 0` |
| 45 | `SDN-45` | ART | `n(r802) == 1 and n(r801) == 5` |
| 52 | `SDN-52` | ART | `n(r1002) == 1 and n(r1006) == 1` |
| 53 | `SDN-53` | ART | `n(r1108) > 20` |
| 55 | `SDN-55` | ART | `n(r1206) == 1 and n(r1207) == 1` |
| 56 | `SDN-56` | ART | `n(r410) == _row_index and (n(r1206)==8 or n(r1207)==8 or n(r1208)==8)` |

## Sudah diterjemahkan sebelumnya (33)

### Gelombang 2 — referensi antar-ART (fungsi `roster_*`)

Ditambahkan pada Increment 11. Fungsi: `roster(var, idx)` (nilai var untuk ART
ke-idx, untuk pointer R503/R504), `roster_count(var, kode)`, `roster_all/any(var, kode)`,
`roster_first(nilaiVar, kunciVar, kode)`, `roster_max/sum(var)`.

| Sidona # | Kode sistem | Cakupan | Ekspresi (ringkas) |
|---|---|---|---|
| 5 | `SDN-05` | ruta | `roster_first(r501,r403,1) != roster_first(r501,r403,2)` |
| 10 | `SDN-10` | ruta | `n(r301)==1 and roster_first(r404,r403,1)==1 and n(roster_first(r502,r403,1))>0` |
| 11 | `SDN-11` | ART | `n(r503)>0 and (n(roster(r404,r503))==1 or n(roster(r405,r503))==1)` |
| 12 | `SDN-12` | ART | `n(r504)>0 and (n(roster(r404,r504))==1 or n(roster(r405,r504))==2)` |
| 13 | `SDN-13` | ART | `n(r403)==3 and (n(r503)>2 or n(r504)>2)` |
| 16 | `SDN-16` | ART | `n(r403)==3 and filled(r503) and n(r503)!=1 and n(r503)!=2` |
| 17 | `SDN-17` | ART | `n(r403)==3 and filled(r504) and n(r504)!=1 and n(r504)!=2` |
| 34 | `SDN-34` | ART | `n(r619)==1 and roster_all(r701, 5)` |
| 35 | `SDN-35` | ART | `n(r701)==1 and n(r702)==5` |
| 67 | `SDN-67` | ruta | `roster_max(r502) > n(r1601)` |

## Gelombang 1 (23 aturan)

| Sidona # | Kode sistem | Cakupan | Ekspresi |
|---|---|---|---|
| 3 | `SDN-03` | ART | `n(r404) > 1 and n(r409) > 0 and n(r409) < 13` |
| 4 | `SDN-04` | ART | `n(r407) < 7 and n(r410) == _row_index` |
| 6 | `SDN-06` | ART | `n(r404) > 1 and n(r502) == 0` |
| 7 | `SDN-07` | ART | `(n(r403)==3 or n(r403)==4) and n(r404)==1 and n(r502)!=1` |
| 8 | `SDN-08` | ART | `(n(r403)==3 or n(r403)==4) and n(r404)>1 and n(r502)==1` |
| 9 | `SDN-09` | ART | `n(r403) > 4 and n(r502) == 1` |
| 14 | `SDN-14` | ART | `n(r403)==7 and (n(r503)!=0 or n(r504)!=0)` |
| 15 | `SDN-15` | ART | `(n(r403)==5 or n(r403)==8) and (n(r503)!=0 or n(r504)!=0)` |
| 18 | `SDN-18` | ART | `(n(r501)==1 or n(r501)==2) and n(r505)==5` |
| 19 | `SDN-19` | ART | `n(r407)<17 and n(r404)==1 and n(r508)==2` |
| 20 | `SDN-20` | ART | `n(r509)==1 and n(r505)==5` |
| 21 | `SDN-21` | ART | `n(r509)>4 and (n(r610)==2 or n(r610)==3)` |
| 22 | `SDN-22` | ART | `n(r505)==5 and (n(r610)==2 or n(r610)==3)` |
| 23 | `SDN-23` | ART | `n(r407)>=7 and n(r407)<11 and n(r610)==1` |
| 66 | `SDN-66` | ruta | `n(r1601)==n(r301) and n(r301)>2` |
| 68 | `SDN-68` | ruta | `n(r1602)==5` |
| 69 | `SDN-69` | ruta | `n(r1604)<6 or n(r1604)>500` |
| 70 | `SDN-70` | ruta | `n(r301)>0 and (n(r1604)/n(r301))<2` |
| 72 | `SDN-72` | ruta | `n(r1606)==7` |
| 73 | `SDN-73` | ruta | `n(r1606)==1 and n(r1607)!=1` |
| 76 | `SDN-76` | ruta | `n(r1607)==2` |
| 77 | `SDN-77` | ruta | `n(r1608)==3` |
| 79 | `SDN-79` | ruta | `n(r1608)==1 and n(r1607)!=1` |

## Belum diterjemahkan (~16 aturan)

| Sidona # | Kebutuhan |
|---|---|
| 28–33 | Blok VI: partisipasi & jenjang pendidikan tahun lalu (r620–r623) + logika kecocokan jenjang antar-tahun; shorthand Sidona ambigu, perlu klarifikasi konsep |
| 54, 62 | Blok XII rawat inap detail (r1202f/g), Blok XIV r1401/r1402 cross-check |
| 71 (varian), 90, 94, 95 | pola cek-list multi-kolom Blok XVI (r1613, r1618–r1621) |
| 102–104 | Blok XIX pembiayaan rumah tangga (r1901b/c) + pointer ART penanggung |
| 108 | pernikahan sejenis lintas-ruta — sudah didekati oleh SDN-108 (per-baris) |

## Cara menambah sisanya

Untuk aturan yang polanya sudah didukung: tambahkan lewat **Admin → Aturan
Validasi** (isi *Row block* = `ART` untuk per-ART), lalu uji terhadap dokumen
`CONTOH ANOMALI`. Untuk blok baru: **Admin → Metadata** (tambah blok/rincian
atau impor CSV), lalu duplikasi versi bila untuk periode berikutnya.

### Gelombang 7 — imunisasi, ASI, MP-ASI, persalinan (Increment 41)

Kolom roster Blok XIII (r1301, r1305g, r1306f, r1308a, r1311, r1312a–h) +
Blok XIV (r1401a, r1402a, r1404a, r1404b, r1404c).

`SDN-57/58` (tempat/pemberi imunisasi "lainnya"), `SDN-59` (ibu di ruta tapi
tidak tahu ASI), `SDN-60` (balita < 6 bln sudah makanan manis), `SDN-61`
(MP-ASI tapi semua jenis makanan "tidak"), `SDN-63/64` (tempat/penolong
persalinan "lainnya"/"tidak ada"), `SDN-65` (lahir di faskes tapi bayi tidak
ditimbang). → **87 dari 108**.

### Gelombang 8 — teknologi informasi & komunikasi (Increment 42)

Kolom roster Blok VIII: r803a, r806a–f (lokasi akses internet), r811f (internet banking).

`SDN-46` (akses di tempat kerja tapi tidak bekerja), `SDN-47` (akses di sekolah
tapi tidak sekolah), `SDN-48` (akses via PC tapi hanya saat bergerak — checklist
R806), `SDN-49` (anak kecil akses di kantor/sekolah), `SDN-50` (internet banking
tapi tak ada ART punya rekening). → **92 dari 108**.

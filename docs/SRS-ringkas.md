# SRS Ringkas — Sistem SUSENAS

Status: **draft kerja**, bukan dokumen resmi. Sesuaikan dengan pedoman BPS terbaru
sebelum diedarkan.

## 1. Tujuan & lingkup

Aplikasi web **internal BPS** untuk mendukung SUSENAS pada tingkat kabupaten:

1. **Monitoring lapangan** — progres pencacahan per wilayah & petugas.
2. **Entri & validasi** — memasukkan isian kuesioner dan menjalankan aturan
   konsistensi secara langsung.
3. **Pengolahan (cleaning)** — koreksi isian bermasalah pada *nilai bersih*,
   nilai mentah tetap tersimpan.
4. **Tabulasi & ekspor** — rekap internal untuk pra-pengolahan & QC (bukan
   estimasi resmi; tanpa penimbang).

**Di luar lingkup:** penghitungan angka kemiskinan/IPM resmi, penerapan
penimbang, menggantikan CAPI/FASIH atau alur pengolahan terpusat BPS Pusat.
Aplikasi ini pelengkap lokal.

## 2. Pengguna & peran

| Peran | Kewenangan |
|---|---|
| Operator Entri | Entri isian dokumen yang ditugaskan kepadanya; lihat monitoring cakupannya |
| Pengawas (PML) | Ubah status sampel; lihat & olah dokumen di wilayahnya; tabulasi; audit |
| Editor / Pengolah | Cleaning dokumen (koreksi nilai bersih, tandai bersih); tabulasi |
| Administrator | Semua di atas + kelola metadata kuesioner, aturan, impor, buka kunci dokumen |
| Peninjau | Hanya lihat tabulasi & dashboard |

Pembatasan wilayah: `wilayah_scope` pada akun membatasi data ke prefiks kode
wilayah tertentu.

## 3. Kebutuhan fungsional

### 3.1 Metadata kuesioner (dikelola Admin)
- Struktur (blok, rincian, pilihan jawaban) disimpan sebagai **data berversi**.
- Tipe blok: `ruta` (1 isian/RT), `art` (roster dinamis per ART), `list`
  (baris tetap, mis. daftar komoditas dengan data referensi kalori/harga).
- Aturan validasi, variabel turunan, parameter, dan **agregat terkelompok**
  ditulis sebagai ekspresi/konfigurasi — bukan kode.
- Impor massal rincian & baris komoditas via CSV.
- Duplikasi versi untuk periode berikutnya.

### 3.2 Mesin validasi
- Evaluator ekspresi **aman** (daftar putih; tanpa eksekusi kode arbitrer).
- Aturan tingkat dokumen, konsistensi antar-blok (gaya NERACA), dan **per-baris**
  (mis. harga satuan di luar rentang wajar).
- Konvensi: ekspresi bernilai TRUE = ada masalah (error/warning).
- Hasil dihitung ulang otomatis setiap perubahan isian.

### 3.3 Monitoring
- Dashboard: response rate, status pencacahan, progres per desa & petugas.
- Peta progres skematik (kecamatan → desa → blok sensus).
- Impor master Wilkerstat & daftar sampel via CSV.

### 3.4 Entri
- Form dirender dari metadata; skip-pattern otomatis.
- Autosave per rincian/sel; panel warning & nilai turunan diperbarui langsung.
- `answer_histories` merekam setiap perubahan.
- Finalisasi diblokir bila masih ada error.

### 3.5 Pengolahan (cleaning)
- Antrean dokumen berstatus selesai dengan jumlah error/warning.
- Koreksi pada `clean_value` (nilai mentah tetap); **alasan wajib**.
- Tandai `bersih` (terkunci) hanya bila 0 error; Admin dapat buka kunci.

### 3.6 Tabulasi & ekspor
- Snapshot datar per dokumen (jawaban + turunan + agregat).
- Tabulasi: variabel × kelompok × agregasi (count/mean/sum/min/max/distribusi).
- Ekspor CSV: data datar & daftar warning. Terbatas peran PML/Editor/Admin.

### 3.7 Audit
- Seluruh perubahan status, koreksi, impor, dan perubahan metadata tercatat.

## 4. Kebutuhan non-fungsional

- **Keamanan:** RBAC; header keamanan + CSP; rate-limit login; CSRF; kata sandi
  di-hash. Produksi: integrasi SSO BPS / Keycloak (belum).
- **Kerahasiaan:** data responden individu; akses jaringan internal/VPN; ekspor
  data mentah dibatasi peran; NDA seluruh pihak.
- **Basis data:** SQLite (dev) → PostgreSQL/MySQL (produksi) via `.env`.
- **Portabilitas metadata:** perubahan kuesioner tanpa deploy kode.
- **Skalabilitas target:** 1 kabupaten, ± 3.000 ruta/periode (MVP).

## 5. Asumsi & risiko utama

- Perlu koordinasi dengan BPS Provinsi & direktorat terkait agar tidak
  berbenturan dengan alur resmi ("shadow IT", "angka tandingan").
- Kuesioner berubah tiap semester — desain metadata-driven memitigasi.
- Ketersediaan SDM IT & keberlanjutan antar-periode.

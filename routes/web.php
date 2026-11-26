<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\SsoBangdataController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CleaningController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\PeriodeController;
use App\Http\Controllers\TabulationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('monitoring.dashboard'));

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:8,1');
Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('logout');

// Login SSO Bangdata (aktif bila SSO_CLIENT_ID / BANGDATA_CLIENT_ID terisi). Path harus persis
// "auth/sso/callback" — itu yang didaftarkan sebagai redirect URI di sso.bangdata.web.id.
Route::middleware('guest')->group(function () {
    Route::get('auth/sso', [SsoBangdataController::class, 'redirect'])->name('sso.bangdata');
    Route::get('auth/sso/callback', [SsoBangdataController::class, 'callback'])->name('sso.bangdata.callback');
});

Route::middleware('role')->group(function () {

    Route::get('/akun', [\App\Http\Controllers\AccountController::class, 'edit'])->name('akun');
    Route::post('/akun/sandi', [\App\Http\Controllers\AccountController::class, 'updatePassword'])->name('akun.sandi');

    Route::post('/dokumen/{document}/catatan', [\App\Http\Controllers\DocumentNoteController::class, 'store'])
        ->middleware('role:operator,pml,pj,admin')->name('dokumen.catatan');

    Route::prefix('notifikasi')->name('notifikasi.')->group(function () {
        Route::get('/', [\App\Http\Controllers\NotificationController::class, 'index'])->name('index');
        Route::get('/{id}', [\App\Http\Controllers\NotificationController::class, 'open'])->name('open');
        Route::post('/baca-semua', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('read-all');
    });

    Route::prefix('monitoring')->name('monitoring.')->group(function () {
        Route::get('/', [MonitoringController::class, 'dashboard'])->name('dashboard');
        Route::get('/tugas', [MonitoringController::class, 'myTasks'])->name('tasks');
        Route::get('/peta', [MonitoringController::class, 'peta'])->name('peta');
        Route::get('/sampel', [MonitoringController::class, 'samples'])->name('samples');
        Route::get('/sampel/{sample}', [MonitoringController::class, 'sample'])->name('sample');
        Route::post('/sampel/{sample}/status', [MonitoringController::class, 'setStatus'])
            ->middleware('role:pj,pml,admin')->name('sample.status');
        Route::post('/sampel/{sample}/tugaskan', [MonitoringController::class, 'assign'])
            ->middleware('role:pj,pml,admin')->name('sample.assign');
        Route::post('/sampel/{sample}/ganti', [MonitoringController::class, 'replace'])
            ->middleware('role:pj,pml,admin')->name('sample.replace');
        Route::post('/sampel-tugaskan', [MonitoringController::class, 'bulkAssign'])
            ->middleware('role:pj,pml,admin')->name('samples.bulk-assign');
        Route::post('/sampel/{sample}/dokumen', [MonitoringController::class, 'createDocument'])
            ->middleware('role:operator,admin')->name('sample.document');
        Route::get('/audit', [MonitoringController::class, 'audit'])
            ->middleware('role:pj,pml,admin')->name('audit');
        Route::get('/kinerja', [MonitoringController::class, 'kinerja'])
            ->middleware('role:pj,pml,admin')->name('kinerja');
    });

    // Inc 154 — Periode Survei: tahun → triwulan → survei → hub
    Route::prefix('periode')->name('periode.')->middleware('role:pj,pml,admin')->group(function () {
        Route::get('/', [PeriodeController::class, 'index'])->name('index');
        Route::get('/siklus/{cycle}', [PeriodeController::class, 'hub'])->name('hub');
        Route::get('/siklus/{cycle}/peserta', [PeriodeController::class, 'peserta'])->name('peserta');
        Route::get('/{tahun}', [PeriodeController::class, 'year'])->whereNumber('tahun')->name('tahun');
        Route::get('/{tahun}/tw/{tw}', [PeriodeController::class, 'quarter'])->whereNumber('tahun')->whereNumber('tw')->name('triwulan');
        Route::post('/{tahun}/tw/{tw}/siapkan', [PeriodeController::class, 'prepare'])->whereNumber('tahun')->whereNumber('tw')
            ->middleware('role:admin')->name('siapkan');
    });

    Route::prefix('pengolahan')->name('pengolahan.')->middleware('role:pj,pml,admin')->group(function () {
        Route::get('/', [CleaningController::class, 'index'])->name('index');
        Route::post('/revalidasi', [CleaningController::class, 'revalidate'])->name('revalidate');
        Route::post('/bersih-massal', [CleaningController::class, 'bulkClean'])->name('bulk-clean');
        Route::get('/{document}', [CleaningController::class, 'show'])->name('show');
        Route::post('/{document}/koreksi', [CleaningController::class, 'correct'])->name('correct');
        Route::post('/{document}/bersih', [CleaningController::class, 'markClean'])->name('bersih');
        Route::post('/{document}/buka', [CleaningController::class, 'reopen'])->name('reopen');
        Route::post('/{document}/kembalikan', [CleaningController::class, 'returnDoc'])->name('kembalikan');
        Route::post('/{document}/terima-warning', [CleaningController::class, 'ackWarning'])->name('terima-warning');
        Route::post('/{document}/batal-terima-warning', [CleaningController::class, 'unackWarning'])->name('batal-terima-warning');
    });

    Route::prefix('tabulasi')->name('tabulasi.')->middleware('role:pj,pml,admin,viewer')->group(function () {
        Route::get('/', [TabulationController::class, 'index'])->name('index');
        Route::get('/indikator', [TabulationController::class, 'indicators'])->name('indikator');
        Route::get('/ekspor-data', [TabulationController::class, 'exportRaw'])
            ->middleware('role:pj,pml,admin')->name('export.raw');
        Route::get('/ekspor-warning', [TabulationController::class, 'exportRules'])
            ->middleware('role:pj,pml,admin')->name('export.rules');
        Route::get('/ekspor-koreksi', [TabulationController::class, 'exportCorrections'])
            ->middleware('role:pj,pml,admin')->name('export.corrections');
    });

    Route::prefix('entri')->name('entri.')->group(function () {
        Route::get('/{document}/cetak', [EntryController::class, 'printView'])->name('print');
        Route::get('/{document}', [EntryController::class, 'form'])->name('form');
        Route::post('/{document}/jawaban', [EntryController::class, 'saveAnswer'])
            ->middleware('role:operator,admin')->name('answer');
        Route::post('/{document}/jumlah-art', [EntryController::class, 'setArtCount'])
            ->middleware('role:operator,admin')->name('set-art');
        Route::post('/{document}/finalisasi', [EntryController::class, 'finalize'])
            ->middleware('role:operator,pml,pj,admin')->name('finalize');
        Route::post('/{document}/foto-kunjungan', [\App\Http\Controllers\VisitPhotoController::class, 'store'])->name('foto.store');
    });

    // modul bukti kunjungan (opsional — lihat config/susenas.php)
    Route::get('/kunjungan/foto/{photo}', [\App\Http\Controllers\VisitPhotoController::class, 'show'])->name('kunjungan.foto');
    Route::delete('/kunjungan/foto/{photo}', [\App\Http\Controllers\VisitPhotoController::class, 'destroy'])->name('kunjungan.foto.hapus');

    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/metadata', [AdminController::class, 'metadata'])->name('metadata');
        Route::post('/blok', [AdminController::class, 'addBlock'])->name('blok.add');
        Route::get('/blok/{block}', [AdminController::class, 'blockEditor'])->name('blok');
        Route::post('/blok/{block}', [AdminController::class, 'updateBlock'])->name('blok.update');
        Route::post('/blok/{block}/urutan', [AdminController::class, 'reorderBlock'])->name('blok.order');
        Route::post('/blok/{block}/hapus', [AdminController::class, 'delBlock'])->name('blok.del');
        Route::post('/blok/{block}/rincian', [AdminController::class, 'addQuestion'])->name('rincian.add');
        Route::post('/blok/{block}/baris', [AdminController::class, 'importRows'])->name('baris.import');
        Route::post('/blok/{block}/baris-satu', [AdminController::class, 'saveRow'])->name('baris.save');
        Route::post('/baris/{row}/hapus', [AdminController::class, 'delRow'])->name('baris.del');
        Route::get('/rincian/{question}', [AdminController::class, 'questionEditor'])->name('rincian');
        Route::post('/rincian/{question}', [AdminController::class, 'updateQuestion'])->name('rincian.update');
        Route::post('/rincian/{question}/hapus', [AdminController::class, 'delQuestion'])->name('rincian.del');
        Route::post('/rincian/{question}/urutan', [AdminController::class, 'reorderQuestion'])->name('rincian.order');
        Route::post('/rincian/{question}/opsi', [AdminController::class, 'addOption'])->name('opsi.add');
        Route::post('/rincian/{question}/opsi-massal', [AdminController::class, 'bulkOptions'])->name('opsi.bulk');
        Route::post('/opsi/{option}/hapus', [AdminController::class, 'delOption'])->name('opsi.del');
        Route::post('/opsi/{option}/urutan', [AdminController::class, 'reorderOption'])->name('opsi.order');
        Route::get('/impor', [AdminController::class, 'importForm'])->name('impor');
        Route::post('/impor', [AdminController::class, 'importCsv'])->name('impor.run');
        Route::post('/parameter', [AdminController::class, 'saveParam'])->name('parameter.save');
        Route::post('/parameter/{parameter}/hapus', [AdminController::class, 'delParam'])->name('parameter.del');
        Route::post('/agregat', [AdminController::class, 'saveAggregate'])->name('agregat.save');
        Route::post('/agregat/{aggregate}/hapus', [AdminController::class, 'delAggregate'])->name('agregat.del');
        Route::post('/turunan', [AdminController::class, 'saveDerived'])->name('turunan.save');
        Route::post('/turunan/{derived}/hapus', [AdminController::class, 'delDerived'])->name('turunan.del');
        Route::get('/aturan', [AdminController::class, 'rulesList'])->name('aturan');
        Route::get('/aturan-baru', [AdminController::class, 'ruleNew'])->name('aturan.baru');
        Route::post('/aturan-periksa-ulang', [AdminController::class, 'rulesRecheck'])->name('aturan.periksa-ulang');
        Route::get('/tugas-latar/{task}', [AdminController::class, 'taskStatus'])->name('tugas.status');
        Route::post('/aturan-pratinjau', [AdminController::class, 'rulePreview'])->name('aturan.pratinjau');
        Route::get('/aturan/{rule}', [AdminController::class, 'ruleEditor'])->name('aturan.edit');
        Route::post('/aturan', [AdminController::class, 'saveRule'])->name('aturan.save');
        Route::post('/aturan/{rule}/hapus', [AdminController::class, 'delRule'])->name('aturan.del');
        Route::post('/aturan/{rule}/toggle', [AdminController::class, 'toggleRule'])->name('aturan.toggle');
        Route::post('/aturan/{rule}/cepat', [AdminController::class, 'quickRule'])->name('aturan.cepat');
        Route::post('/aturan/{rule}/kembalikan/{rev}', [AdminController::class, 'ruleRestore'])->name('aturan.kembalikan');
        Route::post('/aturan/{rule}/uji', [AdminController::class, 'ruleTests'])->name('aturan.uji');
        Route::post('/aturan-terpandu', [AdminController::class, 'buildRule'])->name('aturan.terpandu');
        Route::post('/aturan-terpandu/uji', [AdminController::class, 'wizardImpact'])->name('aturan.terpandu.uji');
        Route::post('/aturan-toggle-massal', [AdminController::class, 'bulkToggleRules'])->name('aturan.toggle-massal');
        Route::post('/aturan/uji', [AdminController::class, 'testRule'])->name('aturan.test');
        Route::post('/aturan/dampak', [AdminController::class, 'ruleImpact'])->name('aturan.impact');
        Route::post('/versi/duplikat', [AdminController::class, 'duplicateVersion'])->name('versi.duplikat');
        Route::post('/versi/{qv}/simpan', [AdminController::class, 'saveVersion'])->name('versi.simpan');
        Route::post('/versi/{qv}/aktifkan', [AdminController::class, 'activateVersion'])->name('versi.aktifkan');
        Route::post('/versi/{qv}/periksa', [AdminController::class, 'checkVersion'])->name('versi.periksa');
        Route::get('/versi/{qv}/sampel', [AdminController::class, 'versionSamples'])->name('versi.sampel');
        Route::post('/versi/{qv}/hapus', [AdminController::class, 'deleteVersion'])->name('versi.hapus');
        Route::get('/versi/{qv}/ekspor', [AdminController::class, 'qversionExport'])->name('versi.export');
        Route::get('/versi/{qv}/kodebook', [AdminController::class, 'codebook'])->name('versi.codebook');
        Route::get('/versi/{qv}/bandingkan', [AdminController::class, 'versionCompare'])->name('versi.bandingkan');
        Route::get('/versi-impor', [AdminController::class, 'qversionImportForm'])->name('versi.import');
        Route::post('/versi-impor', [AdminController::class, 'qversionImportRun'])->name('versi.import.run');
        // Pratinjau tampilan entri (renderer yang sama dengan halaman petugas) — Inc 143
        Route::get('/pratinjau/{qv}', [EntryController::class, 'previewForm'])->name('pratinjau');
        Route::get('/impor-wilayah', [AdminController::class, 'wilayahForm'])->name('impor.wilayah');
        Route::post('/impor-wilayah', [AdminController::class, 'wilayahImport'])->name('impor.wilayah.run');
        Route::post('/wilayah', [AdminController::class, 'wilayahStore'])->name('wilayah.simpan');
        Route::post('/wilayah/{wilayah}/nama', [AdminController::class, 'wilayahRename'])->name('wilayah.rename');
        Route::delete('/wilayah/{wilayah}', [AdminController::class, 'wilayahDestroy'])->name('wilayah.hapus');
        Route::get('/impor-sampel', [AdminController::class, 'sampelForm'])->name('impor.sampel');
        Route::post('/sampel', [AdminController::class, 'sampelStore'])->name('sampel.simpan');
        Route::post('/impor-sampel', [AdminController::class, 'sampelImport'])->name('impor.sampel.run');
        Route::get('/pengguna', [AdminController::class, 'users'])->name('pengguna');
        Route::post('/pengguna', [AdminController::class, 'saveUser'])->name('pengguna.save');
        Route::post('/pengguna/{user}/aktif', [AdminController::class, 'toggleUser'])->name('pengguna.toggle');
        Route::get('/impor-dokumen', [AdminController::class, 'docImportForm'])->name('impor.dokumen');
        Route::post('/impor-dokumen', [AdminController::class, 'docImport'])->name('impor.dokumen.run');
        Route::get('/impor-dokumen/template/{cycle}', [AdminController::class, 'docTemplate'])->name('impor.dokumen.template');
        Route::get('/impor-dokumen/kamus/{cycle}', [AdminController::class, 'docDictionary'])->name('impor.dokumen.kamus');
        Route::get('/impor-dokumen/ekspor/{cycle}', [AdminController::class, 'docExport'])->name('impor.dokumen.export');
        Route::post('/impor-dokumen/alias', [AdminController::class, 'aliasSave'])->name('impor.dokumen.alias.save');
        Route::post('/impor-dokumen/alias/{alias}/hapus', [AdminController::class, 'aliasDelete'])->name('impor.dokumen.alias.del');
        Route::get('/jadwal', [AdminController::class, 'jadwalForm'])->name('jadwal');
        Route::post('/jadwal', [AdminController::class, 'cycleCreate'])->name('jadwal.create');
        Route::post('/jadwal/{cycle}', [AdminController::class, 'jadwalSave'])->name('jadwal.save');
        Route::get('/siklus/{cycle}', [AdminController::class, 'cycleShow'])->name('siklus');
        Route::get('/siklus/{cycle}/persiapan', [AdminController::class, 'cyclePrep'])->name('siklus.persiapan');
        Route::post('/siklus/{cycle}/buka-lapangan', [AdminController::class, 'cycleOpenField'])->name('siklus.buka-lapangan');
        Route::post('/siklus/{cycle}/revalidasi', [AdminController::class, 'cycleRevalidate'])->name('siklus.revalidate');
        Route::post('/siklus/{cycle}/kunci', [AdminController::class, 'cycleLock'])->name('siklus.lock');
        Route::post('/siklus/{cycle}/buka', [AdminController::class, 'cycleUnlock'])->name('siklus.unlock');
        Route::get('/harga', [AdminController::class, 'hargaForm'])->name('harga');
        Route::post('/harga', [AdminController::class, 'hargaImport'])->name('harga.import');
        Route::post('/harga/simpan', [AdminController::class, 'hargaSave'])->name('harga.save');
        Route::post('/harga/tambah', [AdminController::class, 'hargaAdd'])->name('harga.add');
    });
});

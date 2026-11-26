<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- Perawatan terjadwal (aktif bila `php artisan schedule:run` dipanggil cron/Task Scheduler) ---

// Cadangan basis data harian 01:30
Schedule::command('susenas:backup')->dailyAt('01:30')->withoutOverlapping();

// Pangkas notifikasi yang sudah dibaca & berumur > 30 hari
Schedule::call(function () {
    DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('created_at', '<', now()->subDays(30))
        ->delete();
})->weekly()->name('prune-notifications');

// Inc 163: pengingat jadwal lapangan vs status siklus (ke Notifikasi admin), tiap pagi
Schedule::command('susenas:ingat-jadwal')->dailyAt('07:00');

// Ringkasan kondisi data mingguan (ke log)
Schedule::command('susenas:health')->weeklyOn(1, '06:00');

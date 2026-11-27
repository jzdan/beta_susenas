<?php

namespace App\Console\Commands;

use App\Models\SurveyCycle;
use App\Models\User;
use App\Notifications\CycleScheduleReminder;
use Illuminate\Console\Command;

/**
 * Inc 163: ingatkan admin bila status siklus tidak cocok dengan kalender. Status TIDAK diubah otomatis
 * (menutup lapangan otomatis bisa mengunci petugas yang masih menyelesaikan isian).
 * Satu pengingat per siklus per keadaan selama pengingat sebelumnya belum dibaca.
 */
class ScheduleReminder extends Command
{
    protected $signature = 'susenas:ingat-jadwal';

    protected $description = 'Kirim notifikasi ke admin untuk periode yang jadwal lapangannya tidak cocok dengan statusnya';

    public function handle(): int
    {
        $admins = User::where('role', 'admin')->where('active', true)->get();
        $sent = 0;
        foreach (SurveyCycle::whereIn('status', ['lapangan', 'persiapan'])->get() as $c) {
            $kind = $c->fieldOverdue() ? 'lewat' : ($c->fieldShouldHaveStarted() ? 'mulai' : null);
            if (! $kind) {
                continue;
            }
            $this->line("• {$c->name}: ".($kind === 'lewat' ? 'jadwal selesai, masih Pencacahan' : 'jadwal mulai, masih Persiapan'));
            foreach ($admins as $a) {
                $pending = $a->unreadNotifications()->where('data->kind', 'cycle_schedule')
                    ->where('data->cycle_id', $c->id)->where('data->sub', $kind)->exists();
                if (! $pending) {
                    $a->notify(new CycleScheduleReminder($c, $kind));
                    $sent++;
                }
            }
        }
        $this->info("{$sent} pengingat dikirim.");

        return self::SUCCESS;
    }
}

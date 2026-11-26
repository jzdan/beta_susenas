<?php

namespace App\Notifications;

use App\Models\SurveyCycle;
use Illuminate\Notifications\Notification;

/** Inc 163: status siklus tidak cocok dengan jadwalnya (lapangan lewat / persiapan padahal sudah mulai). */
class CycleScheduleReminder extends Notification
{
    public function __construct(private SurveyCycle $cycle, private string $kind) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $c = $this->cycle;
        $tgl = fn ($d) => $d->day.' '.SurveyCycle::MONTHS[$d->month].' '.$d->year;
        [$title, $body] = $this->kind === 'lewat'
            ? ["Jadwal lapangan {$c->name} sudah selesai",
                'Jadwal berakhir '.$tgl($c->field_end).', tetapi status masih Lapangan sehingga petugas masih bisa mengubah isian. '
                .'Bila pencacahan memang sudah selesai, ubah status ke Pengolahan.']
            : ["Jadwal lapangan {$c->name} sudah mulai",
                'Jadwal mulai '.$tgl($c->field_start).', tetapi status masih Persiapan sehingga petugas belum bisa mengisi. '
                .'Periksa Persiapan periode lalu buka lapangan.'];

        return [
            'kind' => 'cycle_schedule', 'cycle_id' => $c->id, 'sub' => $this->kind,
            'title' => $title, 'body' => $body, 'by' => 'Sistem',
            'url' => route('admin.siklus.persiapan', $c),
            'ref' => $c->label().' · triwulan '.$c->quarter().' '.$c->year(),
        ];
    }
}

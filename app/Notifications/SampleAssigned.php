<?php

namespace App\Notifications;

use App\Models\SampleHousehold;
use Illuminate\Notifications\Notification;

class SampleAssigned extends Notification
{
    public function __construct(
        private SampleHousehold $sample,
        private ?string $byName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'sample_assigned',
            'title' => 'Sampel baru ditugaskan kepada Anda',
            'body' => $this->sample->krt_name ?: 'Ruta '.$this->sample->no_ruta,
            'by' => $this->byName,
            'url' => route('monitoring.sample', $this->sample),
            'ref' => "BS {$this->sample->no_bs} · Ruta {$this->sample->no_ruta}",
        ];
    }
}

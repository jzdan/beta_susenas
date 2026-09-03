<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Notification;

class DocumentReturned extends Notification
{
    public function __construct(
        private Document $document,
        private string $reason,
        private ?string $byName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $s = $this->document->sample;

        return [
            'kind' => 'document_returned',
            'title' => 'Dokumen dikembalikan untuk perbaikan',
            'body' => \Illuminate\Support\Str::limit($this->reason, 140),
            'by' => $this->byName,
            'url' => route('entri.form', $this->document),
            'ref' => "dok #{$this->document->id} · BS {$s?->no_bs} · Ruta {$s?->no_ruta}",
        ];
    }
}

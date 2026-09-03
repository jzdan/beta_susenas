<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class DocumentNoteAdded extends Notification
{
    public function __construct(
        private Document $document,
        private string $body,
        private ?string $byName = null,
        private string $url = '',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $s = $this->document->sample;

        return [
            'kind' => 'document_note',
            'title' => 'Catatan baru pada dokumen',
            'body' => Str::limit($this->body, 140),
            'by' => $this->byName,
            'url' => $this->url ?: route('entri.form', $this->document),
            'ref' => "dok #{$this->document->id} · BS {$s?->no_bs} · Ruta {$s?->no_ruta}",
        ];
    }
}

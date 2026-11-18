<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentNote;
use App\Models\User;
use App\Notifications\DocumentNoteAdded;
use Illuminate\Http\Request;

class DocumentNoteController extends Controller
{
    public function store(Request $request, Document $document)
    {
        $d = $request->validate([
            'body' => 'required|string|min:2|max:2000',
            'from' => 'nullable|in:entri,pengolahan',
        ]);
        $user = $request->user();

        // cakupan wilayah
        $scope = $user->wilayah_scope;
        if ($scope && ! str_starts_with((string) $document->sample?->wilayah_code, $scope)) {
            abort(403, 'Sampel di luar cakupan wilayah Anda.');
        }

        $note = DocumentNote::create([
            'document_id' => $document->id,
            'body' => $d['body'],
            'by_user' => $user->id,
        ]);
        AuditLog::record('document', $document->id, 'catatan',
            \Illuminate\Support\Str::limit($d['body'], 100), $user->id);

        $this->notifyOthers($document, $note, $user);

        $back = ($d['from'] ?? '') === 'pengolahan'
            ? route('pengolahan.show', $document)
            : route('entri.form', $document);

        return redirect($back.'#catatan')->with('ok', 'Catatan ditambahkan.');
    }

    private function notifyOthers(Document $document, DocumentNote $note, User $author): void
    {
        $s = $document->sample;
        $ids = array_filter([
            $s?->petugas_id, $s?->pml_id,
            $document->created_by, $document->cleaned_by, $document->returned_by,
        ]);
        $ids = array_merge($ids, DocumentNote::where('document_id', $document->id)
            ->pluck('by_user')->all());
        $ids = array_values(array_unique(array_map('intval', $ids)));

        foreach (User::whereIn('id', $ids)->where('id', '!=', $author->id)->where('active', true)->get() as $u) {
            $url = $u->role === 'operator'
                ? route('entri.form', $document)
                : route('pengolahan.show', $document);
            $u->notify(new DocumentNoteAdded($document, $note->body, $author->name, $url));
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\VisitPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Modul opsional "bukti kunjungan" — foto per rumah tangga sampel.
 * Aktif hanya bila config('susenas.bukti_kunjungan') = true.
 */
class VisitPhotoController extends Controller
{
    private function guardEnabled(): void
    {
        abort_unless(config('susenas.bukti_kunjungan'), 404);
    }

    private function canView(VisitPhoto $photo, $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }
        if ((int) $photo->uploaded_by === (int) $user->id) {
            return true;
        }
        $s = $photo->sample;
        if ($s && (int) $s->petugas_id === (int) $user->id) {
            return true;
        }
        if (in_array($user->role, ['pml', 'viewer'], true)) {
            $scope = $user->wilayah_scope;

            return $s && (! $scope || str_starts_with((string) $s->wilayah_code, $scope));
        }

        return false;
    }

    public function store(Request $request, Document $document)
    {
        $this->guardEnabled();
        abort_unless(in_array($request->user()->role, ['operator', 'pml', 'admin'], true), 403);
        abort_if($document->cycleLocked(), 423, 'Siklus sudah dikunci.');
        if ($request->user()->role === 'operator') {
            abort_unless((int) $document->sample->petugas_id === (int) $request->user()->id, 403);
        }

        $d = $request->validate([
            'foto' => 'required|image|mimes:jpeg,jpg,png,webp|max:8192',
            'caption' => 'nullable|string|max:200',
        ]);
        $sid = $document->sample_id;
        $path = $request->file('foto')->store("kunjungan/{$sid}");

        VisitPhoto::create([
            'sample_id' => $sid,
            'path' => $path,
            'caption' => trim($d['caption'] ?? '') ?: null,
            'uploaded_by' => $request->user()->id,
            'bytes' => $request->file('foto')->getSize(),
        ]);
        AuditLog::record('visit_photo', $sid, 'unggah', basename($path), $request->user()->id);

        return back()->with('ok', 'Foto bukti kunjungan tersimpan.');
    }

    public function show(Request $request, VisitPhoto $photo)
    {
        $this->guardEnabled();
        abort_unless($this->canView($photo, $request->user()), 403);
        abort_unless(Storage::exists($photo->path), 404);

        return response()->file(Storage::path($photo->path));
    }

    public function destroy(Request $request, VisitPhoto $photo)
    {
        $this->guardEnabled();
        $user = $request->user();
        abort_unless($user->role === 'admin' || (int) $photo->uploaded_by === (int) $user->id, 403);

        Storage::delete($photo->path);
        $sid = $photo->sample_id;
        $photo->delete();
        AuditLog::record('visit_photo', $sid, 'hapus', '', $user->id);

        return back()->with('ok', 'Foto dihapus.');
    }
}

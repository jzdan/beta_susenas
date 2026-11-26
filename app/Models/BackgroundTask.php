<?php

namespace App\Models;

use App\Jobs\RecheckDocuments;
use Illuminate\Database\Eloquent\Model;

/**
 * Pekerjaan latar belakang yang terlihat di layar (Inc 161). Pekerjaan berat (periksa ulang ratusan
 * sampel, hitung aturan setelah impor besar) dijalankan lewat antrean Laravel supaya halaman tidak
 * menunggu / kehabisan waktu. Dengan QUEUE_CONNECTION=sync (komputer pengembang) langsung dijalankan;
 * di server produksi perlu `php artisan queue:work` (lihat docs/deployment.md).
 */
class BackgroundTask extends Model
{
    public const UPDATED_AT = null;

    /** Di bawah batas ini pekerjaan langsung dijalankan di permintaan yang sama (cepat, tanpa antrean). */
    public static int $inlineMax = 50;

    /** Antre lebih lama dari ini (menit) = kemungkinan proses latar belakang tidak berjalan. */
    public const STUCK_MINUTES = 5;

    public const STATUS_LABELS = ['antre' => 'Menunggu giliran', 'berjalan' => 'Sedang berjalan',
        'selesai' => 'Selesai', 'gagal' => 'Gagal'];

    protected $fillable = ['kind', 'label', 'qversion_id', 'status', 'done', 'total', 'message', 'by_user',
        'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** Buat catatan pekerjaan + kirim ke antrean pemeriksaan ulang untuk daftar sampel tertentu. */
    public static function recheck(string $kind, string $label, ?int $qvId, array $docIds): self
    {
        $t = static::create(['kind' => $kind, 'label' => $label, 'qversion_id' => $qvId,
            'total' => count($docIds), 'by_user' => auth()->id()]);
        RecheckDocuments::dispatch($t->id, array_values($docIds));

        return $t->fresh();
    }

    public function running(): bool
    {
        return in_array($this->status, ['antre', 'berjalan'], true);
    }

    public function stuck(): bool
    {
        return $this->status === 'antre' && $this->created_at?->lt(now()->subMinutes(self::STUCK_MINUTES));
    }

    public function pct(): int
    {
        return $this->total ? (int) floor(100 * $this->done / $this->total) : ($this->status === 'selesai' ? 100 : 0);
    }

    public function toStatus(): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'label' => self::STATUS_LABELS[$this->status] ?? $this->status,
            'done' => $this->done, 'total' => $this->total, 'pct' => $this->pct(), 'message' => $this->message,
            'running' => $this->running(), 'stuck' => $this->stuck()];
    }

    /** Pekerjaan yang perlu ditampilkan: masih berjalan, atau selesai/gagal dalam 30 menit terakhir. */
    public static function visibleFor(?int $qvId, ?string $kind = null)
    {
        return static::query()
            ->when($qvId, fn ($q) => $q->where('qversion_id', $qvId))
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->where(fn ($q) => $q->whereIn('status', ['antre', 'berjalan'])
                ->orWhere('finished_at', '>=', now()->subMinutes(30)))
            ->orderByDesc('id')->limit(3)->get();
    }
}

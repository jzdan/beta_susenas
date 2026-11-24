<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = [
        'sample_id', 'qversion_id', 'status', 'submitted_at', 'created_by', 'cleaned_by', 'cleaned_at',
        'return_reason', 'returned_by', 'returned_at',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'cleaned_at' => 'datetime', 'returned_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // catat kapan dokumen pertama kali keluar dari tahap entri
        static::saving(function (self $doc) {
            if (! $doc->submitted_at && in_array($doc->status, ['selesai', 'bersih', 'dikembalikan'], true)) {
                $doc->submitted_at = now();
            }
        });
    }

    /** Siklus dokumen ini sudah dikunci (status 'selesai')? */
    public function cycleLocked(): bool
    {
        return (bool) $this->sample?->cycle?->isLocked();
    }

    /**
     * Petugas boleh mengubah isian ini? Ya selama periode lapangan siklusnya dibuka, atau —
     * di luar itu — bila isian dikembalikan pemeriksa untuk diperbaiki (selama siklus belum
     * dikunci). Kepemilikan sampel diperiksa terpisah (EntryController::assertCanView).
     */
    public function editableByPetugas(): bool
    {
        $cycle = $this->sample?->cycle;
        if (! $cycle || $cycle->isLocked() || $this->status === 'bersih') {
            return false;
        }

        return $cycle->isFieldOpen() || $this->status === 'dikembalikan';
    }

    /** Sejak kapan dokumen menunggu di tahap saat ini (null bila tak relevan). */
    public function waitingSince(): ?\Illuminate\Support\Carbon
    {
        return match ($this->status) {
            'selesai' => $this->submitted_at,
            'dikembalikan' => $this->returned_at,
            default => null,
        };
    }

    public function waitingDays(): ?int
    {
        return $this->waitingSince()?->startOfDay()->diffInDays(now()->startOfDay());
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(SampleHousehold::class, 'sample_id');
    }

    public function qversion(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireVersion::class, 'qversion_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }
}

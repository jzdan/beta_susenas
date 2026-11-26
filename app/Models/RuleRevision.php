<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu revisi aturan validasi (Inc 158). `data` = keadaan aturan sesudah perubahan.
 * Revisi pertama sebuah aturan seed bertanda `awal` (keadaan sebelum perubahan pertama).
 */
class RuleRevision extends Model
{
    public $timestamps = false;

    /** Kolom aturan yang direkam & bisa dikembalikan. */
    public const FIELDS = ['code', 'expr', 'message', 'severity', 'scope', 'row_block', 'target_var', 'note', 'active'];

    public const ACTION_LABELS = [
        'awal' => 'Keadaan awal', 'buat' => 'Dibuat', 'ubah' => 'Diubah', 'sunting-cepat' => 'Sunting cepat',
        'nyalakan' => 'Dinyalakan', 'matikan' => 'Dimatikan', 'kembalikan' => 'Dikembalikan',
    ];

    /** Label kolom untuk ringkasan perbedaan. */
    public const FIELD_LABELS = [
        'code' => 'Kode', 'expr' => 'Rumus', 'message' => 'Pesan', 'severity' => 'Tingkat', 'scope' => 'Kelompok',
        'row_block' => 'Blok per baris', 'target_var' => 'Rincian sasaran', 'note' => 'Keterangan', 'active' => 'Menyala',
    ];

    /** Aksi berikutnya yang dipaksakan pemanggil (mis. 'kembalikan'); dibaca sekali oleh event model. */
    public static ?string $nextAction = null;

    /** Jalankan perubahan dengan label aksi tertentu (direkam event model ValidationRule). */
    public static function as(string $action, callable $fn): mixed
    {
        self::$nextAction = $action;
        try {
            return $fn();
        } finally {
            self::$nextAction = null;
        }
    }

    protected $fillable = ['rule_id', 'action', 'data', 'by_user', 'at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'at' => 'datetime'];
    }

    public function rule()
    {
        return $this->belongsTo(ValidationRule::class, 'rule_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'by_user');
    }

    public static function snapshot(ValidationRule $r, bool $original = false): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $v = $original ? $r->getOriginal($f) : $r->getAttribute($f);
            $out[$f] = $f === 'active' ? (bool) $v : (string) ($v ?? '');
        }

        return $out;
    }

    public static function record(ValidationRule $r, string $action, ?array $data = null): self
    {
        return static::create([
            'rule_id' => $r->id, 'action' => $action, 'data' => $data ?? self::snapshot($r),
            'by_user' => auth()->id(), 'at' => now(),
        ]);
    }

    /** Kolom yang berbeda dari revisi sebelumnya: [kolom => [lama, baru]]. */
    public function diffFrom(?self $prev): array
    {
        if (! $prev) {
            return [];
        }
        $out = [];
        foreach (self::FIELDS as $f) {
            $a = $prev->data[$f] ?? '';
            $b = $this->data[$f] ?? '';
            if ($a !== $b) {
                $out[$f] = [$a, $b];
            }
        }

        return $out;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu siklus = satu instrumen kuesioner pada satu periode (mis. "SUSENAS Maret 2026 (KP)").
 * Inc 154: dikelompokkan tahun → triwulan → survei. Tahun & triwulan dihitung dari `period`
 * (format YYYY-MM): Jan–Mar = TW1, Apr–Jun = TW2, Jul–Sep = TW3, Okt–Des = TW4.
 */
class SurveyCycle extends Model
{
    protected $fillable = ['name', 'period', 'status', 'field_start', 'field_end', 'survey', 'instrument'];

    protected $casts = [
        'field_start' => 'date',
        'field_end' => 'date',
    ];

    public const SURVEYS = ['SUSENAS', 'SERUTI'];

    /** Instrumen yang dikenali dari nama (isi di dalam kurung). Kode, bukan singkatan antarmuka. */
    public const INSTRUMENTS = ['KOR', 'KP', 'INTI', 'MAK', 'MODUL'];

    public const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
        'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    protected static function booted(): void
    {
        // siklus yang dibuat tanpa menyebut survei/instrumen (seeder lama, impor) — tebak dari nama
        static::creating(function (self $c) {
            if (! $c->instrument || ! $c->getAttribute('survey')) {
                [$survey, $instrument] = self::inferIdentity((string) $c->name, null);
                $c->survey = $c->getAttribute('survey') ?: $survey;
                $c->instrument = $c->instrument ?: $instrument;
            }
        });
    }

    /**
     * Tebak [survei, instrumen] dari nama siklus & kode versi kuesionernya.
     * "SERUTI Juni 2026 (INTI)" → [SERUTI, INTI]; "SUSENAS Maret 2026 (KP)" → [SUSENAS, KP];
     * "SUSENAS Maret 2027 (contoh)" + versi VSEN27.KP → [SUSENAS, KP]; "SERUTI Maret 2026" → [SERUTI, INTI].
     */
    public static function inferIdentity(string $name, ?string $qvCode): array
    {
        $survey = stripos($name, 'SERUTI') !== false || stripos((string) $qvCode, 'SERUTI') !== false ? 'SERUTI' : 'SUSENAS';
        $instrument = null;
        if (preg_match('/\(([^)]+)\)/', $name, $m) && in_array(strtoupper(trim($m[1])), self::INSTRUMENTS, true)) {
            $instrument = strtoupper(trim($m[1]));
        } elseif ($qvCode && preg_match('/\.(KP|K|INTI|MAK|M)\b/i', $qvCode, $m)) {
            $instrument = ['K' => 'KOR', 'M' => 'MODUL'][strtoupper($m[1])] ?? strtoupper($m[1]);
        } elseif ($survey === 'SERUTI') {
            $instrument = 'INTI';
        }

        return [$survey, $instrument];
    }

    /** Siklus 'selesai' terkunci: entri & pengolahan tidak boleh mengubah data lagi. */
    public function isLocked(): bool
    {
        return $this->status === 'selesai';
    }

    /**
     * Periode lapangan dibuka: petugas boleh membuat & mengisi isian. Diatur admin per siklus
     * di Jadwal Lapangan — terpisah dari "versi aktif" (itu cuma versi bawaan menu admin).
     */
    public function isFieldOpen(): bool
    {
        return $this->status === 'lapangan';
    }

    public function year(): ?int
    {
        return preg_match('/^(\d{4})-(\d{2})/', (string) $this->period, $m) ? (int) $m[1] : null;
    }

    public function month(): ?int
    {
        return preg_match('/^(\d{4})-(\d{2})/', (string) $this->period, $m) ? (int) $m[2] : null;
    }

    /** 1–4, atau null bila period tidak berformat YYYY-MM. */
    public function quarter(): ?int
    {
        $m = $this->month();

        return $m ? (int) ceil($m / 3) : null;
    }

    // ---------------------------------------------------------------- kalender (Inc 163)

    /** Lapangan boleh selesai paling lambat sekian bulan sesudah triwulannya berakhir (mis. SERUTI Maret s.d. 15 April). */
    public const FIELD_END_GRACE_MONTHS = 2;

    /** [awal, akhir] triwulan sebuah period YYYY-MM, atau null bila formatnya tidak dikenal. */
    public static function quarterRange(string $period): ?array
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $period, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return null;
        }
        $q = (int) ceil((int) $m[2] / 3);
        $start = \Illuminate\Support\Carbon::create((int) $m[1], $q * 3 - 2, 1)->startOfDay();

        return [$start, $start->copy()->addMonths(3)->subDay()->endOfDay()];
    }

    /**
     * Masalah jadwal lapangan terhadap periodenya, atau null bila wajar:
     * tanggal mulai harus di dalam triwulan periode; tanggal selesai paling lambat
     * FIELD_END_GRACE_MONTHS bulan sesudah triwulan berakhir.
     */
    public static function scheduleProblem(string $period, $start, $end): ?string
    {
        $r = self::quarterRange($period);
        if (! $r || (! $start && ! $end)) {
            return null;
        }
        [$qs, $qe] = $r;
        $q = (int) ceil($qs->month / 3);
        $fmt = fn ($d) => $d->day.' '.self::MONTHS[$d->month].' '.$d->year;
        $start = $start ? \Illuminate\Support\Carbon::parse($start) : null;
        $end = $end ? \Illuminate\Support\Carbon::parse($end) : null;
        if ($start && ($start->lt($qs) || $start->gt($qe))) {
            return "Periode {$period} termasuk triwulan {$q} {$qs->year} ({$fmt($qs)} – {$fmt($qe)}), "
                ."jadi tanggal mulai lapangan harus di antara tanggal itu (sekarang {$fmt($start)}).";
        }
        $latest = $qe->copy()->addMonthsNoOverflow(self::FIELD_END_GRACE_MONTHS)->endOfMonth();
        if ($end && ($end->gt($latest) || $end->lt($qs))) {
            return "Tanggal selesai lapangan ({$fmt($end)}) di luar periode {$period}: paling cepat {$fmt($qs)}, "
                ."paling lambat {$fmt($latest)}.";
        }

        return null;
    }

    public function scheduleIssue(): ?string
    {
        return self::scheduleProblem((string) $this->period, $this->field_start, $this->field_end);
    }

    /** Triwulan periode ini belum dimulai (menurut kalender hari ini). */
    public function quarterNotStarted(): bool
    {
        $r = self::quarterRange((string) $this->period);

        return $r !== null && now()->lt($r[0]);
    }

    /** Masih berstatus lapangan padahal jadwal lapangannya sudah lewat. */
    public function fieldOverdue(): bool
    {
        return $this->status === 'lapangan' && $this->field_end && $this->field_end->lt(today());
    }

    /** Masih persiapan padahal tanggal mulai lapangan sudah tiba. */
    public function fieldShouldHaveStarted(): bool
    {
        return $this->status === 'persiapan' && $this->field_start && $this->field_start->lte(today());
    }

    /** "SUSENAS KP", "SERUTI INTI", atau "SUSENAS" bila instrumen tak diketahui. */
    public function label(): string
    {
        return trim(($this->survey ?: 'SUSENAS').' '.($this->instrument ?? ''));
    }

    public function monthName(): string
    {
        return self::MONTHS[$this->month()] ?? (string) $this->period;
    }

    /** Siklus dalam satu triwulan: period di antara YYYY-(3q-2) dan YYYY-(3q). */
    public function scopeInQuarter(Builder $q, int $year, int $quarter): Builder
    {
        return $q->whereBetween('period', [
            sprintf('%04d-%02d', $year, $quarter * 3 - 2), sprintf('%04d-%02d', $year, $quarter * 3).'~',
        ]);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuestionnaireVersion::class, 'cycle_id');
    }

    public function samples(): HasMany
    {
        return $this->hasMany(SampleHousehold::class, 'cycle_id');
    }
}

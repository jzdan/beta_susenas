<?php

namespace Database\Seeders;

use App\Models\Block;
use App\Models\Document;
use App\Models\Question;
use App\Services\RuleEngine;
use Illuminate\Support\Facades\DB;

/**
 * Inc 165 — pengisi isian SINTETIS untuk data contoh (bukan untuk produksi).
 * Melengkapi setiap blok yang belum punya jawaban di sebuah isian, sesuai jenis rincian &
 * petunjuk dari label/satuannya:
 *  - pilihan → kode yang sah (nama pilihan dipakai utk hubungan KRT, jenis kelamin, status kawin);
 *  - rupiah → rentang wajar menurut kata kunci label (aset, simpanan, sebulan, seminggu, …);
 *  - roster anggota → sebanyak "banyaknya anggota rumah tangga", umur/jenis kelamin/hubungan konsisten;
 *  - blok daftar → sebagian baris; baris subtotal "[453 + 454]" & "TOTAL" = jumlah rinciannya;
 *  - syarat lewati dihormati: sesudah diisi, sel yang syarat lewatinya menyala dihapus lagi.
 * Acak memakai mt_rand (seed diatur pemanggil) → hasil sama setiap seeding.
 */
class IsianSintetis
{
    private const NAMES_M = ['Moh. Rasyid', 'Abdul Hamid', 'Slamet', 'Fauzan', 'Hasan', 'Mat Sahri', 'Ridwan', 'Sukardi',
        'Imam Syafi\'i', 'Ach. Junaidi', 'Hendra', 'Yusuf', 'Bahrul', 'Taufik', 'Zainal'];

    private const NAMES_F = ['Siti Aminah', 'Halimah', 'Nur Laila', 'Fatimah', 'Rukayah', 'Maimunah', 'Sulastri', 'Juwairiyah',
        'Khotimah', 'Dewi', 'Rina', 'Musyarofah', 'Ummi Kulsum', 'Faridah', 'Nurul Hidayah'];

    private const JOBS = ['Buruh tani', 'Petani padi', 'Nelayan', 'Pedagang sembako', 'Guru SD', 'Tukang batu', 'Penjahit',
        'Sopir angkutan', 'Pegawai toko', 'Peternak sapi', 'Buruh pabrik', 'Pedagang sayur keliling'];

    public function __construct(private RuleEngine $engine) {}

    /** Lengkapi blok kosong di isian ini. Mengembalikan banyaknya jawaban yang ditambahkan. */
    public function fill(Document $doc, ?string $dateHint = null): int
    {
        $blocks = Block::where('qversion_id', $doc->qversion_id)->orderBy('order')->with(['questions.options', 'rows'])->get();
        $have = DB::table('answers')->where('document_id', $doc->id)->get(['question_id', 'art_index', 'raw_value']);
        $filledQ = $have->pluck('question_id')->flip();
        $varOf = $blocks->flatMap(fn ($b) => $b->questions)->pluck('var_name', 'id');

        // banyaknya anggota: dari roster yang sudah ada, atau rincian "banyaknya anggota", atau acak
        $artIdx = $have->filter(fn ($a) => $blocks->first(fn ($b) => $b->repeat === 'art'
            && $b->questions->contains('id', $a->question_id)))->max('art_index');
        $nArtQ = $blocks->where('repeat', 'ruta')->flatMap(fn ($b) => $b->questions)
            ->first(fn ($q) => $q->unit === 'orang' && preg_match('/banyak.*anggota|^r301$|banyak_art/i', $q->label.' '.$q->var_name));
        $nArtVal = $nArtQ ? $have->firstWhere('question_id', $nArtQ->id)?->raw_value : null;
        $nArt = (int) ($artIdx ?: $nArtVal ?: mt_rand(2, 6));
        $nArt = max(1, min($nArt, 9));
        $people = $this->people($nArt);

        $rows = [];
        $put = function (Question $q, int $idx, $val) use (&$rows, $doc) {
            if ($val === null || $val === '') {
                return;
            }
            $rows[] = ['document_id' => $doc->id, 'question_id' => $q->id, 'art_index' => $idx, 'raw_value' => (string) $val,
                'clean_value' => null, 'created_at' => $doc->updated_at ?? now(), 'updated_at' => $doc->updated_at ?? now()];
        };

        $mainRoster = true;
        foreach ($blocks as $b) {
            if ($b->questions->isEmpty() || $b->questions->contains(fn ($q) => isset($filledQ[$q->id]))) {
                if ($b->repeat === 'art') {
                    $mainRoster = false;
                }
                continue; // blok ini sudah ada isinya (disalin dari isian sumber)
            }
            switch ($b->repeat) {
                case 'ruta':
                    foreach ($b->questions as $q) {
                        $put($q, 0, $q->id === $nArtQ?->id ? $nArt : $this->value($q, null, $dateHint));
                    }
                    break;
                case 'art':
                    foreach ($people as $no => $p) {
                        // blok anggota lain (pekerjaan, usaha): hanya sebagian anggota dewasa
                        if (! $mainRoster && ($p['umur'] < 15 || ($no > 1 && mt_rand(0, 1)))) {
                            continue;
                        }
                        foreach ($b->questions as $q) {
                            $put($q, $no, $this->value($q, $p, $dateHint, $no));
                        }
                    }
                    $mainRoster = false;
                    break;
                case 'list':
                    $this->fillList($b, $put, $dateHint);
                    break;
                case 'list_art':
                    $pick = $b->rows->shuffle()->take(mt_rand(3, 7));
                    foreach ($pick as $r) {
                        foreach ($people as $no => $p) {
                            if (mt_rand(0, 2) === 0) {
                                continue;
                            }
                            foreach ($b->questions as $q) {
                                $put($q, Block::packRowArt($r->row_index, $no), $this->value($q, $p, $dateHint, $no));
                            }
                        }
                    }
                    break;
            }
        }
        if (! $rows) {
            return 0;
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('answers')->insert($chunk);
        }
        $this->dropSkipped($doc, $blocks);

        return count($rows);
    }

    /** Hapus jawaban yang syarat lewatinya menyala (rincian ruta & sel berulang). */
    private function dropSkipped(Document $doc, $blocks): void
    {
        $ctx = $this->engine->buildContext($doc);
        foreach ($blocks->where('repeat', 'ruta') as $b) {
            foreach ($b->questions as $q) {
                if ($q->skip_expr && $this->engine->skipFires($q->skip_expr, $ctx)) {
                    DB::table('answers')->where('document_id', $doc->id)->where('question_id', $q->id)->delete();
                }
            }
        }
        $byVar = $blocks->flatMap(fn ($b) => $b->questions)->keyBy('var_name');
        foreach ($this->engine->lastSkipped() as $var => $idx) {
            if ($q = $byVar->get($var)) {
                DB::table('answers')->where('document_id', $doc->id)->where('question_id', $q->id)
                    ->whereIn('art_index', array_keys($idx))->delete();
            }
        }
    }

    /** Baris tetap: sebagian rincian diisi; baris subtotal/TOTAL = jumlah rinciannya. */
    private function fillList(Block $b, callable $put, ?string $dateHint): void
    {
        $isSum = fn ($r) => preg_match('/\[[\d\s+]+\]|^\s*(total|jumlah)\b/i', (string) $r->label);
        $detail = $b->rows->reject($isSum)->values();
        $take = $detail->count() <= 12 ? max(1, (int) ceil($detail->count() * 0.5)) : mt_rand(12, 24);
        $vals = []; // code => [var => angka]
        foreach ($detail->shuffle()->take($take) as $r) {
            foreach ($b->questions as $q) {
                $v = $this->value($q, null, $dateHint);
                $put($q, $r->row_index, $v);
                if (is_numeric($v)) {
                    $vals[$r->code][$q->var_name] = (float) $v;
                }
            }
        }
        $subCodes = [];
        foreach ($b->rows->filter($isSum)->sortBy('order') as $r) {
            if (preg_match('/\[([\d\s+]+)\]/', (string) $r->label, $m)) {
                $codes = array_map('trim', explode('+', $m[1]));
                $subCodes = array_merge($subCodes, [$r->code]);
            } else { // TOTAL tanpa rumus: jumlah subtotal bila ada, selain itu semua rincian
                $codes = $subCodes ?: $detail->pluck('code')->all();
            }
            foreach ($b->questions as $q) {
                if (! in_array($q->qtype, ['int', 'decimal'], true)) {
                    continue;
                }
                $sum = 0;
                $any = false;
                foreach ($codes as $c) {
                    if (isset($vals[$c][$q->var_name])) {
                        $sum += $vals[$c][$q->var_name];
                        $any = true;
                    }
                }
                if ($any) {
                    $put($q, $r->row_index, (string) (int) $sum);
                    $vals[$r->code][$q->var_name] = $sum;
                }
            }
        }
    }

    /** Anggota rumah tangga rekaan: [no => [nama, jk 1/2, umur, hub]]. */
    private function people(int $n): array
    {
        $krtJk = mt_rand(0, 4) ? 1 : 2;
        $krtUmur = mt_rand(28, 68);
        $out = [1 => ['jk' => $krtJk, 'umur' => $krtUmur, 'hub' => 'kepala']];
        for ($i = 2; $i <= $n; $i++) {
            if ($i === 2 && $krtJk === 1) {
                $out[$i] = ['jk' => 2, 'umur' => max(18, $krtUmur - mt_rand(0, 8)), 'hub' => 'pasangan'];
            } elseif ($i === $n && $n >= 5 && $krtUmur > 50) {
                $out[$i] = ['jk' => mt_rand(1, 2), 'umur' => min(97, $krtUmur + mt_rand(18, 28)), 'hub' => 'orang tua'];
            } else {
                $out[$i] = ['jk' => mt_rand(1, 2), 'umur' => max(0, min($krtUmur - 18, mt_rand(0, 24))), 'hub' => 'anak'];
            }
        }
        foreach ($out as $no => &$p) {
            $pool = $p['jk'] === 1 ? self::NAMES_M : self::NAMES_F;
            $p['nama'] = $pool[mt_rand(0, count($pool) - 1)];
        }

        return $out;
    }

    private function money(int $lo, int $hi, int $step = 1000): int
    {
        return intdiv(mt_rand($lo, $hi), $step) * $step;
    }

    /** Nilai satu rincian. $p = anggota (untuk blok anggota), $no = nomor anggota. */
    private function value(Question $q, ?array $p, ?string $dateHint, int $no = 0): ?string
    {
        $label = mb_strtolower($q->label.' '.$q->var_name);
        $unit = mb_strtolower((string) $q->unit);

        if ($q->qtype === 'code') {
            $opts = $q->options->sortBy('order')->values();
            if ($opts->isEmpty()) {
                return '1';
            }
            $find = fn (string $re) => $opts->first(fn ($o) => preg_match($re, mb_strtolower($o->label)))?->code;
            if ($p && str_contains($label, 'hubungan')) {
                return match ($p['hub']) {
                    'kepala' => $find('/kepala/'), 'pasangan' => $find('/istri|suami/'),
                    'orang tua' => $find('/orang ?tua|mertua/'), default => $find('/^anak|anak kandung/'),
                } ?? (string) $opts[0]->code;
            }
            if ($p && str_contains($label, 'jenis kelamin')) {
                return $find($p['jk'] === 1 ? '/laki/' : '/perempuan/') ?? (string) $p['jk'];
            }
            if ($p && preg_match('/status (perkawinan|kawin)|perkawinan$/', $label)) {
                return ($p['umur'] < 17 ? $find('/belum/') : ($p['hub'] === 'orang tua' ? $find('/mati/') : $find('/^kawin|^\s*kawin/')))
                    ?? (string) $opts[0]->code;
            }

            return (string) $opts[mt_rand(0, $opts->count() - 1)]->code;
        }

        if ($q->qtype === 'text') {
            if (str_contains($label, 'nama')) {
                return $p['nama'] ?? (mt_rand(0, 1) ? self::NAMES_M : self::NAMES_F)[mt_rand(0, 14)];
            }
            if (str_contains($label, 'tanggal')) {
                $d = $dateHint ? \Illuminate\Support\Carbon::parse($dateHint) : now();
                return $d->copy()->addDays(str_contains($label, 'ii') ? 2 : 0)->format('d/m/Y');
            }
            if (preg_match('/uraian|pekerjaan|usaha/', $label)) {
                return self::JOBS[mt_rand(0, count(self::JOBS) - 1)];
            }

            return str_contains($label, 'catatan') ? 'Responden kooperatif, wawancara lancar.' : 'Contoh';
        }

        // angka
        if ($p && str_contains($label, 'umur') && ! str_contains($label, 'kawin')) {
            return (string) $p['umur'];
        }
        if ($p && str_contains($label, 'umur saat perkawinan')) {
            return $p['umur'] >= 17 ? (string) mt_rand(17, min(30, $p['umur'])) : null;
        }
        if ($unit === '%') {
            return (string) mt_rand(5, 30);
        }
        if ($unit === 'orang') {
            return (string) mt_rand(1, 6);
        }
        if ($unit === 'tahun') {
            return (string) mt_rand(20, 70);
        }
        if ($unit === 'jam') {
            return (string) (str_contains($label, 'selesai') ? mt_rand(16, 20) : mt_rand(8, 14));
        }
        if ($unit === 'satuan' || str_contains($label, 'kuantitas') || str_contains($label, 'banyaknya dari')) {
            return (string) (mt_rand(5, 60) / 10);
        }
        if ($unit === 'rp' || str_contains($label, '(rp') || str_contains($label, 'rupiah')) {
            return (string) match (true) {
                (bool) preg_match('/bangunan|rumah|lahan|aset|non-bangunan/', $label) => $this->money(15_000_000, 350_000_000, 1_000_000),
                (bool) preg_match('/saham|surat berharga|obligasi|penyertaan|modal/', $label) => mt_rand(0, 5) ? 0 : $this->money(1_000_000, 25_000_000, 500_000),
                (bool) preg_match('/simpanan|tabungan|piutang|pinjaman|kredit|gadai|arisan|uang tunai/', $label) => mt_rand(0, 2) ? $this->money(0, 8_000_000, 50_000) : 0,
                (bool) preg_match('/upah|gaji|produksi|pendapatan|diterima/', $label) => $this->money(600_000, 9_000_000, 50_000),
                (bool) preg_match('/seminggu/', $label) => $this->money(3_000, 90_000, 500),
                (bool) preg_match('/sebulan|bulan terakhir/', $label) => $this->money(20_000, 600_000, 1_000),
                (bool) preg_match('/setahun|tahun terakhir/', $label) => $this->money(100_000, 4_000_000, 5_000),
                default => $this->money(25_000, 1_500_000, 1_000),
            };
        }
        if (preg_match('/no\.? ?urut/', $label)) {
            return (string) ($no ?: 1);
        }
        if (str_contains($label, 'jumlah komoditas')) {
            return (string) mt_rand(8, 40);
        }

        return (string) ($q->qtype === 'decimal' ? mt_rand(1, 50) / 10 : mt_rand(1, 9));
    }
}

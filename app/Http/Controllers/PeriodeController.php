<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\QuestionnaireVersion;
use App\Models\RuleResult;
use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\User;
use App\Models\Wilayah;
use App\Services\CyclePrep;
use App\Services\QuestionnairePackage;
use App\Support\Tahap;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inc 154 — Periode Survei: tahun → triwulan → survei → hub.
 * Satu siklus = satu instrumen (mis. SUSENAS KP, SERUTI INTI); satu triwulan bisa berisi beberapa.
 * Hub tidak menduplikasi halaman yang sudah ada: tab Kuesioner/Aturan/Sampel/Pengolahan/Persiapan
 * membuka halaman lama yang sudah tersaring ke siklus itu (dengan kepala hub di atasnya,
 * lihat periode/_hub_header). Yang baru di sini: Ringkasan & Peserta.
 */
class PeriodeController extends Controller
{
    // ------------------------------------------------------------ data bersama

    /** Hitungan per siklus dalam cakupan wilayah pengguna. */
    private function stats(Collection $cycles, User $user): array
    {
        $ids = $cycles->pluck('id');
        $sq = SampleHousehold::whereIn('cycle_id', $ids);
        if ($user->wilayah_scope) {
            $sq->where('wilayah_code', 'like', $user->wilayah_scope.'%');
        }
        $samples = $sq->get(['id', 'cycle_id', 'status', 'petugas_id', 'pml_id', 'wilayah_code']);
        $docs = Document::whereIn('sample_id', $samples->pluck('id'))->get(['id', 'sample_id', 'status'])->keyBy('sample_id');
        $errDocs = $docs->isEmpty() ? collect() : RuleResult::whereIn('document_id', $docs->pluck('id'))
            ->where('status', 'error')->distinct()->pluck('document_id')->flip();

        $out = [];
        foreach ($cycles as $c) {
            $out[$c->id] = ['total' => 0, 'terisi' => 0, 'diperiksa' => 0, 'kesalahan' => 0, 'selesai' => 0,
                'tahap' => array_fill_keys(array_keys(Tahap::LABELS), 0)];
        }
        foreach ($samples as $s) {
            $st = &$out[$s->cycle_id];
            $doc = $docs[$s->id] ?? null;
            $st['tahap'][Tahap::of($s, $doc)]++;
            if ($s->status === 'ganti_sampel') {
                continue;   // sudah digantikan: tidak menempati kuota
            }
            $st['total']++;
            if ($doc) {
                $st['terisi']++;
                if (isset($errDocs[$doc->id])) {
                    $st['kesalahan']++;
                }
            }
            if ($s->status === 'selesai') {
                $st['selesai']++;
            }
            unset($st);
        }
        foreach ($out as &$st) {
            $st['diperiksa'] = $st['tahap']['diperiksa'];
        }

        return $out;
    }

    /** Status gabungan beberapa siklus (warna kartu tahun/triwulan): yang paling "hidup" menang. */
    public static function combinedStatus(Collection $cycles): string
    {
        foreach (['lapangan', 'pengolahan', 'persiapan'] as $s) {
            if ($cycles->contains('status', $s)) {
                return $s;
            }
        }

        return $cycles->isEmpty() ? 'kosong' : 'selesai';
    }

    private function sumStats(array $stats, Collection $cycles): array
    {
        $sum = ['total' => 0, 'terisi' => 0, 'diperiksa' => 0, 'kesalahan' => 0];
        foreach ($cycles as $c) {
            foreach ($sum as $k => $_) {
                $sum[$k] += $stats[$c->id][$k] ?? 0;
            }
        }

        return $sum;
    }

    /**
     * Pasangan survei+instrumen yang biasanya berjalan di triwulan ini: dari triwulan yang
     * sama tahun lalu; kalau tidak ada, dari triwulan sebelumnya. Untuk kartu kosong & form Siapkan.
     */
    private function expectedPairs(int $year, int $q): Collection
    {
        $pairs = fn ($cycles) => $cycles->map(fn ($c) => $c->survey.'|'.$c->instrument)->unique()->values();
        $last = $pairs(SurveyCycle::inQuarter($year - 1, $q)->get());
        if ($last->isNotEmpty()) {
            return $last;
        }
        [$py, $pq] = $q === 1 ? [$year - 1, 4] : [$year, $q - 1];

        return $pairs(SurveyCycle::inQuarter($py, $pq)->get());
    }

    // ------------------------------------------------------------ tingkat 1-3

    public function index(Request $request)
    {
        $cycles = SurveyCycle::orderBy('period')->get();
        $stats = $this->stats($cycles, $request->user());
        $years = $cycles->groupBy(fn ($c) => $c->year() ?? 0)->sortKeysDesc()->map(function ($cs, $year) use ($stats) {
            return [
                'year' => $year,
                'cycles' => $cs,
                'quarters' => $cs->groupBy(fn ($c) => $c->quarter())->sortKeys(),
                'surveys' => $cs->pluck('survey')->unique()->values(),
                'status' => self::combinedStatus($cs),
                'sum' => $this->sumStats($stats, $cs),
            ];
        });

        return view('periode.index', ['years' => $years, 'nextYear' => ($years->keys()->max() ?: now()->year) + 1]);
    }

    public function year(Request $request, int $tahun)
    {
        $cycles = SurveyCycle::where('period', 'like', $tahun.'-%')->orderBy('period')->get();
        $stats = $this->stats($cycles, $request->user());
        $quarters = collect(range(1, 4))->mapWithKeys(function ($q) use ($cycles, $stats, $tahun) {
            $cs = $cycles->filter(fn ($c) => $c->quarter() === $q)->values();

            return [$q => [
                'cycles' => $cs, 'status' => self::combinedStatus($cs), 'sum' => $this->sumStats($stats, $cs),
                'expected' => $cs->isEmpty() ? $this->expectedPairs($tahun, $q) : collect(),
            ]];
        });

        return view('periode.tahun', ['tahun' => $tahun, 'quarters' => $quarters]);
    }

    public function quarter(Request $request, int $tahun, int $tw)
    {
        abort_unless($tw >= 1 && $tw <= 4, 404);
        $cycles = SurveyCycle::inQuarter($tahun, $tw)->orderBy('survey')->orderBy('instrument')->get();
        $user = $request->user();
        $stats = $this->stats($cycles, $user);

        // beban petugas gabungan semua survei di triwulan ini
        $sq = SampleHousehold::whereIn('cycle_id', $cycles->pluck('id'))->where('status', '!=', 'ganti_sampel')
            ->whereNotNull('petugas_id');
        if ($user->wilayah_scope) {
            $sq->where('wilayah_code', 'like', $user->wilayah_scope.'%');
        }
        $survByCycle = $cycles->pluck('survey', 'id');
        $names = User::pluck('name', 'id');
        $beban = $sq->get(['petugas_id', 'cycle_id', 'status'])->groupBy('petugas_id')
            ->map(function ($rows, $pid) use ($survByCycle, $names) {
                return [
                    'name' => $names[$pid] ?? '—',
                    'per' => $rows->groupBy(fn ($s) => $survByCycle[$s->cycle_id])->map->count(),
                    'total' => $rows->count(),
                    'selesai' => $rows->where('status', 'selesai')->count(),
                ];
            })->sortByDesc('total')->values();

        return view('periode.triwulan', [
            'tahun' => $tahun, 'tw' => $tw, 'cycles' => $cycles, 'stats' => $stats, 'beban' => $beban,
            'surveys' => $cycles->pluck('survey')->unique()->values(),
            'candidates' => $cycles->isEmpty() || $user->role === 'admin' ? $this->candidates($tahun, $tw, $cycles) : collect(),
        ]);
    }

    // ------------------------------------------------------------ hub

    /** Data kepala hub (dipakai juga oleh halaman lama yang tampil sebagai tab hub). */
    public static function hubContext(SurveyCycle $cycle): array
    {
        return [
            'hubCycle' => $cycle,
            'hubSiblings' => SurveyCycle::inQuarter((int) $cycle->year(), (int) $cycle->quarter())
                ->orderBy('survey')->orderBy('instrument')->get(),
            'hubVersion' => CyclePrep::versionFor($cycle),
        ];
    }

    /**
     * Tab hub sesuai peran: [kunci => [label, url]]. Kuesioner/Aturan/Persiapan hanya admin
     * (halamannya memang admin). Tab "Persiapan & Penutupan" membuka daftar periksa saat
     * persiapan/lapangan, dan laporan penutupan saat pengolahan/selesai.
     */
    public static function hubTabs(SurveyCycle $cycle, ?QuestionnaireVersion $qv, ?User $user = null): array
    {
        $role = ($user ?? auth()->user())?->role;
        $tabs = ['ringkasan' => ['Ringkasan', route('periode.hub', $cycle)]];
        if ($role === 'admin' && $qv) {
            $tabs['kuesioner'] = ['Kuesioner', route('admin.metadata', ['qv' => $qv->id])];
            $tabs['aturan'] = ['Aturan Validasi', route('admin.aturan', ['qv' => $qv->id])];
        }
        $tabs['peserta'] = ['Peserta', route('periode.peserta', $cycle)];
        $tabs['sampel'] = ['Sampel', route('monitoring.samples', ['cycle' => $cycle->id])];
        $tabs['pengolahan'] = ['Pengolahan', route('pengolahan.index', ['cycle' => $cycle->id])];
        if ($role === 'admin') {
            $tabs['persiapan'] = ['Persiapan & Penutupan', in_array($cycle->status, ['persiapan', 'lapangan'], true)
                ? route('admin.siklus.persiapan', $cycle) : route('admin.siklus', $cycle)];
        }

        return $tabs;
    }

    public function hub(Request $request, SurveyCycle $cycle)
    {
        $user = $request->user();
        $st = $this->stats(collect([$cycle]), $user)[$cycle->id];
        $qv = CyclePrep::versionFor($cycle);
        $docIds = Document::whereIn('sample_id', $this->scopedSamples($cycle, $user)->select('id'))->pluck('id');
        $topRules = $docIds->isEmpty() ? collect() : RuleResult::query()
            ->whereIn('rule_results.document_id', $docIds)->whereIn('rule_results.status', ['error', 'warning'])
            ->join('validation_rules as vr', 'vr.id', '=', 'rule_results.rule_id')
            ->selectRaw('vr.code, vr.severity, vr.message, count(distinct rule_results.document_id) as n_doc')
            ->groupBy('vr.code', 'vr.severity', 'vr.message')
            ->orderByRaw('vr.severity = "error" desc, n_doc desc')->limit(5)->get();

        return view('periode.hub', self::hubContext($cycle) + [
            'hubTab' => 'ringkasan', 'st' => $st, 'qv' => $qv, 'topRules' => $topRules,
            'next' => $this->nextStep($cycle, $st, $user),
            'rate' => $st['total'] ? round(100 * $st['selesai'] / $st['total'], 1) : 0.0,
        ]);
    }

    public function peserta(Request $request, SurveyCycle $cycle)
    {
        $user = $request->user();
        $samples = $this->scopedSamples($cycle, $user)->where('status', '!=', 'ganti_sampel')
            ->get(['id', 'status', 'petugas_id', 'pml_id', 'wilayah_code']);
        $returned = Document::whereIn('sample_id', $samples->pluck('id'))->where('status', 'dikembalikan')
            ->pluck('sample_id')->flip();
        $users = User::whereIn('id', $samples->pluck('petugas_id')->merge($samples->pluck('pml_id'))->filter()->unique())
            ->get()->keyBy('id');
        $kec = Wilayah::where('level', 'kecamatan')->pluck('name', 'code');
        $row = function ($u, string $peran, Collection $rows) use ($returned, $kec) {
            return [
                'user' => $u, 'peran' => $peran, 'total' => $rows->count(),
                'selesai' => $rows->where('status', 'selesai')->count(),
                'dikembalikan' => $rows->filter(fn ($s) => isset($returned[$s->id]))->count(),
                'wilayah' => $rows->map(fn ($s) => $kec[substr($s->wilayah_code, 0, 7)] ?? substr($s->wilayah_code, 0, 7))
                    ->unique()->sort()->implode(', '),
            ];
        };
        $peserta = collect();
        foreach ($samples->whereNotNull('petugas_id')->groupBy('petugas_id') as $id => $rows) {
            $peserta->push($row($users[$id] ?? null, 'Pencacah Lapangan', $rows));
        }
        foreach ($samples->whereNotNull('pml_id')->groupBy('pml_id') as $id => $rows) {
            $peserta->push($row($users[$id] ?? null, 'Pengawas Lapangan', $rows));
        }
        // Penanggung Jawab: kecamatan cakupannya punya sampel di siklus ini
        $kecCodes = $samples->map(fn ($s) => substr($s->wilayah_code, 0, 7))->unique();
        foreach (User::where('role', 'pj')->where('active', true)->whereIn('wilayah_scope', $kecCodes)->get() as $pj) {
            $peserta->push($row($pj, 'Penanggung Jawab',
                $samples->filter(fn ($s) => str_starts_with($s->wilayah_code, $pj->wilayah_scope))));
        }

        return view('periode.peserta', self::hubContext($cycle) + [
            'hubTab' => 'peserta', 'peserta' => $peserta,
            'belumDitugaskan' => $samples->whereNull('petugas_id')->count(),
        ]);
    }

    private function scopedSamples(SurveyCycle $cycle, User $user)
    {
        $q = $cycle->samples();
        if ($user->wilayah_scope) {
            $q->where('wilayah_code', 'like', $user->wilayah_scope.'%');
        }

        return $q;
    }

    /** Satu langkah yang paling perlu dikerjakan sekarang, mengikuti status siklus. */
    private function nextStep(SurveyCycle $cycle, array $st, User $user): array
    {
        $admin = $user->role === 'admin';
        switch ($cycle->status) {
            case 'persiapan':
                $prep = app(CyclePrep::class)->check($cycle);
                $wajib = collect($prep['items'])->where('wajib', true);
                $ok = $wajib->where('ok', true)->count();

                return ['text' => $prep['siap'] ? 'Persiapan beres. Pencacahan siap dibuka.'
                        : "Persiapan: {$ok} dari {$wajib->count()} butir wajib beres.",
                    'sub' => 'Selama persiapan, petugas belum melihat sampel triwulan ini.',
                    'url' => $admin ? route('admin.siklus.persiapan', $cycle) : null, 'btn' => 'Buka daftar periksa'];
            case 'lapangan':
                $belum = $st['tahap']['belum'];
                $sisa = $cycle->field_end ? max(0, (int) now()->startOfDay()->diffInDays($cycle->field_end, false)) : null;

                return ['text' => $belum ? "{$belum} sampel belum dikunjungi".($sisa !== null ? ", {$sisa} hari pencacahan tersisa." : '.')
                        : 'Semua sampel sudah dikunjungi.',
                    'sub' => 'Pantau siapa yang tertinggal di tab Peserta.',
                    'url' => route('periode.peserta', $cycle), 'btn' => 'Lihat peserta'];
            case 'pengolahan':
                if ($st['diperiksa'] > 0) {
                    return ['text' => "Periksa {$st['diperiksa']} sampel yang sudah dikirim petugas.",
                        'sub' => 'Sampel yang lolos ditandai bersih; yang bermasalah dikembalikan ke petugas.',
                        'url' => route('pengolahan.index', ['cycle' => $cycle->id, 'status' => 'selesai']), 'btn' => 'Buka antrean'];
                }

                return ['text' => 'Antrean pemeriksaan kosong. Cek kesiapan penutupan.',
                    'sub' => $st['kesalahan'] ? "{$st['kesalahan']} sampel masih ada kesalahan." : 'Bila semua bersih, triwulan bisa dikunci.',
                    'url' => $admin ? route('admin.siklus', $cycle) : null, 'btn' => 'Buka penutupan'];
            default:
                return ['text' => 'Triwulan ini sudah dikunci. Data siap ditabulasi.', 'sub' => 'Kuesioner & aturan triwulan ini hanya bisa dilihat.',
                    'url' => route('tabulasi.index'), 'btn' => 'Buka tabulasi'];
        }
    }

    // ------------------------------------------------------------ siapkan triwulan

    /**
     * Pilihan survei untuk "Siapkan triwulan ini": setiap pasangan survei+instrumen yang pernah ada
     * sebelum triwulan ini (dan belum ada di triwulan ini), beserta SEMUA versi kuesionernya sebagai
     * calon sumber salinan (Inc 156) — dengan jumlah blok/baris/aturan supaya admin bisa memilih,
     * mis. VSEN26.KP lengkap (194 komoditas) alih-alih versi contoh yang hanya subset.
     * Bawaan: versi terbaru yang tidak kosong.
     */
    private function candidates(int $year, int $q, Collection $existing): Collection
    {
        $target = sprintf('%04d-%02d', $year, $q * 3 - 2);
        $have = $existing->map(fn ($c) => $c->survey.'|'.$c->instrument)->flip();
        $expected = $this->expectedPairs($year, $q)->flip();

        return QuestionnaireVersion::with('cycle')->get()
            ->filter(fn ($v) => $v->cycle && $v->cycle->period < $target)
            ->groupBy(fn ($v) => $v->cycle->survey.'|'.$v->cycle->instrument)
            ->reject(fn ($vs, $key) => isset($have[$key]))
            ->map(function ($vs, $key) use ($expected) {
                $opts = $vs->sortByDesc(fn ($v) => $v->cycle->period.sprintf('%08d', $v->id))->values()
                    ->map(function ($v) {
                        $blockIds = \App\Models\Block::where('qversion_id', $v->id)->pluck('id');

                        return ['version' => $v, 'blocks' => $blockIds->count(),
                            'rows' => \App\Models\BlockRow::whereIn('block_id', $blockIds)->count(),
                            'rules' => \App\Models\ValidationRule::where('qversion_id', $v->id)->count()];
                    });
                $default = $opts->first(fn ($o) => $o['blocks'] > 0) ?? $opts->first();

                return ['key' => $key, 'label' => $vs->first()->cycle->label(), 'options' => $opts,
                    'default' => $default['version']->id, 'checked' => isset($expected[$key])];
            })
            ->sortBy('key')->values();
    }

    /** Kode versi baru: tahun di kode diganti, akhiran -TWn diganti triwulan tujuan. */
    public static function suggestCode(string $src, int $year, int $q): string
    {
        $base = preg_replace('/-TW\d$/', '', $src);
        $base = preg_replace('/(VSEN|VSERUTI)\d{2}/', '${1}'.substr((string) $year, 2), $base);
        $code = $q === 1 ? $base : "{$base}-TW{$q}";
        $try = $code;
        for ($i = 2; QuestionnaireVersion::where('code', $try)->exists(); $i++) {
            $try = "{$code}-{$i}";
        }

        return $try;
    }

    public function prepare(Request $request, int $tahun, int $tw)
    {
        abort_unless($tw >= 1 && $tw <= 4, 404);
        $d = $request->validate([
            'pick' => 'required|array|min:1', 'pick.*' => 'string',
            'src' => 'required|array', 'src.*' => 'integer|exists:questionnaire_versions,id',
            'month' => 'required|integer|min:'.($tw * 3 - 2).'|max:'.($tw * 3),
            'field_start' => 'nullable|date', 'field_end' => 'nullable|date|after_or_equal:field_start',
        ]);
        $pkg = app(QuestionnairePackage::class);
        $made = [];
        DB::transaction(function () use ($d, $tahun, $tw, $pkg, &$made) {
            foreach ($d['pick'] as $key) {
                $qv = QuestionnaireVersion::with('cycle')->find($d['src'][$key] ?? 0);
                abort_unless($qv && $qv->cycle && $qv->cycle->survey.'|'.$qv->cycle->instrument === $key, 422,
                    "Versi sumber untuk {$key} tidak cocok.");
                $src = $qv->cycle;
                $bulan = SurveyCycle::MONTHS[(int) $d['month']];
                $cycle = SurveyCycle::create([
                    'name' => trim("{$src->survey} {$bulan} {$tahun}".($src->instrument ? " ({$src->instrument})" : '')),
                    'period' => sprintf('%04d-%02d', $tahun, $d['month']), 'status' => 'persiapan',
                    'survey' => $src->survey, 'instrument' => $src->instrument,
                    'field_start' => ($d['field_start'] ?? null) ?: null, 'field_end' => ($d['field_end'] ?? null) ?: null,
                ]);
                // salin lewat paket metadata (sama dengan Ekspor/Impor JSON): blok, rincian, opsi,
                // baris tetap + patokan harga, parameter, agregat, turunan, aturan
                $new = $pkg->import($pkg->export($qv), $cycle->id, self::suggestCode($qv->code, $tahun, $tw));
                AuditLog::record('cycle', $cycle->id, 'siapkan-triwulan', "{$cycle->name} dari {$qv->code} -> {$new->code}");
                $made[] = $cycle->label()." (dari {$qv->code})";
            }
        });

        return redirect()->route('periode.triwulan', [$tahun, $tw])
            ->with('ok', 'Disiapkan: '.implode(', ', $made).'. Lanjutkan dengan memuat sampel lalu buka daftar periksa Persiapan.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentSnapshot;
use App\Models\RuleResult;
use App\Models\User;
use App\Models\ValidationRule;
use App\Models\Wilayah;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TabulationController extends Controller
{
    private const GROUPS = [
        '' => 'Tanpa pengelompokan',
        'kecamatan' => 'Per kecamatan',
        'desa' => 'Per desa',
        'petugas' => 'Per petugas',
        'status' => 'Per status sampel',
    ];
    private const AGGS = [
        'count' => 'Jumlah dokumen',
        'mean' => 'Rata-rata',
        'sum' => 'Total',
        'min' => 'Minimum',
        'max' => 'Maksimum',
        'dist' => 'Distribusi nilai (kode)',
        'wcount' => 'Estimasi populasi (Σ penimbang)',
        'wmean' => 'Rata-rata tertimbang',
        'wsum' => 'Total tertimbang',
    ];

    /** Dokumen dalam cakupan pengguna + snapshot + metadata sampel. */
    private function scopedRows(Request $request, bool $onlySelesai): array
    {
        $q = Document::query()
            ->join('sample_households as s', 's.id', '=', 'documents.sample_id')
            ->join('document_snapshots as snap', 'snap.document_id', '=', 'documents.id')
            ->select('documents.id', 'documents.status as doc_status', 's.wilayah_code',
                's.no_bs', 's.no_ruta', 's.krt_name', 's.petugas_id', 's.status as sample_status',
                's.weight', 'snap.data as snap');
        $user = $request->user();
        if ($user->wilayah_scope) {
            $q->where('s.wilayah_code', 'like', $user->wilayah_scope.'%');
        }
        if ($onlySelesai) {
            $q->whereIn('documents.status', ['selesai', 'bersih']);
        }
        return $q->get()->map(function ($r) {
            $r->snap = json_decode($r->snap, true) ?: [];
            return $r;
        })->all();
    }

    private function groupKey($row, string $group, array $wil, array $users): string
    {
        return match ($group) {
            'kecamatan' => $wil[substr($row->wilayah_code, 0, 6)] ?? substr($row->wilayah_code, 0, 6),
            'desa' => $wil[$row->wilayah_code] ?? $row->wilayah_code,
            'petugas' => $users[$row->petugas_id] ?? '—',
            'status' => $row->sample_status,
            default => 'Semua',
        };
    }

    /** Satu nilai agregat dari daftar item [{v,w}]. Untuk 'dist' pakai distValues(). */
    private function aggValue(array $items, string $agg): mixed
    {
        $vals = array_column($items, 'v');
        $pairs = array_values(array_filter(array_map(
            fn ($it) => is_numeric($it['v']) ? ['x' => (float) $it['v'], 'w' => (float) ($it['w'] ?? 0)] : null,
            $items
        ), fn ($p) => $p !== null));
        $nums = array_column($pairs, 'x');
        $sumW = array_sum(array_column($pairs, 'w'));
        $sumWX = array_sum(array_map(fn ($p) => $p['x'] * $p['w'], $pairs));

        return match ($agg) {
            'count' => count($vals),
            'sum' => array_sum($nums),
            'min' => $nums ? min($nums) : null,
            'max' => $nums ? max($nums) : null,
            'mean' => $nums ? round(array_sum($nums) / count($nums), 2) : null,
            'wcount' => round($sumW, 1),
            'wsum' => round($sumWX, 1),
            'wmean' => $sumW > 0 ? round($sumWX / $sumW, 2) : null,
            default => null,
        };
    }

    public function index(Request $request)
    {
        $onlySelesai = $request->boolean('selesai', true);
        $rows = $this->scopedRows($request, $onlySelesai);

        $vars = [];
        foreach ($rows as $r) {
            foreach (array_keys($r->snap) as $k) {
                $vars[$k] = true;
            }
        }
        ksort($vars);
        $vars = array_keys($vars);

        $var = $request->query('var', 'pengeluaran_per_kapita');
        $group = $request->query('group', 'kecamatan');
        $group2 = $request->query('group2', '');
        $agg = $request->query('agg', 'mean');
        if (!in_array($group, array_keys(self::GROUPS), true)) {
            $group = '';
        }
        if (!in_array($group2, array_keys(self::GROUPS), true) || $group2 === $group) {
            $group2 = '';
        }
        if (!array_key_exists($agg, self::AGGS)) {
            $agg = 'mean';
        }

        $wil = Wilayah::pluck('name', 'code')->all();
        $users = User::pluck('name', 'id')->all();

        $weighted = in_array($agg, ['wcount', 'wmean', 'wsum'], true);
        $missingWeight = 0;

        // kumpulkan item per sel (1-dim: $group; 2-dim: $group × $group2)
        $cells = [];   // "k1\tk2" => items
        $k2set = [];
        foreach ($rows as $r) {
            if (!array_key_exists($var, $r->snap)) {
                continue;
            }
            if ($weighted && ($r->weight === null || (float) $r->weight <= 0)) {
                $missingWeight++;
                continue;
            }
            $k1 = $this->groupKey($r, $group, $wil, $users);
            $k2 = $group2 ? $this->groupKey($r, $group2, $wil, $users) : '';
            $cells["{$k1}\t{$k2}"][] = ['v' => $r->snap[$var], 'w' => $r->weight];
            if ($group2) {
                $k2set[$k2] = true;
            }
        }
        ksort($k2set);
        $k2list = array_keys($k2set);

        // ---- hasil 1-dim (untuk tabel biasa & ekspor & mode dist) ----
        $oneDim = [];
        $agg1 = collect($cells)->groupBy(fn ($v, $key) => explode("\t", $key)[0])
            ->map(fn ($grp) => $grp->flatten(1)->all())->all();
        foreach ($agg1 as $key => $items) {
            $vals = array_column($items, 'v');
            if ($agg === 'dist') {
                $dist = [];
                foreach ($vals as $v) {
                    $dist[(string) $v] = ($dist[(string) $v] ?? 0) + 1;
                }
                ksort($dist);
                $oneDim[] = ['key' => $key, 'n' => count($vals), 'dist' => $dist];
            } else {
                $oneDim[] = ['key' => $key, 'n' => count($vals), 'val' => $this->aggValue($items, $agg)];
            }
        }
        usort($oneDim, fn ($a, $b) => strcmp($a['key'], $b['key']));

        // ---- matriks 2-dim (bila group2 dipilih & bukan mode dist) ----
        $matrix = null;
        if ($group2 && $agg !== 'dist') {
            $rowKeys = collect(array_keys($agg1))->sort()->values()->all();
            $matrix = [];
            foreach ($rowKeys as $rk) {
                $line = ['key' => $rk, 'cells' => []];
                foreach ($k2list as $ck) {
                    $items = $cells["{$rk}\t{$ck}"] ?? [];
                    $line['cells'][] = ['n' => count($items), 'val' => $items ? $this->aggValue($items, $agg) : null];
                }
                $matrix[] = $line;
            }
        }

        if ($request->boolean('export')) {
            return $this->exportTabulation($oneDim, $matrix, $k2list, $var, $group, $group2, $agg);
        }

        return view('tabulasi.index', [
            'vars' => $vars, 'groups' => self::GROUPS, 'aggs' => self::AGGS,
            'var' => $var, 'group' => $group, 'group2' => $group2, 'agg' => $agg, 'onlySelesai' => $onlySelesai,
            'result' => $oneDim, 'matrix' => $matrix, 'colKeys' => $k2list, 'totalDocs' => count($rows),
            'weighted' => $weighted, 'missingWeight' => $missingWeight,
        ]);
    }

    private function exportTabulation(array $oneDim, ?array $matrix, array $colKeys,
        string $var, string $group, string $group2, string $agg): StreamedResponse
    {
        $g = fn ($k) => self::GROUPS[$k] ?? 'Semua';
        $name = 'tabulasi_'.$var.'_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($oneDim, $matrix, $colKeys, $var, $group, $group2, $agg, $g) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ["# variabel: {$var}", "agregasi: ".(self::AGGS[$agg] ?? $agg)]);
            if ($matrix) {
                fputcsv($out, array_merge([$g($group).' \\ '.$g($group2)], $colKeys));
                foreach ($matrix as $line) {
                    fputcsv($out, array_merge([$line['key']],
                        array_map(fn ($c) => $c['val'] ?? '', $line['cells'])));
                }
            } else {
                fputcsv($out, [$g($group), 'n', self::AGGS[$agg] ?? $agg]);
                foreach ($oneDim as $r) {
                    fputcsv($out, [$r['key'], $r['n'],
                        isset($r['dist']) ? json_encode($r['dist']) : ($r['val'] ?? '')]);
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /** Indikator kesejahteraan ringkas (internal QC — bukan estimasi resmi). */
    public function indicators(Request $request)
    {
        $rows = $this->scopedRows($request, $request->boolean('selesai', true));

        // pilih variabel pengeluaran per kapita
        $prefer = ['pengeluaran_per_kapita_bulan', 'pengeluaran_per_kapita'];
        $numVars = [];
        foreach ($rows as $r) {
            foreach ($r->snap as $k => $v) {
                if (is_numeric($v)) {
                    $numVars[$k] = true;
                }
            }
        }
        ksort($numVars);
        $numVars = array_keys($numVars);
        $var = $request->query('var');
        if (! in_array($var, $numVars, true)) {
            $var = collect($prefer)->first(fn ($p) => in_array($p, $numVars, true)) ?? ($numVars[0] ?? null);
        }
        $foodVar = 'rekap_makanan_sebulan';
        $rutaVar = 'rekap_ruta_sebulan';
        $gk = (float) ($request->query('gk')
            ?: (collect($rows)->map(fn ($r) => $r->snap['garis_kemiskinan_kab'] ?? null)->first(fn ($v) => is_numeric($v)) ?? 550000));

        $wil = Wilayah::pluck('name', 'code')->all();
        $classes = [[0, 500_000], [500_000, 1_000_000], [1_000_000, 2_000_000], [2_000_000, PHP_INT_MAX]];

        $bucket = [];
        foreach ($rows as $r) {
            $x = $r->snap[$var] ?? null;
            if (! is_numeric($x) || $x <= 0) {
                continue;
            }
            $key = $wil[substr($r->wilayah_code, 0, 6)] ?? substr($r->wilayah_code, 0, 6);
            foreach (['__TOTAL__' => 'Semua wilayah', $key => $key] as $bk => $bl) {
                $bucket[$bk]['label'] = $bl;
                $bucket[$bk]['x'][] = (float) $x;
                $bucket[$bk]['w'][] = ($r->weight && $r->weight > 0) ? (float) $r->weight : null;
                $fs = (is_numeric($r->snap[$foodVar] ?? null) && is_numeric($r->snap[$rutaVar] ?? null) && $r->snap[$rutaVar] > 0)
                    ? $r->snap[$foodVar] / $r->snap[$rutaVar] : null;
                $bucket[$bk]['fs'][] = $fs;
            }
        }

        $result = [];
        foreach ($bucket as $bk => $b) {
            $xs = $b['x'];
            sort($xs);
            $n = count($xs);
            $poorU = count(array_filter($b['x'], fn ($v) => $v < $gk));
            $wPairs = [];
            foreach ($b['x'] as $i => $v) {
                if ($b['w'][$i] !== null) {
                    $wPairs[] = ['x' => $v, 'w' => $b['w'][$i]];
                }
            }
            $sumW = array_sum(array_column($wPairs, 'w'));
            $poorW = array_sum(array_map(fn ($p) => $p['x'] < $gk ? $p['w'] : 0, $wPairs));
            $fsVals = array_values(array_filter($b['fs'], fn ($v) => $v !== null));
            $dist = array_fill(0, count($classes), 0);
            foreach ($b['x'] as $v) {
                foreach ($classes as $ci => [$lo, $hi]) {
                    if ($v >= $lo && $v < $hi) {
                        $dist[$ci]++;
                        break;
                    }
                }
            }
            $result[$bk === '__TOTAL__' ? '0' : '1_'.$bk] = [
                'label' => $b['label'], 'n' => $n,
                'p0_u' => $n ? round(100 * $poorU / $n, 1) : null,
                'p0_w' => $sumW > 0 ? round(100 * $poorW / $sumW, 1) : null,
                'mean' => $n ? round(array_sum($xs) / $n) : null,
                'median' => $n ? round($xs[intdiv($n, 2)]) : null,
                'food_share' => $fsVals ? round(100 * array_sum($fsVals) / count($fsVals), 1) : null,
                'pop_est' => $sumW > 0 ? round($sumW) : null,
                'dist' => $dist,
            ];
        }
        ksort($result);

        // histogram sebaran pengeluaran per kapita (semua wilayah): geometri SVG
        // dihitung di sini supaya blade hanya merender (tanpa @php blok).
        $hist = null;
        $allX = $bucket['__TOTAL__']['x'] ?? [];
        if (count($allX) >= 5) {
            sort($allX);
            $nb = 12;
            $p95 = $allX[(int) floor(0.95 * (count($allX) - 1))];
            $top = max($gk * 1.5, ceil($p95 / 250_000) * 250_000);
            $binW = $top / $nb;
            $counts = array_fill(0, $nb, 0);
            $over = 0;
            foreach ($allX as $v) {
                $idx = (int) floor($v / $binW);
                $idx >= $nb ? $over++ : $counts[$idx]++;
            }
            $maxC = max(max($counts), 1);

            $vw = 760;
            $vh = 240;
            $ml = 8;
            $mt = 16;
            $ph = $vh - $mt - 34;
            $pw = $vw - $ml - 8;
            $gap = 2;
            $bw = $pw / $nb - $gap;
            $fmt = fn ($n) => number_format($n, 0, ',', '.');
            $short = fn ($n) => $n >= 1_000_000 ? round($n / 1_000_000, 1).'jt' : round($n / 1000).'rb';

            $bars = [];
            foreach ($counts as $i => $c) {
                $h = $c > 0 ? max(3, round($c / $maxC * $ph, 1)) : 0;
                $x = round($ml + $i * ($bw + $gap), 1);
                $bars[] = [
                    'x' => $x, 'y' => round($mt + $ph - $h, 1), 'w' => round($bw, 1), 'h' => $h,
                    'cx' => round($x + $bw / 2, 1), 'count' => $c,
                    'range' => 'Rp '.$fmt($i * $binW).'–'.$fmt(($i + 1) * $binW),
                ];
            }
            $ticks = [];
            for ($i = 0; $i <= $nb; $i += 3) {
                $ticks[] = ['x' => round(min($ml + $i * ($bw + $gap), $ml + $pw), 1), 'label' => $short($i * $binW)];
            }
            $hist = [
                'vw' => $vw, 'vh' => $vh, 'baseline_y' => $mt + $ph, 'baseline_x2' => $ml + $pw, 'ml' => $ml, 'mt' => $mt,
                'bars' => $bars, 'ticks' => $ticks, 'over' => $over,
                'gk_x' => round($ml + min(1.0, $gk / $top) * $pw, 1),
                'gk_label' => 'GK Rp '.$fmt($gk),
                'over_x' => $ml + $pw, 'over_y' => $mt + $ph + 30, 'tick_y' => $mt + $ph + 16,
                'gk_y1' => $mt - 4, 'gk_y2' => $mt + $ph, 'gk_text_y' => $mt + 6,
            ];
        }

        return view('tabulasi.indikator', [
            'result' => array_values($result), 'var' => $var, 'numVars' => $numVars,
            'gk' => $gk, 'total' => count($rows), 'hist' => $hist,
            'classLabels' => ['< 500rb', '500rb–1jt', '1jt–2jt', '≥ 2jt'],
        ]);
    }

    public function exportRaw(Request $request): StreamedResponse
    {
        $rows = $this->scopedRows($request, $request->boolean('selesai', false));
        $wil = Wilayah::pluck('name', 'code')->all();
        $users = User::pluck('name', 'id')->all();

        $varCols = [];
        foreach ($rows as $r) {
            foreach (array_keys($r->snap) as $k) {
                $varCols[$k] = true;
            }
        }
        ksort($varCols);
        $varCols = array_keys($varCols);

        // batasi ke kolom yang dipilih (bila ada) — jaga urutannya konsisten
        $pick = collect((array) $request->query('cols'))->filter(fn ($c) => is_string($c) && $c !== '')->all();
        if ($pick) {
            $set = array_flip($pick);
            $varCols = array_values(array_filter($varCols, fn ($c) => isset($set[$c])));
        }
        $meta = ['doc_id', 'wilayah_code', 'kecamatan', 'desa', 'no_bs', 'no_ruta',
            'krt_name', 'petugas', 'status_sampel', 'status_dokumen', 'penimbang'];

        return response()->streamDownload(function () use ($rows, $varCols, $meta, $wil, $users) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge($meta, $varCols));
            foreach ($rows as $r) {
                $line = [
                    $r->id, $r->wilayah_code,
                    $wil[substr($r->wilayah_code, 0, 6)] ?? '',
                    $wil[$r->wilayah_code] ?? '',
                    $r->no_bs, $r->no_ruta, $r->krt_name,
                    $users[$r->petugas_id] ?? '', $r->sample_status, $r->doc_status,
                    $r->weight ?? '',
                ];
                foreach ($varCols as $c) {
                    $line[] = $r->snap[$c] ?? '';
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, 'susenas_data_datar_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function exportRules(Request $request): StreamedResponse
    {
        $rows = $this->scopedRows($request, false);
        $docIds = array_column($rows, 'id');
        $rowMeta = [];
        foreach ($rows as $r) {
            $rowMeta[$r->id] = $r;
        }
        $ruleCodes = ValidationRule::pluck('code', 'id')->all();

        $results = RuleResult::whereIn('document_id', $docIds)
            ->whereIn('status', ['error', 'warning'])
            ->orderBy('document_id')->orderBy('row_index')->get();

        return response()->streamDownload(function () use ($results, $rowMeta, $ruleCodes) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['doc_id', 'wilayah_code', 'no_bs', 'no_ruta', 'kode_aturan',
                'severity', 'target_var', 'row_index', 'pesan']);
            foreach ($results as $rr) {
                $m = $rowMeta[$rr->document_id] ?? null;
                fputcsv($out, [
                    $rr->document_id, $m->wilayah_code ?? '', $m->no_bs ?? '', $m->no_ruta ?? '',
                    $ruleCodes[$rr->rule_id] ?? $rr->rule_id, $rr->status,
                    $rr->target_var, $rr->row_index, $rr->message,
                ]);
            }
            fclose($out);
        }, 'susenas_warning_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Jejak koreksi cleaning: setiap perubahan nilai bersih beserta alasan & petugas. */
    public function exportCorrections(Request $request): StreamedResponse
    {
        $rows = $this->scopedRows($request, false);
        $docIds = array_column($rows, 'id');
        $meta = [];
        foreach ($rows as $r) {
            $meta[$r->id] = $r;
        }
        $users = User::pluck('name', 'id')->all();

        $qMeta = \App\Models\Question::query()
            ->join('blocks as b', 'b.id', '=', 'questions.block_id')
            ->pluck('b.code', 'questions.id')->all();
        $qVar = \App\Models\Question::pluck('var_name', 'id')->all();

        $hist = \App\Models\AnswerHistory::whereIn('document_id', $docIds)
            ->where('reason', 'like', 'CLEANING:%')
            ->orderBy('document_id')->orderBy('id')->get();

        return response()->streamDownload(function () use ($hist, $meta, $users, $qMeta, $qVar) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['doc_id', 'wilayah_code', 'no_bs', 'no_ruta', 'blok', 'var_name',
                'baris', 'nilai_lama', 'nilai_baru', 'alasan', 'oleh', 'waktu']);
            foreach ($hist as $h) {
                $m = $meta[$h->document_id] ?? null;
                fputcsv($out, [
                    $h->document_id, $m->wilayah_code ?? '', $m->no_bs ?? '', $m->no_ruta ?? '',
                    $qMeta[$h->question_id] ?? '', $qVar[$h->question_id] ?? $h->question_id,
                    $h->art_index, $h->old_value, $h->new_value,
                    \Illuminate\Support\Str::after($h->reason, 'CLEANING: '),
                    $users[$h->by_user] ?? $h->by_user,
                    optional($h->at)->format('Y-m-d H:i'),
                ]);
            }
            fclose($out);
        }, 'susenas_koreksi_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv']);
    }
}

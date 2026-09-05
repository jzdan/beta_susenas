<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\QuestionnaireVersion;
use App\Models\RuleResult;
use App\Models\SampleHousehold;
use App\Models\User;
use App\Models\Wilayah;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    public const STATUS = [
        'belum' => 'Belum dikunjungi',
        'proses' => 'Sedang dikerjakan',
        'selesai' => 'Selesai dicacah',
        'non_respons' => 'Non-respons',
        'ganti_sampel' => 'Ganti sampel',
    ];
    private const DONE = ['selesai'];

    /** status yang mewajibkan alasan non-respons. */
    private const NEEDS_REASON = ['non_respons', 'ganti_sampel'];

    public const NONRESPONSE_REASONS = [
        'menolak' => 'Menolak diwawancarai',
        'tidak_ditemukan' => 'Tidak ditemukan / berulang kali tidak di rumah',
        'pindah' => 'Pindah ke luar blok sensus',
        'bangunan_kosong' => 'Bangunan sensus kosong / bukan tempat tinggal',
        'ruta_ganda' => 'Ruta ganda / tidak memenuhi syarat',
        'akses' => 'Tidak dapat dikunjungi (akses/bencana/keamanan)',
        'lainnya' => 'Lainnya (lihat catatan)',
    ];

    private function visibleSamples(User $user)
    {
        $q = SampleHousehold::query();
        if ($user->wilayah_scope) {
            $q->where('wilayah_code', 'like', $user->wilayah_scope.'%');
        }
        if ($user->role === 'operator') {
            $q->where('petugas_id', $user->id);
        }
        return $q;
    }

    /** Halaman aksi: apa yang perlu dikerjakan petugas / pemeriksa hari ini. */
    public function myTasks(Request $request)
    {
        $user = $request->user();
        $samples = $this->visibleSamples($user)->with('documents:id,sample_id,status,return_reason,returned_at')->get();
        $wil = Wilayah::pluck('name', 'code');

        // sampel belum tuntas (belum/proses)
        $pending = $samples->whereIn('status', ['belum', 'proses'])
            ->sortBy([['wilayah_code', 'asc'], ['no_bs', 'asc'], ['no_ruta', 'asc']])
            ->map(fn ($s) => [
                'sample' => $s,
                'wil' => $wil[$s->wilayah_code] ?? $s->wilayah_code,
                'doc' => $s->documents->firstWhere('status', '!=', 'bersih') ?? $s->documents->first(),
            ])->values();

        // dokumen dikembalikan ke petugas
        $returned = collect();
        foreach ($samples as $s) {
            foreach ($s->documents->where('status', 'dikembalikan') as $doc) {
                $returned->push([
                    'doc' => $doc, 'sample' => $s,
                    'wil' => $wil[$s->wilayah_code] ?? $s->wilayah_code,
                ]);
            }
        }
        $returned = $returned->sortByDesc(fn ($r) => $r['doc']->returned_at)->values();

        // catatan terakhir yang bukan dari saya (perlu ditanggapi)
        $docIds = $samples->flatMap->documents->pluck('id');
        $lastNotes = \App\Models\DocumentNote::with('user:id,name')
            ->whereIn('document_id', $docIds)
            ->get()->groupBy('document_id')
            ->map(fn ($g) => $g->sortBy('id')->last())
            ->filter(fn ($n) => (int) $n->by_user !== (int) $user->id)
            ->sortByDesc('id')->take(15);
        $noteRows = $lastNotes->map(function ($n) use ($samples, $wil) {
            $doc = $samples->flatMap->documents->firstWhere('id', $n->document_id);
            $s = $doc ? $samples->firstWhere('id', $doc->sample_id) : null;

            return [
                'note' => $n, 'doc_id' => $n->document_id,
                'ruta' => $s ? (($wil[$s->wilayah_code] ?? $s->wilayah_code)." · Ruta {$s->no_ruta}") : '',
            ];
        })->values();

        return view('monitoring.tugas', [
            'pending' => $pending, 'returned' => $returned, 'noteRows' => $noteRows,
            'isOperator' => $user->role === 'operator',
        ]);
    }

    public function dashboard(Request $request)
    {
        $all = $this->visibleSamples($request->user())->get();
        // sampel yang sudah digantikan tidak lagi menempati kuota — pengganti yang dihitung
        $rows = $all->where('status', '!=', 'ganti_sampel')->values();
        $total = $rows->count();
        $byStatus = $all->countBy('status');
        $done = collect(self::DONE)->sum(fn ($s) => $rows->where('status', $s)->count());
        $rate = $total ? round(100 * $done / $total, 1) : 0.0;

        // angka respons: kelompok tidak-eligible (bangunan kosong / ruta ganda) dikeluarkan dari penyebut
        $ineligible = $rows->whereIn('nonresponse_reason', ['bangunan_kosong', 'ruta_ganda'])->count();
        $eligible = $total - $ineligible;
        $nonresp = $rows->where('status', 'non_respons')->count();
        $response = [
            'eligible' => $eligible,
            'responded' => $done,
            'nonresponse' => $nonresp,
            'pending' => $eligible - $done - $nonresp,
            'ineligible' => $ineligible,
            'replaced' => $all->where('status', 'ganti_sampel')->count(),
            'rate' => $eligible ? round(100 * $done / $eligible, 1) : 0.0,
        ];

        $wilNames = Wilayah::pluck('name', 'code');
        $userNames = User::pluck('name', 'id');

        $agg = function (callable $key) use ($rows) {
            $acc = [];
            foreach ($rows as $s) {
                $k = $key($s);
                $acc[$k] ??= ['total' => 0, 'done' => 0];
                $acc[$k]['total']++;
                if (in_array($s->status, self::DONE, true)) {
                    $acc[$k]['done']++;
                }
            }
            return $acc;
        };

        $perWil = [];
        foreach ($agg(fn ($s) => $s->wilayah_code) as $code => $a) {
            $perWil[] = [
                'code' => $code, 'name' => $wilNames[$code] ?? $code,
                'kec' => $wilNames[substr($code, 0, 6)] ?? substr($code, 0, 6),
                'total' => $a['total'], 'done' => $a['done'],
                'rate' => $a['total'] ? round(100 * $a['done'] / $a['total'], 1) : 0.0,
            ];
        }
        usort($perWil, fn ($x, $y) => $x['rate'] <=> $y['rate']);

        $perPetugas = [];
        foreach ($agg(fn ($s) => $s->petugas_id) as $pid => $a) {
            $perPetugas[] = [
                'name' => $userNames[$pid] ?? '—',
                'total' => $a['total'], 'done' => $a['done'],
                'rate' => $a['total'] ? round(100 * $a['done'] / $a['total'], 1) : 0.0,
            ];
        }
        usort($perPetugas, fn ($x, $y) => $x['rate'] <=> $y['rate']);

        // jadwal & kecepatan pencacahan per siklus yang punya jadwal lapangan
        $schedule = [];
        $cycles = \App\Models\SurveyCycle::whereIn('id', $rows->pluck('cycle_id')->unique())
            ->whereNotNull('field_start')->whereNotNull('field_end')->get();
        $today = \Illuminate\Support\Carbon::today();
        foreach ($cycles as $c) {
            $cs = $rows->where('cycle_id', $c->id);
            $cTotal = $cs->count();
            $cDone = $cs->whereIn('status', self::DONE)->count();
            $totalDays = max(1, $c->field_start->diffInDays($c->field_end) + 1);
            $elapsed = min($totalDays, max(0, $c->field_start->diffInDays($today) + 1));
            if ($today->lt($c->field_start)) {
                $elapsed = 0;
            }
            $daysLeft = $today->gt($c->field_end) ? 0 : $today->diffInDays($c->field_end);
            $expected = round(100 * $elapsed / $totalDays, 1);
            $actual = $cTotal ? round(100 * $cDone / $cTotal, 1) : 0.0;
            $remaining = $cTotal - $cDone;
            $schedule[] = [
                'cycle' => $c->name,
                'start' => $c->field_start->format('d M Y'),
                'end' => $c->field_end->format('d M Y'),
                'total' => $cTotal, 'done' => $cDone, 'remaining' => $remaining,
                'days_left' => $daysLeft, 'elapsed_days' => $elapsed, 'total_days' => $totalDays,
                'expected' => $expected, 'actual' => $actual, 'gap' => round($actual - $expected, 1),
                'need_per_day' => $daysLeft > 0 ? ceil($remaining / $daysLeft) : $remaining,
                'phase' => $today->lt($c->field_start) ? 'belum_mulai'
                    : ($today->gt($c->field_end) ? 'lewat' : 'berjalan'),
            ];
        }

        $docs = Document::whereIn('sample_id', $rows->pluck('id'))->get(['id', 'status']);
        $docIds = $docs->pluck('id');
        $nErrDocs = $docIds->isEmpty() ? 0 : RuleResult::whereIn('document_id', $docIds)
            ->where('status', 'error')->distinct('document_id')->count('document_id');
        $nWarnDocs = $docIds->isEmpty() ? 0 : RuleResult::whereIn('document_id', $docIds)
            ->where('status', 'warning')->distinct('document_id')->count('document_id');

        // corong pengolahan: dokumen per tahap
        $funnel = [];
        $docByStatus = $docs->countBy('status');
        foreach (['draft' => 'Entri/draft', 'selesai' => 'Menunggu cleaning',
            'dikembalikan' => 'Dikembalikan', 'bersih' => 'Bersih'] as $k => $lbl) {
            $funnel[] = ['label' => $lbl, 'n' => (int) ($docByStatus[$k] ?? 0)];
        }

        // aturan yang paling sering menyala (untuk fokus perbaikan)
        $topRules = $docIds->isEmpty() ? collect() : RuleResult::query()
            ->whereIn('rule_results.document_id', $docIds)
            ->whereIn('rule_results.status', ['error', 'warning'])
            ->join('validation_rules as vr', 'vr.id', '=', 'rule_results.rule_id')
            ->selectRaw('vr.code, vr.severity, count(distinct rule_results.document_id) as n_doc')
            ->groupBy('vr.code', 'vr.severity')
            ->orderByRaw('vr.severity = "error" desc, n_doc desc')
            ->limit(6)->get();

        return view('monitoring.dashboard', compact(
            'total', 'done', 'rate', 'byStatus', 'perWil', 'perPetugas',
            'nErrDocs', 'nWarnDocs', 'schedule', 'funnel', 'topRules', 'response'
        ) + ['statusLabels' => self::STATUS]);
    }

    public function peta(Request $request)
    {
        $rows = $this->visibleSamples($request->user())->get()
            ->where('status', '!=', 'ganti_sampel');
        $wil = Wilayah::pluck('name', 'code');

        $tree = [];
        foreach ($rows as $s) {
            $desa = $s->wilayah_code;
            $kec = substr($desa, 0, 6);
            $tree[$kec] ??= ['name' => $wil[$kec] ?? $kec, 'total' => 0, 'done' => 0, 'desa' => []];
            $tree[$kec]['desa'][$desa] ??= ['name' => $wil[$desa] ?? $desa, 'total' => 0, 'done' => 0, 'bs' => []];
            $tree[$kec]['desa'][$desa]['bs'][$s->no_bs] ??= ['t' => 0, 'd' => 0];
            $isDone = in_array($s->status, self::DONE, true) ? 1 : 0;
            $tree[$kec]['total']++;
            $tree[$kec]['done'] += $isDone;
            $tree[$kec]['desa'][$desa]['total']++;
            $tree[$kec]['desa'][$desa]['done'] += $isDone;
            $tree[$kec]['desa'][$desa]['bs'][$s->no_bs]['t']++;
            $tree[$kec]['desa'][$desa]['bs'][$s->no_bs]['d'] += $isDone;
        }
        ksort($tree);

        $pct = fn ($d, $t) => $t ? round(100 * $d / $t, 1) : 0.0;
        $kecs = [];
        foreach ($tree as $kc => $k) {
            $desas = [];
            ksort($k['desa']);
            foreach ($k['desa'] as $dc => $d) {
                $bslist = [];
                ksort($d['bs']);
                foreach ($d['bs'] as $bsc => $b) {
                    $r = $pct($b['d'], $b['t']);
                    $bslist[] = ['bs' => $bsc, 't' => $b['t'], 'd' => $b['d'], 'rate' => $r, 'bucket' => $this->bucket($r)];
                }
                $r = $pct($d['done'], $d['total']);
                $desas[] = ['code' => $dc, 'name' => $d['name'], 'total' => $d['total'],
                    'done' => $d['done'], 'rate' => $r, 'bucket' => $this->bucket($r), 'bs' => $bslist];
            }
            $r = $pct($k['done'], $k['total']);
            $kecs[] = ['code' => $kc, 'name' => $k['name'], 'total' => $k['total'],
                'done' => $k['done'], 'rate' => $r, 'bucket' => $this->bucket($r), 'desa' => $desas];
        }

        return view('monitoring.peta', [
            'kecs' => $kecs, 'total' => $rows->count(),
            'done' => $rows->whereIn('status', self::DONE)->count(),
        ]);
    }

    private function bucket(float $rate): string
    {
        return match (true) {
            $rate >= 100 => 'b100',
            $rate >= 90 => 'b90',
            $rate >= 50 => 'b50',
            $rate > 0 => 'b1',
            default => 'b0',
        };
    }

    public function samples(Request $request)
    {
        $query = $this->visibleSamples($request->user());
        if ($s = $request->query('status')) {
            $query->where('status', $s);
        }
        if ($w = $request->query('wil')) {
            $query->where('wilayah_code', 'like', $w.'%');
        }
        if ($q = $request->query('q')) {
            $query->where('krt_name', 'like', "%{$q}%");
        }
        if ($p = $request->query('petugas')) {
            $query->where('petugas_id', $p === 'none' ? null : (int) $p);
        }
        $items = $query->orderBy('wilayah_code')->orderBy('no_bs')->orderBy('no_ruta')->get();

        $canAssign = in_array($request->user()->role, ['pml', 'admin'], true);

        return view('monitoring.samples', [
            'items' => $items,
            'wilNames' => Wilayah::pluck('name', 'code'),
            'userNames' => User::pluck('name', 'id'),
            'docBySample' => Document::whereIn('sample_id', $items->pluck('id'))->get()->keyBy('sample_id'),
            'statusLabels' => self::STATUS,
            'kecs' => Wilayah::where('level', 'kecamatan')->get(),
            'fStatus' => $request->query('status', ''),
            'fWil' => $request->query('wil', ''),
            'fQ' => $request->query('q', ''),
            'fPetugas' => $request->query('petugas', ''),
            'canAssign' => $canAssign,
            'operators' => $canAssign ? $this->assignableUsers($request->user())['operator'] : [],
        ]);
    }

    public function sample(Request $request, SampleHousehold $sample)
    {
        $canAssign = in_array($request->user()->role, ['pml', 'admin'], true);

        return view('monitoring.sample', [
            's' => $sample,
            'wilNames' => Wilayah::pluck('name', 'code'),
            'userNames' => User::pluck('name', 'id'),
            'docs' => $sample->documents,
            'statusLabels' => self::STATUS,
            'nrReasons' => self::NONRESPONSE_REASONS,
            'replacement' => $sample->replacedBy()->first(),
            'original' => $sample->replaces,
            'canAssign' => $canAssign,
            'assignable' => $canAssign ? $this->assignableUsers($request->user()) : ['operator' => [], 'pml' => []],
            'audit' => AuditLog::where('entity', 'sample')->where('entity_id', (string) $sample->id)
                ->orderByDesc('at')->limit(20)->get(),
        ]);
    }

    public function setStatus(Request $request, SampleHousehold $sample)
    {
        $new = $request->input('new_status');
        abort_unless(array_key_exists($new, self::STATUS), 400, 'Status tidak valid');
        $this->assertInScope($request->user(), $sample);

        $needsReason = in_array($new, self::NEEDS_REASON, true);
        $d = $request->validate([
            'reason' => [$needsReason ? 'required' : 'nullable',
                'string', 'in:'.implode(',', array_keys(self::NONRESPONSE_REASONS))],
            'note' => 'nullable|string|max:300',
        ]);

        $old = $sample->status;
        $sample->update([
            'status' => $new,
            'nonresponse_reason' => $needsReason ? $d['reason'] : null,
            'status_note' => $needsReason ? ($d['note'] ?? null) : null,
        ]);
        $detail = "{$old} -> {$new}".($needsReason ? " ({$d['reason']})" : '');
        AuditLog::record('sample', $sample->id, 'ubah-status', $detail, $request->user()->id);

        return redirect()->route('monitoring.sample', $sample)->with('ok', 'Status diperbarui.');
    }

    /** Buat sampel pengganti dari sampel yang non-respons / tidak eligible. */
    public function replace(Request $request, SampleHousehold $sample)
    {
        $this->assertInScope($request->user(), $sample);
        $d = $request->validate([
            'reason' => 'required|string|in:'.implode(',', array_keys(self::NONRESPONSE_REASONS)),
            'note' => 'nullable|string|max:300',
            'no_ruta' => 'nullable|string|max:12',
            'krt_name' => 'nullable|string|max:120',
        ]);
        abort_if($sample->replaces_id, 409, 'Sampel ini sendiri adalah pengganti.');
        abort_if($sample->replacedBy()->exists(), 409, 'Sampel ini sudah punya pengganti.');

        $pengganti = SampleHousehold::create([
            'cycle_id' => $sample->cycle_id,
            'wilayah_code' => $sample->wilayah_code,
            'no_bs' => $sample->no_bs,
            'no_ruta' => ($d['no_ruta'] ?? null) ?: $sample->no_ruta.'P',
            'krt_name' => ($d['krt_name'] ?? null) ?: '(pengganti — belum dicacah)',
            'petugas_id' => $sample->petugas_id,
            'pml_id' => $sample->pml_id,
            'status' => 'belum',
            'weight' => $sample->weight,
            'replaces_id' => $sample->id,
        ]);
        $sample->update([
            'status' => 'ganti_sampel',
            'nonresponse_reason' => $d['reason'],
            'status_note' => $d['note'] ?? null,
        ]);
        AuditLog::record('sample', $sample->id, 'ganti-sampel',
            "diganti sampel #{$pengganti->id} ({$d['reason']})", $request->user()->id);
        AuditLog::record('sample', $pengganti->id, 'buat-pengganti',
            "pengganti sampel #{$sample->id}", $request->user()->id);

        return redirect()->route('monitoring.sample', $pengganti)
            ->with('ok', 'Sampel pengganti dibuat. Tugaskan & mulai pencacahan seperti biasa.');
    }

    private function assertInScope(User $user, SampleHousehold $sample): void
    {
        if ($user->wilayah_scope && ! str_starts_with($sample->wilayah_code, $user->wilayah_scope)) {
            abort(403, 'Sampel di luar cakupan wilayah Anda.');
        }
    }

    /** id petugas (operator) & pml yang boleh ditugaskan oleh $user. */
    private function assignableUsers(User $user): array
    {
        $ops = User::where('role', 'operator')->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
        $pmls = User::where('role', 'pml')->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
        return ['operator' => $ops, 'pml' => $pmls];
    }

    public function assign(Request $request, SampleHousehold $sample)
    {
        $this->assertInScope($request->user(), $sample);
        $d = $request->validate([
            'petugas_id' => 'nullable|integer|exists:users,id',
            'pml_id' => 'nullable|integer|exists:users,id',
        ]);
        $before = "petugas={$sample->petugas_id}, pml={$sample->pml_id}";
        $oldPetugas = (int) $sample->petugas_id;
        $sample->update([
            'petugas_id' => ($d['petugas_id'] ?? null) ?: null,
            'pml_id' => ($d['pml_id'] ?? null) ?: null,
        ]);
        AuditLog::record('sample', $sample->id, 'tugaskan',
            "{$before} -> petugas={$sample->petugas_id}, pml={$sample->pml_id}", $request->user()->id);
        if ($sample->petugas_id && (int) $sample->petugas_id !== $oldPetugas) {
            User::find($sample->petugas_id)?->notify(new \App\Notifications\SampleAssigned($sample, $request->user()->name));
        }
        return back()->with('ok', 'Penugasan diperbarui.');
    }

    public function bulkAssign(Request $request)
    {
        $d = $request->validate([
            'ids' => 'required|array', 'ids.*' => 'integer',
            'petugas_id' => 'required|integer|exists:users,id',
        ]);
        $q = $this->visibleSamples($request->user())->whereIn('id', $d['ids']);
        $n = 0;
        foreach ($q->get() as $s) {
            if ($request->user()->wilayah_scope && ! str_starts_with($s->wilayah_code, $request->user()->wilayah_scope)) {
                continue;
            }
            $changed = $s->petugas_id !== (int) $d['petugas_id'];
            $s->update(['petugas_id' => $d['petugas_id']]);
            if ($changed) {
                User::find($d['petugas_id'])?->notify(new \App\Notifications\SampleAssigned($s, $request->user()->name));
            }
            $n++;
        }
        AuditLog::record('sample', '-', 'tugaskan-massal',
            "{$n} sampel -> petugas {$d['petugas_id']}", $request->user()->id);
        return back()->with('ok', "{$n} sampel ditugaskan.");
    }

    public function createDocument(Request $request, SampleHousehold $sample)
    {
        abort_if($sample->cycle?->isLocked(), 423, 'Siklus sudah dikunci (selesai).');
        $doc = $sample->documents()->first();
        if (! $doc) {
            $qv = QuestionnaireVersion::where('cycle_id', $sample->cycle_id)->orderByDesc('id')->first();
            $doc = Document::create([
                'sample_id' => $sample->id, 'qversion_id' => $qv->id,
                'status' => 'draft', 'created_by' => $request->user()->id,
            ]);
            if ($sample->status === 'belum') {
                $sample->update(['status' => 'proses']);
            }
            AuditLog::record('document', 'baru', 'buat', "sampel {$sample->id}", $request->user()->id);
        }
        return redirect()->route('entri.form', $doc);
    }

    /** Ringkasan produktivitas entri & pengolahan per petugas/pemeriksa. */
    public function kinerja(Request $request)
    {
        $scope = $request->user()->wilayah_scope;
        $docQ = Document::query()->join('sample_households as s', 's.id', '=', 'documents.sample_id');
        if ($scope) {
            $docQ->where('s.wilayah_code', 'like', $scope.'%');
        }
        $docs = $docQ->select('documents.*', 's.petugas_id as s_petugas')->get();
        $userNames = User::pluck('name', 'id');

        $snap = \App\Models\DocumentSnapshot::whereIn('document_id', $docs->pluck('id'))
            ->pluck('data', 'document_id')
            ->map(fn ($d) => is_array($d) ? $d : (json_decode($d, true) ?: []));

        // --- entri: dikelompokkan per petugas sampel ---
        $entri = [];
        foreach ($docs as $d) {
            $pid = (int) $d->s_petugas;
            if (! $pid) {
                continue;
            }
            $e = &$entri[$pid];
            $e['name'] ??= $userNames[$pid] ?? '—';
            $e['total'] = ($e['total'] ?? 0) + 1;
            if ($d->submitted_at) {
                $e['submit'] = ($e['submit'] ?? 0) + 1;
                $e['entry_days'][] = abs($d->created_at->diffInDays($d->submitted_at));
                $w = $snap[$d->id]['_n_warn'] ?? null;
                if (is_numeric($w)) {
                    $e['warn'][] = (int) $w;
                }
            }
            if ($d->status === 'bersih') {
                $e['bersih'] = ($e['bersih'] ?? 0) + 1;
            }
            if ($d->status === 'dikembalikan' || $d->returned_at) {
                $e['dikembalikan'] = ($e['dikembalikan'] ?? 0) + 1;
            }
            unset($e);
        }
        $entriRows = collect($entri)->map(fn ($e) => [
            'name' => $e['name'],
            'total' => $e['total'], 'submit' => $e['submit'] ?? 0, 'bersih' => $e['bersih'] ?? 0,
            'dikembalikan' => $e['dikembalikan'] ?? 0,
            'avg_entry_days' => ! empty($e['entry_days']) ? round(collect($e['entry_days'])->avg(), 1) : null,
            'avg_warn' => ! empty($e['warn']) ? round(collect($e['warn'])->avg(), 1) : null,
        ])->sortByDesc('submit')->values();

        // --- pengolahan: dikelompokkan per pemeriksa ---
        $docIds = $docs->pluck('id');
        $koreksi = \App\Models\AnswerHistory::whereIn('document_id', $docIds)
            ->where('reason', 'like', 'CLEANING:%')
            ->selectRaw('by_user, count(*) as n')->groupBy('by_user')->pluck('n', 'by_user');
        $ditinjau = \App\Models\WarningAck::whereIn('document_id', $docIds)
            ->selectRaw('by_user, count(*) as n')->groupBy('by_user')->pluck('n', 'by_user');
        $dikembalikan = $docs->whereNotNull('returned_by')->groupBy('returned_by')->map->count();

        $olah = [];
        foreach ($docs->whereNotNull('cleaned_by') as $d) {
            $uid = (int) $d->cleaned_by;
            $o = &$olah[$uid];
            $o['name'] ??= $userNames[$uid] ?? '—';
            $o['bersih'] = ($o['bersih'] ?? 0) + 1;
            if ($d->submitted_at && $d->cleaned_at) {
                $o['lead'][] = abs($d->submitted_at->diffInDays($d->cleaned_at));
            }
            unset($o);
        }
        foreach (array_merge($koreksi->keys()->all(), $ditinjau->keys()->all(), $dikembalikan->keys()->all()) as $uid) {
            $uid = (int) $uid;
            $olah[$uid]['name'] ??= $userNames[$uid] ?? '—';
        }
        $olahRows = collect($olah)->map(fn ($o, $uid) => [
            'name' => $o['name'],
            'bersih' => $o['bersih'] ?? 0,
            'koreksi' => (int) ($koreksi[$uid] ?? 0),
            'ditinjau' => (int) ($ditinjau[$uid] ?? 0),
            'dikembalikan' => (int) ($dikembalikan[$uid] ?? 0),
            'avg_lead_days' => ! empty($o['lead']) ? round(collect($o['lead'])->avg(), 1) : null,
        ])->sortByDesc('bersih')->values();

        return view('monitoring.kinerja', [
            'entriRows' => $entriRows, 'olahRows' => $olahRows,
            'totalDocs' => $docs->count(),
        ]);
    }

    public function audit(Request $request)
    {
        $fEntity = $request->query('entity', '');
        $fUser = $request->query('user', '');
        $fFrom = $request->query('from', '');
        $fTo = $request->query('to', '');
        $fQ = trim((string) $request->query('q', ''));

        $q = AuditLog::orderByDesc('at');
        if ($fEntity === 'keamanan') {
            $q->where('entity', 'auth')->whereIn('action', ['login-gagal', 'login-terkunci']);
        } elseif ($fEntity) {
            $q->where('entity', $fEntity);
        }
        if ($fUser !== '' && ctype_digit((string) $fUser)) {
            $q->where('by_user', (int) $fUser);
        }
        if ($fFrom !== '') {
            $q->where('at', '>=', $fFrom.' 00:00:00');
        }
        if ($fTo !== '') {
            $q->where('at', '<=', $fTo.' 23:59:59');
        }
        if ($fQ !== '') {
            $q->where(fn ($w) => $w->where('detail', 'like', "%{$fQ}%")
                ->orWhere('action', 'like', "%{$fQ}%")
                ->orWhere('entity_id', $fQ));
        }

        $userNames = User::pluck('name', 'id');

        if ($request->boolean('export')) {
            $rows = (clone $q)->limit(20000)->get();

            return response()->streamDownload(function () use ($rows, $userNames) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['waktu', 'entitas', 'entity_id', 'aksi', 'detail', 'oleh']);
                foreach ($rows as $r) {
                    fputcsv($out, [optional($r->at)->format('Y-m-d H:i:s'), $r->entity, $r->entity_id,
                        $r->action, $r->detail, $userNames[$r->by_user] ?? $r->by_user]);
                }
                fclose($out);
            }, 'audit_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv']);
        }

        return view('monitoring.audit', [
            'rows' => $q->limit(300)->get(),
            'userNames' => $userNames,
            'actors' => User::whereIn('id', AuditLog::distinct()->pluck('by_user')->filter())
                ->orderBy('name')->pluck('name', 'id'),
            'fEntity' => $fEntity, 'fUser' => $fUser, 'fFrom' => $fFrom, 'fTo' => $fTo, 'fQ' => $fQ,
            'entities' => AuditLog::distinct()->orderBy('entity')->pluck('entity')->all(),
            'nFailLogins' => AuditLog::where('entity', 'auth')
                ->whereIn('action', ['login-gagal', 'login-terkunci'])
                ->where('at', '>=', now()->subDay())->count(),
        ]);
    }
}

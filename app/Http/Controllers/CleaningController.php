<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\AnswerHistory;
use App\Models\AuditLog;
use App\Models\Block;
use App\Models\Document;
use App\Models\RuleResult;
use App\Models\SampleHousehold;
use App\Models\User;
use App\Models\WarningAck;
use App\Models\Wilayah;
use App\Services\RuleEngine;
use Illuminate\Http\Request;

class CleaningController extends Controller
{
    /** ambang "mengendap" — dokumen menunggu ≥ sekian hari di antrean. */
    private const STALE_DAYS = 7;

    public function __construct(private RuleEngine $engine) {}

    private function scoped(Request $request)
    {
        $q = Document::query()
            ->join('sample_households as s', 's.id', '=', 'documents.sample_id')
            ->select('documents.*', 's.wilayah_code', 's.no_bs', 's.no_ruta', 's.krt_name');
        if ($scope = $request->user()->wilayah_scope) {
            $q->where('s.wilayah_code', 'like', $scope.'%');
        }
        // Inc 154: tab Pengolahan di hub siklus = antrean yang sama, tersaring ke satu siklus
        if ($cycle = (int) $request->input('cycle')) {
            $q->where('s.cycle_id', $cycle);
        }
        return $q;
    }

    public function index(Request $request)
    {
        $fStatus = $request->query('status', '');
        $fRule = $request->query('rule', '');
        $fQ = trim((string) $request->query('q', ''));
        $allDocIds = $this->scoped($request)->pluck('documents.id');

        // rekap temuan lintas dokumen: berapa dokumen kena tiap kode aturan
        $ruleAgg = RuleResult::query()
            ->whereIn('rule_results.document_id', $allDocIds)
            ->whereIn('rule_results.status', ['error', 'warning'])
            ->join('validation_rules as vr', 'vr.id', '=', 'rule_results.rule_id')
            ->selectRaw('vr.code, vr.severity, vr.message, count(distinct rule_results.document_id) as n_doc, count(*) as n_hit')
            ->groupBy('vr.code', 'vr.severity', 'vr.message')
            ->orderByRaw('vr.severity = "error" desc, n_doc desc')
            ->get();

        $q = $this->scoped($request);
        if ($fStatus) {
            $q->where('documents.status', $fStatus);
        }
        if ($fRule) {
            $hitDocIds = RuleResult::whereIn('status', ['error', 'warning'])
                ->whereHas('rule', fn ($r) => $r->where('code', $fRule))
                ->pluck('document_id');
            $q->whereIn('documents.id', $hitDocIds);
        }
        if ($fQ !== '') {
            $q->where(function ($w) use ($fQ) {
                $w->where('s.wilayah_code', 'like', "%{$fQ}%")
                    ->orWhere('s.no_bs', 'like', "%{$fQ}%")
                    ->orWhere('s.no_ruta', 'like', "%{$fQ}%")
                    ->orWhere('s.krt_name', 'like', "%{$fQ}%");
                if (ctype_digit($fQ)) {
                    $w->orWhere('documents.id', (int) $fQ);
                }
            });
        }
        $docs = $q->orderBy('documents.status')
            ->orderByRaw("CASE documents.status WHEN 'selesai' THEN documents.submitted_at "
                ."WHEN 'dikembalikan' THEN documents.returned_at ELSE documents.updated_at END asc")
            ->orderBy('s.wilayah_code')->get();

        $docIds = $docs->pluck('id');
        $counts = RuleResult::whereIn('document_id', $docIds)
            ->selectRaw('document_id, sum(status = "error") as e, sum(status = "warning") as w')
            ->groupBy('document_id')->get()->keyBy('document_id');
        $noteCounts = \App\Models\DocumentNote::whereIn('document_id', $docIds)
            ->selectRaw('document_id, count(*) as n')->groupBy('document_id')->pluck('n', 'document_id');
        $ackCounts = \App\Models\WarningAck::whereIn('document_id', $docIds)
            ->selectRaw('document_id, count(*) as n')->groupBy('document_id')->pluck('n', 'document_id');

        $wil = Wilayah::pluck('name', 'code');
        $rows = $docs->map(fn ($d) => [
            'doc' => $d,
            'wil' => $wil[$d->wilayah_code] ?? $d->wilayah_code,
            'err' => (int) ($counts[$d->id]->e ?? 0),
            'warn' => (int) ($counts[$d->id]->w ?? 0),
            'warn_open' => max(0, (int) ($counts[$d->id]->w ?? 0) - (int) ($ackCounts[$d->id] ?? 0)),
            'notes' => (int) ($noteCounts[$d->id] ?? 0),
            'wait_days' => $d->waitingDays(),
        ]);

        // umur antrean: dokumen menunggu cleaning (selesai) & dikembalikan
        $waiting = $docs->whereIn('status', ['selesai', 'dikembalikan'])
            ->map(fn ($d) => $d->waitingDays())->filter(fn ($n) => $n !== null);
        $queueAge = [
            'n' => $waiting->count(),
            'avg' => $waiting->count() ? round($waiting->avg(), 1) : 0,
            'max' => (int) ($waiting->max() ?? 0),
            'stale' => $waiting->filter(fn ($n) => $n >= self::STALE_DAYS)->count(),
        ];

        return view('pengolahan.index', [
            'rows' => $rows, 'fStatus' => $fStatus, 'fRule' => $fRule, 'fQ' => $fQ,
            'fCycle' => (int) $request->query('cycle') ?: null,
            'ruleAgg' => $ruleAgg, 'totalDocs' => $allDocIds->count(),
            'queueAge' => $queueAge, 'staleDays' => self::STALE_DAYS,
            // label sama dengan tahap di Daftar Sampel (App\Support\Tahap)
            'statuses' => collect(\App\Support\Tahap::FROM_DOC)
                ->map(fn ($t) => \App\Support\Tahap::label($t))->all(),
        ]);
    }

    public function revalidate(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['pj', 'pml', 'admin'], true), 403);
        $docs = $this->scoped($request)->where('documents.status', '!=', 'bersih')
            ->select('documents.id')->pluck('id');
        $n = 0;
        foreach (Document::whereIn('id', $docs)->get() as $doc) {
            $this->engine->run($doc);
            $n++;
        }
        AuditLog::record('pengolahan', $request->user()->id, 'revalidasi', "{$n} sampel", $request->user()->id);

        return redirect()->route('pengolahan.index', $request->only('status', 'rule', 'q', 'cycle'))
            ->with('ok', "Aturan validasi dijalankan ulang untuk {$n} sampel (di luar yang sudah bersih).");
    }

    /** Dokumen non-bersih berikutnya dalam antrean tercakup (urutan tertua dulu). */
    private function nextDoc(Request $request, Document $current): ?Document
    {
        $ids = $this->scoped($request)
            ->where('documents.status', '!=', 'bersih')
            ->orderBy('documents.status')
            ->orderByRaw("CASE documents.status WHEN 'selesai' THEN documents.submitted_at "
                ."WHEN 'dikembalikan' THEN documents.returned_at ELSE documents.updated_at END asc")
            ->orderBy('s.wilayah_code')
            ->pluck('documents.id')->all();

        $pos = array_search($current->id, $ids, true);
        if ($pos === false) {
            return Document::find($ids[0] ?? null);
        }
        $nextId = $ids[$pos + 1] ?? null;

        return $nextId ? Document::find($nextId) : null;
    }

    public function show(Request $request, Document $document)
    {
        $sum = $this->engine->run($document);
        $onlyIssues = ! $request->boolean('semua');
        $nextDoc = $this->nextDoc($request, $document);

        $blocks = Block::where('qversion_id', $document->qversion_id)->orderBy('order')->get()->keyBy('id');
        $qById = [];
        foreach ($blocks as $b) {
            foreach ($b->questions as $q) {
                $qById[$q->id] = ['q' => $q, 'block' => $b];
            }
        }
        $rowLabels = [];
        foreach ($blocks as $b) {
            if ($b->isRowBlock()) {
                foreach ($b->rows as $r) {
                    $rowLabels[$b->id][$r->row_index] = $r->label;
                }
            }
        }

        // peringatan yang sudah "diterima" (ditinjau) pemeriksa
        $acks = WarningAck::mapFor($document->id);
        $nWarnAck = 0;

        // warning per (target_var, row_index)
        $warn = [];
        foreach (RuleResult::with('rule:id,code')->where('document_id', $document->id)
            ->whereIn('status', ['error', 'warning'])->get() as $rr) {
            $code = $rr->rule->code ?? '';
            $ack = $acks[WarningAck::key($code, (string) $rr->target_var, (int) $rr->row_index)] ?? null;
            if ($ack && $rr->status === 'warning') {
                $nWarnAck++;
            }
            $warn[$rr->target_var.':'.$rr->row_index][] = [
                'status' => $rr->status, 'message' => $rr->message,
                'code' => $code, 'row_index' => (int) $rr->row_index,
                'ack' => $ack ? ['note' => $ack->note, 'by' => $ack->user->name ?? '', 'at' => $ack->created_at] : null,
            ];
        }
        // alasan koreksi terakhir per (question_id, art_index)
        $lastReason = [];
        foreach (AnswerHistory::where('document_id', $document->id)->orderBy('id')->get() as $h) {
            $lastReason[$h->question_id.':'.$h->art_index] = $h->reason;
        }

        $items = [];
        foreach (Answer::where('document_id', $document->id)->orderBy('question_id')->orderBy('art_index')->get() as $a) {
            $meta = $qById[$a->question_id] ?? null;
            if (!$meta) {
                continue;
            }
            $w = $warn[$meta['q']->var_name.':'.$a->art_index] ?? [];
            $corrected = $a->clean_value !== null && $a->clean_value !== '';
            if ($onlyIssues && !$w && !$corrected) {
                continue;
            }
            $items[] = [
                'qid' => $a->question_id, 'art' => $a->art_index,
                'block' => $meta['block']->code, 'code' => $meta['q']->code_rincian,
                'label' => $meta['q']->label, 'var' => $meta['q']->var_name,
                'row_label' => match ($meta['block']->repeat) {
                    'list' => $rowLabels[$meta['block']->id][$a->art_index] ?? "baris {$a->art_index}",
                    'list_art' => (function () use ($meta, $a, $rowLabels) {
                        [$cr, $an] = \App\Models\Block::unpackRowArt((int) $a->art_index);
                        return ($rowLabels[$meta['block']->id][$cr] ?? "baris {$cr}")." · anggota {$an}";
                    })(),
                    default => $a->art_index > 0 ? "anggota {$a->art_index}" : '',
                },
                'raw' => $a->raw_value, 'clean' => $a->clean_value,
                'reason' => $lastReason[$a->question_id.':'.$a->art_index] ?? '',
                'warnings' => $w,
            ];
        }

        // tambahkan rincian yang PUNYA warning tapi belum ada jawaban (mis. "harus diisi")
        $seen = [];
        foreach ($items as $it) {
            $seen[$it['var'].':'.$it['art']] = true;
        }
        $qByVar = collect($qById)->keyBy(fn ($m) => $m['q']->var_name);
        foreach ($warn as $key => $w) {
            [$var, $art] = explode(':', $key, 2);
            if ($var === '' || isset($seen[$key]) || ! $qByVar->has($var)) {
                continue;
            }
            $meta = $qByVar[$var];
            if ($meta['block']->repeat !== 'ruta') {
                continue; // rincian berulang: koreksi lewat layar entri
            }
            $q = $meta['q'];
            $items[] = [
                'qid' => $q->id, 'art' => (int) $art,
                'block' => $meta['block']->code, 'code' => $q->code_rincian,
                'label' => $q->label, 'var' => $var, 'row_label' => '',
                'raw' => '', 'clean' => null,
                'reason' => $lastReason[$q->id.':'.$art] ?? '',
                'warnings' => $w,
            ];
        }

        return view('pengolahan.show', [
            'doc' => $document,
            'sample' => SampleHousehold::find($document->sample_id),
            'items' => $items, 'onlyIssues' => $onlyIssues,
            'nErr' => $sum['errors'], 'nWarn' => $sum['warnings'], 'nWarnAck' => $nWarnAck,
            'docWarn' => $warn[':0'] ?? [],
            'nextDoc' => $nextDoc,
            'notes' => \App\Models\DocumentNote::with('user:id,name')
                ->where('document_id', $document->id)->orderBy('id')->get(),
        ]);
    }

    public function correct(Request $request, Document $document)
    {
        abort_if($document->status === 'bersih', 409, 'Sampel sudah bersih (terkunci).');
        abort_if($document->cycleLocked(), 423, 'Periode sudah dikunci (selesai).');
        $d = $request->validate([
            'question_id' => 'required|integer',
            'art_index' => 'nullable|integer',
            'clean_value' => 'nullable|string',
            'reason' => 'required|string|min:3',
        ]);
        $art = (int) ($d['art_index'] ?? 0);
        \App\Models\Question::findOrFail($d['question_id']);
        $a = Answer::firstOrNew([
            'document_id' => $document->id, 'question_id' => $d['question_id'], 'art_index' => $art,
        ]);

        $old = $a->clean_value ?? ($a->raw_value ?? '');
        $new = trim($d['clean_value'] ?? '');
        $a->clean_value = $new === '' ? null : $new;
        $a->raw_value ??= '';
        $a->save();
        AnswerHistory::create([
            'document_id' => $document->id, 'question_id' => $d['question_id'], 'art_index' => $art,
            'old_value' => $old, 'new_value' => $new, 'by_user' => $request->user()->id,
            'reason' => 'CLEANING: '.$d['reason'],
        ]);
        // nilai berubah -> peninjauan lama untuk rincian ini tidak berlaku lagi
        WarningAck::where('document_id', $document->id)
            ->where('target_var', $a->question->var_name)
            ->where('row_index', $art)->delete();

        $this->engine->run($document);
        AuditLog::record('document', $document->id, 'koreksi',
            "{$a->question->var_name}[{$art}] -> {$new}", $request->user()->id);

        return back()->with('ok', 'Koreksi disimpan.');
    }

    /** Tandai sebuah peringatan sudah ditinjau (bukan anomali). */
    public function ackWarning(Request $request, Document $document)
    {
        abort_if($document->status === 'bersih', 409, 'Sampel sudah bersih (terkunci).');
        $d = $request->validate([
            'rule_code' => 'required|string|max:40',
            'target_var' => 'nullable|string|max:60',
            'row_index' => 'nullable|integer',
            'note' => 'required|string|min:3|max:500',
        ]);
        $var = (string) ($d['target_var'] ?? '');
        $row = (int) ($d['row_index'] ?? 0);

        // pastikan peringatan itu memang ada saat ini
        $exists = RuleResult::where('rule_results.document_id', $document->id)
            ->where('rule_results.status', 'warning')
            ->where('rule_results.target_var', $var)
            ->where('rule_results.row_index', $row)
            ->whereHas('rule', fn ($r) => $r->where('code', $d['rule_code']))
            ->exists();
        abort_unless($exists, 404, 'Peringatan tidak ditemukan.');

        WarningAck::updateOrCreate(
            ['document_id' => $document->id, 'rule_code' => $d['rule_code'], 'target_var' => $var, 'row_index' => $row],
            ['note' => $d['note'], 'by_user' => $request->user()->id],
        );
        AuditLog::record('document', $document->id, 'terima-warning',
            "{$d['rule_code']} {$var}[{$row}]: ".\Illuminate\Support\Str::limit($d['note'], 100), $request->user()->id);

        return back()->with('ok', 'Peringatan ditandai sudah ditinjau.');
    }

    /** Batalkan peninjauan sebuah peringatan. */
    public function unackWarning(Request $request, Document $document)
    {
        abort_if($document->status === 'bersih', 409, 'Sampel sudah bersih (terkunci).');
        $d = $request->validate([
            'rule_code' => 'required|string|max:40',
            'target_var' => 'nullable|string|max:60',
            'row_index' => 'nullable|integer',
        ]);
        WarningAck::where('document_id', $document->id)
            ->where('rule_code', $d['rule_code'])
            ->where('target_var', (string) ($d['target_var'] ?? ''))
            ->where('row_index', (int) ($d['row_index'] ?? 0))->delete();
        AuditLog::record('document', $document->id, 'batal-terima-warning',
            "{$d['rule_code']} ".(string) ($d['target_var'] ?? ''), $request->user()->id);

        return back()->with('ok', 'Peninjauan dibatalkan.');
    }

    public function markClean(Request $request, Document $document)
    {
        abort_if($document->cycleLocked(), 423, 'Periode sudah dikunci (selesai).');
        $sum = $this->engine->run($document);
        if ($sum['errors'] > 0) {
            return back()->withErrors(['bersih' => "Masih ada {$sum['errors']} kesalahan, belum bisa ditandai bersih."]);
        }
        // tentukan dokumen berikutnya SEBELUM status berubah (agar posisi antrean akurat)
        $next = $request->boolean('next') ? $this->nextDoc($request, $document) : null;

        $document->update([
            'status' => 'bersih',
            'cleaned_by' => $request->user()->id,
            'cleaned_at' => now(),
        ]);
        $nAck = WarningAck::where('document_id', $document->id)->count();
        AuditLog::record('document', $document->id, 'bersih',
            "{$sum['warnings']} warning tersisa ({$nAck} ditinjau)", $request->user()->id);

        if ($next) {
            return redirect()->route('pengolahan.show', $next)
                ->with('ok', "Sampel #{$document->id} bersih. Lanjut ke #{$next->id}.");
        }

        return redirect()->route('pengolahan.index')->with('ok', "Sampel #{$document->id} ditandai bersih.");
    }

    /** Tandai bersih beberapa dokumen sekaligus (hanya yang 0 error). */
    public function bulkClean(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['pj', 'pml', 'admin'], true), 403);
        $d = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);

        $ids = $this->scoped($request)
            ->whereIn('documents.id', $d['ids'])
            ->whereIn('documents.status', ['selesai', 'dikembalikan'])
            ->pluck('documents.id');

        $done = 0;
        $skip = 0;
        foreach (Document::whereIn('id', $ids)->get() as $doc) {
            if ($doc->cycleLocked()) {
                $skip++;
                continue;
            }
            $sum = $this->engine->run($doc);
            if ($sum['errors'] > 0) {
                $skip++;
                continue;
            }
            $doc->update(['status' => 'bersih', 'cleaned_by' => $request->user()->id, 'cleaned_at' => now()]);
            AuditLog::record('document', $doc->id, 'bersih',
                "{$sum['warnings']} warning (massal)", $request->user()->id);
            $done++;
        }
        AuditLog::record('pengolahan', $request->user()->id, 'bersih-massal',
            "{$done} bersih, {$skip} dilewati", $request->user()->id);

        return redirect()->route('pengolahan.index', $request->only('status', 'rule', 'q', 'cycle'))
            ->with('ok', "{$done} sampel ditandai bersih".($skip ? ", {$skip} dilewati (masih ada kesalahan atau periode terkunci)." : '.'));
    }

    public function reopen(Request $request, Document $document)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $document->update(['status' => 'selesai', 'cleaned_by' => null, 'cleaned_at' => null]);
        AuditLog::record('document', $document->id, 'buka-kunci', '', $request->user()->id);
        return back()->with('ok', 'Kunci dibuka, status kembali ke selesai.');
    }

    public function returnDoc(Request $request, Document $document)
    {
        abort_if($document->status === 'bersih', 409, 'Sampel sudah bersih (terkunci).');
        $d = $request->validate(['reason' => 'required|string|min:5']);
        $document->update([
            'status' => 'dikembalikan',
            'return_reason' => $d['reason'],
            'returned_by' => $request->user()->id,
            'returned_at' => now(),
        ]);
        if (in_array($document->sample->status, ['selesai'], true)) {
            $document->sample->update(['status' => 'proses']);
        }
        AuditLog::record('document', $document->id, 'kembalikan',
            \Illuminate\Support\Str::limit($d['reason'], 120), $request->user()->id);

        $petugas = \App\Models\User::find($document->sample->petugas_id ?? $document->created_by);
        $petugas?->notify(new \App\Notifications\DocumentReturned($document, $d['reason'], $request->user()->name));

        return redirect()->route('pengolahan.index')->with('ok', 'Sampel dikembalikan ke petugas untuk perbaikan.');
    }
}

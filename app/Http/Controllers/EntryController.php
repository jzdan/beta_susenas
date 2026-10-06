<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\AnswerHistory;
use App\Models\AuditLog;
use App\Models\Block;
use App\Models\DerivedField;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\RuleResult;
use App\Models\SampleHousehold;
use App\Models\Wilayah;
use App\Services\ExpressionEvaluator;
use App\Services\RuleEngine;
use App\Services\RuleException;
use Illuminate\Http\Request;

class EntryController extends Controller
{
    private const MAX_ART = 30;      // batas baris ART yang ditampilkan
    private const MAX_ROW = 999_999; // batas art_index yang diterima (list_art: baris*1000 + no_art)

    /** var_name yang bisa menjadi "Banyaknya ART" (beda antar-kuesioner). */
    private const ART_COUNT_VARS = ['banyak_art', 'r301', 'jml_art', 'jumlah_art'];

    public function __construct(
        private RuleEngine $engine,
        private ExpressionEvaluator $ev,
    ) {}

    /**
     * Operator hanya boleh membuka/mengisi dokumen milik siklus yang seperiode dengan versi
     * kuesioner yang sedang aktif (KOR & KP periode yang sama tetap sama-sama terbuka —
     * lihat QuestionnaireVersion::activeCycleIds()). Peran lain (pml/pj/admin/viewer) tidak
     * dibatasi (perlu akses lintas siklus/periode utk pengolahan).
     */
    private function assertActiveCycle(Request $request, Document $document): void
    {
        if ($request->user()->role !== 'operator') {
            return;
        }
        abort_unless(in_array($document->sample->cycle_id, QuestionnaireVersion::activeCycleIds(), true), 403,
            'Periode kuesioner siklus dokumen ini sedang tidak aktif — hubungi admin.');
    }

    public function form(Request $request, Document $document)
    {
        $this->assertActiveCycle($request, $document);
        $this->engine->run($document);
        $state = $this->formState($document);
        $sample = $document->sample;

        return view('entri.document', [
            'doc' => $document,
            'sample' => $sample,
            'qv' => $document->qversion,
            'wil' => Wilayah::pluck('name', 'code'),
            'state' => $state,
            'canEdit' => in_array($request->user()->role, ['operator', 'admin'], true),
            'notes' => \App\Models\DocumentNote::with('user:id,name')
                ->where('document_id', $document->id)->orderBy('id')->get(),
            'visitPhotos' => config('susenas.bukti_kunjungan')
                ? \App\Models\VisitPhoto::with('uploader:id,name')
                    ->where('sample_id', $document->sample_id)->orderBy('id')->get()
                : collect(),
        ]);
    }

    /** Tampilan cetak/arsip satu dokumen: semua blok, jawaban (mentah + bersih), temuan. */
    public function printView(Request $request, Document $document)
    {
        $this->engine->run($document);
        $blocks = Block::where('qversion_id', $document->qversion_id)->orderBy('order')->get();

        $answers = [];
        foreach (Answer::where('document_id', $document->id)->get() as $a) {
            $answers["{$a->question_id}:{$a->art_index}"] = $a;
        }

        $warn = [];
        foreach (RuleResult::where('document_id', $document->id)->whereIn('status', ['error', 'warning'])
            ->join('validation_rules as vr', 'vr.id', '=', 'rule_results.rule_id')
            ->select('rule_results.*', 'vr.code')->get() as $r) {
            $warn[] = ['code' => $r->code, 'status' => $r->status, 'message' => $r->message,
                'var' => $r->target_var, 'row' => $r->row_index];
        }

        $out = [];
        foreach ($blocks as $b) {
            $qs = $b->questions;
            if ($b->repeat === 'ruta') {
                $rows = [[
                    'idx' => 0, 'label' => null,
                    'cells' => $qs->map(fn ($q) => [
                        'label' => $q->label, 'code' => $q->code_rincian,
                        'raw' => $answers["{$q->id}:0"]->raw_value ?? '',
                        'clean' => $answers["{$q->id}:0"]->clean_value ?? null,
                    ])->all(),
                ]];
            } else {
                $idxs = $b->repeat === 'list'
                    ? $b->rows->pluck('row_index')->all()
                    : array_values(array_unique(collect($answers)->keys()
                        ->filter(fn ($k) => $qs->contains('id', (int) explode(':', $k)[0]))
                        ->map(fn ($k) => (int) explode(':', $k)[1])->filter()->sort()->all()));
                $labelByIdx = $b->rows->pluck('label', 'row_index');
                $isListArt = $b->repeat === 'list_art';
                $rows = [];
                foreach ($idxs as $i) {
                    $cells = $qs->map(fn ($q) => [
                        'label' => $q->label, 'code' => $q->code_rincian,
                        'raw' => $answers["{$q->id}:{$i}"]->raw_value ?? '',
                        'clean' => $answers["{$q->id}:{$i}"]->clean_value ?? null,
                    ])->all();
                    if (collect($cells)->every(fn ($c) => $c['raw'] === '' && $c['clean'] === null)) {
                        continue;   // baris kosong tak dicetak
                    }
                    if ($isListArt) {
                        [$cr, $an] = \App\Models\Block::unpackRowArt((int) $i);
                        $lbl = ($labelByIdx[$cr] ?? "baris {$cr}").' · ART '.$an;
                    } else {
                        $lbl = $labelByIdx[$i] ?? null;
                    }
                    $rows[] = ['idx' => $i, 'label' => $lbl, 'cells' => $cells];
                }
            }
            $out[] = ['code' => $b->code, 'title' => $b->title, 'repeat' => $b->repeat,
                'columns' => $qs->map(fn ($q) => ['label' => $q->label, 'code' => $q->code_rincian])->all(),
                'rows' => $rows];
        }

        $ctx = $this->engine->buildContext($document);

        // jejak koreksi cleaning + catatan dokumen untuk arsip
        $qVar = Question::whereIn('block_id', Block::where('qversion_id', $document->qversion_id)->pluck('id'))
            ->pluck('var_name', 'id');
        $userNames = \App\Models\User::pluck('name', 'id');
        $corrections = AnswerHistory::where('document_id', $document->id)
            ->where('reason', 'like', 'CLEANING:%')->orderBy('id')->get()
            ->map(fn ($h) => [
                'var' => $qVar[$h->question_id] ?? $h->question_id, 'row' => $h->art_index,
                'old' => $h->old_value, 'new' => $h->new_value,
                'reason' => \Illuminate\Support\Str::after($h->reason, 'CLEANING: '),
                'by' => $userNames[$h->by_user] ?? '—', 'at' => optional($h->at)->format('d M Y H:i'),
            ])->all();
        $notes = \App\Models\DocumentNote::with('user:id,name')
            ->where('document_id', $document->id)->orderBy('id')->get()
            ->map(fn ($n) => ['by' => $n->user->name ?? '—',
                'at' => $n->created_at?->format('d M Y H:i'), 'body' => $n->body])->all();

        return view('entri.print', [
            'doc' => $document, 'sample' => $document->sample, 'qv' => $document->qversion,
            'wil' => Wilayah::pluck('name', 'code'), 'blocks' => $out, 'warn' => $warn,
            'corrections' => $corrections, 'notes' => $notes,
            'derived' => DerivedField::where('qversion_id', $document->qversion_id)->orderBy('order')->get()
                ->map(fn ($d) => ['label' => $d->label, 'value' => $ctx[$d->var_name] ?? null])->all(),
        ]);
    }

    public function saveAnswer(Request $request, Document $document)
    {
        $this->assertActiveCycle($request, $document);
        abort_if($document->status === 'bersih', 409, 'Dokumen sudah dikunci (bersih)');
        abort_if($document->cycleLocked(), 423, 'Siklus sudah dikunci (selesai).');
        $data = $request->validate([
            'question_id' => 'required|integer',
            'value' => 'nullable|string',
            'art_index' => 'nullable|integer',
            'reason' => 'nullable|string',
        ]);
        $q = Question::find($data['question_id']);
        abort_unless($q, 400, 'Rincian tidak dikenal');
        $artIndex = max(0, min((int) ($data['art_index'] ?? 0), self::MAX_ROW));
        $value = trim($data['value'] ?? '');

        $a = Answer::where('document_id', $document->id)
            ->where('question_id', $q->id)->where('art_index', $artIndex)->first();
        $old = $a->raw_value ?? '';
        if ($value !== $old) {
            if ($a) {
                $a->update(['raw_value' => $value]);
            } else {
                Answer::create([
                    'document_id' => $document->id, 'question_id' => $q->id,
                    'art_index' => $artIndex, 'raw_value' => $value,
                ]);
            }
            AnswerHistory::create([
                'document_id' => $document->id, 'question_id' => $q->id, 'art_index' => $artIndex,
                'old_value' => $old, 'new_value' => $value,
                'by_user' => $request->user()->id, 'reason' => $data['reason'] ?? '', 'at' => now(),
            ]);
        }

        $sum = $this->engine->run($document);

        return response()->json([
            'ok' => true,
            'saved' => $value !== $old,
            'n_err' => $sum['errors'],
            'n_warn' => $sum['warnings'],
            'state' => $this->formState($document),
        ]);
    }

    /**
     * Setel Banyaknya ART (R301) lalu sesuaikan baris roster/list_art:
     * hapus isian untuk nomor ART di atas jumlah baru.
     */
    public function setArtCount(Request $request, Document $document)
    {
        $this->assertActiveCycle($request, $document);
        abort_if($document->status === 'bersih', 409, 'Dokumen sudah dikunci (bersih)');
        abort_if($document->cycleLocked(), 423, 'Siklus sudah dikunci (selesai).');
        $d = $request->validate(['count' => 'required|integer|min:0|max:'.self::MAX_ART]);
        $n = (int) $d['count'];

        $q = Question::whereIn('block_id', Block::where('qversion_id', $document->qversion_id)->pluck('id'))
            ->whereIn('var_name', self::ART_COUNT_VARS)->first();
        if ($q) {
            $a = Answer::firstOrNew(['document_id' => $document->id, 'question_id' => $q->id, 'art_index' => 0]);
            $old = $a->raw_value ?? '';
            $a->raw_value = (string) $n;
            $a->save();
            if ($old !== (string) $n) {
                AnswerHistory::create([
                    'document_id' => $document->id, 'question_id' => $q->id, 'art_index' => 0,
                    'old_value' => $old, 'new_value' => (string) $n,
                    'by_user' => $request->user()->id, 'reason' => 'setel jumlah ART', 'at' => now(),
                ]);
            }
        }

        // buang isian ART di atas jumlah baru
        $dropped = 0;
        foreach (Block::where('qversion_id', $document->qversion_id)->get() as $b) {
            $qids = $b->questions()->pluck('id');
            if ($qids->isEmpty()) {
                continue;
            }
            if ($b->repeat === 'art') {
                $dropped += Answer::where('document_id', $document->id)->whereIn('question_id', $qids)
                    ->where('art_index', '>', $n)->delete();
            } elseif ($b->repeat === 'list_art') {
                $dropped += Answer::where('document_id', $document->id)->whereIn('question_id', $qids)
                    ->where('art_index', '>=', Block::LIST_ART_FACTOR)
                    ->whereRaw('art_index % '.Block::LIST_ART_FACTOR.' > ?', [$n])->delete();
            }
        }

        $sum = $this->engine->run($document);

        return response()->json([
            'ok' => true, 'dropped' => $dropped,
            'n_err' => $sum['errors'], 'n_warn' => $sum['warnings'],
            'state' => $this->formState($document),
        ]);
    }

    public function finalize(Request $request, Document $document)
    {
        $this->assertActiveCycle($request, $document);
        abort_if($document->cycleLocked(), 423, 'Siklus sudah dikunci (selesai).');
        $sum = $this->engine->run($document);
        if ($sum['errors'] > 0) {
            return back()->withErrors(['finalisasi' => "Masih ada {$sum['errors']} error — perbaiki dulu."]);
        }
        if (config('susenas.bukti_kunjungan') && config('susenas.bukti_kunjungan_wajib')
            && ! \App\Models\VisitPhoto::where('sample_id', $document->sample_id)->exists()) {
            return back()->withErrors(['finalisasi' => 'Unggah minimal 1 foto bukti kunjungan dulu.']);
        }
        $wasReturned = $document->status === 'dikembalikan';
        $document->update([
            'status' => 'selesai', 'submitted_at' => now(),
            'return_reason' => null, 'returned_by' => null, 'returned_at' => null,
        ]);
        $sample = $document->sample;
        if (in_array($sample->status, ['belum', 'proses'], true)) {
            $sample->update(['status' => 'selesai']);
        }
        AuditLog::record('document', $document->id, $wasReturned ? 'finalisasi-ulang' : 'finalisasi',
            "{$sum['warnings']} warning tersisa", $request->user()->id);
        return redirect()->route('entri.form', $document);
    }

    // ------------------------------------------------------------------

    /**
     * Jumlah baris roster ART yang ditampilkan: maksimum dari Banyaknya ART
     * dan art_index tertinggi pada jawaban blok roster (bukan blok daftar).
     *
     * @param  array<int,true>  $rosterQids  question_id yang berada di blok repeat='art'
     */
    private function artCount(array $ctx, array $answersByKey, array $rosterQids): int
    {
        $n = 1;
        foreach (self::ART_COUNT_VARS as $v) {
            $ba = ExpressionEvaluator::toNum($ctx[$v] ?? null);
            if ($ba !== null) {
                $n = max($n, (int) $ba);
                break;
            }
        }
        foreach (array_keys($answersByKey) as $key) {
            [$qid, $idx] = explode(':', $key);
            if (isset($rosterQids[(int) $qid])) {
                $n = max($n, (int) $idx);
            }
        }
        return min($n, self::MAX_ART);
    }

    private function formState(Document $doc): array
    {
        $blocks = Block::where('qversion_id', $doc->qversion_id)->orderBy('order')->get();

        $answers = [];
        foreach (Answer::where('document_id', $doc->id)->get() as $a) {
            $answers["{$a->question_id}:{$a->art_index}"] = $a;
        }
        $ctx = $this->engine->buildContext($doc);
        $rosterQids = [];
        foreach ($blocks as $blk) {
            if ($blk->repeat === 'art') {
                foreach ($blk->questions as $q) {
                    $rosterQids[$q->id] = true;
                }
            }
        }
        $nArt = $this->artCount($ctx, $answers, $rosterQids);

        // var_name yang jadi "Banyaknya ART" di kuesioner ini (untuk tombol "Terapkan jumlah ART")
        $qVars = Question::whereIn('block_id', $blocks->pluck('id'))->pluck('var_name')->all();
        $hasRoster = $blocks->contains(fn ($b) => in_array($b->repeat, ['art', 'list_art'], true));
        $artVar = $hasRoster
            ? (collect(self::ART_COUNT_VARS)->first(fn ($v) => in_array($v, $qVars, true)))
            : null;

        $byVar = [];       // target_var => [entry, ...]   (row_index 0)
        $byCell = [];      // "var:rowIdx" => [entry, ...]  (row_index > 0)
        $docLevel = [];
        $nErr = $nWarn = 0;
        foreach (RuleResult::where('document_id', $doc->id)->get() as $r) {
            if ($r->status === 'ok') {
                continue;
            }
            if ($r->status === 'error') {
                $nErr++;
            } elseif ($r->status === 'warning') {
                $nWarn++;
            }
            $entry = ['status' => $r->status, 'message' => $r->message];
            if ($r->row_index > 0 && $r->target_var) {
                $byCell["{$r->target_var}:{$r->row_index}"][] = $entry;
            } elseif ($r->target_var) {
                $byVar[$r->target_var][] = $entry;
            } else {
                $docLevel[] = $entry;
            }
        }

        $outBlocks = [];
        $expFilled = 0;     // rincian yang seharusnya diisi & sudah terisi
        $expTotal = 0;      // rincian yang seharusnya diisi (tak ter-skip, di luar blok daftar)
        $missing = [];      // rincian ruta wajib yang masih kosong (dibatasi)
        $missingTotal = 0;
        foreach ($blocks as $b) {
            $cols = [];
            foreach ($b->questions as $q) {
                $skipped = false;
                if ($q->skip_expr && $b->repeat === 'ruta') {
                    try {
                        $skipped = ExpressionEvaluator::truthy($this->ev->evaluate($q->skip_expr, $ctx));
                    } catch (RuleException) {
                        $skipped = false;
                    }
                }
                $cols[] = [
                    'id' => $q->id, 'code' => $q->code_rincian, 'var' => $q->var_name,
                    'label' => $q->label, 'type' => $q->qtype, 'unit' => $q->unit,
                    'help' => $q->help_text, 'skipped' => $skipped,
                    'options' => $q->options->map(fn ($o) => ['code' => $o->code, 'label' => $o->label])->all(),
                    'value' => '', 'warnings' => $byVar[$q->var_name] ?? [],
                ];
            }

            $blk = ['code' => $b->code, 'title' => $b->title, 'repeat' => $b->repeat, 'n_err' => 0, 'n_warn' => 0];
            $tally = function (array $ws) use (&$blk) {
                foreach ($ws as $w) {
                    $w['status'] === 'error' ? $blk['n_err']++ : $blk['n_warn']++;
                }
            };
            foreach ($cols as $c) {
                $tally($c['warnings']);
            }
            if (in_array($b->repeat, ['art', 'list', 'list_art'], true)) {
                // 'art' = baris dinamis (1..banyak ART); 'list' = baris tetap (block_rows);
                // 'list_art' = tiap baris tetap × tiap ART (idx = row*1000 + no_art)
                if ($b->repeat === 'list') {
                    $rowDefs = $b->rows->map(fn ($r) => [
                        'idx' => $r->row_index, 'label' => $r->label,
                        'group' => $r->ref['group'] ?? null,
                    ])->all();
                } elseif ($b->repeat === 'list_art') {
                    $rowDefs = [];
                    foreach ($b->rows->sortBy('order') as $r) {
                        for ($a = 1; $a <= max(1, $nArt); $a++) {
                            $rowDefs[] = [
                                'idx' => \App\Models\Block::packRowArt($r->row_index, $a),
                                'label' => 'ART '.$a,
                                'group' => $a === 1 ? trim(($r->code ? $r->code.' ' : '').$r->label) : null,
                            ];
                        }
                    }
                } else {
                    $rowDefs = array_map(fn ($i) => ['idx' => $i, 'label' => null, 'group' => null], range(1, max(1, $nArt)));
                }
                $rows = [];
                $lastGroup = null;
                foreach ($rowDefs as $rd) {
                    if (($rd['group'] ?? null) && $rd['group'] !== $lastGroup) {
                        $rows[] = ['group_header' => $rd['group']];
                        $lastGroup = $rd['group'];
                    }
                    $cells = [];
                    $rowWarn = [];
                    foreach ($b->questions as $q) {
                        $a = $answers["{$q->id}:{$rd['idx']}"] ?? null;
                        $w = $byCell["{$q->var_name}:{$rd['idx']}"] ?? [];
                        $rowWarn = array_merge($rowWarn, $w);
                        $tally($w);
                        $cells[] = ['qid' => $q->id, 'var' => $q->var_name,
                            'value' => $a->raw_value ?? '', 'warnings' => $w];
                    }
                    $rows[] = ['idx' => $rd['idx'], 'label' => $rd['label'],
                        'cells' => $cells, 'warnings' => $rowWarn];
                    // roster ART masuk hitungan kelengkapan; blok daftar (komoditas) opsional
                    if ($b->repeat === 'art') {
                        foreach ($cells as $cell) {
                            $expTotal++;
                            if (trim((string) $cell['value']) !== '') {
                                $expFilled++;
                            }
                        }
                    }
                }
                $blk['columns'] = $cols;
                $blk['rows'] = $rows;
            } else {
                foreach ($cols as &$c) {
                    $a = $answers["{$c['id']}:0"] ?? null;
                    $c['value'] = $a->raw_value ?? '';
                    if (! $c['skipped']) {
                        $expTotal++;
                        if (trim((string) $c['value']) !== '') {
                            $expFilled++;
                        } else {
                            $missingTotal++;
                            if (count($missing) < 60) {
                                $missing[] = ['block' => $b->code, 'var' => $c['var'],
                                    'code' => $c['code'], 'label' => $c['label']];
                            }
                        }
                    }
                }
                unset($c);
                $blk['questions'] = $cols;
            }
            $outBlocks[] = $blk;
        }

        $derived = DerivedField::where('qversion_id', $doc->qversion_id)->orderBy('order')->get()
            ->map(fn ($d) => ['label' => $d->label, 'var' => $d->var_name, 'value' => $ctx[$d->var_name] ?? null])
            ->all();

        // rekap agregat per kelompok (agregat yang disaring lewat nilai referensi baris)
        $rekap = \App\Models\Aggregate::where('qversion_id', $doc->qversion_id)
            ->where('filter_ref', '!=', '')->orderBy('order')->get()
            ->map(fn ($a) => ['label' => $a->label, 'var' => $a->name, 'value' => $ctx[$a->name] ?? null])
            ->all();

        return [
            'blocks' => $outBlocks, 'doc_level' => $docLevel, 'derived' => $derived, 'rekap' => $rekap,
            'n_err' => $nErr, 'n_warn' => $nWarn, 'art_var' => $artVar,
            'progress' => [
                'filled' => $expFilled, 'expected' => $expTotal,
                'pct' => $expTotal ? (int) round(100 * $expFilled / $expTotal) : 100,
                'missing' => $missing, 'missing_total' => $missingTotal,
            ],
        ];
    }
}

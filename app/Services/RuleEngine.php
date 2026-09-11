<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\Block;
use App\Models\DerivedField;
use App\Models\Document;
use App\Models\Parameter;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\RuleResult;
use App\Models\ValidationRule;
use Illuminate\Support\Facades\DB;

class RuleEngine
{
    /** Nama fungsi helper roster yang tersedia di ekspresi. */
    public const ROSTER_FUNCS = [
        'roster', 'roster_count', 'roster_all', 'roster_any', 'roster_first', 'roster_max', 'roster_sum',
    ];

    /** repeatByVar dokumen terakhir yang di-buildContext, untuk membangun fungsi roster. */
    private array $lastRepeat = [];

    public function __construct(private ExpressionEvaluator $ev) {}

    /**
     * Fungsi helper roster untuk ekspresi (referensi antar-ART / agregat kode bebas).
     *
     * @param  array<string,array<int,mixed>>  $repeatByVar
     * @return array<string,callable>
     */
    public static function rosterFuncs(array $repeatByVar): array
    {
        $vals = fn (string $var) => $repeatByVar[$var] ?? [];
        $num = fn ($v) => ExpressionEvaluator::toNum($v);

        return [
            // nilai <var> untuk ART ke-<idx> (untuk pointer seperti R503/R504)
            'roster' => function ($var, $idx) use ($vals, $num) {
                $i = (int) ($num($idx) ?? 0);
                return $vals((string) $var)[$i] ?? null;
            },
            // jumlah ART yang <var> == <code>
            'roster_count' => function ($var, $code) use ($vals, $num) {
                $c = $num($code);
                return (float) count(array_filter($vals((string) $var), fn ($v) => $num($v) === $c));
            },
            // TRUE jika SEMUA ART terisi <var> == <code>
            'roster_all' => function ($var, $code) use ($vals, $num) {
                $nb = array_filter($vals((string) $var), fn ($v) => ! ExpressionEvaluator::isBlank($v));
                if (! $nb) {
                    return false;
                }
                $c = $num($code);
                foreach ($nb as $v) {
                    if ($num($v) !== $c) {
                        return false;
                    }
                }
                return true;
            },
            // TRUE jika ADA minimal satu ART dengan <var> == <code>
            'roster_any' => function ($var, $code) use ($vals, $num) {
                $c = $num($code);
                foreach ($vals((string) $var) as $v) {
                    if ($num($v) === $c) {
                        return true;
                    }
                }
                return false;
            },
            // nilai <valvar> untuk ART pertama yang <keyvar> == <keycode>
            'roster_first' => function ($valvar, $keyvar, $keycode) use ($repeatByVar, $num) {
                $key = $repeatByVar[(string) $keyvar] ?? [];
                $val = $repeatByVar[(string) $valvar] ?? [];
                $c = $num($keycode);
                foreach ($key as $idx => $v) {
                    if ($num($v) === $c) {
                        return $val[$idx] ?? null;
                    }
                }
                return null;
            },
            'roster_max' => fn ($var) => (function () use ($vals, $num, $var) {
                $n = array_filter(array_map($num, $vals((string) $var)), fn ($x) => $x !== null);
                return $n ? max($n) : 0.0;
            })(),
            'roster_sum' => fn ($var) => array_sum(array_map(
                fn ($v) => ExpressionEvaluator::toNum($v) ?? 0.0, $vals((string) $var)
            )),
        ];
    }

    /**
     * Susun konteks variabel untuk satu dokumen: jawaban ruta, parameter,
     * agregat roster (<var>__n, __sum, __count1, __count2, __min, __max),
     * lalu variabel turunan.
     *
     * @return array<string,mixed>
     */
    public function buildContext(Document $doc): array
    {
        $qvId = $doc->qversion_id;
        $ctx = [];

        /** @var array<int,object{q:Question,repeat:string}> */
        $questions = Question::query()
            ->join('blocks', 'blocks.id', '=', 'questions.block_id')
            ->where('blocks.qversion_id', $qvId)
            ->select('questions.*', 'blocks.repeat as blk_repeat', 'blocks.code as blk_code')
            ->get();

        $qById = [];
        $repeatById = [];        // question_id => repeat ('ruta'|'art'|'list')
        $blockCodeByVar = [];    // var_name => block code (untuk blok berulang)
        $rosterVars = [];
        foreach ($questions as $q) {
            $qById[$q->id] = $q;
            $repeatById[$q->id] = $q->blk_repeat;
            if ($q->blk_repeat !== 'ruta') {
                $blockCodeByVar[$q->var_name] = $q->blk_code;
            }
            if ($q->blk_repeat === 'art') {
                $rosterVars[$q->var_name] = true;
            }
        }

        // var_name => [row_index => value]  untuk semua blok berulang
        $repeatByVar = [];

        $answers = Answer::where('document_id', $doc->id)->orderBy('art_index')->get();
        foreach ($answers as $a) {
            $q = $qById[$a->question_id] ?? null;
            if (!$q) {
                continue;
            }
            $val = ($a->clean_value !== null && $a->clean_value !== '') ? $a->clean_value : $a->raw_value;
            if (($repeatById[$q->id] ?? 'ruta') === 'ruta') {
                $ctx[$q->var_name] = $val;
            } else {
                $repeatByVar[$q->var_name][(int) $a->art_index] = $val;
            }
        }

        foreach (Parameter::where('qversion_id', $qvId)->get() as $p) {
            $ctx[$p->name] = $p->value;
        }

        // --- metadata sampel (untuk cek identitas Blok I / II) ---
        if ($s = $doc->sample) {
            $ctx['sampel_wilayah'] = $s->wilayah_code;
            $ctx['sampel_bs'] = $s->no_bs;
            $ctx['sampel_ruta'] = $s->no_ruta;
            $ctx['sampel_krt'] = $s->krt_name;
        }

        // --- data referensi per baris (block_rows.ref): [blockCode => [rowIdx => refArray]] ---
        $rowRefs = [];
        $rowsQ = \App\Models\BlockRow::query()
            ->join('blocks', 'blocks.id', '=', 'block_rows.block_id')
            ->where('blocks.qversion_id', $qvId)
            ->select('block_rows.*', 'blocks.code as blk_code')->get();
        foreach ($rowsQ as $r) {
            $rowRefs[$r->blk_code][(int) $r->row_index] = $r->ref ?? [];
        }

        // --- agregat roster (blok repeat='art') ---
        $artTerisi = 0;
        foreach (array_keys($rosterVars) as $var) {
            $vals = array_values($repeatByVar[$var] ?? []);
            $nonblank = array_values(array_filter($vals, fn ($v) => !ExpressionEvaluator::isBlank($v)));
            $nums = array_map(fn ($v) => ExpressionEvaluator::toNum($v) ?? 0.0, $nonblank);
            $ctx["{$var}__n"] = (float) count($nonblank);
            $ctx["{$var}__sum"] = array_sum($nums);
            $ctx["{$var}__count1"] = (float) count(array_filter($nonblank, fn ($v) => ExpressionEvaluator::toNum($v) === 1.0));
            $ctx["{$var}__count2"] = (float) count(array_filter($nonblank, fn ($v) => ExpressionEvaluator::toNum($v) === 2.0));
            $ctx["{$var}__max"] = $nums ? max($nums) : 0.0;
            $ctx["{$var}__min"] = $nums ? min($nums) : 0.0;
            $artTerisi = max($artTerisi, count($nonblank));
        }
        $ctx['art_terisi'] = (float) $artTerisi;
        $this->lastRepeat = $repeatByVar;
        $rf = self::rosterFuncs($repeatByVar);

        // --- agregat cek-silang (NERACA): agregasi terkelompok atas blok berulang ---
        foreach (\App\Models\Aggregate::where('qversion_id', $qvId)->orderBy('order')->get() as $agg) {
            $ctx[$agg->name] = $this->computeAggregate($agg, $repeatByVar, $rowRefs[$agg->block_code] ?? []);
        }

        foreach (DerivedField::where('qversion_id', $qvId)->orderBy('order')->get() as $d) {
            try {
                $ctx[$d->var_name] = $this->ev->evaluate($d->expr, $ctx, $rf);
            } catch (RuleException) {
                $ctx[$d->var_name] = null;
            }
        }

        return $ctx;
    }

    /**
     * Agregasi terkelompok atas satu blok berulang.
     * Mengalikan value_var[row] hanya untuk baris yang filter-nya lolos.
     *
     * @param  array<string,array<int,mixed>>  $repeatByVar
     */
    private function computeAggregate(\App\Models\Aggregate $agg, array $repeatByVar, array $rowRefs): float
    {
        $values = $repeatByVar[$agg->value_var] ?? [];
        $filter = $repeatByVar[$agg->filter_var] ?? [];
        $codes = array_filter(array_map('trim', explode(',', $agg->filter_val)), fn ($x) => $x !== '');

        $picked = [];
        foreach ($values as $rowIdx => $val) {
            if (ExpressionEvaluator::isBlank($val)) {
                continue;
            }
            $fv = $filter[$rowIdx] ?? null;
            $pass = match ($agg->filter_op) {
                'eq' => (string) ($fv ?? '') === ($codes[0] ?? ''),
                'ne' => (string) ($fv ?? '') !== ($codes[0] ?? ''),
                'in' => in_array((string) ($fv ?? ''), $codes, true),
                default => true, // 'any'
            };
            // penyaring berdasarkan nilai referensi baris (mis. kelompok komoditas)
            if ($pass && $agg->filter_ref !== '') {
                $pass = (string) ($rowRefs[$rowIdx][$agg->filter_ref] ?? '') === $agg->filter_ref_val;
            }
            if (!$pass) {
                continue;
            }
            $num = ExpressionEvaluator::toNum($val) ?? 0.0;
            if ($agg->op === 'weighted_sum' && $agg->weight_ref !== '') {
                $num *= ExpressionEvaluator::toNum($rowRefs[$rowIdx][$agg->weight_ref] ?? null) ?? 0.0;
            }
            $picked[] = $num;
        }

        return match ($agg->op) {
            'count' => (float) count($picked),
            'max' => $picked ? max($picked) : 0.0,
            'min' => $picked ? min($picked) : 0.0,
            default => array_sum($picked), // 'sum' & 'weighted_sum'
        };
    }

    /**
     * Hitung ulang seluruh RuleResult untuk dokumen.
     *
     * @return array{errors:int,warnings:int,context:array<string,mixed>}
     */
    public function run(Document $doc): array
    {
        $ctx = $this->buildContext($doc);
        $rf = self::rosterFuncs($this->lastRepeat);
        $rules = ValidationRule::where('qversion_id', $doc->qversion_id)
            ->where('active', true)->orderBy('code')->get();

        RuleResult::where('document_id', $doc->id)->delete();

        $nErr = $nWarn = 0;
        $rows = [];
        $rowCtxCache = [];   // blockCode => [rowIdx => rowCtx]

        foreach ($rules as $rule) {
            // aturan per-baris: dievaluasi untuk setiap baris blok row_block
            if ($rule->row_block !== '') {
                $rowCtxCache[$rule->row_block] ??= $this->rowContexts($doc, $rule->row_block, $ctx);
                foreach ($rowCtxCache[$rule->row_block] as $rowIdx => $rc) {
                    [$status, $msg] = $this->evalRule($rule, $rc, $rf);
                    if ($status === 'error') {
                        $nErr++;
                    } elseif ($status === 'warning') {
                        $nWarn++;
                    }
                    $rows[] = [
                        'document_id' => $doc->id, 'rule_id' => $rule->id,
                        'status' => $status, 'message' => $msg,
                        'target_var' => $rule->target_var, 'row_index' => $rowIdx,
                        'computed_at' => now(),
                    ];
                }
                continue;
            }

            [$status, $msg] = $this->evalRule($rule, $ctx, $rf);
            if ($status === 'error') {
                $nErr++;
            } elseif ($status === 'warning') {
                $nWarn++;
            }
            $rows[] = [
                'document_id' => $doc->id, 'rule_id' => $rule->id,
                'status' => $status, 'message' => $msg,
                'target_var' => $rule->target_var, 'row_index' => 0,
                'computed_at' => now(),
            ];
        }
        if ($rows) {
            RuleResult::insert($rows);
        }

        // snapshot datar untuk tabulasi & ekspor (skalar saja)
        $scalars = array_filter($ctx, fn ($v) => $v === null || is_scalar($v));
        $scalars['_n_err'] = $nErr;
        $scalars['_n_warn'] = $nWarn;
        \App\Models\DocumentSnapshot::updateOrCreate(
            ['document_id' => $doc->id],
            ['data' => $scalars, 'updated_at' => now()],
        );

        return ['errors' => $nErr, 'warnings' => $nWarn, 'context' => $ctx];
    }

    /** @return array{0:string,1:string}  [status, message] */
    private function evalRule(ValidationRule $rule, array $ctx, array $funcs = []): array
    {
        try {
            $fired = ExpressionEvaluator::truthy($this->ev->evaluate($rule->expr, $ctx, $funcs));
            return $fired ? [$rule->severity, $this->interpolate($rule->message, $ctx, $funcs)] : ['ok', ''];
        } catch (RuleException $e) {
            return ['error', "[rule rusak] {$e->getMessage()}"];
        }
    }

    /**
     * Sisipkan nilai ke dalam pesan aturan:
     *   {nama_var}   → nilai variabel konteks (angka diformat gaya Indonesia)
     *   {=ekspresi}  → hasil ekspresi (mis. {=n(iv1_nilai_beli)/n(iv1_kuantitas)})
     * Token tak dikenal dibiarkan apa adanya.
     */
    private function interpolate(string $msg, array $ctx, array $funcs): string
    {
        if (strpos($msg, '{') === false) {
            return $msg;
        }

        return preg_replace_callback('/\{([^{}]+)\}/', function ($m) use ($ctx, $funcs) {
            $token = trim($m[1]);
            if (str_starts_with($token, '=')) {
                try {
                    return self::fmtNum($this->ev->evaluate(substr($token, 1), $ctx, $funcs));
                } catch (RuleException) {
                    return '?';
                }
            }
            if (array_key_exists($token, $ctx)) {
                return self::fmtNum($ctx[$token]);
            }
            return $m[0];
        }, $msg);
    }

    private static function fmtNum(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '?';
        }
        if (is_bool($v)) {
            return $v ? 'ya' : 'tidak';
        }
        if (is_numeric($v)) {
            $n = (float) $v;
            $dec = abs($n - round($n)) < 0.005 ? 0 : 2;
            return number_format($n, $dec, ',', '.');
        }
        return (string) $v;
    }

    /**
     * Konteks lokal per baris untuk aturan per-baris:
     * konteks dokumen + nilai kolom baris itu + ref_<key> dari block_rows.ref.
     *
     * @return array<int,array<string,mixed>>  rowIndex => context
     */
    /**
     * Pratinjau dampak sebuah ekspresi (kandidat aturan) terhadap semua dokumen
     * satu versi kuesioner — tanpa menulis apa pun. Untuk kalibrasi ambang.
     *
     * @return array{docs:int,truncated:bool,fire_docs:int,fire_rows:int,eval_errors:int,samples:array<int,array<string,mixed>>}
     */
    public function previewExpression(string $expr, string $rowBlock = '', ?int $qversionId = null): array
    {
        $qvId = $qversionId ?: QuestionnaireVersion::query()->orderByDesc('id')->value('id');
        $base = Document::where('qversion_id', $qvId);
        $total = (clone $base)->count();
        $docs = $base->orderBy('id')->limit(500)->get();

        $fireDocs = $fireRows = $evalErrors = 0;
        $samples = [];
        foreach ($docs as $doc) {
            $ctx = $this->buildContext($doc);
            $rf = self::rosterFuncs($this->lastRepeat);
            $hit = false;
            $rowsHit = [];
            try {
                if ($rowBlock !== '') {
                    foreach ($this->rowContexts($doc, $rowBlock, $ctx) as $rowIdx => $rc) {
                        if (ExpressionEvaluator::truthy($this->ev->evaluate($expr, $rc, $rf))) {
                            $hit = true;
                            $fireRows++;
                            $rowsHit[] = $rowIdx;
                        }
                    }
                } elseif (ExpressionEvaluator::truthy($this->ev->evaluate($expr, $ctx, $rf))) {
                    $hit = true;
                    $fireRows++;
                }
            } catch (\Throwable) {
                $evalErrors++;
                continue;
            }
            if ($hit) {
                $fireDocs++;
                if (count($samples) < 15) {
                    $s = $doc->sample;
                    $samples[] = [
                        'doc_id' => $doc->id,
                        'wilayah' => $s?->wilayah_code,
                        'ruta' => $s ? "BS {$s->no_bs} / Ruta {$s->no_ruta}" : '',
                        'rows' => $rowsHit,
                    ];
                }
            }
        }

        return [
            'docs' => $docs->count(), 'truncated' => $total > $docs->count(),
            'fire_docs' => $fireDocs, 'fire_rows' => $fireRows, 'eval_errors' => $evalErrors,
            'samples' => $samples,
        ];
    }

    private function rowContexts(Document $doc, string $blockCode, array $baseCtx): array
    {
        $block = Block::where('qversion_id', $doc->qversion_id)->where('code', $blockCode)->first();
        if (!$block) {
            return [];
        }
        $qIds = $block->questions->pluck('var_name', 'id');
        $refByRow = $block->rows->keyBy('row_index');

        $byRow = [];   // rowIdx => [var => value]
        foreach (Answer::where('document_id', $doc->id)
            ->whereIn('question_id', $qIds->keys())->get() as $a) {
            $byRow[(int) $a->art_index][$qIds[$a->question_id]] =
                ($a->clean_value !== null && $a->clean_value !== '') ? $a->clean_value : $a->raw_value;
        }

        $isListArt = $block->repeat === 'list_art';

        $out = [];
        foreach ($byRow as $rowIdx => $vals) {
            // list_art: art_index = row_index_komoditas * 1000 + no_art
            [$comRow, $artNo] = $isListArt ? Block::unpackRowArt((int) $rowIdx) : [(int) $rowIdx, 0];
            $refRow = $refByRow[$comRow] ?? null;

            $rc = $baseCtx;
            foreach ($vals as $var => $v) {
                $rc[$var] = $v;
            }
            foreach (($refRow->ref ?? []) as $k => $v) {
                $rc["ref_{$k}"] = $v;
            }
            $rc['baris_label'] = $refRow->label ?? (string) $comRow;
            $rc['_row_index'] = (float) $comRow;
            if ($isListArt) {
                $rc['_art_no'] = (float) $artNo;
            }
            $out[$rowIdx] = $rc;
        }
        return $out;
    }
}

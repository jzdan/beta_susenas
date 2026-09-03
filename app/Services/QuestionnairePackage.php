<?php

namespace App\Services;

use App\Models\Aggregate;
use App\Models\Block;
use App\Models\BlockRow;
use App\Models\DerivedField;
use App\Models\Parameter;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\ResponseOption;
use App\Models\ValidationRule;
use Illuminate\Support\Facades\DB;

// ExpressionEvaluator & RuleEngine di namespace yang sama (App\Services)

/**
 * Ekspor/impor satu versi kuesioner (blok, rincian, opsi, parameter, aturan,
 * agregat, nilai turunan, baris tetap) sebagai paket JSON yang dapat dipindahkan
 * antar-instansi — mendukung alur "kuesioner tiap semester = versi baru (data)".
 */
class QuestionnairePackage
{
    public const FORMAT = 'susenas.qversion/1';

    public function __construct(private ExpressionEvaluator $ev) {}

    /**
     * Periksa paket sebelum impor: format, kode/var duplikat, sintaks ekspresi
     * (aturan, nilai turunan, agregat, skip), referensi blok.
     *
     * @param  array<string,mixed>  $pkg
     * @return array{errors:string[],warnings:string[]}
     */
    public function lint(array $pkg): array
    {
        $errors = [];
        $warnings = [];

        if (($pkg['format'] ?? null) !== self::FORMAT) {
            return ['errors' => ["Format paket tidak dikenal (harus '".self::FORMAT."')."], 'warnings' => []];
        }
        if (trim((string) ($pkg['code'] ?? '')) === '') {
            $errors[] = 'Kode versi kosong.';
        }

        $blockCodes = [];
        $vars = [];
        $funcs = RuleEngine::ROSTER_FUNCS;
        $chk = function (string $expr, string $where) use (&$errors, $funcs) {
            $expr = trim($expr);
            if ($expr === '') {
                return;
            }
            if ($err = $this->ev->checkSyntax($expr, $funcs)) {
                $errors[] = "{$where}: sintaks — {$err}";
            }
        };

        foreach ($pkg['blocks'] ?? [] as $bi => $b) {
            $code = $b['code'] ?? '';
            if ($code === '') {
                $errors[] = "Blok #{$bi} tanpa kode.";
            } elseif (isset($blockCodes[$code])) {
                $errors[] = "Kode blok ganda: {$code}.";
            }
            $blockCodes[$code] = true;

            foreach ($b['questions'] ?? [] as $qi => $q) {
                $v = $q['var_name'] ?? '';
                if ($v === '') {
                    $errors[] = "Blok {$code} rincian #{$qi} tanpa var_name.";
                } elseif (isset($vars[$v])) {
                    $errors[] = "var_name ganda: {$v}.";
                }
                $vars[$v] = true;
                $chk($q['skip_expr'] ?? '', "skip_expr {$v}");
            }
        }

        foreach ($pkg['derived'] ?? [] as $d) {
            $chk($d['expr'] ?? '', "turunan {$d['var_name']}");
        }
        foreach ($pkg['rules'] ?? [] as $r) {
            $chk($r['expr'] ?? '', "aturan {$r['code']}");
            $rb = trim((string) ($r['row_block'] ?? ''));
            if ($rb !== '' && ! isset($blockCodes[$rb])) {
                $errors[] = "Aturan {$r['code']}: row_block '{$rb}' tidak ada di paket.";
            }
        }
        foreach ($pkg['aggregates'] ?? [] as $a) {
            $bc = trim((string) ($a['block_code'] ?? ''));
            if ($bc !== '' && ! isset($blockCodes[$bc])) {
                $warnings[] = "Agregat {$a['name']}: block_code '{$bc}' tidak ada di paket.";
            }
        }

        if (QuestionnaireVersion::where('code', trim((string) ($pkg['code'] ?? '')))->exists()) {
            $warnings[] = "Kode '{$pkg['code']}' sudah ada — impor perlu --code berbeda.";
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** @return array<string,mixed> */
    public function export(QuestionnaireVersion $qv): array
    {
        $qv->loadMissing([
            'blocks.questions.options', 'blocks.rows',
            'parameters', 'derived', 'aggregates', 'rules',
        ]);

        return [
            'format' => self::FORMAT,
            'exported_at' => now()->toIso8601String(),
            'code' => $qv->code,
            'effective_date' => $qv->effective_date,
            'blocks' => $qv->blocks->map(fn (Block $b) => [
                'code' => $b->code, 'title' => $b->title, 'order' => $b->order, 'repeat' => $b->repeat,
                'questions' => $b->questions->map(fn (Question $q) => [
                    'code_rincian' => $q->code_rincian, 'var_name' => $q->var_name, 'label' => $q->label,
                    'qtype' => $q->qtype, 'unit' => $q->unit, 'order' => $q->order,
                    'skip_expr' => $q->skip_expr, 'help_text' => $q->help_text,
                    'options' => $q->options->map(fn (ResponseOption $o) => [
                        'code' => $o->code, 'label' => $o->label, 'order' => $o->order,
                    ])->values()->all(),
                ])->values()->all(),
                'rows' => $b->rows->map(fn (BlockRow $r) => [
                    'row_index' => $r->row_index, 'code' => $r->code, 'label' => $r->label,
                    'order' => $r->order, 'ref' => $r->ref,
                ])->values()->all(),
            ])->values()->all(),
            'parameters' => $qv->parameters->map(fn (Parameter $p) => [
                'name' => $p->name, 'value' => $p->value, 'note' => $p->note,
            ])->values()->all(),
            'derived' => $qv->derived->map(fn (DerivedField $d) => [
                'var_name' => $d->var_name, 'label' => $d->label, 'expr' => $d->expr, 'order' => $d->order,
            ])->values()->all(),
            'aggregates' => $qv->aggregates->map(fn (Aggregate $a) => collect($a->getAttributes())
                ->only((new Aggregate)->getFillable())->except('qversion_id')->all())->values()->all(),
            'rules' => $qv->rules->map(fn (ValidationRule $r) => [
                'code' => $r->code, 'scope' => $r->scope, 'row_block' => $r->row_block,
                'target_var' => $r->target_var, 'expr' => $r->expr, 'severity' => $r->severity,
                'message' => $r->message, 'active' => (bool) $r->active,
            ])->values()->all(),
        ];
    }

    /**
     * Buat versi kuesioner baru dari paket. Melempar \InvalidArgumentException
     * bila format tak dikenal atau code bentrok.
     *
     * @param  array<string,mixed>  $pkg
     */
    public function import(array $pkg, int $cycleId, ?string $code = null): QuestionnaireVersion
    {
        if (($pkg['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException('Format paket tidak dikenal.');
        }
        $code = trim($code ?: ($pkg['code'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException('Kode versi kosong.');
        }
        if (QuestionnaireVersion::where('code', $code)->exists()) {
            throw new \InvalidArgumentException("Kode versi '{$code}' sudah ada.");
        }

        return DB::transaction(function () use ($pkg, $cycleId, $code) {
            $qv = QuestionnaireVersion::create([
                'cycle_id' => $cycleId, 'code' => $code,
                'effective_date' => $pkg['effective_date'] ?? now()->toDateString(),
            ]);

            foreach ($pkg['blocks'] ?? [] as $b) {
                $block = Block::create([
                    'qversion_id' => $qv->id, 'code' => $b['code'], 'title' => $b['title'] ?? '',
                    'order' => $b['order'] ?? 0, 'repeat' => $b['repeat'] ?? 'ruta',
                ]);
                foreach ($b['questions'] ?? [] as $q) {
                    $question = Question::create([
                        'block_id' => $block->id, 'code_rincian' => $q['code_rincian'] ?? '',
                        'var_name' => $q['var_name'], 'label' => $q['label'] ?? '',
                        'qtype' => $q['qtype'] ?? 'text', 'unit' => $q['unit'] ?? null,
                        'order' => $q['order'] ?? 0, 'skip_expr' => $q['skip_expr'] ?? null,
                        'help_text' => $q['help_text'] ?? null,
                    ]);
                    foreach ($q['options'] ?? [] as $o) {
                        ResponseOption::create([
                            'question_id' => $question->id, 'code' => $o['code'],
                            'label' => $o['label'] ?? '', 'order' => $o['order'] ?? 0,
                        ]);
                    }
                }
                foreach ($b['rows'] ?? [] as $r) {
                    BlockRow::create([
                        'block_id' => $block->id, 'row_index' => $r['row_index'],
                        'code' => $r['code'] ?? '', 'label' => $r['label'] ?? '',
                        'order' => $r['order'] ?? 0, 'ref' => $r['ref'] ?? null,
                    ]);
                }
            }
            foreach ($pkg['parameters'] ?? [] as $p) {
                Parameter::create(['qversion_id' => $qv->id, 'name' => $p['name'],
                    'value' => $p['value'] ?? '', 'note' => $p['note'] ?? null]);
            }
            foreach ($pkg['derived'] ?? [] as $d) {
                DerivedField::create(['qversion_id' => $qv->id, 'var_name' => $d['var_name'],
                    'label' => $d['label'] ?? '', 'expr' => $d['expr'] ?? '', 'order' => $d['order'] ?? 0]);
            }
            foreach ($pkg['aggregates'] ?? [] as $a) {
                Aggregate::create(array_merge(
                    collect($a)->only((new Aggregate)->getFillable())->all(),
                    ['qversion_id' => $qv->id],
                ));
            }
            foreach ($pkg['rules'] ?? [] as $r) {
                ValidationRule::create([
                    'qversion_id' => $qv->id, 'code' => $r['code'], 'scope' => $r['scope'] ?? 'dokumen',
                    'row_block' => $r['row_block'] ?? '', 'target_var' => $r['target_var'] ?? '',
                    'expr' => $r['expr'] ?? '', 'severity' => $r['severity'] ?? 'warning',
                    'message' => $r['message'] ?? '', 'active' => $r['active'] ?? true,
                ]);
            }

            return $qv;
        });
    }
}

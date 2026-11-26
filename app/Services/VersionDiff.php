<?php

namespace App\Services;

use App\Models\QuestionnaireVersion;
use App\Models\SurveyCycle;

/**
 * Bandingkan dua versi kuesioner (Inc 160): apa yang ditambah, dihapus, dan diubah di blok,
 * rincian, pilihan jawaban, baris tetap, aturan, parameter, agregat, dan nilai turunan.
 * Kunci pencocokan memakai kode/nama (bukan id), jadi versi hasil salinan bisa dibandingkan.
 */
class VersionDiff
{
    /** Kelompok yang dibandingkan: kunci => label. */
    public const SECTIONS = [
        'blocks' => 'Blok', 'questions' => 'Rincian', 'options' => 'Pilihan jawaban', 'rows' => 'Baris tetap',
        'rules' => 'Aturan validasi', 'parameters' => 'Parameter', 'aggregates' => 'Agregat', 'derived' => 'Nilai turunan',
    ];

    public const FIELD_LABELS = [
        'title' => 'Judul', 'repeat' => 'Jenis blok', 'order' => 'Urutan', 'harga_kalori' => 'Blok harga & kalori',
        'block' => 'Blok', 'code_rincian' => 'Kode', 'label' => 'Label', 'qtype' => 'Tipe', 'unit' => 'Satuan',
        'skip_expr' => 'Syarat lewati', 'help_text' => 'Bantuan', 'code' => 'Kode', 'ref' => 'Data patokan',
        'expr' => 'Rumus', 'message' => 'Pesan', 'severity' => 'Tingkat', 'scope' => 'Jenis', 'row_block' => 'Blok per baris',
        'target_var' => 'Sasaran', 'note' => 'Keterangan', 'active' => 'Menyala', 'value' => 'Nilai',
        'block_code' => 'Blok', 'value_var' => 'Variabel nilai', 'op' => 'Operasi', 'weight_ref' => 'Bobot',
        'filter_var' => 'Saring variabel', 'filter_op' => 'Cara saring', 'filter_val' => 'Nilai saring',
        'filter_ref' => 'Saring patokan', 'filter_ref_val' => 'Nilai patokan',
    ];

    /** Versi pembanding bawaan: versi terbaru dari siklus survei & instrumen sama yang periodenya lebih dulu. */
    public static function previousOf(QuestionnaireVersion $qv): ?QuestionnaireVersion
    {
        $c = $qv->cycle_id ? SurveyCycle::find($qv->cycle_id) : null;
        if (! $c) {
            return null;
        }
        $prevCycleIds = SurveyCycle::where('survey', $c->survey)->where('instrument', $c->instrument)
            ->where('period', '<', $c->period)->orderByDesc('period')->pluck('id');
        foreach ($prevCycleIds as $cid) {
            $v = QuestionnaireVersion::where('cycle_id', $cid)->orderByDesc('id')->first();
            if ($v && $v->blocks()->exists()) {
                return $v;
            }
        }

        return null;
    }

    /** @return array<string,array{added:array,removed:array,changed:array}> */
    public function compare(QuestionnaireVersion $old, QuestionnaireVersion $new): array
    {
        $a = $this->flatten($old);
        $b = $this->flatten($new);
        $out = [];
        foreach (array_keys(self::SECTIONS) as $sec) {
            $added = array_values(array_diff_key($b[$sec], $a[$sec]));
            $removed = array_values(array_diff_key($a[$sec], $b[$sec]));
            $changed = [];
            foreach (array_intersect_key($b[$sec], $a[$sec]) as $key => $item) {
                $fields = [];
                foreach ($item['f'] as $f => $v) {
                    $ov = $a[$sec][$key]['f'][$f] ?? '';
                    if ($f !== 'order' && $ov !== $v) {
                        $fields[$f] = [$ov, $v];
                    }
                }
                if ($fields) {
                    $changed[] = ['name' => $item['name'], 'fields' => $fields];
                }
            }
            $out[$sec] = ['added' => $added, 'removed' => $removed, 'changed' => $changed];
        }

        return $out;
    }

    public static function totals(array $diff): array
    {
        $t = ['added' => 0, 'removed' => 0, 'changed' => 0];
        foreach ($diff as $s) {
            foreach ($t as $k => $_) {
                $t[$k] += count($s[$k]);
            }
        }

        return $t;
    }

    /** @return array<string,array<string,array{name:string,f:array<string,string>}>> */
    private function flatten(QuestionnaireVersion $qv): array
    {
        $s = fn ($v) => is_bool($v) ? ($v ? 'ya' : 'tidak') : (is_array($v) ? self::refText($v) : trim((string) ($v ?? '')));
        $out = array_fill_keys(array_keys(self::SECTIONS), []);
        foreach ($qv->blocks()->with(['questions.options', 'rows'])->orderBy('order')->get() as $b) {
            $out['blocks'][$b->code] = ['name' => "Blok {$b->code} — {$b->title}", 'f' => [
                'title' => $s($b->title), 'repeat' => $s($b->repeat), 'harga_kalori' => $s((bool) $b->harga_kalori)]];
            foreach ($b->questions as $q) {
                $out['questions'][$q->var_name] = ['name' => "{$q->var_name} ({$q->code_rincian}) — ".\Illuminate\Support\Str::limit($q->label, 70),
                    'f' => ['block' => $s($b->code), 'code_rincian' => $s($q->code_rincian), 'label' => $s($q->label),
                        'qtype' => $s($q->qtype), 'unit' => $s($q->unit), 'skip_expr' => $s($q->skip_expr), 'help_text' => $s($q->help_text)]];
                foreach ($q->options as $o) {
                    $out['options']["{$q->var_name}={$o->code}"] = ['name' => "{$q->var_name} = {$o->code}", 'f' => ['label' => $s($o->label)]];
                }
            }
            foreach ($b->rows as $r) {
                $k = $b->code.'#'.($r->code !== null && $r->code !== '' ? $r->code : $r->row_index);
                $out['rows'][$k] = ['name' => "Blok {$b->code} · ".trim(($r->code ? $r->code.' ' : '').$r->label),
                    'f' => ['label' => $s($r->label), 'ref' => $s((array) $r->ref)]];
            }
        }
        foreach ($qv->rules()->get() as $r) {
            $out['rules'][$r->code] = ['name' => "{$r->code} — ".\Illuminate\Support\Str::limit($r->message, 70), 'f' => [
                'expr' => $s($r->expr), 'message' => $s($r->message), 'severity' => $s($r->severity), 'scope' => $s($r->scope),
                'row_block' => $s($r->row_block), 'target_var' => $s($r->target_var), 'note' => $s($r->note), 'active' => $s((bool) $r->active)]];
        }
        foreach ($qv->parameters()->get() as $p) {
            $out['parameters'][$p->name] = ['name' => $p->name, 'f' => ['value' => $s($p->value), 'note' => $s($p->note)]];
        }
        foreach ($qv->aggregates()->get() as $g) {
            $f = [];
            foreach (['label', 'block_code', 'value_var', 'op', 'weight_ref', 'filter_var', 'filter_op', 'filter_val', 'filter_ref', 'filter_ref_val'] as $k) {
                $f[$k] = $s($g->{$k});
            }
            $out['aggregates'][$g->name] = ['name' => "{$g->name} — {$g->label}", 'f' => $f];
        }
        foreach ($qv->derived()->get() as $d) {
            $out['derived'][$d->var_name] = ['name' => "{$d->var_name} — {$d->label}", 'f' => ['label' => $s($d->label), 'expr' => $s($d->expr)]];
        }

        return $out;
    }

    private static function refText(array $ref): string
    {
        ksort($ref);

        return implode(', ', array_map(fn ($k, $v) => "{$k}=".(is_scalar($v) ? $v : json_encode($v)), array_keys($ref), $ref));
    }
}

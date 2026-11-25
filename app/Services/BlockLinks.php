<?php

namespace App\Services;

use App\Models\Aggregate;
use App\Models\Block;
use App\Models\DerivedField;
use App\Models\Parameter;
use App\Models\QuestionnaireVersion;
use App\Models\ValidationRule;

/**
 * Inc 155 — keterhubungan kuesioner ↔ aturan/neraca/turunan dalam satu versi.
 *
 * Nama di rumus dibaca dengan ExpressionEvaluator::referencedNames(), lalu dipetakan ke blok asal:
 *  - nama rincian (var_name) → bloknya (termasuk akhiran ringkasan daftar anggota: __n, __sum, …);
 *  - agregat → `block_code`-nya;
 *  - turunan → blok dari semua nama di rumusnya (turunan yang memakai turunan lain ditelusuri);
 *  - aturan per-baris → `row_block` + blok dari rumusnya.
 * Parameter & metadata sampel tidak punya blok. Hasil juga mencatat "dipakai oleh" (aturan/turunan
 * yang memakai suatu agregat, turunan, atau parameter).
 */
class BlockLinks
{
    private const ROSTER_SUFFIX = ['__n', '__sum', '__count1', '__count2', '__max', '__min'];

    public function __construct(private ExpressionEvaluator $ev) {}

    /**
     * @return array{
     *   rules: array<int, list<string>>, aggregates: array<int, list<string>>, derived: array<int, list<string>>,
     *   usedBy: array<string, list<string>>, byBlock: array<string, array{rules:int, aggregates:int, derived:int}>
     * }
     */
    public function for(QuestionnaireVersion $qv): array
    {
        $blocks = Block::where('qversion_id', $qv->id)->with('questions:id,block_id,var_name')->get();
        $nameBlocks = [];   // nama variabel => [kode blok => true]
        foreach ($blocks as $b) {
            foreach ($b->questions as $q) {
                $nameBlocks[$q->var_name][$b->code] = true;
                if ($b->repeat === 'art') {
                    foreach (self::ROSTER_SUFFIX as $sfx) {
                        $nameBlocks[$q->var_name.$sfx][$b->code] = true;
                    }
                }
            }
        }
        $aggregates = Aggregate::where('qversion_id', $qv->id)->get();
        foreach ($aggregates as $a) {
            if ($a->block_code !== '') {
                $nameBlocks[$a->name][$a->block_code] = true;
            }
        }
        $derived = DerivedField::where('qversion_id', $qv->id)->get()->keyBy('var_name');
        $params = Parameter::where('qversion_id', $qv->id)->pluck('name')->flip();
        $rules = ValidationRule::where('qversion_id', $qv->id)->get();

        $names = function (string $expr): array {
            try {
                return $this->ev->referencedNames($expr);
            } catch (\Throwable) {
                return [];   // rumus rusak dilaporkan di tempat lain (VersionChecker)
            }
        };

        // blok turunan: telusuri turunan → turunan, aman dari putaran
        $memo = [];
        $resolve = function (string $name, array $seen = []) use (&$resolve, &$memo, $nameBlocks, $derived, $names): array {
            if (isset($memo[$name])) {
                return $memo[$name];
            }
            $out = $nameBlocks[$name] ?? [];
            if (isset($derived[$name]) && ! isset($seen[$name])) {
                foreach ($names((string) $derived[$name]->expr) as $n) {
                    $out += $resolve($n, $seen + [$name => true]);
                }
            }

            return $memo[$name] = $out;
        };
        $blocksOf = function (string $expr) use ($names, $resolve): array {
            $out = [];
            foreach ($names($expr) as $n) {
                $out += $resolve($n);
            }

            return $out;
        };

        $order = $blocks->sortBy('order')->pluck('code')->flip();
        $sorted = fn (array $set) => collect(array_keys($set))->sortBy(fn ($c) => $order[$c] ?? 999)->values()->all();

        $res = ['rules' => [], 'aggregates' => [], 'derived' => [], 'usedBy' => [], 'byBlock' => []];
        foreach ($blocks as $b) {
            $res['byBlock'][$b->code] = ['rules' => 0, 'aggregates' => 0, 'derived' => 0];
        }
        $use = function (string $who, array $refs) use (&$res, $derived, $aggregates, $params) {
            foreach ($refs as $n) {
                if (isset($derived[$n]) || $aggregates->contains('name', $n) || isset($params[$n])) {
                    $res['usedBy'][$n][] = $who;
                }
            }
        };

        foreach ($aggregates as $a) {
            $set = $a->block_code !== '' ? [$a->block_code => true] : [];
            $res['aggregates'][$a->id] = $sorted($set);
        }
        foreach ($derived as $d) {
            $res['derived'][$d->id] = $sorted($blocksOf((string) $d->expr));
            $use('turunan '.$d->var_name, $names((string) $d->expr));
        }
        foreach ($rules as $r) {
            $set = $blocksOf((string) $r->expr);
            if ($r->row_block !== '') {
                $set[$r->row_block] = true;
            }
            $res['rules'][$r->id] = $sorted($set);
            $use('aturan '.$r->code, $names((string) $r->expr));
        }

        foreach (['rules', 'aggregates', 'derived'] as $kind) {
            foreach ($res[$kind] as $codes) {
                foreach ($codes as $c) {
                    if (isset($res['byBlock'][$c])) {
                        $res['byBlock'][$c][$kind]++;
                    }
                }
            }
        }
        foreach ($res['usedBy'] as &$list) {
            $list = array_values(array_unique($list));
        }

        return $res;
    }
}

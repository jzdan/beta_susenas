<?php

namespace App\Services;

use App\Models\Parameter;
use App\Models\ValidationRule;

/**
 * Contoh kasus uji per aturan (Inc 159). Satu kasus = isian kecil "var=nilai; var2=nilai" +
 * harapan (menyala / tidak). Dijalankan di atas parameter versi kuesioner saja — tidak
 * menyentuh sampel mana pun — supaya admin tahu rumus masih benar setelah diubah.
 */
class RuleTester
{
    public function __construct(private ExpressionEvaluator $ev) {}

    /** "a=1; b = dua; c=" → ['a'=>'1','b'=>'dua','c'=>'']. Baris/titik koma memisahkan. */
    public static function parse(string $isian): array
    {
        $out = [];
        foreach (preg_split('/[;\n]+/', $isian) as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $part, 2));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) {
                $out[$k] = trim($v, "\"'");
            }
        }

        return $out;
    }

    /** Bersihkan masukan formulir menjadi daftar kasus yang disimpan. */
    public static function normalize(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $isian = trim((string) ($r['isian'] ?? ''));
            $name = trim((string) ($r['name'] ?? ''));
            if ($isian === '' && $name === '') {
                continue;
            }
            $out[] = ['name' => $name !== '' ? $name : 'Kasus '.(count($out) + 1),
                'isian' => $isian, 'expect' => ($r['expect'] ?? '1') === '1'];
        }

        return $out;
    }

    /**
     * @param  array<int,array{name:string,isian:string,expect:bool}>  $cases
     * @return array{total:int,pass:int,results:array<int,array{name:string,expect:bool,fired:?bool,pass:bool,error:?string}>}
     */
    public function run(int $qvId, string $expr, string $rowBlock, array $cases): array
    {
        static $params = [];
        $params[$qvId] ??= Parameter::where('qversion_id', $qvId)->pluck('value', 'name')->all();
        $base = $params[$qvId];
        if ($rowBlock !== '') {
            $base += ['_row_index' => 1.0, 'baris_label' => 'contoh'];
        }
        $funcs = RuleEngine::rosterFuncs([]);

        $results = [];
        $pass = 0;
        foreach ($cases as $c) {
            $fired = null;
            $error = null;
            try {
                $fired = ExpressionEvaluator::truthy($this->ev->evaluate($expr, self::parse($c['isian'] ?? '') + $base, $funcs));
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $ok = $error === null && $fired === (bool) ($c['expect'] ?? true);
            $pass += $ok ? 1 : 0;
            $results[] = ['name' => $c['name'] ?? '', 'expect' => (bool) ($c['expect'] ?? true),
                'fired' => $fired, 'pass' => $ok, 'error' => $error];
        }

        return ['total' => count($cases), 'pass' => $pass, 'results' => $results];
    }

    public function forRule(ValidationRule $r, ?string $expr = null): array
    {
        return $this->run($r->qversion_id, $expr ?? $r->expr, (string) $r->row_block, $r->test_cases ?? []);
    }
}

<?php

namespace App\Services;

/**
 * Evaluator ekspresi aman untuk aturan validasi / skip / turunan.
 *
 * Ekspresi ditulis sebagai string dan disimpan di basis data. Evaluator ini
 * adalah tokenizer + parser recursive-descent + tree-walker yang HANYA
 * mengizinkan: angka, string, nama (dari konteks), operator aritmetika &
 * perbandingan, and/or/not, daftar, dan pemanggilan fungsi ke daftar putih.
 * Tidak ada akses properti/method, indexing, atau `eval()` PHP — jadi eksekusi
 * kode sembarang tidak mungkin.
 *
 * Konvensi aturan: ekspresi bernilai TRUE artinya "ada masalah".
 * Semantik disamakan dengan prototipe Python (koersi angka, koma desimal, dll).
 */
class ExpressionEvaluator
{
    /** Fungsi yang menerima NAMA variabel (bukan nilai) pada posisi argumen tertentu. */
    private const ID_ARG_POSITIONS = [
        'roster' => [0], 'roster_count' => [0], 'roster_all' => [0], 'roster_any' => [0],
        'roster_first' => [0, 1], 'roster_max' => [0], 'roster_sum' => [0],
    ];

    /** @var array<string,mixed> */
    private array $ctx = [];

    /** Fungsi tambahan per-evaluasi (mis. helper roster), nama => callable. */
    private array $extraFuncs = [];

    /** @var list<array{type:string,value:mixed}> */
    private array $toks = [];

    private int $pos = 0;

    // ------------------------------------------------------------------ API

    public function evaluate(string $expr, array $ctx, array $extraFuncs = []): mixed
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }
        $this->ctx = $ctx;
        $this->extraFuncs = $extraFuncs;
        $this->toks = $this->tokenize($expr);
        $this->pos = 0;
        $node = $this->parseOr();
        if ($this->peek()['type'] !== 'eof') {
            throw new RuleException('token berlebih: ' . json_encode($this->peek()['value']));
        }
        return $this->ev($node);
    }

    /**
     * Kembalikan pesan error bila ekspresi tak valid, atau string kosong.
     * $extraFuncNames: nama fungsi tambahan yang sah (mis. helper roster).
     */
    public function checkSyntax(string $expr, array $extraFuncNames = []): string
    {
        $stubs = [];
        foreach ($extraFuncNames as $n) {
            $stubs[$n] = fn (...$x) => null;
        }
        try {
            $this->evaluate($expr, [], $stubs);
            return '';
        } catch (RuleException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Nama variabel yang dirujuk ekspresi — termasuk argumen-nama fungsi roster_*.
     * Hanya mengurai (tidak mengevaluasi). Dipakai pengecek versi (Inc 145) untuk
     * menemukan aturan yang merujuk variabel yang tidak ada (aturan seperti itu
     * diam-diam tidak pernah menyala, karena nama tak dikenal bernilai null).
     *
     * @return list<string>
     *
     * @throws RuleException bila sintaks rusak
     */
    public function referencedNames(string $expr): array
    {
        $expr = trim($expr);
        if ($expr === '') {
            return [];
        }
        $this->toks = $this->tokenize($expr);
        $this->pos = 0;
        $node = $this->parseOr();
        if ($this->peek()['type'] !== 'eof') {
            throw new RuleException('token berlebih: ' . json_encode($this->peek()['value']));
        }
        $out = [];
        $this->collectNames($node, $out);

        return array_keys($out);
    }

    /** @param array<string,true> $out */
    private function collectNames(array $node, array &$out): void
    {
        switch ($node['k']) {
            case 'name':
                $out[$node['name']] = true;
                return;
            case 'list':
                foreach ($node['items'] as $x) {
                    $this->collectNames($x, $out);
                }
                return;
            case 'not':
            case 'neg':
            case 'pos':
                $this->collectNames($node['v'], $out);
                return;
            case 'and':
            case 'or':
            case 'bin':
                $this->collectNames($node['l'], $out);
                $this->collectNames($node['r'], $out);
                return;
            case 'cmp':
                $this->collectNames($node['first'], $out);
                foreach ($node['rest'] as $x) {
                    $this->collectNames($x, $out);
                }
                return;
            case 'call':
                foreach ($node['args'] as $x) {
                    $this->collectNames($x, $out);
                }
                return;
        }
    }

    // -------------------------------------------------------- helper nilai

    public static function toNum(mixed $v): ?float
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = str_replace(',', '.', trim((string) $v));
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        return (float) $s;
    }

    public static function isBlank(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '');
    }

    public static function truthy(mixed $v): bool
    {
        if ($v === null) {
            return false;
        }
        if (is_string($v)) {
            return !in_array(trim($v), ['', '0'], true);
        }
        if (is_int($v) || is_float($v)) {
            return $v != 0;
        }
        return (bool) $v;
    }

    private static function n(mixed $v): float
    {
        return self::toNum($v) ?? 0.0;
    }

    // -------------------------------------------------------- daftar fungsi

    private function callFunc(string $name, array $a): mixed
    {
        if (isset($this->extraFuncs[$name])) {
            return ($this->extraFuncs[$name])(...$a);
        }
        switch ($name) {
            case 'blank':        return self::isBlank($a[0] ?? null);
            case 'filled':       return !self::isBlank($a[0] ?? null);
            case 'num':          return self::toNum($a[0] ?? null);
            case 'n':            return self::n($a[0] ?? null);
            case 'coalesce':
                foreach ($a as $x) {
                    if (!self::isBlank($x)) {
                        return $x;
                    }
                }
                return null;
            case 'abs':          return abs(self::n($a[0] ?? null));
            case 'round':        return round(self::n($a[0] ?? null), (int) self::n($a[1] ?? 0));
            case 'floor':        return (float) floor(self::n($a[0] ?? null));
            case 'min':          return $a ? min(array_map([self::class, 'n'], $a)) : 0.0;
            case 'max':          return $a ? max(array_map([self::class, 'n'], $a)) : 0.0;
            case 'sum':          return array_sum(array_map([self::class, 'n'], $a));
            case 'any_filled':
                foreach ($a as $x) {
                    if (!self::isBlank($x)) {
                        return true;
                    }
                }
                return false;
            case 'all_filled':
                foreach ($a as $x) {
                    if (self::isBlank($x)) {
                        return false;
                    }
                }
                return true;
            case 'count_filled':
                return (float) count(array_filter($a, fn ($x) => !self::isBlank($x)));
            case 'ymd':
                // "2026-03-10" / "10/03/2026" / "20260310" -> 20260310 (untuk perbandingan tanggal)
                $digits = preg_replace('/\D/', '', (string) ($a[0] ?? ''));
                if (strlen($digits) === 8 && str_starts_with($digits, '20')) {
                    return (float) $digits;                       // sudah YYYYMMDD
                }
                if (strlen($digits) === 8) {
                    return (float) (substr($digits, 4, 4).substr($digits, 2, 2).substr($digits, 0, 2));
                }
                return self::isBlank($a[0] ?? null) ? null : 0.0;
        }
        throw new RuleException("fungsi tidak dikenal: {$name}");
    }

    // --------------------------------------------------------- tokenizer

    private function tokenize(string $s): array
    {
        $toks = [];
        $len = strlen($s);
        $i = 0;
        $ops = ['**', '==', '!=', '<=', '>=', '<', '>', '+', '-', '*', '/', '%', '(', ')', ',', '[', ']'];
        while ($i < $len) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            // string
            if ($c === '"' || $c === "'") {
                $q = $c;
                $j = $i + 1;
                $buf = '';
                while ($j < $len && $s[$j] !== $q) {
                    if ($s[$j] === '\\' && $j + 1 < $len) {
                        $buf .= $s[$j + 1];
                        $j += 2;
                        continue;
                    }
                    $buf .= $s[$j];
                    $j++;
                }
                if ($j >= $len) {
                    throw new RuleException('string tidak ditutup');
                }
                $toks[] = ['type' => 'str', 'value' => $buf];
                $i = $j + 1;
                continue;
            }
            // number
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($s[$i + 1]))) {
                $j = $i;
                while ($j < $len && (ctype_digit($s[$j]) || $s[$j] === '.')) {
                    $j++;
                }
                $toks[] = ['type' => 'num', 'value' => (float) substr($s, $i, $j - $i)];
                $i = $j;
                continue;
            }
            // identifier / keyword
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $len && (ctype_alnum($s[$j]) || $s[$j] === '_')) {
                    $j++;
                }
                $word = substr($s, $i, $j - $i);
                $i = $j;
                if (in_array($word, ['and', 'or', 'not'], true)) {
                    $toks[] = ['type' => $word, 'value' => $word];
                } elseif ($word === 'True' || $word === 'true') {
                    $toks[] = ['type' => 'const', 'value' => true];
                } elseif ($word === 'False' || $word === 'false') {
                    $toks[] = ['type' => 'const', 'value' => false];
                } elseif ($word === 'None' || $word === 'null') {
                    $toks[] = ['type' => 'const', 'value' => null];
                } else {
                    $toks[] = ['type' => 'name', 'value' => $word];
                }
                continue;
            }
            // operator
            $matched = null;
            foreach ($ops as $op) {
                if (substr($s, $i, strlen($op)) === $op) {
                    $matched = $op;
                    break;
                }
            }
            if ($matched === null) {
                throw new RuleException("karakter tak dikenal: '{$c}'");
            }
            $toks[] = ['type' => 'op', 'value' => $matched];
            $i += strlen($matched);
        }
        $toks[] = ['type' => 'eof', 'value' => null];
        return $toks;
    }

    // --------------------------------------------------------- parser

    private function peek(): array
    {
        return $this->toks[$this->pos];
    }

    private function next(): array
    {
        return $this->toks[$this->pos++];
    }

    private function expectOp(string $op): void
    {
        $t = $this->next();
        if ($t['type'] !== 'op' || $t['value'] !== $op) {
            throw new RuleException("diharapkan '{$op}'");
        }
    }

    private function isOp(string $op): bool
    {
        $t = $this->peek();
        return $t['type'] === 'op' && $t['value'] === $op;
    }

    private function parseOr(): array
    {
        $node = $this->parseAnd();
        while ($this->peek()['type'] === 'or') {
            $this->next();
            $node = ['k' => 'or', 'l' => $node, 'r' => $this->parseAnd()];
        }
        return $node;
    }

    private function parseAnd(): array
    {
        $node = $this->parseNot();
        while ($this->peek()['type'] === 'and') {
            $this->next();
            $node = ['k' => 'and', 'l' => $node, 'r' => $this->parseNot()];
        }
        return $node;
    }

    private function parseNot(): array
    {
        if ($this->peek()['type'] === 'not') {
            $this->next();
            return ['k' => 'not', 'v' => $this->parseNot()];
        }
        return $this->parseCompare();
    }

    private function parseCompare(): array
    {
        $node = $this->parseAdd();
        $ops = [];
        $rhs = [];
        while (in_array($this->peek()['value'], ['==', '!=', '<', '<=', '>', '>='], true)
               && $this->peek()['type'] === 'op') {
            $ops[] = $this->next()['value'];
            $rhs[] = $this->parseAdd();
        }
        if ($ops) {
            return ['k' => 'cmp', 'first' => $node, 'ops' => $ops, 'rest' => $rhs];
        }
        return $node;
    }

    private function parseAdd(): array
    {
        $node = $this->parseMul();
        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->next()['value'];
            $node = ['k' => 'bin', 'op' => $op, 'l' => $node, 'r' => $this->parseMul()];
        }
        return $node;
    }

    private function parseMul(): array
    {
        $node = $this->parsePow();
        while ($this->isOp('*') || $this->isOp('/') || $this->isOp('%')) {
            $op = $this->next()['value'];
            $node = ['k' => 'bin', 'op' => $op, 'l' => $node, 'r' => $this->parsePow()];
        }
        return $node;
    }

    private function parsePow(): array
    {
        $node = $this->parseUnary();
        if ($this->isOp('**')) {
            $this->next();
            $node = ['k' => 'bin', 'op' => '**', 'l' => $node, 'r' => $this->parsePow()];
        }
        return $node;
    }

    private function parseUnary(): array
    {
        if ($this->isOp('-')) {
            $this->next();
            return ['k' => 'neg', 'v' => $this->parseUnary()];
        }
        if ($this->isOp('+')) {
            $this->next();
            return ['k' => 'pos', 'v' => $this->parseUnary()];
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): array
    {
        $t = $this->peek();
        if ($t['type'] === 'num' || $t['type'] === 'str' || $t['type'] === 'const') {
            $this->next();
            return ['k' => 'lit', 'v' => $t['value']];
        }
        if ($this->isOp('(')) {
            $this->next();
            $node = $this->parseOr();
            $this->expectOp(')');
            return $node;
        }
        if ($this->isOp('[')) {
            $this->next();
            $items = [];
            if (!$this->isOp(']')) {
                $items[] = $this->parseOr();
                while ($this->isOp(',')) {
                    $this->next();
                    $items[] = $this->parseOr();
                }
            }
            $this->expectOp(']');
            return ['k' => 'list', 'items' => $items];
        }
        if ($t['type'] === 'name') {
            $this->next();
            if ($this->isOp('(')) {
                $this->next();
                $args = [];
                if (!$this->isOp(')')) {
                    $args[] = $this->parseOr();
                    while ($this->isOp(',')) {
                        $this->next();
                        $args[] = $this->parseOr();
                    }
                }
                $this->expectOp(')');
                return ['k' => 'call', 'name' => $t['value'], 'args' => $args];
            }
            return ['k' => 'name', 'name' => $t['value']];
        }
        throw new RuleException('ekspresi tidak valid di dekat ' . json_encode($t['value']));
    }

    // --------------------------------------------------------- evaluator

    private function ev(array $node): mixed
    {
        switch ($node['k']) {
            case 'lit':
                return $node['v'];
            case 'name':
                return $this->ctx[$node['name']] ?? null;
            case 'list':
                return array_map(fn ($x) => $this->ev($x), $node['items']);
            case 'not':
                return !self::truthy($this->ev($node['v']));
            case 'neg':
                return -self::n($this->ev($node['v']));
            case 'pos':
                return self::n($this->ev($node['v']));
            case 'and':
                return self::truthy($this->ev($node['l'])) && self::truthy($this->ev($node['r']));
            case 'or':
                return self::truthy($this->ev($node['l'])) || self::truthy($this->ev($node['r']));
            case 'bin':
                return $this->binop($node['op'], self::n($this->ev($node['l'])), self::n($this->ev($node['r'])));
            case 'cmp':
                $left = $this->ev($node['first']);
                foreach ($node['ops'] as $idx => $op) {
                    $right = $this->ev($node['rest'][$idx]);
                    if (!$this->compare($op, $left, $right)) {
                        return false;
                    }
                    $left = $right;
                }
                return true;
            case 'call':
                // Beberapa fungsi (roster_*) menerima NAMA variabel pada posisi tertentu:
                // arg berupa Name diteruskan sebagai string, bukan dievaluasi.
                $idPos = self::ID_ARG_POSITIONS[$node['name']] ?? [];
                $args = [];
                foreach ($node['args'] as $i => $an) {
                    $args[] = (in_array($i, $idPos, true) && $an['k'] === 'name')
                        ? $an['name']
                        : $this->ev($an);
                }
                return $this->callFunc($node['name'], $args);
        }
        throw new RuleException('node tidak dikenal');
    }

    private function binop(string $op, float $a, float $b): float
    {
        return match ($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
            '/' => $b == 0.0 ? 0.0 : $a / $b,
            '%' => $b == 0.0 ? 0.0 : fmod($a, $b),
            '**' => $a ** $b,
        };
    }

    private function compare(string $op, mixed $a, mixed $b): bool
    {
        if ($op === '==' || $op === '!=') {
            $na = self::toNum($a);
            $nb = self::toNum($b);
            if ($na !== null && $nb !== null) {
                return $op === '==' ? $na == $nb : $na != $nb;
            }
            $sa = $a === null ? '' : (string) $a;
            $sb = $b === null ? '' : (string) $b;
            return $op === '==' ? $sa === $sb : $sa !== $sb;
        }
        $na = self::toNum($a);
        $nb = self::toNum($b);
        if ($na === null || $nb === null) {
            return false;
        }
        return match ($op) {
            '<' => $na < $nb,
            '<=' => $na <= $nb,
            '>' => $na > $nb,
            '>=' => $na >= $nb,
        };
    }
}

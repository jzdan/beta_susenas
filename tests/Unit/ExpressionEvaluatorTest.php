<?php

namespace Tests\Unit;

use App\Services\ExpressionEvaluator;
use App\Services\RuleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExpressionEvaluatorTest extends TestCase
{
    private ExpressionEvaluator $ev;

    protected function setUp(): void
    {
        $this->ev = new ExpressionEvaluator();
    }

    #[DataProvider('correctnessCases')]
    public function test_evaluates_correctly(string $expr, array $ctx, mixed $expected): void
    {
        $got = $this->ev->evaluate($expr, $ctx);
        if (is_float($expected)) {
            $this->assertEqualsWithDelta($expected, $got, 0.001, $expr);
        } else {
            $this->assertSame($expected, $got, $expr);
        }
    }

    public static function correctnessCases(): array
    {
        return [
            'or + blank' => ['n(banyak_art) == 0 or blank(kode_wilayah)', ['banyak_art' => '0', 'kode_wilayah' => ''], true],
            'and + skip filled' => ['n(sekolah) == 1 and (blank(spp) or n(spp) == 0)', ['sekolah' => '1', 'spp' => ''], true],
            'and + spp filled -> false' => ['n(sekolah) == 1 and (blank(spp) or n(spp) == 0)', ['sekolah' => '1', 'spp' => '300000'], false],
            'round + div + max' => ['round(p / max(n(a), 1), 0)', ['p' => '1000000', 'a' => '4'], 250000.0],
            'chained compare true' => ['1 < 2 < 3', [], true],
            'chained compare false' => ['1 < 5 < 3', [], false],
            'arithmetic precedence' => ['n(a) + n(b) * 2', ['a' => '10', 'b' => '3'], 16.0],
            'comma decimal string equals number' => ['"1,5" == 1.5', [], true],
            'not with text' => ['not blank(x)', ['x' => 'abc'], true],
            'div by zero -> 0' => ['n(a) / n(b)', ['a' => '5', 'b' => '0'], 0.0],
            'missing name is null-ish' => ['blank(tidak_ada)', [], true],
            'weighted style sum' => ['sum(n(a), n(b), n(c))', ['a' => '1', 'b' => '2', 'c' => '3'], 6.0],
            'ymd iso to int' => ['ymd(x)', ['x' => '2026-03-10'], 20260310.0],
            'ymd compares dates' => ['ymd(a) < ymd(b)', ['a' => '2026-03-05', 'b' => '2026-11-02'], true],
            'ymd reversed' => ['ymd(a) < ymd(b)', ['a' => '2026-11-02', 'b' => '2026-03-05'], false],
            'ymd blank -> null-ish' => ['blank(ymd(x))', ['x' => ''], true],
        ];
    }

    #[DataProvider('dangerousCases')]
    public function test_rejects_dangerous_expressions(string $expr): void
    {
        $this->assertNotSame('', $this->ev->checkSyntax($expr), "harus ditolak: {$expr}");
    }

    public static function dangerousCases(): array
    {
        return [
            ['phpinfo()'],
            ["system('id')"],
            ['__import__("os")'],
            ['x.foo'],
            ['x.bar()'],
            ['a["b"]'],
            ['1; 2'],
            ['`whoami`'],
            ['file_get_contents("/etc/passwd")'],
        ];
    }

    public function test_check_syntax_passes_valid(): void
    {
        $this->assertSame('', $this->ev->checkSyntax('n(a) > 0 and filled(b)'));
    }

    public function test_unknown_function_throws(): void
    {
        $this->expectException(RuleException::class);
        $this->ev->evaluate('definitely_not_a_func(1)', []);
    }
}

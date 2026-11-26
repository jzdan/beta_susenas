<?php

namespace App\Services;

use App\Models\Aggregate;
use App\Models\Block;
use App\Models\BlockRow;
use App\Models\DerivedField;
use App\Models\Parameter;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\ValidationRule;

/**
 * Pengecek keutuhan satu versi kuesioner (Inc 145) — dijalankan sebelum versi diaktifkan.
 *
 * Mencari rujukan yang putus: aturan/agregat yang menunjuk blok yang tidak ada,
 * ekspresi yang merujuk variabel yang tidak ada (aturan seperti itu diam-diam tidak
 * pernah menyala), parameter sistem yang menunjuk variabel yang tidak ada, dsb.
 * Daftar nama yang "tersedia" meniru RuleEngine::buildContext() & rowContexts().
 */
class VersionChecker
{
    /** Nama tambahan yang selalu ada di konteks aturan tingkat dokumen. */
    private const DOC_CTX = ['sampel_wilayah', 'sampel_bs', 'sampel_ruta', 'sampel_krt', 'art_terisi'];

    /** Nama tambahan di konteks aturan per-baris. */
    private const ROW_CTX = ['baris_label', '_row_index', '_art_no'];

    /** Akhiran agregat otomatis per variabel roster ART. */
    private const ROSTER_SUFFIX = ['__n', '__sum', '__count1', '__count2', '__max', '__min'];

    public function __construct(private ExpressionEvaluator $ev) {}

    /** @return array{errors:list<string>,warnings:list<string>} */
    public function check(QuestionnaireVersion $qv): array
    {
        $errors = [];
        $warnings = [];

        $blocks = Block::where('qversion_id', $qv->id)->with('questions')->get()->keyBy('code');
        $varsByBlock = $blocks->map(fn (Block $b) => $b->questions->pluck('var_name')->all());
        $refKeysByBlock = [];
        foreach (BlockRow::whereIn('block_id', $blocks->pluck('id'))->get() as $r) {
            $code = $blocks->firstWhere('id', $r->block_id)?->code;
            foreach (array_keys((array) $r->ref) as $k) {
                $refKeysByBlock[$code][$k] = true;
            }
        }
        $aggregates = Aggregate::where('qversion_id', $qv->id)->get();
        $derived = DerivedField::where('qversion_id', $qv->id)->get();
        $params = Parameter::where('qversion_id', $qv->id)->get();

        // --- semua nama yang tersedia di konteks aturan tingkat dokumen ---
        $known = array_fill_keys(self::DOC_CTX, true);
        foreach ($blocks as $b) {
            foreach ($b->questions as $q) {
                // var blok berulang hanya sah sbg argumen roster_*(...) — tetap diterima di sini
                $known[$q->var_name] = true;
                if ($b->repeat === 'art') {
                    foreach (self::ROSTER_SUFFIX as $sfx) {
                        $known[$q->var_name.$sfx] = true;
                    }
                }
            }
        }
        foreach ($params as $p) {
            $known[$p->name] = true;
        }
        foreach ($aggregates as $a) {
            $known[$a->name] = true;
        }
        foreach ($derived as $d) {
            $known[$d->var_name] = true;
        }

        $unknown = function (string $expr, array $extra = []) use ($known): array {
            try {
                $names = $this->ev->referencedNames($expr);
            } catch (RuleException) {
                return [];   // sintaks rusak dilaporkan terpisah
            }

            return array_values(array_filter($names, fn ($n) => ! isset($known[$n]) && ! isset($extra[$n])));
        };

        // --- aturan aktif ---
        foreach (ValidationRule::where('qversion_id', $qv->id)->where('active', true)->orderBy('code')->get() as $r) {
            if ($err = $this->ev->checkSyntax($r->expr, RuleEngine::ROSTER_FUNCS)) {
                $errors[] = "Aturan {$r->code}: sintaks ekspresi rusak — {$err}.";
                continue;
            }
            $extra = [];
            if ($r->row_block !== '') {
                if (! $blocks->has($r->row_block)) {
                    $errors[] = "Aturan {$r->code}: menunjuk blok \"{$r->row_block}\" yang tidak ada di versi ini.";
                    continue;
                }
                $extra = array_fill_keys(self::ROW_CTX, true);
                foreach (array_keys($refKeysByBlock[$r->row_block] ?? []) as $k) {
                    $extra["ref_{$k}"] = true;
                }
            }
            $miss = $unknown($r->expr, $extra);
            // ref_<kunci> = data patokan per baris (boleh belum diisi) → cukup peringatan
            $refMiss = array_values(array_filter($miss, fn ($n) => str_starts_with($n, 'ref_')));
            $varMiss = array_values(array_diff($miss, $refMiss));
            if ($varMiss) {
                $errors[] = "Aturan {$r->code}: merujuk variabel yang tidak ada — ".implode(', ', $varMiss)
                    .' (aturan ini tidak akan pernah menyala).';
            }
            if ($refMiss) {
                $warnings[] = "Aturan {$r->code}: data referensi ".implode(', ', $refMiss)
                    ." belum diisi untuk baris mana pun di Blok {$r->row_block} — aturan belum akan menyala.";
            }
        }

        // --- agregat (NERACA) ---
        foreach ($aggregates as $a) {
            if ($a->block_code === '' || ! $blocks->has($a->block_code)) {
                $errors[] = "Agregat {$a->name}: menunjuk blok \"{$a->block_code}\" yang tidak ada di versi ini.";
                continue;
            }
            $bv = $varsByBlock[$a->block_code];
            if (! in_array($a->value_var, $bv, true)) {
                $errors[] = "Agregat {$a->name}: kolom nilai \"{$a->value_var}\" tidak ada di Blok {$a->block_code}.";
            }
            if ($a->filter_var !== '' && $a->filter_var !== null && ! in_array($a->filter_var, $bv, true)) {
                $errors[] = "Agregat {$a->name}: kolom filter \"{$a->filter_var}\" tidak ada di Blok {$a->block_code}.";
            }
            foreach (['weight_ref' => 'ref pembobot', 'filter_ref' => 'ref penyaring'] as $col => $lbl) {
                $k = (string) $a->{$col};
                if ($k !== '' && ! isset($refKeysByBlock[$a->block_code][$k])) {
                    $warnings[] = "Agregat {$a->name}: {$lbl} \"{$k}\" tidak ada di data referensi baris Blok {$a->block_code}.";
                }
            }
        }

        // --- variabel turunan ---
        foreach ($derived as $d) {
            if ($err = $this->ev->checkSyntax($d->expr, RuleEngine::ROSTER_FUNCS)) {
                $errors[] = "Turunan {$d->var_name}: sintaks ekspresi rusak — {$err}.";
            } elseif ($miss = $unknown($d->expr)) {
                $errors[] = "Turunan {$d->var_name}: merujuk variabel yang tidak ada — ".implode(', ', $miss).'.';
            }
        }

        // --- skip (lompatan) rincian ---
        foreach ($blocks as $b) {
            foreach ($b->questions as $q) {
                // blok berulang: syarat dihitung per baris (Inc 157) → nama konteks baris juga sah
                $extra = [];
                if ($b->repeat !== 'ruta') {
                    $extra = array_fill_keys(self::ROW_CTX, true);
                    foreach (array_keys($refKeysByBlock[$b->code] ?? []) as $k) {
                        $extra["ref_{$k}"] = true;
                    }
                }
                if (trim((string) $q->skip_expr) !== '' && ($miss = $unknown($q->skip_expr, $extra))) {
                    $warnings[] = "Rincian {$q->var_name} (Blok {$b->code}): syarat lompatan merujuk variabel yang tidak ada — "
                        .implode(', ', $miss).'.';
                }
            }
        }

        // --- parameter sistem yang diisi admin harus menunjuk variabel yang ada ---
        foreach ($params as $p) {
            if (! isset(Parameter::SYSTEM[$p->name])) {
                continue;
            }
            $cands = Parameter::systemVars($qv->id, $p->name);
            if (! array_filter($cands, fn ($v) => isset($known[$v]))) {
                $errors[] = "Parameter {$p->name} = \"{$p->value}\": tidak ada satu pun variabel itu di versi ini.";
            }
        }

        // --- blok yang punya patokan harga tapi belum ditandai blok harga & kalori ---
        foreach ($blocks as $b) {
            $keys = $refKeysByBlock[$b->code] ?? [];
            if (! $b->harga_kalori && (isset($keys['harga_min']) || isset($keys['harga_max']))) {
                $warnings[] = "Blok {$b->code} punya patokan harga tapi belum ditandai \"blok harga & kalori\" "
                    .'— daftarnya tidak muncul di tab Harga & Kalori (centang di Sunting blok).';
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}

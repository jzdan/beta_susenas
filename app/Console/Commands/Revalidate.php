<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\SurveyCycle;
use App\Services\RuleEngine;
use Illuminate\Console\Command;

/**
 * Jalankan ulang mesin aturan (rule_results + document_snapshots) untuk banyak
 * dokumen sekaligus — dipakai setelah menyunting aturan/agregat/turunan.
 */
class Revalidate extends Command
{
    protected $signature = 'susenas:revalidate
        {--cycle= : id atau nama siklus (default: semua)}
        {--all : ikutkan dokumen berstatus bersih}';

    protected $description = 'Hitung ulang hasil aturan & snapshot untuk banyak dokumen';

    public function handle(RuleEngine $engine): int
    {
        $q = Document::query();

        if ($ref = $this->option('cycle')) {
            $cycle = ctype_digit((string) $ref)
                ? SurveyCycle::find((int) $ref)
                : SurveyCycle::where('name', $ref)->first();
            if (! $cycle) {
                $this->error("Periode '{$ref}' tidak ditemukan.");

                return self::FAILURE;
            }
            $q->whereIn('sample_id', $cycle->samples()->select('id'));
        }
        if (! $this->option('all')) {
            $q->where('status', '!=', 'bersih');
        }

        $ids = $q->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            $this->info('Tidak ada dokumen untuk direvalidasi.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($ids->count());
        $bar->start();
        $err = $warn = 0;
        foreach ($ids as $id) {
            $sum = $engine->run(Document::find($id));
            $err += $sum['errors'];
            $warn += $sum['warnings'];
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("{$ids->count()} dokumen direvalidasi · {$err} error, {$warn} warning total.");

        return self::SUCCESS;
    }
}

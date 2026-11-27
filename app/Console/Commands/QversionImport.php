<?php

namespace App\Console\Commands;

use App\Models\SurveyCycle;
use App\Services\QuestionnairePackage;
use Illuminate\Console\Command;

class QversionImport extends Command
{
    protected $signature = 'susenas:qversion:import {file : path paket JSON}
        {--cycle= : id atau nama siklus tujuan (default: siklus terbaru)}
        {--code= : kode versi baru (default: dari paket)}
        {--check : hanya periksa paket, tidak mengimpor}
        {--force : impor walau ada peringatan lint}';

    protected $description = 'Impor paket JSON kuesioner sebagai versi baru';

    public function handle(QuestionnairePackage $pkg): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File tidak ditemukan: {$file}");

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            $this->error('File bukan JSON yang valid.');

            return self::FAILURE;
        }

        if ($this->option('code')) {
            $data['code'] = $this->option('code');
        }
        $lint = $pkg->lint($data);
        foreach ($lint['warnings'] as $w) {
            $this->warn("  ⚠ {$w}");
        }
        foreach ($lint['errors'] as $e) {
            $this->error("  ✗ {$e}");
        }
        if ($lint['errors']) {
            $this->error(count($lint['errors']).' galat — perbaiki paket dulu.');

            return self::FAILURE;
        }
        if ($this->option('check')) {
            $this->info('Paket lolos pemeriksaan'.($lint['warnings'] ? ' (dengan peringatan)' : '').'.');

            return self::SUCCESS;
        }
        if ($lint['warnings'] && ! $this->option('force')) {
            $this->error('Ada peringatan — jalankan lagi dengan --force untuk tetap impor.');

            return self::FAILURE;
        }

        $cycle = $this->resolveCycle($this->option('cycle'));
        if (! $cycle) {
            $this->error('Periode tujuan tidak ditemukan. Buat periode dulu atau beri --cycle.');

            return self::FAILURE;
        }

        try {
            $qv = $pkg->import($data, $cycle->id, $this->option('code'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Versi '{$qv->code}' dibuat pada periode '{$cycle->name}' (id {$qv->id}).");
        $this->line(sprintf('  %d blok · %d parameter · %d aturan',
            $qv->blocks()->count(), $qv->parameters()->count(), $qv->rules()->count()));

        return self::SUCCESS;
    }

    private function resolveCycle(?string $ref): ?SurveyCycle
    {
        if ($ref === null || $ref === '') {
            return SurveyCycle::orderByDesc('id')->first();
        }

        return ctype_digit($ref)
            ? SurveyCycle::find((int) $ref)
            : SurveyCycle::where('name', $ref)->first();
    }
}

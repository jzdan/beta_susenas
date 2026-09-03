<?php

namespace App\Console\Commands;

use App\Models\QuestionnaireVersion;
use App\Services\QuestionnairePackage;
use Illuminate\Console\Command;

class QversionExport extends Command
{
    protected $signature = 'susenas:qversion:export {code : kode versi kuesioner} {--out= : path file JSON tujuan}';

    protected $description = 'Ekspor satu versi kuesioner (metadata lengkap) sebagai paket JSON';

    public function handle(QuestionnairePackage $pkg): int
    {
        $qv = QuestionnaireVersion::where('code', $this->argument('code'))->first();
        if (! $qv) {
            $this->error("Versi '{$this->argument('code')}' tidak ditemukan.");

            return self::FAILURE;
        }

        $data = $pkg->export($qv);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $out = $this->option('out') ?: storage_path('app/qversion_'.$qv->code.'_'.now()->format('Ymd_His').'.json');

        @mkdir(dirname($out), 0775, true);
        file_put_contents($out, $json);

        $this->info("Diekspor: {$out}");
        $this->line(sprintf('  %d blok · %d parameter · %d aturan · %d agregat · %d nilai turunan',
            count($data['blocks']), count($data['parameters']), count($data['rules']),
            count($data['aggregates']), count($data['derived'])));

        return self::SUCCESS;
    }
}

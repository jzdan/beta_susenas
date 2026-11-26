<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\Document;
use App\Services\RuleEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Jalankan ulang aturan untuk sekumpulan sampel; kemajuan dicatat di background_tasks (Inc 161). */
class RecheckDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $taskId, public array $docIds) {}

    public function handle(RuleEngine $engine): void
    {
        $task = BackgroundTask::find($this->taskId);
        if (! $task) {
            return;
        }
        $task->update(['status' => 'berjalan', 'started_at' => now()]);
        $done = 0;
        $err = 0;
        foreach (array_chunk($this->docIds, 100) as $chunk) {
            foreach (Document::whereIn('id', $chunk)->get() as $doc) {
                $err += $engine->run($doc)['errors'] > 0 ? 1 : 0;
                $done++;
                if ($done % 20 === 0) {
                    $task->update(['done' => $done]);
                }
            }
        }
        $task->update(['status' => 'selesai', 'done' => $done, 'finished_at' => now(),
            'message' => "{$done} sampel diperiksa ulang; {$err} masih punya kesalahan."]);
    }

    public function failed(\Throwable $e): void
    {
        BackgroundTask::whereKey($this->taskId)->update(['status' => 'gagal', 'finished_at' => now(),
            'message' => mb_substr($e->getMessage(), 0, 500)]);
    }
}

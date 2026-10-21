<?php

namespace App\Jobs;

use App\Models\MasterImport;
use App\Services\Mindmap\MasterImportCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class AdvanceMasterImport implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $tries = 10;
    public array $backoff = [10, 30, 60, 120, 300];
    public int $uniqueFor = 1800;

    public function __construct(public int $masterImportId) {}

    public function uniqueId(): string
    {
        return 'master-import-'.$this->masterImportId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('master-import-'.$this->masterImportId))->releaseAfter(10)];
    }

    public function handle(MasterImportCoordinator $coordinator): void
    {
        $master = MasterImport::findOrFail($this->masterImportId);
        $coordinator->advance($master);
    }

    public function failed(?Throwable $exception): void
    {
        $master = MasterImport::whereKey($this->masterImportId)->whereIn('status', ['queued', 'running'])->first();
        if (! $master) return;
        $items = $master->items ?? [];
        $index = (int) $master->current_index;
        if (isset($items[$index]) && in_array($items[$index]['status'] ?? '', ['queued', 'running', 'pending'], true)) {
            $items[$index]['status'] = 'failed';
            $items[$index]['message'] = $exception && (str_contains(mb_strtolower($exception->getMessage()), 'too many attempts')
                || str_contains(mb_strtolower(class_basename($exception)), 'maxattempts'))
                ? 'Workerul a depășit numărul de încercări pentru coordonare. Verifică workerul Laravel și reia coordonarea.'
                : 'Coordonarea s-a oprit după încercări repetate; sursa poate fi reluată.';
            $items[$index]['finished_at'] = now()->toIso8601String();
        }
        $master->update(['items' => $items, 'status' => 'partial', 'finished_at' => now()]);
    }
}

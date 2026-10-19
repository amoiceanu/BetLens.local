<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\ImportAuditService;
use App\Services\Imports\ApiFootballImportService;
use App\Services\Imports\DigitalOceanFunctionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportApiFootballFromDigitalOcean implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 150;

    public function __construct(public int $runId) {}

    public function handle(DigitalOceanFunctionService $function, ApiFootballImportService $importer, ImportAuditService $audit): void
    {
        $run = ImportRun::with('source')->findOrFail($this->runId);
        if ($run->finished_at) return;
        if ($run->source?->slug !== 'api-football' || $run->import_method !== 'digitalocean_function') {
            throw new \RuntimeException('Rularea nu aparține importului API-Football.');
        }
        $run->update(['status' => 'running', 'started_at' => now(), 'message' => 'Se apelează funcția DigitalOcean.']);
        $audit->syncRun($run);
        $started = microtime(true);

        try {
            $items = $function->fetch('api_football_l1');
            $counts = $importer->import($run->source, $items);
            $duration = (int) round((microtime(true) - $started) * 1000);
            $status = $counts['failed'] ? 'partial' : 'completed';
            $message = "{$counts['identified']} competiții identificate; {$counts['competitions_saved']} competiții și {$counts['seasons_saved']} sezoane salvate.";
            $run->update([
                'status' => $status, 'records_fetched' => $counts['received'], 'records_new' => $counts['new'],
                'records_updated' => $counts['updated'], 'records_skipped' => $counts['unchanged'],
                'records_unchanged' => $counts['unchanged'], 'records_errors' => $counts['failed'],
                'duration_ms' => $duration, 'metadata' => $counts, 'message' => $message, 'finished_at' => now(),
            ]);
            $run->source->update(['status' => $status === 'completed' ? 'healthy' : 'degraded',
                'last_checked_at' => now(), 'last_duration_ms' => $duration,
                'records_checked' => $counts['received'], 'last_new_records' => $counts['new'],
                'last_updated_records' => $counts['updated'], 'last_unchanged_records' => $counts['unchanged'],
                'last_error_records' => $counts['failed'], 'last_message' => $message]);
            DB::table('data_sync_logs')->insert(['provider' => 'api-football', 'type' => 'api_football_competitions',
                'status' => $status === 'completed' ? 'healthy' : 'degraded', 'records' => $counts['received'],
                'message' => $message, 'started_at' => $run->started_at, 'finished_at' => $run->finished_at,
                'created_at' => now(), 'updated_at' => now()]);
            $audit->syncRun($run);
        } catch (Throwable $e) {
            Log::warning('API-Football DigitalOcean import failed', ['run_id' => $run->id, 'exception' => get_class($e)]);
            $message = str_starts_with($e->getMessage(), 'Funcția DigitalOcean') || str_starts_with($e->getMessage(), 'Răspunsul funcției')
                ? str($e->getMessage())->limit(180)->toString()
                : 'Importul a eșuat; consultă logurile serverului.';
            $run->update(['status' => 'failed', 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'records_errors' => 1, 'message' => $message, 'finished_at' => now()]);
            $audit->syncRun($run);
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = ImportRun::find($this->runId);
        if (! $run || $run->finished_at) return;
        $run->update(['status' => 'failed', 'records_errors' => 1,
            'message' => 'Jobul a fost întrerupt; verifică workerul de coadă.', 'finished_at' => now()]);
        app(ImportAuditService::class)->syncRun($run);
    }
}

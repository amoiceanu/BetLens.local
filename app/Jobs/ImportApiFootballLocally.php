<?php

namespace App\Jobs;

use App\Exceptions\MasterImportCancelled;
use App\Models\ImportDocument;
use App\Models\ImportRun;
use App\Services\ImportAuditService;
use App\Services\Imports\ApiFootballImportService;
use App\Services\Mindmap\MasterImportProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportApiFootballLocally implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 150;
    public array $backoff = [30, 120, 300];

    public function __construct(public int $runId) {}

    public function handle(ApiFootballImportService $importer, ImportAuditService $audit): void
    {
        $run = ImportRun::with('source')->findOrFail($this->runId);
        if ($run->finished_at) return;
        if ($run->source?->slug !== 'api-football' || $run->import_method !== 'api_football_local') {
            throw new \RuntimeException('Rularea nu aparține importului local API-Football.');
        }

        $source = $run->source;
        $key = $source->credentialValue();
        if (blank($key)) throw new \RuntimeException('API_FOOTBALL_KEY nu este configurată pentru importul local.');

        $run->update(['status' => 'running', 'started_at' => now(), 'message' => 'Se conectează Laravel local la API-Football.']);
        $audit->syncRun($run);
        $progress = app(MasterImportProgress::class);
        if (! isset($run->metadata['master_progress'])) $progress->initialize($run);
        $run->refresh();
        $started = microtime(true);

        try {
            $this->cancelIfRequested($run, $audit);
            $progress->start($run, 'connection', 'Se deschide conexiunea HTTPS din Laravel local către API-Football.');
            $baseUrl = rtrim((string) config('services.api_football.base_url', 'https://v3.football.api-sports.io'), '/');
            $response = Http::acceptJson()->withHeader('x-apisports-key', $key)
                ->connectTimeout(10)->timeout(min(120, max(10, (int) ($source->request_timeout ?: 30))))
                ->retry(2, 500, throw: false)
                ->get($baseUrl.'/leagues', ['country' => 'Romania']);
            $progress->complete($run, 'connection', $response->successful()
                ? 'Conexiune reușită; API-Football a răspuns.'
                : 'API-Football a răspuns cu HTTP '.$response->status().'.', ['http_status' => $response->status()]);
            if (! $response->successful()) throw new \RuntimeException('API-Football a răspuns cu HTTP '.$response->status().'.');

            $progress->start($run, 'collection', 'Se validează competițiile și sezoanele din România.');
            $payload = $response->json();
            $items = is_array($payload) ? ($payload['response'] ?? null) : null;
            if (! is_array($items) || ! array_is_list($items)) throw new \RuntimeException('Răspunsul API-Football nu conține lista response așteptată.');

            $body = $response->body();
            $disk = config('betlens.raw_import_disk', 'local');
            $path = 'imports/'.$run->id.'/api-football-local.json';
            Storage::disk($disk)->put($path, $body);
            ImportDocument::updateOrCreate(['import_run_id' => $run->id], [
                'disk' => $disk, 'path' => $path, 'content_type' => (string) $response->header('Content-Type'),
                'checksum' => hash('sha256', $body), 'bytes' => strlen($body),
            ]);
            $progress->complete($run, 'collection', 'Payloadul API-Football a fost descărcat și arhivat local.', [
                'detected' => count($items), 'response_bytes' => strlen($body), 'country' => 'Romania',
            ]);
            $this->cancelIfRequested($run, $audit);

            $progress->start($run, 'persistence', 'Se scriu competițiile și sezoanele în batchuri.', ['detected' => count($items)]);
            $checkpoint = $run->metadata ?? [];
            $counts = $importer->import($source, $items,
                function (int $cursor, int $total, array $batch) use ($run, $audit, $progress, &$checkpoint): void {
                    $run->refresh();
                    $checkpoint = array_merge($run->metadata ?? [], [
                        'api_football_write_cursor' => $cursor,
                        'api_football_write_total' => $total,
                        'api_football_write_counts' => array_intersect_key($batch, array_flip(['new', 'updated', 'unchanged'])),
                    ]);
                    $run->update(['metadata' => $checkpoint, 'records_fetched' => $batch['received'],
                        'records_new' => $batch['new'], 'records_updated' => $batch['updated'],
                        'records_unchanged' => $batch['unchanged'], 'records_errors' => $batch['failed']]);
                    $progress->start($run, 'persistence', 'Batch '.$cursor.' din '.$total.' scris.', [
                        'processed' => $cursor, 'total' => $total, 'new' => $batch['new'],
                        'updated' => $batch['updated'], 'unchanged' => $batch['unchanged'], 'errors' => $batch['failed'],
                    ]);
                    $this->cancelIfRequested($run, $audit);
                }, (int) data_get($checkpoint, 'api_football_write_cursor', 0),
                data_get($checkpoint, 'api_football_write_counts', []));

            $progress->complete($run, 'persistence', 'Scrierea batchurilor s-a încheiat.', $counts + [
                'processed' => $counts['write_cursor'], 'total' => $counts['write_total'],
            ]);
            $progress->complete($run, 'finalization', 'Auditul și rezultatul importului au fost salvate.', [
                'received' => $counts['received'], 'errors' => $counts['failed'],
            ]);
            $status = $counts['failed'] ? 'partial' : 'completed';
            $message = "Import local API-Football: {$counts['identified']} competiții din România; {$counts['competitions_saved']} competiții și {$counts['seasons_saved']} sezoane procesate.";
            $run->update(['status' => $status, 'records_fetched' => $counts['received'], 'records_new' => $counts['new'],
                'records_updated' => $counts['updated'], 'records_skipped' => $counts['unchanged'],
                'records_unchanged' => $counts['unchanged'], 'records_errors' => $counts['failed'],
                'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'metadata' => array_merge($run->fresh()->metadata ?? [], $counts),
                'message' => $message, 'finished_at' => now()]);
            $source->update(['status' => $status === 'completed' ? 'healthy' : 'degraded', 'last_checked_at' => now(),
                'last_duration_ms' => (int) round((microtime(true) - $started) * 1000), 'records_checked' => $counts['received'],
                'last_new_records' => $counts['new'], 'last_updated_records' => $counts['updated'],
                'last_unchanged_records' => $counts['unchanged'], 'last_error_records' => $counts['failed'], 'last_message' => $message]);
            DB::table('data_sync_logs')->insert(['provider' => 'api-football', 'type' => 'api_football_competitions',
                'status' => $status === 'completed' ? 'healthy' : 'degraded', 'records' => $counts['received'], 'message' => $message,
                'started_at' => $run->started_at, 'finished_at' => $run->fresh()->finished_at, 'created_at' => now(), 'updated_at' => now()]);
            $audit->syncRun($run->fresh());
        } catch (MasterImportCancelled) {
            return;
        } catch (Throwable $e) {
            Log::warning('Local API-Football import failed', ['run_id' => $run->id, 'exception' => get_class($e)]);
            $message = str_starts_with($e->getMessage(), 'API-Football') || str_starts_with($e->getMessage(), 'Răspunsul')
                ? str($e->getMessage())->limit(180)->toString() : 'Importul local API-Football a eșuat; verifică cheia și logurile serverului.';
            $run->update(['status' => 'failed', 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'records_errors' => 1, 'message' => $message, 'finished_at' => now()]);
            $audit->syncRun($run->fresh());
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = ImportRun::find($this->runId);
        if (! $run || $run->finished_at) return;
        $run->update(['status' => 'failed', 'records_errors' => 1,
            'message' => 'Importul local API-Football a fost întrerupt; verifică workerul Laravel.', 'finished_at' => now()]);
        app(ImportAuditService::class)->syncRun($run);
    }

    private function cancelIfRequested(ImportRun $run, ImportAuditService $audit): void
    {
        $run->refresh();
        if (blank(data_get($run->metadata ?? [], 'master_import_cancel_requested_at'))) return;
        $run->update(['status' => 'cancelled', 'message' => 'Import local oprit la cererea Master Import; fișierul și loturile confirmate au fost păstrate.', 'finished_at' => now()]);
        $audit->syncRun($run->fresh());
        throw new MasterImportCancelled('Import API-Football local anulat.');
    }
}

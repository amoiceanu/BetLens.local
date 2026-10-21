<?php

namespace App\Jobs;

use App\Exceptions\MasterImportCancelled;
use App\Models\ImportRun;
use App\Models\ImportDocument;
use App\Services\ImportAuditService;
use App\Services\Mindmap\MasterImportProgress;
use App\Services\Imports\ApiFootballImportService;
use App\Services\Imports\DigitalOceanFunctionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        $progress = app(MasterImportProgress::class);
        if (! isset($run->metadata['master_progress'])) $progress->initialize($run);
        $run->refresh();

        try {
            $this->cancelIfRequested($run, $audit);
            $progress->start($run, 'connection', 'Se conectează la Function-DO și se invocă funcția API-Football.');
            $document = ImportDocument::where('import_run_id', $run->id)->first();
            if ($document && Storage::disk($document->disk)->exists($document->path)) {
                $items = json_decode(Storage::disk($document->disk)->get($document->path), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($items) || ! array_is_list($items)) throw new \RuntimeException('Payloadul salvat al funcției DigitalOcean nu este o listă JSON validă.');
            } else {
                $items = $function->fetch('api_football_l1');
                $body = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $disk = config('betlens.raw_import_disk', 'local');
                $path = 'imports/'.$run->id.'/api-football-function.json';
                Storage::disk($disk)->put($path, $body);
                ImportDocument::updateOrCreate(['import_run_id' => $run->id], ['disk' => $disk, 'path' => $path,
                    'content_type' => 'application/json', 'checksum' => hash('sha256', $body), 'bytes' => strlen($body)]);
            }
            $progress->complete($run, 'connection', 'Funcția DigitalOcean a răspuns.');
            $progress->complete($run, 'collection', 'Payload JSON descărcat și validat.',['items'=>count($items)]);
            $this->cancelIfRequested($run, $audit);
            $progress->start($run, 'persistence', 'Se salvează competițiile și sezoanele în batchuri.',['items'=>count($items)]);
            $checkpoint = $run->metadata ?? [];
            $counts = $importer->import($run->source, $items, function (int $cursor, int $total, array $batchProgress) use ($run, $audit, $progress, &$checkpoint): void {
                $run->refresh();
                $checkpoint = array_merge($run->metadata ?? [], ['api_football_write_cursor' => $cursor,
                    'api_football_write_total' => $total, 'api_football_write_counts' => array_intersect_key($batchProgress,
                        array_flip(['new', 'updated', 'unchanged']))]);
                $run->update(['metadata' => $checkpoint, 'records_fetched' => $batchProgress['received'],
                    'records_new' => $batchProgress['new'], 'records_updated' => $batchProgress['updated'],
                    'records_unchanged' => $batchProgress['unchanged'], 'records_errors' => $batchProgress['failed']]);
                $progress->start($run, 'persistence', 'Se procesează batchul '.$cursor.' din '.$total.'.', [
                    'processed' => $cursor, 'total' => $total, 'new' => $batchProgress['new'],
                    'updated' => $batchProgress['updated'], 'unchanged' => $batchProgress['unchanged'],
                    'errors' => $batchProgress['failed'],
                ]);
                $this->cancelIfRequested($run, $audit);
            }, (int) data_get($checkpoint, 'api_football_write_cursor', 0), data_get($checkpoint, 'api_football_write_counts', []));
            $progress->complete($run, 'persistence', 'Scrierea batchurilor s-a încheiat.',$counts+['processed'=>data_get($run->fresh()->metadata,'api_football_write_cursor',0)]);
            $progress->complete($run, 'finalization', 'Auditul și rezultatul importului au fost salvate.',['received'=>$counts['received'],'errors'=>$counts['failed']]);
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
        } catch (MasterImportCancelled) {
            return;
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

    private function cancelIfRequested(ImportRun $run, ImportAuditService $audit): void
    {
        $run->refresh();
        if (blank(data_get($run->metadata ?? [], 'master_import_cancel_requested_at'))) return;

        $run->update(['status' => 'cancelled', 'message' => 'Import oprit la cererea de anulare a Master Import; loturile deja confirmate și fișierul descărcat au fost păstrate.', 'finished_at' => now()]);
        $audit->syncRun($run->fresh());
        throw new MasterImportCancelled('Import API-Football anulat.');
    }
}

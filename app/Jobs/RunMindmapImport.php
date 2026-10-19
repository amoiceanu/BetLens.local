<?php

namespace App\Jobs;

use App\Models\DataSource;
use App\Models\ImportRun;
use App\Models\League;
use App\Services\ImportAuditService;
use App\Services\MatchAnalysisService;
use App\Services\SourcePayloadStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunMindmapImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $runId) {}

    public function handle(SourcePayloadStoreService $store, MatchAnalysisService $analysis, ImportAuditService $audit): void
    {
        $run = ImportRun::with(['source', 'documents'])->findOrFail($this->runId);
        if ($run->finished_at) return;
        $run->update(['status' => 'running', 'started_at' => now()]);
        $audit->syncRun($run);
        $source = $run->source;

        try {
            if ($run->import_method === 'internal_model') {
                $leagues = $run->league_id ? League::whereKey($run->league_id)->get() : League::where('active', true)->get();
                $new = $updated = $fetched = 0;
                foreach ($leagues as $league) {
                    $result = $analysis->generateModelEstimates($league);
                    $new += $result['created']; $updated += $result['updated']; $fetched += $result['matches_analyzed'];
                }
                $source->update(['status' => 'healthy', 'last_checked_at' => now(), 'records_checked' => $fetched, 'last_new_records' => $new, 'last_updated_records' => $updated, 'last_message' => 'Model recalculat; nu este import extern.']);
                $run->update(['status' => 'completed', 'records_fetched' => $fetched, 'records_new' => $new, 'records_updated' => $updated, 'message' => 'Model recalculat; nu este import extern.', 'finished_at' => now()]);
                $audit->syncRun($run);
                return;
            }

            if (in_array($run->import_method, ['manual_json', 'manual_csv'], true)) {
                $document = $run->documents->firstOrFail();
                $body = Storage::disk($document->disk)->get($document->path);
                $records = $run->import_method === 'manual_json' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : $this->csvRecords($body);
                if (! is_array($records) || ! array_is_list($records)) throw new \RuntimeException('Fișierul trebuie să conțină o listă de înregistrări.');
                $mappings = DB::table('source_field_mappings')->where('data_source_id', $source->id)->get();
                if ($mappings->isNotEmpty()) {
                    $records = array_map(function ($record) use ($mappings) {
                        if (! is_array($record)) return $record;
                        $mapped = [];
                        foreach ($mappings as $mapping) {
                            $value = data_get($record, $mapping->external_field);
                            if ($value !== null) $mapped[$mapping->internal_field] = $value;
                        }
                        return $record + ['_mapped' => $mapped];
                    }, $records);
                }
                $metrics = $store->store($source, 'manual_record', $records);
                $source->update(['status' => 'healthy', 'last_checked_at' => now(), 'records_checked' => count($records), 'last_new_records' => $metrics['new'], 'last_updated_records' => $metrics['updated'], 'last_unchanged_records' => $metrics['unchanged'], 'last_message' => 'Fișier importat.']);
                $run->update(['status' => 'completed', 'records_fetched' => count($records), 'records_new' => $metrics['new'], 'records_updated' => $metrics['updated'], 'records_skipped' => $metrics['unchanged'], 'message' => 'Fișier importat.', 'finished_at' => now()]);
                $audit->syncRun($run);
                return;
            }

            (new VerifyDataSource($source->id, null, $run->id))->handle();
            $source->refresh();
            $status = match ($source->status) {
                'healthy' => ($source->last_error_records > 0 ? 'partial' : 'completed'),
                'degraded' => 'partial',
                default => 'failed',
            };
            $run->update([
                'status' => $status, 'records_fetched' => $source->records_checked,
                'records_new' => $source->last_new_records, 'records_updated' => $source->last_updated_records,
                'records_skipped' => $source->last_unchanged_records, 'records_errors' => $source->last_error_records,
                'message' => $source->last_message, 'finished_at' => now(),
            ]);
            $audit->syncRun($run);
        } catch (Throwable $e) {
            Log::warning('Mindmap import failed', ['run_id' => $run->id, 'source_id' => $source->id, 'exception' => get_class($e)]);
            if ($this->attempts() >= $this->tries) {
                $source->update(['status' => 'unavailable', 'last_checked_at' => now(), 'last_message' => 'Importul a eșuat; consultă logurile serverului.']);
                $run->update(['status' => 'failed', 'records_errors' => 1, 'message' => 'Importul a eșuat; consultă logurile serverului.', 'finished_at' => now()]);
                $audit->syncRun($run);
            }
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ImportRun::whereKey($this->runId)->whereNull('finished_at')->update(['status' => 'failed', 'records_errors' => 1, 'message' => 'Importul a eșuat după încercări repetate.', 'finished_at' => now()]);
        if ($run = ImportRun::find($this->runId)) app(ImportAuditService::class)->syncRun($run);
    }

    private function csvRecords(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        if (count($lines) < 2) return [];
        $headers = str_getcsv(array_shift($lines));
        $records = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $values = str_getcsv($line);
            if (count($values) !== count($headers)) throw new \RuntimeException('Rând CSV invalid.');
            $records[] = array_combine($headers, $values);
        }
        return $records;
    }
}

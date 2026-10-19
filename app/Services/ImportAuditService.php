<?php

namespace App\Services;

use App\Models\ImportRun;
use App\Models\DataSource;
use Illuminate\Support\Facades\DB;

class ImportAuditService
{
    public function syncVerification(DataSource $source, string $runId): void
    {
        $progress = $source->verification_progress ?? [];
        $finished = ($progress['stage'] ?? null) === 'finished';
        $status = $finished ? $source->status : (($progress['stage'] ?? null) === 'queued' ? 'queued' : 'running');
        $overall = match ($status) {
            'healthy' => 'success', 'unavailable', 'not_configured', 'failed' => 'failed',
            'degraded' => 'warning', default => $status,
        };
        if ($overall === 'success' && ($progress['errors'] ?? 0) > 0) $overall = 'warning';
        $started = isset($progress['started_at']) ? \Illuminate\Support\Carbon::parse($progress['started_at']) : now();
        $new = (int) ($progress['new'] ?? 0);
        $updated = (int) ($progress['updated'] ?? 0);
        DB::table('import_audits')->updateOrInsert(['verification_run_id' => $runId], [
            'provider' => $source->slug, 'import_type' => 'source_sync',
            'execution_source' => config('betlens.import_execution_source', 'Laravel-local'),
            'job_name' => 'VerifyDataSource', 'status' => $status, 'overall_status' => $overall,
            'imported_at' => $started, 'started_at' => $started, 'finished_at' => $finished ? now() : null,
            'records_new' => $new, 'records_updated' => $updated, 'records_deleted' => 0,
            'records_imported' => $new + $updated, 'message' => $progress['message'] ?? null,
            'created_at' => $started, 'updated_at' => now(),
        ]);
    }

    public function syncRun(ImportRun $run): void
    {
        $run->loadMissing('source');
        DB::table('import_audits')->updateOrInsert(['import_run_id' => $run->id], [
            'provider' => $run->source?->slug ?? 'sursa-ștearsă',
            'import_type' => $run->import_method,
            'execution_source' => $run->execution_source,
            'job_name' => $run->import_method === 'digitalocean_function' ? 'ImportApiFootballFromDigitalOcean' : 'RunMindmapImport',
            'status' => $run->status,
            'overall_status' => match ($run->status) {
                'completed' => 'success', 'failed' => 'failed', 'partial' => 'warning', default => $run->status,
            },
            'imported_at' => $run->created_at,
            'started_at' => $run->started_at ?? $run->created_at,
            'finished_at' => $run->finished_at,
            'records_new' => $run->records_new ?? 0,
            'records_updated' => $run->records_updated ?? 0,
            'records_deleted' => 0,
            'records_imported' => ($run->records_new ?? 0) + ($run->records_updated ?? 0),
            'message' => $run->message,
            'updated_at' => now(),
            'created_at' => $run->created_at,
        ]);
    }

    public function record(string $provider, string $type, string $status, array $metrics = [], ?string $message = null, ?float $started = null, ?string $jobName = null): void
    {
        $source = config('betlens.import_execution_source');
        $source = in_array($source, ['Laravel-local', 'Laravel-DO', 'Function-DO'], true) ? $source : 'Laravel-local';
        $overall = match ($status) {
            'healthy', 'success' => 'success',
            'unavailable', 'failed', 'not_configured' => 'failed',
            default => 'warning',
        };
        if ($overall === 'success' && (int) ($metrics['errors'] ?? 0) > 0) {
            $overall = 'warning';
        }
        $finished = now();
        $new = max(0, (int) ($metrics['new'] ?? 0));
        $updated = max(0, (int) ($metrics['updated'] ?? 0));
        DB::table('import_audits')->insert([
            'provider' => $provider,
            'import_type' => $type,
            'execution_source' => $source,
            'job_name' => $jobName ?? $type,
            'status' => $status,
            'overall_status' => $overall,
            'imported_at' => $finished,
            'started_at' => $started === null ? $finished : $finished->copy()->subMilliseconds(max(0, (int) round((microtime(true) - $started) * 1000))),
            'finished_at' => $finished,
            'records_new' => $new,
            'records_updated' => $updated,
            'records_deleted' => $metrics['deleted'] ?? 0,
            'records_imported' => $new + $updated,
            'message' => $message,
            'created_at' => $finished,
            'updated_at' => $finished,
        ]);
    }
}

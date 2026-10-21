<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\ImportRun;
use App\Models\SourceRecord;
use App\Services\ImportAuditService;
use App\Services\Imports\DigitalOceanFunctionService;
use App\Services\Mindmap\ImportManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportApiFootballL1 extends Command
{
    protected $signature = 'betlens:import-api-football-l1
        {--latest : Show the most recent run and saved record counts without queuing}
        {--probe : Call the function and validate its response without importing}
        {--reconcile-run= : Mark a queued run failed only when its job is confirmed in failed_jobs}';

    protected $description = 'Queue the Romanian competition and season import through the DigitalOcean API-Football function';

    public function handle(ImportManager $imports, ImportAuditService $audit, DigitalOceanFunctionService $function): int
    {
        $source = DataSource::where('slug', 'api-football')->first();
        if (! $source) {
            $this->error('Sursa API-Football nu există.');
            return self::FAILURE;
        }

        if ($this->option('probe')) {
            try {
                $items = $function->fetch('api_football_l1');
                $this->info(count($items).' competiții primite; nicio scriere în baza de date.');
                return self::SUCCESS;
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }
        }

        if ($this->option('reconcile-run')) {
            $id = (int) $this->option('reconcile-run');
            $run = ImportRun::whereKey($id)->where('data_source_id', $source->id)
                ->where('import_method', 'digitalocean_function')->where('status', 'queued')->first();
            $failed = $run && DB::connection('mysql_auth')->table('failed_jobs')->where('exception', 'like', '%ImportApiFootballFromDigitalOcean%')
                ->where('exception', 'like', '%"runId":'.$id.'%')->exists();
            if (! $run || ! $failed) {
                $this->error('Rularea nu este în coadă sau nu are un job eșuat confirmat.');
                return self::FAILURE;
            }
            $run->update(['status' => 'failed', 'message' => 'Workerul a preluat jobul fără clasa importatorului instalată.',
                'records_errors' => 1, 'finished_at' => now()]);
            $audit->syncRun($run);
            $this->info("Rularea #{$id} a fost marcată eșuată.");
            return self::SUCCESS;
        }

        if ($this->option('latest')) {
            $run = ImportRun::where('data_source_id', $source->id)
                ->where('import_method', 'digitalocean_function')->latest('id')->first();
            $this->line('Latest run: '.($run ? "#{$run->id} {$run->status}; received={$run->records_fetched}; new={$run->records_new}; updated={$run->records_updated}; unchanged={$run->records_unchanged}; failed={$run->records_errors}; message={$run->message}" : 'none'));
            if ($run) {
                $auditStatus = DB::table('import_audits')->where('import_run_id', $run->id)->value('overall_status');
                $this->line('Import audit status: '.($auditStatus ?? 'missing'));
            }
            $this->line('Saved Romanian competitions: '.SourceRecord::where('data_source_id', $source->id)->where('record_type', 'api_football_competition')->count());
            $this->line('Saved seasons: '.SourceRecord::where('data_source_id', $source->id)->where('record_type', 'api_football_season')->count());
            $this->line('Queue connection: '.config('queue.default'));
            if (DB::connection('mysql_auth')->getSchemaBuilder()->hasTable('jobs')) {
                $this->line('Queued jobs: '.DB::connection('mysql_auth')->table('jobs')->count());
            }
            if (DB::connection('mysql_auth')->getSchemaBuilder()->hasTable('failed_jobs')) {
                $this->line('Failed jobs: '.DB::connection('mysql_auth')->table('failed_jobs')->count());
                $failed = DB::connection('mysql_auth')->table('failed_jobs')->latest('id')->first();
                if ($failed) $this->line('Last failed job: #'.$failed->id.' '.strtok((string) $failed->exception, "\n"));
            }
            return self::SUCCESS;
        }

        try {
            $run = $imports->enqueue($source);
        } catch (ValidationException $e) {
            $this->error($e->errors()['source'][0] ?? 'Importul nu a putut fi pus în coadă.');
            return self::FAILURE;
        }

        $this->info("Importul API-Football a fost pus în coadă (run #{$run->id}).");
        return self::SUCCESS;
    }
}

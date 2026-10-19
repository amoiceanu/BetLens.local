<?php

namespace App\Services\Mindmap;

use App\Jobs\RunMindmapImport;
use App\Jobs\ImportApiFootballFromDigitalOcean;
use App\Models\DataSource;
use App\Models\ImportRun;
use App\Services\ImportAuditService;
use App\Services\Imports\DigitalOceanFunctionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportManager
{
    public function canImport(DataSource $source, ?int $leagueId = null): bool
    {
        if (! $source->active || ! $source->import_enabled) return false;
        if ($source->slug === 'api-football') return app(DigitalOceanFunctionService::class)->configured('api_football_l1');
        if (! $source->is_configured) return false;
        if ($source->import_method === 'internal_model') return $source->slug === 'betlens-model';
        if (in_array($source->import_method, ['manual_json', 'manual_csv'], true)) return true;
        if ($leagueId !== null) return false; // Existing remote adapters are not scoped to a selected league.

        return $source->import_method === 'existing'
            && isset(SourceCatalog::ENDPOINTS[$source->slug])
            && $source->verification_url === SourceCatalog::ENDPOINTS[$source->slug];
    }

    public function enqueue(DataSource $source, ?int $leagueId = null, ?int $seasonId = null, bool $dispatch = true): ImportRun
    {
        $run = DB::transaction(function () use ($source, $leagueId, $seasonId, $dispatch) {
            $locked = DataSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
            if (! $this->canImport($locked, $leagueId) || ($dispatch && in_array($locked->import_method, ['manual_json', 'manual_csv'], true))) {
                throw ValidationException::withMessages(['source' => 'Această sursă nu are importator activ pentru selecția curentă.']);
            }
            if ($locked->importRuns()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['source' => 'Un import pentru această sursă este deja în coadă sau în curs.']);
            }
            $functionImport = $locked->slug === 'api-football';
            $run = $locked->importRuns()->create([
                'league_id' => $functionImport ? null : $leagueId, 'season_id' => $functionImport ? null : $seasonId,
                'status' => 'queued', 'import_method' => $functionImport ? 'digitalocean_function' : $locked->import_method,
                'function_name' => $functionImport ? config('services.digitalocean_functions.api_football_l1.name') : null,
                'level' => $functionImport ? 1 : null, 'data_format' => $functionImport ? 'JSON' : null,
                'execution_source' => config('betlens.import_execution_source', 'Laravel-local'),
            ]);
            app(ImportAuditService::class)->syncRun($run);
            return $run;
        });

        if ($dispatch) {
            if ($source->slug === 'api-football') ImportApiFootballFromDigitalOcean::dispatch($run->id)
                ->onQueue(config('services.digitalocean_functions.api_football_l1.queue', 'betlens-api-football-l1'));
            else RunMindmapImport::dispatch($run->id);
        }
        return $run;
    }
}

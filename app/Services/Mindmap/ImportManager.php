<?php

namespace App\Services\Mindmap;

use App\Jobs\RunMindmapImport;
use App\Jobs\ImportApiFootballLocally;
use App\Models\DataSource;
use App\Models\ImportRun;
use App\Services\ImportAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportManager
{
    public function canImport(DataSource $source, ?int $leagueId = null): bool
    {
        if (! $source->active || ! $source->import_enabled) return false;
        if ($source->slug === 'api-football') return $source->is_configured;
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
            $apiFootballImport = $locked->slug === 'api-football';
            $run = $locked->importRuns()->create([
                'league_id' => $apiFootballImport ? null : $leagueId, 'season_id' => $apiFootballImport ? null : $seasonId,
                'status' => 'queued', 'import_method' => $apiFootballImport ? 'api_football_local' : $locked->import_method,
                'function_name' => null,
                'level' => $apiFootballImport ? 1 : null, 'data_format' => $apiFootballImport ? 'JSON' : null,
                'execution_source' => config('betlens.import_execution_source', 'Laravel-local'),
            ]);
            app(ImportAuditService::class)->syncRun($run);
            return $run;
        });

        if ($dispatch) {
            if ($source->slug === 'api-football') ImportApiFootballLocally::dispatch($run->id);
            else RunMindmapImport::dispatch($run->id);
        }
        return $run;
    }

    public function resume(ImportRun $run): ImportRun
    {
        $run->loadMissing('source');
        if (! $run->source || ! in_array($run->status, ['failed', 'partial'], true)) {
            throw ValidationException::withMessages(['source' => 'Doar o rulare eșuată sau parțială poate fi reluată.']);
        }
        $source = $run->source;
        $run->update(['status' => 'queued', 'finished_at' => null, 'records_errors' => 0,
            'message' => 'Reluare de la ultimul checkpoint salvat.',
            'metadata' => array_merge($run->metadata ?? [], ['resume_count' => (int) data_get($run->metadata, 'resume_count', 0) + 1])]);
        $run->refresh();

        if ($source->slug === 'api-football') {
            ImportApiFootballLocally::dispatch($run->id);
        } else {
            RunMindmapImport::dispatch($run->id);
        }

        return $run;
    }
}

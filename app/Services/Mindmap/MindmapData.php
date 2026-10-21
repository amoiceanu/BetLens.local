<?php

namespace App\Services\Mindmap;

use App\Models\ApplicationSetting;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Season;
use App\Models\SourceRecord;
use Illuminate\Support\Facades\DB;

class MindmapData
{
    public function snapshot(?int $leagueId = null, ?int $seasonId = null): array
    {
        $observed = now()->toIso8601String();
        $demo = false;
        $metadata = ApplicationSetting::where('group', 'mindmap')->get()->keyBy('key');
        $sources = DataSource::with(['levels'])->withCount('records')->get()->keyBy('slug');
        $rows = [];
        $catalog = [];
        foreach ($sources as $slug => $source) {
            $meta = $metadata->get('mindmap.source.'.$source->id)?->value ?? [];
            $catalog[] = [$slug, $source->name, $meta['group'] ?? 'football', $meta['type'] ?? match ($slug) {
                'understat' => 'html', 'football-data-uk' => 'csv', default => 'rest'
            }, 'în registru'];
        }
        foreach ($catalog as [$slug, $name, $group, $type, $role]) {
            $source = $sources->get($slug);
            $meta = $source ? ($metadata->get('mindmap.source.'.$source->id)?->value ?? []) : [];
            $group = $meta['group'] ?? $group;
            $type = $meta['type'] ?? $type;
            $last = $source ? DB::table('data_sync_logs')->where('provider', $slug)->where('type', 'source_sync')->latest('finished_at')->first() : null;
            $levels = $source?->levels->pluck('level')->all() ?? [];
            $levels = array_values(array_filter($levels, fn ($level) => SourceCatalog::levelApplies($slug, (int) $level)));
            $latestRun = $source?->importRuns()->latest()->first();
            $lastSuccessfulRun = $source?->importRuns()->where('status', 'completed')->latest()->first();
            $configured = $source?->is_configured ?? false;
            $status = $source?->import_method === 'unsupported' ? 'unsupported' : (! $source?->active ? 'inactive' : (! $source?->import_enabled ? 'inactive' : (! $configured ? 'not_configured' : (in_array($latestRun?->status, ['queued', 'running']) ? 'checking' : ($source?->status === 'not_configured' ? 'never_checked' : $source?->status)))));
            $coverage = $leagueId ? DB::table('data_source_competitions')->where('data_source_id', $source->id)->where('league_id', $leagueId)->first() : null;
            $rows[] = [
                'id' => $source?->id, 'slug' => $slug, 'name' => $name, 'group' => $group,
                'levels' => $levels, 'level' => $levels[0] ?? 1,
                'type' => $type, 'typeLabel' => $slug === 'football-data-uk' ? 'CSV · adaptor existent' : SourceCatalog::TYPES[$type], 'role' => $role,
                'category' => $slug === 'football-data-uk' ? 'Rezultate istorice și statistici de meci' : ($source?->category ?? SourceCatalog::GROUPS[$group]),
                'demo' => false, 'configured' => $configured,
                'status' => $status,
                'statusLabel' => match ($status) { 'inactive' => 'Inactiv', 'unsupported' => 'Nesuportat', 'checking' => 'În curs', 'never_checked' => 'Neverificată', 'not_configured' => 'Neconfigurată', default => $source?->status_label ?? 'Neconfigurată' },
                'active' => $source?->active ?? false,
                'activeRunId' => in_array($latestRun?->status, ['queued', 'running'], true) ? $latestRun->id : null,
                'activeRunStatus' => in_array($latestRun?->status, ['queued', 'running'], true) ? $latestRun->status : null,
                'canImport' => $source && app(ImportManager::class)->canImport($source, $leagueId),
                'lastSync' => $lastSuccessfulRun?->finished_at?->toIso8601String() ?? ($last && $last->status === 'healthy' ? $last->finished_at : null),
                'created' => $latestRun?->records_new ?? ($last && $last->status === 'healthy' ? $source->last_new_records : null),
                'updated' => $latestRun?->records_updated ?? ($last && $last->status === 'healthy' ? $source->last_updated_records : null),
                'errors' => $last ? $source->last_error_records : null,
                // Existing importers do not record reconciled, cross-provider conflicts.
                'conflicts' => null, 'competition' => $coverage?->coverage_status ?? 'neconfirmată',
                'externalCompetitionId' => $coverage?->external_competition_id,
                'recordsCount' => $source->records_count,
                'method' => $source->import_method, 'frequency' => $source->import_frequency,
                'nextRun' => ($source->import_frequency !== 'manual' && isset(ImportScheduler::MINUTES[$source->import_frequency]))
                    ? ($latestRun?->created_at?->addMinutes(ImportScheduler::MINUTES[$source->import_frequency])->toIso8601String() ?? now()->toIso8601String()) : null,
                'timeout' => $source->request_timeout, 'importEnabled' => $source->import_enabled,
                'website' => $source->website_url,
                'mappings' => DB::table('source_field_mappings')->where('data_source_id', $source->id)->get(['external_field', 'internal_field'])->toArray(),
                'endpoint' => isset(SourceCatalog::ENDPOINTS[$slug]) && $source?->verification_url === SourceCatalog::ENDPOINTS[$slug] ? SourceCatalog::ENDPOINTS[$slug] : null,
                'provenance' => $source ? 'data_sources #'.$source->id.' · data_sync_logs' : 'Catalog de integrare · '.($demo ? 'Demo' : 'neconfigurat'),
                'observedAt' => $source?->updated_at?->toIso8601String() ?? $observed,
                'errorDetail' => $source && in_array($source->status, ['unavailable', 'degraded', 'not_configured']) ? 'Sursa necesită verificarea configurației sau a conexiunii. Mesajele brute sunt omise pentru a proteja acreditările.' : null,
            ];
        }
        $matches = FootballMatch::query()->when($leagueId, fn ($q) => $q->where('league_id', $leagueId))->when($seasonId, fn ($q) => $q->where('season_id', $seasonId))->get();
        $filled = $slots = 0;
        foreach ($matches as $match) {
            foreach (['home_team_id', 'away_team_id', 'kickoff_at', 'venue'] as $field) {
                $slots++;
                $filled += filled($match->$field) ? 1 : 0;
            }
        }
        $total = $valid = 0;
        $integrityConflicts = [];
        SourceRecord::whereIn('data_source_id', $sources->pluck('id'))->select('id', 'data_source_id', 'payload', 'checksum', 'external_key')->chunkById(500, function ($records) use (&$total, &$valid, &$integrityConflicts) {
            foreach ($records as $record) {
                $total++;
                $encoded = json_encode($this->normalize($record->payload ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $checksumValid = hash_equals($record->checksum, hash('sha256', $encoded)) || hash_equals($record->checksum, hash('sha256', json_encode($record->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
                if (! $checksumValid) {
                    $integrityConflicts[$record->data_source_id] = ($integrityConflicts[$record->data_source_id] ?? 0) + 1;
                }
                $valid += filled($record->external_key) && filled($record->payload) && $checksumValid ? 1 : 0;
            }
        });
        foreach ($rows as &$row) {
            if (isset($integrityConflicts[$row['id']])) {
                $row['conflicts'] = $integrityConflicts[$row['id']];
                $row['status'] = 'conflict';
                $row['statusLabel'] = 'conflict';
                $row['errorDetail'] = 'Checksum și payload contradictorii în source_records. Este necesară verificarea integrității.';
            }
        }
        unset($row);
        $slugs = $sources->keys();
        $logs = DB::table('data_sync_logs')->whereIn('provider', $slugs)->where('type', 'source_sync');
        $lastSuccess = (clone $logs)->where('status', 'healthy')->max('finished_at');
        $coverage = $slots ? round($filled / $slots * 100, 1) : null;

        return [
            'rows' => $rows, 'demo' => $demo, 'observedAt' => $observed,
            'groups' => SourceCatalog::GROUPS, 'types' => SourceCatalog::TYPES, 'levels' => SourceCatalog::LEVELS,
            'leagues' => League::orderBy('name')->get(),
            'seasons' => Season::when($leagueId, fn ($q) => $q->where('league_id', $leagueId))->orderByDesc('starts_at')->get(),
            'leagueId' => $leagueId, 'seasonId' => $seasonId,
            'metrics' => [
                'Surse active / înregistrate' => [count(array_filter($rows, fn ($r) => $r['active'])).' / '.$sources->count(), 'data_sources · active=true; înregistrat ≠ importator disponibil'],
                'Acoperirea câmpurilor' => [$coverage === null ? 'unavailable' : $coverage.'%', "matches · {$filled}/{$slots} câmpuri completate; competiția și sezonul selectate"],
                'Importuri astăzi' => [(clone $logs)->where('status', 'healthy')->whereDate('finished_at', today())->count(), 'data_sync_logs · source_sync healthy; '.today()->toDateString().' · '.config('app.timezone')],
                'Conflicte de date' => [array_sum($integrityConflicts) ?: 'unavailable', 'source_records · contradicții checksum/payload detectate; reconcilierea între furnizori rămâne unavailable'],
                'Calitatea datelor' => [$total ? round($valid / $total * 100, 1).'%' : 'unavailable', "source_records · {$valid}/{$total} înregistrări cu payload, identificator și checksum SHA-256 valid; toate sursele afișate"],
                'Ultima sincronizare reușită' => [$lastSuccess ?? 'unavailable', 'data_sync_logs · max(finished_at), status=healthy'],
                'Progresul importurilor' => ['unavailable', $sources->where('status', 'checking')->count().' surse marcate checking; fără heartbeat sau total de lucru raportat'],
            ],
            'estimates' => [
                'Cost API' => ['De configurat', 'Niciun plan tarifar introdus; nu se estimează un cost.'],
                'Următoarea fereastră' => [now()->addHour()->startOfHour()->format('H:i').' · '.config('app.timezone'), 'routes/console.php · orar; execuția depinde de scheduler, fără heartbeat disponibil'],
                'Date lipsă' => [$coverage === null ? 'unavailable' : round(100 - $coverage, 1).'%', 'Complementul acoperirii câmpurilor în selecția curentă.'],
            ],
        ];
    }

    public function canImport(DataSource $source): bool
    {
        return app(ImportManager::class)->canImport($source);
    }

    private function normalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->normalize($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}

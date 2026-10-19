<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchWeatherSnapshot;
use App\Models\SourceRecord;
use App\Services\Coverage\FieldRegistry;
use App\Services\Coverage\ObservationProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RebuildSourceCoverage extends Command
{
    protected $signature = 'betlens:coverage-rebuild {--source= : Slug-ul sursei, opțional}';

    protected $description = 'Reconstruiește numai evidența locală de acoperire, fără apeluri externe sau modificarea datelor importate.';

    public function handle(ObservationProjector $projector): int
    {
        if (! app(FieldRegistry::class)->ready()) {
            $this->error('Migrația de observabilitate nu este aplicată.');

            return self::FAILURE;
        }
        $sources = DataSource::when($this->option('source'), fn ($q, $slug) => $q->where('slug', $slug))->get()->keyBy('id');
        if ($sources->isEmpty()) {
            $this->error('Sursa nu există.');

            return self::FAILURE;
        }
        $count = 0;
        SourceRecord::whereIn('data_source_id', $sources->keys())->orderBy('id')->chunkById(300, function ($records) use ($sources, $projector, &$count) {
            foreach ($records as $record) {
                $projector->stored($sources[$record->data_source_id], $record->record_type, [$record->payload], $record->last_seen_at);
                $count++;
            }$projector->flush();
        });
        $openLiga = $sources->firstWhere('slug', 'openligadb');
        if ($openLiga) {
            FootballMatch::with(['homeTeam', 'awayTeam', 'league'])->where('external_id', 'like', 'openligadb:%')->chunkById(300, function ($matches) use ($projector, $openLiga) {
                foreach ($matches as $game) {
                    $projector->safely(fn () => $projector->record($openLiga, ['external_id' => substr($game->external_id, strlen('openligadb:')), 'match_status' => $game->status, 'values' => ['kickoff_at' => $game->kickoff_at->copy()->utc()->toIso8601String(), 'home_team' => $game->homeTeam->name, 'away_team' => $game->awayTeam->name]], $game, $game->updated_at));
                }$projector->flush();
            });
        }
        if (Schema::hasTable('match_weather_snapshots')) {
            MatchWeatherSnapshot::whereIn('provider', $sources->pluck('slug'))->chunkById(300, function ($snapshots) use ($sources, $projector) {
                $games = FootballMatch::with('league')->whereIn('id', $snapshots->pluck('match_id'))->get()->keyBy('id');
                foreach ($snapshots as $snapshot) {
                    $game = $games->get($snapshot->match_id);
                    $source = $sources->firstWhere('slug', $snapshot->provider);
                    if (! $game || ! $source) {
                        continue;
                    }$projector->safely(fn () => $projector->record($source, ['external_id' => 'weather:'.$game->id, 'match_status' => $game->status, 'values' => ['temperature_c' => $snapshot->temperature_c, 'precipitation_mm' => $snapshot->precipitation_mm, 'wind_speed_kmh' => $snapshot->wind_speed_kmh]], $game, $snapshot->fetched_at));
                }$projector->flush();
            });
        }
        $this->info($count.' înregistrări locale examinate; evidența și neconcordanțele au fost reconstruite. Datele originale nu au fost modificate.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Services\Coverage;

use App\Models\DataSource;
use App\Models\ExternalIdMapping;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Season;
use App\Models\SourceCoverageCell;
use App\Models\SourceMatchObservation;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Services\TeamAliasMatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Local read model of existing imports, never a provider client. */
class ObservationProjector
{
    private array $dirty = [];

    private ?array $fields = null;

    private array $identities = [];

    private function fields(): array
    {
        return $this->fields ??= app(FieldRegistry::class)->fields();
    }

    public function safely(callable $callback): void
    {
        if (! Schema::hasTable('source_match_observations')) {
            return;
        }
        try {
            $callback();
        } catch (\Throwable) {
            Log::warning('Evidența locală a acoperirii nu a putut fi actualizată. Importul principal a fost păstrat.');
        }
    }

    public function stored(DataSource $source, string $type, array $records, mixed $observedAt = null): void
    {
        $this->safely(function () use ($source, $type, $records, $observedAt) {
            foreach ($records as $payload) {
                if (! is_array($payload)) {
                    continue;
                }
                $fixture = app(StoredFixtureReader::class)->read($source->slug, $payload, $type);
                if ($fixture) {
                    $this->record($source, $fixture, null, $observedAt ?? now());
                }
            }
        });
    }

    public function record(DataSource $source, array $fixture, ?FootballMatch $trusted = null, mixed $observedAt = null): void
    {
        $values = [];
        foreach ($fixture['values'] as $key => $value) {
            if (! isset($this->fields()[$key])) {
                continue;
            }
            if ($value === null || $value === '') {
                $values[$key] = null;

                continue;
            }
            if (in_array($key, ['home_team', 'away_team', 'kickoff_at'], true)) {
                if (is_scalar($value)) {
                    $values[$key] = app(SafeImportMessage::class)->clean(mb_substr((string) $value, 0, 160));
                }
            } elseif (is_numeric($value) && is_finite((float) $value) && ($value >= 0 || $key === 'temperature_c')) {
                $values[$key] = 0 + $value;
            } else {
                $values[$key] = null;
            }
        }
        if (array_key_exists('home_corners', $values) || array_key_exists('away_corners', $values)) {
            $values['total_corners'] = isset($values['home_corners'],$values['away_corners']) ? $values['home_corners'] + $values['away_corners'] : null;
        }
        $fixture['values'] = $values;
        [$game,$league,$season,$association] = $trusted ? [$trusted, $trusted->league, $trusted->season_id, 'mapped'] : $this->associate($source, $fixture);
        $old = SourceMatchObservation::where(['data_source_id' => $source->id, 'external_id' => $fixture['external_id']])->first();
        if ($old && $old->observed_at->gt($observedAt ?? now())) {
            return;
        } // Do not replace a newer observation during historical backfill.
        if ($old) {
            $this->mark($source->id, $old->league_id, $old->season_id);
        }
        $fieldTimes = $old?->field_observed_at ?? [];
        foreach (array_keys($values) as $key) {
            $fieldTimes[$key] = Carbon::parse($observedAt ?? now())->toIso8601String();
        }
        $values = array_replace($old?->values ?? [], $values);
        $observation = SourceMatchObservation::updateOrCreate(['data_source_id' => $source->id, 'external_id' => $fixture['external_id']], ['game_id' => $game?->id, 'league_id' => $league?->id, 'season_id' => $season, 'association' => $association, 'match_status' => $fixture['match_status'] ?? null, 'values' => $values, 'field_observed_at' => $fieldTimes, 'observed_at' => $observedAt ?? now()]);
        $this->mark($source->id, $league?->id, $season);
        if ($game) {
            app(DiscrepancyDetector::class)->refresh($game->id);
        }
        if ($old?->game_id && $old->game_id !== $game?->id) {
            app(DiscrepancyDetector::class)->refresh($old->game_id);
        }
    }

    private function associate(DataSource $source, array $fixture): array
    {
        $mapping = Schema::hasTable('external_id_mappings') ? ExternalIdMapping::where(['provider' => $source->slug, 'entity_type' => 'match', 'external_id' => $fixture['external_id']])->first() : null;
        $game = $mapping ? FootballMatch::with('league')->find($mapping->internal_id) : null;
        if (! $game && Schema::hasTable('provider_match_mappings')) {
            $mapping = DB::table('provider_match_mappings')->where(['provider' => $source->slug, 'external_id' => $fixture['external_id']])->first();
            $game = $mapping ? FootballMatch::with('league')->find($mapping->match_id) : null;
        }
        if ($game) {
            return [$game, $game->league, $game->season_id, 'mapped'];
        }
        $hint = $fixture['league_hint'] ?? null;
        $normalizer = app(TeamAliasMatcher::class);
        $leagues = League::get()->filter(fn ($league) => $hint && ($league->slug === $hint || $normalizer->normalize($league->name) === $normalizer->normalize($hint)));
        $league = $leagues->count() === 1 ? $leagues->first() : null;
        $season = null;
        if ($league && ($fixture['date'] ?? null)) {
            $seasons = Season::where('league_id', $league->id)->whereDate('starts_at', '<=', $fixture['date'])->whereDate('ends_at', '>=', $fixture['date'])->get();
            if ($seasons->count() === 1) {
                $season = $seasons->first()->id;
            }
        }
        if (! $league || empty($fixture['date']) || empty($fixture['values']['home_team']) || empty($fixture['values']['away_team'])) {
            return [null, $league, $season, 'unmatched'];
        }
        $candidates = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])->where('league_id', $league->id);
        if (! empty($fixture['values']['kickoff_at'])) {
            $time = Carbon::parse($fixture['values']['kickoff_at']);
            $minutes = config('coverage.matching_window_minutes');
            $candidates->whereBetween('kickoff_at', [$time->copy()->subMinutes($minutes), $time->copy()->addMinutes($minutes)]);
        } else {
            $candidates->whereDate('kickoff_at', $fixture['date']);
        }
        $home = $this->identity($fixture['values']['home_team'], $league->id, $source->slug);
        $away = $this->identity($fixture['values']['away_team'], $league->id, $source->slug);
        $matched = $candidates->get()->filter(fn ($candidate) => $this->identity($candidate->homeTeam->name, $league->id, $source->slug) === $home && $this->identity($candidate->awayTeam->name, $league->id, $source->slug) === $away);
        if ($matched->count() === 1) {
            $game = $matched->first();

            return [$game, $league, $game->season_id, 'matched'];
        }

        return [null, $league, $season, $matched->count() > 1 ? 'ambiguous' : 'unmatched'];
    }

    public function identity(string $value, int $league, string $provider): string
    {
        $normal = app(TeamAliasMatcher::class)->normalize($value);
        $key = $league.':'.$provider;
        if (! isset($this->identities[$key])) {
            $map = [];
            foreach (Team::where('league_id', $league)->get() as $team) {
                $map[app(TeamAliasMatcher::class)->normalize($team->name)][] = $team->id;
            }
            $aliases = TeamAlias::where('league_id', $league)->whereIn('provider', [$provider, 'all'])->get();
            foreach ($aliases->groupBy('normalized_alias') as $alias => $rows) {
                $specific = $rows->where('provider', $provider);
                $map[$alias] = ($specific->isNotEmpty() ? $specific : $rows)->pluck('team_id')->all();
            }
            $this->identities[$key] = $map;
        }
        $ids = array_unique($this->identities[$key][$normal] ?? []);

        return count($ids) === 1 ? 'team:'.reset($ids) : 'name:'.$normal;
    }

    public function remap(ExternalIdMapping $mapping): void
    {
        if ($mapping->entity_type !== 'match') {
            return;
        }
        $this->safely(function () use ($mapping) {
            $source = DataSource::where('slug', $mapping->provider)->first();
            $game = FootballMatch::with('league')->find($mapping->internal_id);
            if (! $source || ! $game) {
                return;
            }
            $observation = SourceMatchObservation::where(['data_source_id' => $source->id, 'external_id' => $mapping->external_id])->first();
            if ($observation) {
                $this->record($source, ['external_id' => $mapping->external_id, 'match_status' => $observation->match_status, 'values' => $observation->values], $game, $observation->observed_at);
            }
        });
    }

    private function mark(int $source, ?int $league, ?int $season): void
    {
        if ($league) {
            $this->dirty[$source.':'.$league.':'.($season ?? 'null')] = [$source, $league, $season];
        }
    }

    public function latest()
    {
        return SourceMatchObservation::query()->where(function ($query) {
            $query->whereNull('game_id')->orWhereNotExists(function ($newer) {
                $newer->selectRaw('1')->from('source_match_observations as newer')->whereColumn('newer.data_source_id', 'source_match_observations.data_source_id')->whereColumn('newer.game_id', 'source_match_observations.game_id')->where(fn ($q) => $q->whereColumn('newer.observed_at', '>', 'source_match_observations.observed_at')->orWhere(fn ($tie) => $tie->whereColumn('newer.observed_at', 'source_match_observations.observed_at')->whereColumn('newer.id', '>', 'source_match_observations.id')));
            });
        });
    }

    public function flush(): void
    {
        $this->safely(function () {
            $dirty = $this->dirty;
            $this->dirty = [];
            $this->identities = [];
            $sources = DataSource::whereIn('id', array_column($dirty, 0))->get()->keyBy('id');
            foreach ($dirty as [$source,$league,$season]) {
                $profile = app(FieldRegistry::class)->profile($sources[$source]->slug, $this->fields());
                $counts = $eligible = array_fill_keys(array_keys($profile), 0);
                $observed = $found = $scheduled = $ambiguous = $unknown = 0;
                $last = null;
                $this->latest()->where(['data_source_id' => $source, 'league_id' => $league])->where('season_id', $season)->chunkById(500, function ($rows) use (&$counts, &$eligible, &$observed, &$found, &$scheduled, &$ambiguous, &$unknown, &$last, $profile) {
                    foreach ($rows as $row) {
                        $observed++;
                        $ambiguous += (int) ($row->association === 'ambiguous');
                        $unknown += (int) ($row->match_status === null);
                        $scheduled += (int) ($row->match_status === 'scheduled');
                        $last = max($last ?? '', $row->observed_at->format('Y-m-d H:i:s'));
                        if (! $row->game_id) {
                            continue;
                        }$found++;
                        foreach ($profile as $field => $definition) {
                            if (! app(FieldRegistry::class)->eligible($definition, $row->match_status)) {
                                continue;
                            }$eligible[$field]++;
                            if (array_key_exists($field, $row->values) && $row->values[$field] !== null) {
                                $counts[$field]++;
                            }
                        }
                    }
                });
                SourceCoverageCell::updateOrCreate(['scope_key' => hash('sha256', "$source:$league:".($season ?? 'null'))], ['data_source_id' => $source, 'league_id' => $league, 'season_id' => $season, 'observed_count' => $observed, 'found_count' => $found, 'scheduled_count' => $unknown ? null : $scheduled, 'ambiguous_count' => $ambiguous, 'completed_slots' => array_sum($counts), 'expected_slots' => array_sum($eligible), 'available_counts' => $counts, 'eligible_counts' => $eligible, 'last_observation_at' => $last]);
            }
        });
    }
}

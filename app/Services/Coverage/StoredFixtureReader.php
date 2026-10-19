<?php

namespace App\Services\Coverage;

use Carbon\Carbon;

/** Reads only known, persisted provider shapes. Unknown payloads are deliberately ignored. */
class StoredFixtureReader
{
    public function read(string $provider, array $p, string $type): ?array
    {
        $values = [];
        $date = null;
        $status = null;
        $id = null;
        $league = null;
        if ($provider === 'sportmonks' && in_array($type, ['sportmonks_fixture', 'sportmonks_statistics'], true)) {
            $raw = $p['raw'] ?? $p;
            $id = $p['external_id'] ?? $raw['id'] ?? null;
            $league = is_scalar($p['league'] ?? null) ? $p['league'] : data_get($raw, 'league.name');
            $values = ['kickoff_at' => $p['kickoff_at'] ?? $raw['starting_at'] ?? null, 'home_team' => $p['home_team'] ?? null, 'away_team' => $p['away_team'] ?? null];
            $participants = collect($raw['participants'] ?? []);
            $home = $participants->first(fn ($team) => data_get($team, 'meta.location') === 'home');
            $away = $participants->first(fn ($team) => data_get($team, 'meta.location') === 'away');
            $values['home_team'] ??= $home['name'] ?? null;
            $values['away_team'] ??= $away['name'] ?? null;
            foreach ($raw['scores'] ?? [] as $score) {
                $side = (string) ($score['participant_id'] ?? '') === (string) ($home['id'] ?? -1) ? 'home' : (((string) ($score['participant_id'] ?? '') === (string) ($away['id'] ?? -2)) ? 'away' : null);
                $suffix = match ($score['description'] ?? '') {
                    'CURRENT' => '','1ST_HALF' => '_ht','2ND_HALF' => '_2h',default => null
                };
                if ($side && $suffix !== null) {
                    $values[$side.'_score'.$suffix] = data_get($score, 'score.goals');
                }
            }
            $status = match ((int) ($raw['state_id'] ?? 0)) {
                1 => 'scheduled',5,7,8 => 'finished',2,3,4,6 => 'live',default => null
            };
            if (! empty($raw['lineups']) && is_array($raw['lineups'])) {
                $values['lineups_count'] = count($raw['lineups']);
            }
        } elseif ($provider === 'football-data-uk' && $type === 'historical_match') {
            if (! isset($p['HomeTeam'],$p['AwayTeam'],$p['Date'])) {
                return null;
            }
            $id = 'csv:'.hash('sha256', ($p['Div'] ?? '').':'.$p['Date'].':'.$p['HomeTeam'].':'.$p['AwayTeam']);
            $league = config('coverage.csv_leagues.'.($p['Div'] ?? ''));
            try {
                $format = preg_match('~\d{2}/\d{2}/\d{4}~', $p['Date']) ? '!d/m/Y' : '!d/m/y';
                $parsed = Carbon::createFromFormat($format, $p['Date']);
                $errors = Carbon::getLastErrors();
                if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                    return null;
                }
                $date = $parsed->toDateString();
                if (filled(config('coverage.csv_timezone')) && filled($p['Time'] ?? null)) {
                    $values['kickoff_at'] = Carbon::parse($date.' '.$p['Time'], config('coverage.csv_timezone'))->utc()->toIso8601String();
                }
            } catch (\Throwable) {
                return null;
            }
            $values += ['home_team' => $p['HomeTeam'], 'away_team' => $p['AwayTeam']];
            foreach (config('coverage.fields') as $key => $definition) {
                if (isset($definition['csv']) && array_key_exists($definition['csv'], $p)) {
                    $values[$key] = $p[$definition['csv']] === '' ? null : $p[$definition['csv']];
                }
            }
            $status = isset($values['home_score'],$values['away_score']) ? 'finished' : null;
        } elseif ($provider === 'api-football' && isset($p['fixture']['id'])) {
            $id = data_get($p, 'fixture.id');
            $league = data_get($p, 'league.name');
            $values = ['kickoff_at' => data_get($p, 'fixture.date'), 'home_team' => data_get($p, 'teams.home.name'), 'away_team' => data_get($p, 'teams.away.name'), 'home_score' => data_get($p, 'goals.home'), 'away_score' => data_get($p, 'goals.away'), 'home_score_ht' => data_get($p, 'score.halftime.home'), 'away_score_ht' => data_get($p, 'score.halftime.away')];
            $status = match (data_get($p, 'fixture.status.short')) {
                'FT','AET','PEN' => 'finished','NS' => 'scheduled','1H','HT','2H','ET','LIVE' => 'live','PST' => 'postponed',default => null
            };
        } elseif ($provider === 'football-data' && isset($p['utcDate'],$p['homeTeam'],$p['awayTeam'])) {
            $id = $p['id'] ?? null;
            $league = data_get($p, 'competition.name');
            $values = ['kickoff_at' => $p['utcDate'], 'home_team' => data_get($p, 'homeTeam.name'), 'away_team' => data_get($p, 'awayTeam.name'), 'home_score' => data_get($p, 'score.fullTime.home'), 'away_score' => data_get($p, 'score.fullTime.away'), 'home_score_ht' => data_get($p, 'score.halfTime.home'), 'away_score_ht' => data_get($p, 'score.halfTime.away')];
            $status = match ($p['status'] ?? null) {
                'FINISHED' => 'finished','SCHEDULED','TIMED' => 'scheduled','IN_PLAY','PAUSED' => 'live','POSTPONED' => 'postponed',default => null
            };
        } else {
            return null;
        }
        $id = is_scalar($id) ? app(SafeImportMessage::class)->clean((string) $id) : null;
        if ($id === null || ! preg_match('/^[\pL\pN:._-]{1,160}$/u', (string) $id)) {
            return null;
        }
        if (filled($values['kickoff_at'] ?? null)) {
            try {
                $values['kickoff_at'] = Carbon::parse($values['kickoff_at'])->utc()->toIso8601String();
                $date = Carbon::parse($values['kickoff_at'])->toDateString();
            } catch (\Throwable) {
                $values['kickoff_at'] = null;
            }
        }

        return ['external_id' => (string) $id, 'league_hint' => $league, 'date' => $date, 'match_status' => $status, 'values' => $values];
    }
}

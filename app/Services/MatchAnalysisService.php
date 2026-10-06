<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Market;
use App\Models\Recommendation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MatchAnalysisService
{
    public const MODEL_VERSION = 'poisson-v1';
    public const FAIR_MODEL_VERSION = 'poisson-fair-v1';

    public function __construct(
        private ValueCalculatorService $valueCalculator,
        private TeamStatisticsService $statistics,
        private WeatherImpactService $weatherImpact
    ) {}

    public function generateForLeague(League $league): array
    {
        $this->statistics->rebuild($league);
        $history = $this->history($league);
        $created = $updated = $eligible = 0;

        $matches = FootballMatch::with(['odds.market', 'homeTeam', 'awayTeam', 'latestWeatherSnapshot'])
            ->where('league_id', $league->id)
            ->where('data_status','!=','conflict')
            ->where('kickoff_at', '>', now())
            ->whereHas('odds')
            ->get();

        foreach ($matches as $match) {
            Recommendation::where('match_id', $match->id)->where('model_version', self::FAIR_MODEL_VERSION)->update(['eligible' => false]);
            Recommendation::where('match_id', $match->id)->where('model_version', self::MODEL_VERSION)->update(['eligible' => false]);
            $probabilities = $this->probabilities($match, $history);
            foreach ($this->bestPrices($match->odds) as $price) {
                $probability = $probabilities[$price['selection']] ?? null;
                if ($probability === null || $price['price'] <= 1) continue;

                $value = $this->valueCalculator->calculate($probability, $price['price']);
                $score = (int) round(min(100, $probability * 70 + max(0, min(.15, $value)) / .15 * 30));
                $weather=$this->weatherImpact->assess($match->latestWeatherSnapshot,$price['selection']);
                $score=max(0,min(100,$score+$weather['score_adjustment']));
                $isEligible = $this->passesThreshold('agresiv', $probability, $value, $score);
                $existing = Recommendation::where([
                    'match_id' => $match->id,
                    'market_id' => $price['market_id'],
                    'selection' => $price['selection'],
                    'model_version' => self::MODEL_VERSION,
                ])->first();

                Recommendation::updateOrCreate(
                    ['match_id' => $match->id, 'market_id' => $price['market_id'], 'selection' => $price['selection'], 'model_version' => self::MODEL_VERSION],
                    [
                        'odds' => $price['price'],
                        'model_probability' => round($probability, 4),
                        'implied_probability' => $this->valueCalculator->impliedProbability($price['price']),
                        'value' => $value,
                        'confidence' => $score >= 72 ? 'Ridicat' : ($score >= 60 ? 'Mediu' : 'Scăzut'),
                        'score' => $score,
                        'explanation' => $this->explanation($match, $price['selection'], $probability, $value, $history).($weather['explanation']?' '.$weather['explanation']:''),
                        'factors' => ['model' => 'Poisson', 'history_matches' => $history['played'], 'expected_home_goals' => $probabilities['_home_xg'], 'expected_away_goals' => $probabilities['_away_xg'], 'bookmaker' => $price['provider'], 'weather_score_adjustment'=>$weather['score_adjustment']],
                        'eligible' => $isEligible,
                    ]
                );
                $existing ? $updated++ : $created++;
                if ($isEligible) $eligible++;
            }
        }

        return compact('created', 'updated', 'eligible') + ['matches_analyzed' => $matches->count()];
    }

    public function generateModelEstimates(League $league, int $days = 45): array
    {
        $this->statistics->rebuild($league);
        $history = $this->history($league);
        $created = $updated = $eligible = 0;
        $markets = Market::whereIn('key', ['result', 'over_25', 'double_chance'])->where('active', true)->get()->keyBy('key');
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'latestWeatherSnapshot'])
            ->where('league_id', $league->id)
            ->where('data_status','!=','conflict')
            ->whereBetween('kickoff_at', [now(), now()->addDays($days)])
            ->whereDoesntHave('odds')
            ->orderBy('kickoff_at')->get();

        foreach ($matches as $match) {
            Recommendation::where('match_id', $match->id)->where('model_version', self::FAIR_MODEL_VERSION)->update(['eligible' => false]);
            $p = $this->probabilities($match, $history);
            $candidates = [
                ['market' => 'result', 'selection' => collect(['1' => $p['1'], 'X' => $p['X'], '2' => $p['2']])->sortDesc()->keys()->first()],
                ['market' => 'over_25', 'selection' => $p['Peste 2.5'] >= $p['Sub 2.5'] ? 'Peste 2.5' : 'Sub 2.5'],
                ['market' => 'double_chance', 'selection' => collect(['1X' => $p['1'] + $p['X'], 'X2' => $p['X'] + $p['2'], '12' => $p['1'] + $p['2']])->sortDesc()->keys()->first()],
            ];
            foreach ($candidates as $candidate) {
                $candidate['selection'] = (string) $candidate['selection'];
                $market = $markets->get($candidate['market']);
                if (! $market) continue;
                $probability = match ($candidate['selection']) {
                    '1X' => $p['1'] + $p['X'], 'X2' => $p['X'] + $p['2'], '12' => $p['1'] + $p['2'],
                    default => $p[$candidate['selection']],
                };
                $score = (int) round($probability * 100);
                $weather=$this->weatherImpact->assess($match->latestWeatherSnapshot,$candidate['selection']);
                $score=max(0,min(100,$score+$weather['score_adjustment']));
                $isEligible = $this->passesModelThreshold('agresiv', $probability, $score);
                $identity = ['match_id' => $match->id, 'market_id' => $market->id, 'selection' => $candidate['selection'], 'model_version' => self::FAIR_MODEL_VERSION];
                $existing = Recommendation::where($identity)->first();
                $fairOdds = max(1.01, round(1 / max(.01, $probability), 2));
                Recommendation::updateOrCreate($identity, [
                    'odds' => $fairOdds,
                    'model_probability' => round($probability, 4),
                    'implied_probability' => round(1 / $fairOdds, 4),
                    'value' => 0,
                    'confidence' => $score >= 72 ? 'Ridicat' : ($score >= 60 ? 'Mediu' : 'Scăzut'),
                    'score' => $score,
                    'explanation' => sprintf('Cotă echitabilă BetLens calculată din %d rezultate reale din %s. Modelul Poisson estimează selecția %s la %.1f%%. Aceasta este o estimare analitică, nu o cotă oferită de un bookmaker.', $history['played'], $league->name, $candidate['selection'], $probability * 100).($weather['explanation']?' '.$weather['explanation']:''),
                    'factors' => ['model' => 'Poisson', 'pricing' => 'model_fair', 'history_matches' => $history['played'], 'expected_home_goals' => $p['_home_xg'], 'expected_away_goals' => $p['_away_xg'], 'weather_score_adjustment'=>$weather['score_adjustment']],
                    'eligible' => $isEligible,
                ]);
                $existing ? $updated++ : $created++;
                if ($isEligible) $eligible++;
            }
        }
        return compact('created', 'updated', 'eligible') + ['matches_analyzed' => $matches->count()];
    }

    public function passesThreshold(string $profile, float $probability, float $value, int $score): bool
    {
        $defaults = [
            'conservator' => ['min_probability' => .68, 'min_value' => .02, 'min_confidence' => 70],
            'echilibrat' => ['min_probability' => .62, 'min_value' => .015, 'min_confidence' => 62],
            'agresiv' => ['min_probability' => .56, 'min_value' => .01, 'min_confidence' => 55],
        ];
        $settings = ApplicationSetting::where('key', 'risk.'.$profile)->value('value') ?? ($defaults[$profile] ?? $defaults['echilibrat']);
        if (is_string($settings)) $settings = json_decode($settings, true) ?: [];
        $settings += $defaults[$profile] ?? $defaults['echilibrat'];
        return $probability >= (float) $settings['min_probability']
            && $value >= (float) $settings['min_value']
            && $score >= (int) $settings['min_confidence'];
    }

    public function passesModelThreshold(string $profile, float $probability, int $score): bool
    {
        $defaults = ['conservator' => [.68, 70], 'echilibrat' => [.62, 62], 'agresiv' => [.56, 55]];
        [$minimumProbability, $minimumScore] = $defaults[$profile] ?? $defaults['echilibrat'];
        $settings = ApplicationSetting::where('key', 'risk.'.$profile)->value('value');
        if (is_string($settings)) $settings = json_decode($settings, true) ?: [];
        return $probability >= (float) ($settings['min_probability'] ?? $minimumProbability)
            && $score >= (int) ($settings['min_confidence'] ?? $minimumScore);
    }

    private function history(League $league): array
    {
        $rows = DB::table('matches as m')->join('match_results as r', 'r.match_id', '=', 'm.id')
            ->where('m.league_id', $league->id)
            ->select('m.home_team_id', 'm.away_team_id', 'r.home_score', 'r.away_score')
            ->get();
        $stats = [];
        foreach ($rows as $row) {
            $stats[$row->home_team_id]['home'][] = [(int) $row->home_score, (int) $row->away_score];
            $stats[$row->away_team_id]['away'][] = [(int) $row->away_score, (int) $row->home_score];
        }
        $played = max(1, $rows->count());
        return [
            'played' => $rows->count(),
            'home_avg' => max(.5, $rows->sum('home_score') / $played),
            'away_avg' => max(.5, $rows->sum('away_score') / $played),
            'teams' => $stats,
        ];
    }

    private function probabilities(FootballMatch $match, array $history): array
    {
        $homeRows = $history['teams'][$match->home_team_id]['home'] ?? [];
        $awayRows = $history['teams'][$match->away_team_id]['away'] ?? [];
        $homeFor = $this->shrunkAverage($homeRows, 0, $history['home_avg']);
        $homeAgainst = $this->shrunkAverage($homeRows, 1, $history['away_avg']);
        $awayFor = $this->shrunkAverage($awayRows, 0, $history['away_avg']);
        $awayAgainst = $this->shrunkAverage($awayRows, 1, $history['home_avg']);
        $homeXg = max(.2, min(3.8, $history['home_avg'] * ($homeFor / $history['home_avg']) * ($awayAgainst / $history['home_avg'])));
        $awayXg = max(.2, min(3.8, $history['away_avg'] * ($awayFor / $history['away_avg']) * ($homeAgainst / $history['away_avg'])));

        $homeWin = $draw = $awayWin = $over25 = 0.0;
        for ($home = 0; $home <= 9; $home++) {
            for ($away = 0; $away <= 9; $away++) {
                $p = $this->poisson($home, $homeXg) * $this->poisson($away, $awayXg);
                if ($home > $away) $homeWin += $p; elseif ($home === $away) $draw += $p; else $awayWin += $p;
                if ($home + $away >= 3) $over25 += $p;
            }
        }
        $total = max(.0001, $homeWin + $draw + $awayWin);
        return [
            '1' => $homeWin / $total,
            'X' => $draw / $total,
            '2' => $awayWin / $total,
            'Peste 2.5' => $over25,
            'Sub 2.5' => 1 - $over25,
            '_home_xg' => round($homeXg, 2),
            '_away_xg' => round($awayXg, 2),
        ];
    }

    private function shrunkAverage(array $rows, int $index, float $leagueAverage): float
    {
        $weight = 5;
        return (array_sum(array_column($rows, $index)) + $leagueAverage * $weight) / (count($rows) + $weight);
    }

    private function poisson(int $goals, float $lambda): float
    {
        return exp(-$lambda) * ($lambda ** $goals) / max(1, $this->factorial($goals));
    }

    private function factorial(int $number): int
    {
        $result = 1;
        for ($i = 2; $i <= $number; $i++) $result *= $i;
        return $result;
    }

    private function bestPrices(Collection $odds): array
    {
        return $odds->groupBy(fn ($odd) => $odd->market_id.'|'.$odd->selection)
            ->map(function ($items) {
                $best = $items->sortByDesc('price')->first();
                return ['market_id' => $best->market_id, 'selection' => $best->selection, 'price' => (float) $best->price, 'provider' => $best->provider];
            })->values()->all();
    }

    private function explanation(FootballMatch $match, string $selection, float $probability, float $value, array $history): string
    {
        return sprintf(
            'Modelul Poisson, calibrat pe %d rezultate reale din %s, estimează selecția %s la %.1f%%. Față de cota curentă, avantajul calculat este de %+.1f puncte procentuale. Estimarea folosește golurile marcate/primite acasă și în deplasare și este actualizată la fiecare sincronizare.',
            $history['played'], $match->league->name, $selection, $probability * 100, $value * 100
        );
    }
}

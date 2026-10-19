<?php

namespace App\Services\Mindmap;

use App\Models\FootballMatch;
use App\Services\MatchAnalysisService;
use Illuminate\Support\Facades\DB;

class MatchLevelSimulator
{
    private const OUTCOMES = ['1', 'X', '2'];

    public function __construct(private MatchAnalysisService $analysis) {}

    /**
     * Read-only scenario for the 1/X/2 market. This does not modify recommendations.
     * An enabled level without match-specific evidence or a calibrated 1/X/2 rule is a no-op.
     */
    public function simulate(FootballMatch $match, array $enabledLevels): array
    {
        $match->loadMissing(['league', 'homeTeam', 'awayTeam', 'latestWeatherSnapshot']);
        $enabled = array_fill_keys(array_map('intval', $enabledLevels), true);
        $history = DB::table('matches as m')
            ->join('match_results as r', 'r.match_id', '=', 'm.id')
            ->where('m.league_id', $match->league_id)
            ->where('m.kickoff_at', '<', $match->kickoff_at)
            ->select('r.home_score', 'r.away_score')->get();
        $counts = ['1' => 0, 'X' => 0, '2' => 0];
        foreach ($history as $result) {
            $outcome = $result->home_score > $result->away_score ? '1' : ($result->home_score < $result->away_score ? '2' : 'X');
            $counts[$outcome]++;
        }
        $played = $history->count();
        $baseline = $played >= 10 ? $this->normalize($counts) : null;
        $market = $this->marketEstimate($match);
        $model = $played >= 10 ? $this->analysis->simulateResultProbabilities($match) : null;
        $absences = DB::table('player_absences')->whereIn('team_id', [$match->home_team_id, $match->away_team_id])
            ->where('created_at', '<=', $match->kickoff_at)
            ->where(fn ($query) => $query->whereNull('expected_return')->orWhereDate('expected_return', '>=', $match->kickoff_at->toDateString()))
            ->count();
        $weather = $match->latestWeatherSnapshot;

        $state = null;
        $steps = [];
        foreach (SourceCatalog::LEVELS as $level => $meta) {
            $on = isset($enabled[$level]);
            $status = $on ? 'unavailable' : 'disabled';
            $note = $on ? 'Date indisponibile pentru acest meci.' : 'Nivel exclus din scenariu.';
            $evidence = null;

            if ($level === 1) {
                $evidence = $played.' rezultate anterioare în '.$match->league->name;
                if ($on && $baseline) {
                    $state = $baseline;
                    $status = 'applied';
                    $note = 'Frecvența observată pentru 1/X/2 în liga meciului, înainte de start.';
                } elseif ($on) $note = 'Sunt necesare minimum 10 rezultate anterioare; nu se inventează o bază.';
            } elseif ($level === 2) {
                $evidence = $market ? $market['bookmakers'].' case de pariuri cu cote complete 1/X/2' : 'Nicio serie completă de cote 1/X/2';
                if ($on && $market) {
                    $state = $state ? $this->average($state, $market['probabilities']) : $market['probabilities'];
                    $status = 'applied';
                    $note = 'Probabilități implicite normalizate, fără marja cotelor; media 50/50 cu baza L1 dacă există.';
                }
            } elseif ($level === 3) {
                $evidence = $absences.' absențe active înregistrate pentru cele două echipe';
                if ($on) {
                    $status = $absences ? 'unmodeled' : 'unavailable';
                    $note = $absences ? 'Motorul nu are o calibrare validată a absențelor pentru 1/X/2.' : 'Nu există absențe utilizabile; nu se presupune că lotul este complet.';
                }
            } elseif ($level === 4) {
                $evidence = 'Fără știri corelate verificabil cu acest meci';
                if ($on) $note = 'Nu există adaptor și regulă de probabilitate 1/X/2 pentru acest nivel.';
            } elseif ($level === 5) {
                $evidence = $weather ? 'Snapshot meteo din '.$weather->fetched_at?->format('d.m.Y H:i') : 'Niciun snapshot meteo pentru meci';
                if ($on) {
                    $status = $weather ? 'unmodeled' : 'unavailable';
                    $note = $weather ? 'Regula meteo existentă ajustează doar scorul de încredere pentru peste goluri, nu probabilitatea 1/X/2.' : 'Vremea este indisponibilă; nu se folosește o valoare presupusă.';
                }
            } elseif ($level === 6) {
                $evidence = $model ? 'Poisson din '.$model['history_matches'].' rezultate; goluri așteptate '.$model['expected_home_goals'].' / '.$model['expected_away_goals'] : 'Modelul nu are istoric suficient';
                if ($on && ! isset($enabled[1])) {
                    $status = 'dependency';
                    $note = 'L6 depinde de rezultatele L1; dezactivarea L1 oprește acest calcul.';
                } elseif ($on && $model) {
                    $state = isset($enabled[2]) && $market
                        ? $this->average($model['probabilities'], $market['probabilities'])
                        : $model['probabilities'];
                    $status = 'applied';
                    $note = isset($enabled[2]) && $market
                        ? 'Modelul Poisson pe echipe și consensul cotelor sunt combinate 50/50.'
                        : 'Modelul Poisson pe rezultatele echipelor înlocuiește baza generală a ligii.';
                } elseif ($on) $note = 'Sunt necesare minimum 10 rezultate anterioare în liga meciului.';
            }

            $steps[] = [
                'level' => $level, 'name' => $meta['name'], 'enabled' => $on,
                'status' => $status, 'note' => $note, 'evidence' => $evidence,
                'probabilities' => $state ? $this->rounded($state) : null,
            ];
        }

        return [
            'steps' => $steps, 'final' => $state ? $this->rounded($state) : null,
            'results_count' => $played, 'outcome_counts' => $counts,
            'bookmakers_count' => $market['bookmakers'] ?? 0,
            'market_captured_at' => $market['captured_at'] ?? null,
        ];
    }

    private function marketEstimate(FootballMatch $match): ?array
    {
        $odds = $match->odds()->whereHas('market', fn ($query) => $query->where('key', 'result'))
            ->whereIn('selection', self::OUTCOMES)
            ->where('captured_at', '<=', $match->kickoff_at)
            ->where('price', '>', 1)
            ->get()->groupBy('provider');
        $books = [];
        $latest = null;
        foreach ($odds as $rows) {
            $bySelection = $rows->sortByDesc('captured_at')->unique('selection')->keyBy('selection');
            if (! $bySelection->has('1') || ! $bySelection->has('X') || ! $bySelection->has('2')) continue;
            $inverse = [];
            foreach (self::OUTCOMES as $outcome) $inverse[$outcome] = 1 / $bySelection[$outcome]->price;
            $books[] = $this->normalize($inverse);
            $captured = $rows->max('captured_at');
            if ($captured && (! $latest || $captured->gt($latest))) $latest = $captured;
        }
        if (! $books) return null;

        $consensus = [];
        foreach (self::OUTCOMES as $outcome) {
            $values = array_column($books, $outcome);
            sort($values, SORT_NUMERIC);
            $middle = intdiv(count($values), 2);
            $consensus[$outcome] = count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return ['probabilities' => $this->normalize($consensus), 'bookmakers' => count($books), 'captured_at' => $latest];
    }

    private function normalize(array $values): array
    {
        $total = array_sum($values);
        return array_map(fn ($value) => $value / $total, $values);
    }

    private function average(array $left, array $right): array
    {
        return array_combine(self::OUTCOMES, array_map(fn ($outcome) => ($left[$outcome] + $right[$outcome]) / 2, self::OUTCOMES));
    }

    private function rounded(array $values): array
    {
        return array_map(fn ($value) => round($value * 100, 1), $values);
    }
}

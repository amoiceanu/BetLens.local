<?php

namespace App\Services;

use App\Models\BetlensPrediction;
use App\Models\FootballMatch;

class BetlensPredictionWriter
{
    public function __construct(private MatchAnalysisService $analysis) {}

    /** Existing snapshots are immutable; write only when the model values have changed. */
    public function write(FootballMatch $match, ?array $estimate = null): string
    {
        if ($match->kickoff_at?->lte(now()) || $match->status === 'finished') return 'skipped';
        $estimate ??= $this->analysis->simulateResultProbabilities($match);
        if (! $estimate) return 'unavailable';

        $probabilities = $estimate['probabilities'];
        $values = [
            'home_score' => $estimate['expected_home_goals'],
            'away_score' => $estimate['expected_away_goals'],
            'home_win_probability' => $probabilities['1'],
            'draw_probability' => $probabilities['X'],
            'away_win_probability' => $probabilities['2'],
            'home_win_odds' => $this->fairOdds($probabilities['1']),
            'draw_odds' => $this->fairOdds($probabilities['X']),
            'away_win_odds' => $this->fairOdds($probabilities['2']),
            'model_version' => MatchAnalysisService::MODEL_VERSION,
        ];
        $latest = $match->betlensPredictions()->latest('predicted_at')->latest('id')->first();
        if ($latest && collect($values)->every(fn ($value, $key) =>
            $value === null ? $latest->$key === null :
                (is_numeric($value) ? $latest->$key !== null && abs((float) $latest->$key - (float) $value) <= 0.00001 : $latest->$key === $value))) {
            return 'unchanged';
        }

        BetlensPrediction::create(['game_id' => $match->id, 'predicted_at' => now()] + $values);

        return 'created';
    }

    private function fairOdds(float $probability): ?float
    {
        return $probability > 0 ? min(999999.9999, round(1 / $probability, 4)) : null;
    }
}

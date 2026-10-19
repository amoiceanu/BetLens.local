<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\League;

class UpcomingPredictionBackfill
{
    public function __construct(
        private MatchAnalysisService $analysis,
        private BetlensPredictionWriter $writer,
    ) {}

    public function run(bool $dryRun = false): array
    {
        $matches = FootballMatch::with(['league', 'latestBetlensPrediction'])
            ->where('kickoff_at', '>', now())
            ->where('status', '!=', 'finished')
            ->where(fn ($query) => $query->whereNull('data_status')->orWhere('data_status', '!=', 'conflict'))
            ->orderBy('id')->get();
        $counts = ['matches' => $matches->count(), 'created' => 0, 'unchanged' => 0,
            'unavailable' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($matches->groupBy('league_id') as $leagueMatches) {
            /** @var League|null $league */
            $league = $leagueMatches->first()->league;
            if (! $league) { $counts['unavailable'] += $leagueMatches->count(); continue; }
            $estimates = $this->analysis->simulateUpcomingBatch($league, $leagueMatches);
            foreach ($leagueMatches as $match) {
                $estimate = $estimates[$match->id] ?? null;
                if (! $estimate) { $counts['unavailable']++; continue; }
                if ($dryRun) {
                    $counts[$match->latestBetlensPrediction ? 'unchanged' : 'created']++;
                    continue;
                }
                try {
                    $counts[$this->writer->write($match, $estimate)]++;
                } catch (\Throwable $exception) {
                    report($exception);
                    $counts['errors']++;
                }
            }
        }

        if (! $dryRun) {
            app(ImportAuditService::class)->record('betlens', 'prediction_backfill', $counts['errors'] ? 'failed' : 'success', [
                'read' => $counts['matches'], 'new' => $counts['created'],
                'unchanged' => $counts['unchanged'], 'errors' => $counts['errors'],
            ], 'Estimări BetLens pentru meciuri viitoare.', null, 'BackfillUpcomingPredictions');
        }

        return $counts;
    }
}

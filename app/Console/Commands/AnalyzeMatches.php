<?php

namespace App\Console\Commands;

use App\Models\League;
use App\Services\MatchAnalysisService;
use Illuminate\Console\Command;

class AnalyzeMatches extends Command
{
    protected $signature = 'betlens:analyze {--days=45 : Orizontul analizat în zile}';
    protected $description = 'Generează cote echitabile și recomandări din rezultatele reale disponibile';

    public function handle(MatchAnalysisService $analysis): int
    {
        $totals = ['matches_analyzed' => 0, 'created' => 0, 'updated' => 0, 'eligible' => 0];
        foreach (League::where('active', true)->whereHas('matches', fn ($query) => $query->where('kickoff_at', '>', now()))->get() as $league) {
            $metrics = $analysis->generateModelEstimates($league, max(1, (int) $this->option('days')));
            foreach (array_keys($totals) as $key) $totals[$key] += $metrics[$key];
        }
        $this->table(['Meciuri analizate', 'Recomandări noi', 'Actualizate', 'Eligibile'], [array_values($totals)]);
        return self::SUCCESS;
    }
}

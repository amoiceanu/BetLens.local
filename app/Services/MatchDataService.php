<?php
namespace App\Services;
use App\Models\FootballMatch;
class MatchDataService
{
    public function upcoming()
    {
        return $this->searchable();
    }

    public function searchable(bool $includeStarted = false)
    {
        $query=FootballMatch::with(['league','homeTeam','awayTeam','recommendations.market']);
        if (!$includeStarted) $query->where('kickoff_at','>',now());

        return $query->orderBy('kickoff_at')->orderBy('id')->get();
    }
}

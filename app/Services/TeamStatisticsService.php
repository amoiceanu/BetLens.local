<?php

namespace App\Services;

use App\Models\League;
use App\Models\TeamStatistic;
use Illuminate\Support\Facades\DB;

class TeamStatisticsService
{
    public function rebuild(League $league): int
    {
        $rows = DB::table('matches as m')
            ->join('match_results as r', 'r.match_id', '=', 'm.id')
            ->where('m.league_id', $league->id)
            ->select('m.*', 'r.home_score', 'r.away_score')
            ->orderBy('m.kickoff_at')
            ->get();

        $teams = [];
        foreach ($rows as $row) {
            $this->record($teams, $row->home_team_id, $row->season_id, (int) $row->home_score, (int) $row->away_score);
            $this->record($teams, $row->away_team_id, $row->season_id, (int) $row->away_score, (int) $row->home_score);
        }

        foreach ($teams as $stats) {
            TeamStatistic::updateOrCreate(
                ['team_id' => $stats['team_id'], 'season_id' => $stats['season_id'], 'scope' => 'overall'],
                ['played' => $stats['played'], 'goals_for' => $stats['goals_for'], 'goals_against' => $stats['goals_against'], 'recent_form' => array_slice($stats['form'], -5)]
            );
        }

        return count($teams);
    }

    private function record(array &$teams, int $teamId, ?int $seasonId, int $for, int $against): void
    {
        $key = $teamId.':'.($seasonId ?? 0);
        $teams[$key] ??= ['team_id' => $teamId, 'season_id' => $seasonId, 'played' => 0, 'goals_for' => 0, 'goals_against' => 0, 'form' => []];
        $teams[$key]['played']++;
        $teams[$key]['goals_for'] += $for;
        $teams[$key]['goals_against'] += $against;
        $teams[$key]['form'][] = $for > $against ? 'W' : ($for === $against ? 'D' : 'L');
    }
}

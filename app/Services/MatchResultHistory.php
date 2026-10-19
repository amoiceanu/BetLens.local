<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class MatchResultHistory
{
    /** New results take precedence; legacy history remains readable without being rewritten. */
    public function query(): Builder
    {
        $current = DB::table('game_actual_results')
            ->selectRaw('game_id as match_id, home_score, away_score')
            ->where('match_status', 'finished')
            ->whereNotNull('home_score')->whereNotNull('away_score');
        $legacy = DB::table('match_results')
            ->select('match_id', 'home_score', 'away_score')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('game_actual_results')->whereColumn('game_actual_results.game_id', 'match_results.match_id'));

        return $current->unionAll($legacy);
    }
}

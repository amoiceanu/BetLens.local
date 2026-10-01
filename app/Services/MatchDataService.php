<?php
namespace App\Services;
use App\Models\FootballMatch;
class MatchDataService { public function upcoming(){return FootballMatch::with(['league','homeTeam','awayTeam','recommendations.market'])->where('kickoff_at','>',now())->orderBy('kickoff_at')->get();} }

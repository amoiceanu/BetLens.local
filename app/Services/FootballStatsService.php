<?php
namespace App\Services;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
class FootballStatsService { public function summary(Team $team): array { $stats=DB::table('team_statistics')->where('team_id',$team->id)->latest('id')->first(); return ['form'=>$stats?json_decode($stats->recent_form,true)??[]:[],'scored'=>$stats?->goals_for??0,'conceded'=>$stats?->goals_against??0,'xg'=>$stats?->xg??'—','xga'=>$stats?->xga??'—']; } }

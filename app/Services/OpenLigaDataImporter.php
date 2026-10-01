<?php
namespace App\Services;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
class OpenLigaDataImporter
{
    private const LEAGUES=['premier-league'=>'pl','la-liga'=>'la1','bundesliga'=>'bl1','champions-league'=>'ucl'];
    public function importCurrentSeason(int $season=2026): array
    {
        $metrics=['leagues'=>0,'teams'=>0,'matches_new'=>0,'matches_updated'=>0,'results'=>0,'errors'=>[]];
        foreach(self::LEAGUES as $slug=>$shortcut){
            $league=League::where('slug',$slug)->first(); if(!$league)continue;
            try{$response=Http::acceptJson()->timeout(30)->retry(2,300,throw:false)->get("https://api.openligadb.de/getmatchdata/{$shortcut}/{$season}");if(!$response->successful()){throw new \RuntimeException("HTTP {$response->status()}");}$matches=$response->json();if(!is_array($matches))continue;$metrics['leagues']++;$seasonModel=Season::updateOrCreate(['league_id'=>$league->id,'name'=>$season.'/'.substr((string)($season+1),-2)],['starts_at'=>"{$season}-07-01",'ends_at'=>($season+1).'-06-30','current'=>true]);foreach($matches as $item)$this->importMatch($league,$seasonModel,$item,$metrics);}catch(\Throwable $e){$metrics['errors'][]=$league->name.': '.$e->getMessage();}
        }
        return $metrics;
    }
    private function importMatch(League $league,Season $season,array $item,array &$metrics): void
    {
        if(empty($item['matchID'])||empty($item['team1']['teamId'])||empty($item['team2']['teamId']))return;
        $home=$this->team($league,$item['team1'],$metrics);$away=$this->team($league,$item['team2'],$metrics);$kickoff=Carbon::parse($item['matchDateTimeUTC']??$item['matchDateTime'])->setTimezone(config('app.timezone'));
        $externalId='openligadb:'.$item['matchID'];$existing=FootballMatch::where('external_id',$externalId)->first();$attributes=['league_id'=>$league->id,'season_id'=>$season->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>$kickoff,'status'=>($item['matchIsFinished']??false)?'finished':'scheduled','venue'=>$item['location']['locationStadium']??$item['location']['locationCity']??null];
        $match=FootballMatch::updateOrCreate(['external_id'=>$externalId],$attributes);$existing?$metrics['matches_updated']++:$metrics['matches_new']++;
        if($item['matchIsFinished']??false){$result=collect($item['matchResults']??[])->sortByDesc('resultOrderID')->first();if($result){DB::table('match_results')->updateOrInsert(['match_id'=>$match->id],['home_score'=>(int)$result['pointsTeam1'],'away_score'=>(int)$result['pointsTeam2'],'details'=>json_encode(['provider'=>'openligadb','results'=>$item['matchResults']??[]],JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now()]);$metrics['results']++;}}
    }
    private function team(League $league,array $item,array &$metrics): Team
    {
        $key=['league_id'=>$league->id,'provider'=>'openligadb','external_id'=>(string)$item['teamId']];$team=Team::where($key)->first();if(!$team)$metrics['teams']++;
        $crest=isset($item['teamIconUrl'])&&str_starts_with($item['teamIconUrl'],'http')?$item['teamIconUrl']:null;
        return Team::updateOrCreate($key,['name'=>$item['teamName'],'short_name'=>mb_substr($item['shortName']?:$item['teamName'],0,12),'crest'=>$crest]);
    }
}

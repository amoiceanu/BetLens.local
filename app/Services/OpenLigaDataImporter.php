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
    private const WRITE_BATCH_SIZE = 200;
    private const LEAGUES=['premier-league'=>'pl','la-liga'=>'la1','bundesliga'=>'bl1','champions-league'=>'ucl','zweite-bundesliga'=>'bl2','3-liga'=>'bl3'];
    private const ADDITIONAL_LEAGUES=[
        'zweite-bundesliga'=>['name'=>'2. Bundesliga','country'=>'Germania','code'=>'2BL','accent'=>'#ef4444','active'=>true],
        '3-liga'=>['name'=>'3. Liga','country'=>'Germania','code'=>'3L','accent'=>'#f97316','active'=>true],
    ];

    /** Import only future fixtures; repeated runs update fixtures but keep prediction snapshots. */
    public function importUpcomingMatches(int $days = 30, ?callable $progress = null): array
    {
        $days = max(1, min(90, $days));
        $seasonYear = now()->month >= 7 ? now()->year : now()->year - 1;
        $cutoff = now()->copy()->addDays($days);
        $metrics = ['leagues' => 0, 'read' => 0, 'teams' => 0, 'matches_new' => 0, 'matches_updated' => 0, 'matches_unchanged' => 0,
            'predictions_new' => 0, 'predictions_unchanged' => 0, 'errors' => []];

        foreach (self::LEAGUES as $slug => $shortcut) {
            $league = League::where('slug', $slug)->first();
            if (! $league && isset(self::ADDITIONAL_LEAGUES[$slug])) {
                $league = League::create(self::ADDITIONAL_LEAGUES[$slug] + ['slug' => $slug]);
            }
            if (! $league) continue;
            $progress && $progress('connecting', $league->name, $metrics);
            $started = now();
            $beforeNew = $metrics['matches_new'];
            $beforeUpdated = $metrics['matches_updated'];
            $beforeUnchanged = $metrics['matches_unchanged'];
            $season = null;
            try {
                $response = Http::acceptJson()->timeout(30)->retry(2, 300, throw: false)
                    ->get("https://api.openligadb.de/getmatchdata/{$shortcut}/{$seasonYear}");
                if (! $response->successful() || ! is_array($response->json()) || ! array_is_list($response->json())) {
                    throw new \RuntimeException('Răspuns OpenLigaDB invalid sau HTTP '.$response->status());
                }
                $items = $response->json();
                $metrics['leagues']++;
                $metrics['read'] += count($items);
                $progress && $progress('writing', $league->name, $metrics);
                $season = Season::firstOrCreate(
                    ['league_id' => $league->id, 'name' => $seasonYear.'/'.substr((string) ($seasonYear + 1), -2)],
                    ['starts_at' => "$seasonYear-07-01", 'ends_at' => ($seasonYear + 1).'-06-30', 'current' => true]
                );
                $fixtureBatch=[];
                foreach ($items as $index => $item) {
                    if (! is_array($item) || empty($item['matchID']) || empty($item['matchDateTimeUTC'] ?? $item['matchDateTime'] ?? null)
                    ) continue;
                    if ($item['matchIsFinished'] ?? false) {
                        if (FootballMatch::where('external_id', 'openligadb:'.$item['matchID'])->exists()) {
                            $updateMetrics = ['leagues' => 0, 'teams' => 0, 'matches_new' => 0,
                                'matches_updated' => 0, 'results' => 0, 'errors' => []];
                            $this->importMatch($league, $season, $item, $updateMetrics);
                            $metrics['matches_updated'] += $updateMetrics['matches_updated'];
                        }
                        continue;
                    }
                    $kickoff = Carbon::parse($item['matchDateTimeUTC'] ?? $item['matchDateTime']);
                    if ($kickoff->lte(now()) || $kickoff->gt($cutoff)) continue;
                    $fixtureBatch[]=$item;
                    if(count($fixtureBatch)>=self::WRITE_BATCH_SIZE){$this->importUpcomingBatch($league,$season,$fixtureBatch,$metrics);$fixtureBatch=[];$progress&&$progress('writing',$league->name,$metrics);}
                }
                if($fixtureBatch)$this->importUpcomingBatch($league,$season,$fixtureBatch,$metrics);
                $progress && $progress('league_done', $league->name, $metrics);
                app(ImportAuditService::class)->record('openligadb', 'upcoming_matches', 'healthy', [
                    'league_id' => $league->id, 'season_id' => $season->id, 'started_at' => $started,
                    'read' => count($items), 'new' => $metrics['matches_new'] - $beforeNew,
                    'updated' => $metrics['matches_updated'] - $beforeUpdated,
                    'unchanged' => $metrics['matches_unchanged'] - $beforeUnchanged, 'errors' => 0,
                ], 'Import meciuri viitoare încheiat.', null, 'ImportUpcomingMatches');
            } catch (\Throwable $e) {
                $metrics['errors'][] = $league->name.': '.$e->getMessage();
                $progress && $progress('league_error', $league->name, $metrics);
                app(ImportAuditService::class)->record('openligadb', 'upcoming_matches', 'failed', [
                    'league_id' => $league->id, 'season_id' => $season?->id,
                    'started_at' => $started, 'errors' => 1,
                ], 'Importul meciurilor viitoare a eșuat.', null, 'ImportUpcomingMatches');
            }
        }
        return $metrics;
    }

    /** Refresh results only for already-imported fixtures whose kickoff has passed. */
    public function refreshPastMatchResults(?callable $progress = null): array
    {
        $seasonYear = now()->month >= 7 ? now()->year : now()->year - 1;
        $metrics = ['leagues' => 0, 'read' => 0, 'teams' => 0, 'matches_new' => 0, 'matches_updated' => 0,
            'matches_unchanged' => 0, 'predictions_new' => 0, 'predictions_unchanged' => 0, 'errors' => []];

        foreach (self::LEAGUES as $slug => $shortcut) {
            $league = League::where('slug', $slug)->first();
            if (! $league) continue;
            $progress && $progress('connecting', $league->name, $metrics);
            $started = now();
            $beforeUpdated = $metrics['matches_updated'];
            $season = null;
            try {
                $response = Http::acceptJson()->timeout(30)->retry(2, 300, throw: false)
                    ->get("https://api.openligadb.de/getmatchdata/{$shortcut}/{$seasonYear}");
                if (! $response->successful() || ! is_array($response->json()) || ! array_is_list($response->json())) {
                    throw new \RuntimeException('Răspuns OpenLigaDB invalid sau HTTP '.$response->status());
                }
                $items = $response->json();
                $metrics['leagues']++;
                $metrics['read'] += count($items);
                $progress && $progress('writing', $league->name, $metrics);
                $season = Season::firstOrCreate(
                    ['league_id' => $league->id, 'name' => $seasonYear.'/'.substr((string) ($seasonYear + 1), -2)],
                    ['starts_at' => "$seasonYear-07-01", 'ends_at' => ($seasonYear + 1).'-06-30', 'current' => true]
                );
                foreach ($items as $item) {
                    if (! is_array($item) || empty($item['matchID']) || empty($item['matchDateTimeUTC'] ?? $item['matchDateTime'] ?? null)) continue;
                    $kickoff = Carbon::parse($item['matchDateTimeUTC'] ?? $item['matchDateTime']);
                    if ($kickoff->gt(now())) continue;
                    $existing = FootballMatch::where('external_id', 'openligadb:'.$item['matchID'])->first();
                    if (! $existing) continue;
                    $one = ['leagues' => 0, 'teams' => 0, 'matches_new' => 0, 'matches_updated' => 0,
                        'results' => 0, 'errors' => []];
                    $this->importMatch($league, $season, $item, $one);
                    $metrics['matches_updated'] += $one['matches_updated'];
                }
                $progress && $progress('league_done', $league->name, $metrics);
                app(ImportAuditService::class)->record('openligadb', 'past_match_results', 'healthy', [
                    'league_id' => $league->id, 'season_id' => $season->id, 'started_at' => $started,
                    'read' => count($items), 'updated' => $metrics['matches_updated'] - $beforeUpdated, 'errors' => 0,
                ], 'Actualizare rezultate meciuri trecute încheiată.', null, 'RefreshPastMatchResults');
            } catch (\Throwable $e) {
                $metrics['errors'][] = $league->name.': '.$e->getMessage();
                $progress && $progress('league_error', $league->name, $metrics);
                app(ImportAuditService::class)->record('openligadb', 'past_match_results', 'failed', [
                    'league_id' => $league->id, 'season_id' => $season?->id, 'started_at' => $started, 'errors' => 1,
                ], 'Actualizarea rezultatelor a eșuat.', null, 'RefreshPastMatchResults');
            }
        }

        return $metrics;
    }

    private function importUpcomingBatch(League $league, Season $season, array $items, array &$metrics): void
    {
        $teamItems=[];$valid=[];
        foreach($items as $item){if(empty($item['matchID'])||empty($item['team1']['teamId'])||empty($item['team2']['teamId']))continue;$valid[]=$item;foreach([$item['team1'],$item['team2']] as $team)$teamItems[(string)$team['teamId']]=$team;}
        if(!$valid)return;
        $teamRows=[];$now=now();$teamKeys=array_keys($teamItems);
        foreach(array_chunk($teamItems,self::WRITE_BATCH_SIZE,true) as $chunk){
            $existing=DB::table('teams')->where('league_id',$league->id)->where('provider','openligadb')->whereIn('external_id',array_map('strval',array_keys($chunk)))->get(['external_id'])->keyBy('external_id');
            foreach($chunk as $externalId=>$team){$crest=isset($team['teamIconUrl'])&&str_starts_with($team['teamIconUrl'],'http')?$team['teamIconUrl']:null;$teamRows[]=['league_id'=>$league->id,'provider'=>'openligadb','external_id'=>(string)$externalId,'name'=>$team['teamName'],'short_name'=>mb_substr($team['shortName']?:$team['teamName'],0,12),'crest'=>$crest,'created_at'=>$now,'updated_at'=>$now];if(!$existing->has((string)$externalId))$metrics['teams']++;}
        }
        foreach(array_chunk($teamRows,self::WRITE_BATCH_SIZE) as $chunk)DB::table('teams')->upsert($chunk,['league_id','provider','external_id'],['name','short_name','crest','updated_at']);
        $teams=Team::where('league_id',$league->id)->where('provider','openligadb')->whereIn('external_id',array_map('strval',$teamKeys))->get()->keyBy('external_id');
        $externalMatches=array_map(fn($item)=>'openligadb:'.$item['matchID'],$valid);$existingMatches=FootballMatch::with('actualResult')->whereIn('external_id',$externalMatches)->get()->keyBy('external_id');
        $matchRows=[];$resultRows=[];$predictionMatches=[];
        foreach($valid as $item){$externalId='openligadb:'.$item['matchID'];$old=$existingMatches->get($externalId);if($old?->status==='finished'||$old?->actualResult?->match_status==='finished')continue;
            $home=$teams->get((string)$item['team1']['teamId']);$away=$teams->get((string)$item['team2']['teamId']);if(!$home||!$away)continue;
            $kickoff=Carbon::parse($item['matchDateTimeUTC']??$item['matchDateTime'])->setTimezone(config('app.timezone'));
            $matchRows[]=['external_id'=>$externalId,'league_id'=>$league->id,'season_id'=>$season->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>$kickoff->format('Y-m-d H:i:s'),'status'=>'scheduled','venue'=>$item['location']['locationStadium']??$item['location']['locationCity']??null,'created_at'=>$now,'updated_at'=>$now];
            if(!$old)$metrics['matches_new']++;else $metrics['matches_updated']++;
        }
        foreach(array_chunk($matchRows,self::WRITE_BATCH_SIZE) as $chunk)DB::table('matches')->upsert($chunk,['external_id'],['league_id','season_id','home_team_id','away_team_id','kickoff_at','status','venue','updated_at']);
        if(!$matchRows)return;
        $saved=FootballMatch::whereIn('external_id',array_column($matchRows,'external_id'))->with(['league','homeTeam','awayTeam'])->get();
        foreach($saved as $match){$resultRows[]=['game_id'=>$match->id,'match_status'=>'scheduled','created_at'=>$now,'updated_at'=>$now];$predictionMatches[]=$match;}
        foreach(array_chunk($resultRows,self::WRITE_BATCH_SIZE) as $chunk)DB::table('game_actual_results')->upsert($chunk,['game_id'],['updated_at']);
        foreach($predictionMatches as $match){$prediction=app(BetlensPredictionWriter::class)->write($match);if($prediction==='created')$metrics['predictions_new']++;elseif($prediction==='unchanged')$metrics['predictions_unchanged']++;}
    }
    public function importCurrentSeason(int $season=2026): array
    {
        $metrics=['leagues'=>0,'teams'=>0,'matches_new'=>0,'matches_updated'=>0,'results'=>0,'errors'=>[]];
        foreach(self::LEAGUES as $slug=>$shortcut){
            $league=League::where('slug',$slug)->first();if(!$league&&isset(self::ADDITIONAL_LEAGUES[$slug]))$league=League::create(self::ADDITIONAL_LEAGUES[$slug]+['slug'=>$slug]);if(!$league)continue;$auditStarted=now();$before=$metrics;$seasonModel=null;
            try{$response=Http::acceptJson()->timeout(30)->retry(2,300,throw:false)->get("https://api.openligadb.de/getmatchdata/{$shortcut}/{$season}");if(!$response->successful()){throw new \RuntimeException("HTTP {$response->status()}");}$matches=$response->json();if(!is_array($matches))continue;$metrics['leagues']++;$seasonModel=Season::updateOrCreate(['league_id'=>$league->id,'name'=>$season.'/'.substr((string)($season+1),-2)],['starts_at'=>"{$season}-07-01",'ends_at'=>($season+1).'-06-30','current'=>true]);foreach(array_chunk($matches,self::WRITE_BATCH_SIZE) as $batch)$this->importSeasonBatch($league,$seasonModel,$batch,$metrics);app(\App\Services\Coverage\ObservationProjector::class)->safely(fn()=>app(ImportAuditService::class)->record('openligadb','league_fixtures','healthy',['league_id'=>$league->id,'season_id'=>$seasonModel->id,'started_at'=>$auditStarted,'finished_at'=>now(),'read'=>count($matches),'new'=>$metrics['matches_new']-$before['matches_new'],'updated'=>$metrics['matches_updated']-$before['matches_updated'],'errors'=>0],'Import meciuri încheiat.'));}catch(\Throwable $e){$metrics['errors'][]=$league->name.': '.$e->getMessage();app(\App\Services\Coverage\ObservationProjector::class)->safely(fn()=>app(ImportAuditService::class)->record('openligadb','league_fixtures','unavailable',['league_id'=>$league->id,'season_id'=>$seasonModel?->id,'started_at'=>$auditStarted,'finished_at'=>now(),'errors'=>1],'Importul ligii nu a putut fi finalizat.'));}
        }
        app(\App\Services\Coverage\ObservationProjector::class)->flush();
        return $metrics;
    }

    private function importSeasonBatch(League $league, Season $season, array $items, array &$metrics): void
    {
        $valid=[];$teams=[];$now=now();
        foreach($items as $item){if(!is_array($item)||empty($item['matchID'])||empty($item['team1']['teamId'])||empty($item['team2']['teamId'])||empty($item['matchDateTimeUTC']??$item['matchDateTime']??null))continue;$valid[]=$item;foreach([$item['team1'],$item['team2']] as $team)$teams[(string)$team['teamId']]=$team;}
        if(!$valid)return;
        $teamRows=[];
        foreach(array_chunk($teams,self::WRITE_BATCH_SIZE,true) as $batch){
            $externalIds=array_map('strval',array_keys($batch));$old=DB::table('teams')->where('league_id',$league->id)->where('provider','openligadb')->whereIn('external_id',$externalIds)->pluck('external_id')->all();
            foreach($batch as $id=>$team){$crest=isset($team['teamIconUrl'])&&str_starts_with($team['teamIconUrl'],'http')?$team['teamIconUrl']:null;$teamRows[]=['league_id'=>$league->id,'provider'=>'openligadb','external_id'=>(string)$id,'name'=>$team['teamName'],'short_name'=>mb_substr($team['shortName']?:$team['teamName'],0,12),'crest'=>$crest,'created_at'=>$now,'updated_at'=>$now];if(!in_array((string)$id,$old,true))$metrics['teams']++;}
        }
        foreach(array_chunk($teamRows,self::WRITE_BATCH_SIZE) as $batch)DB::table('teams')->upsert($batch,['league_id','provider','external_id'],['name','short_name','crest','updated_at']);
        $teamModels=Team::where('league_id',$league->id)->where('provider','openligadb')->whereIn('external_id',array_map('strval',array_keys($teams)))->get()->keyBy('external_id');
        $keys=array_map(fn($item)=>'openligadb:'.$item['matchID'],$valid);$oldMatches=FootballMatch::whereIn('external_id',$keys)->get()->keyBy('external_id');$matchRows=[];$pendingResults=[];
        foreach($valid as $item){$home=$teamModels->get((string)$item['team1']['teamId']);$away=$teamModels->get((string)$item['team2']['teamId']);if(!$home||!$away)continue;$key='openligadb:'.$item['matchID'];$kickoff=Carbon::parse($item['matchDateTimeUTC']??$item['matchDateTime'])->setTimezone(config('app.timezone'));$status=($item['matchIsFinished']??false)?'finished':'scheduled';$matchRows[]=['external_id'=>$key,'league_id'=>$league->id,'season_id'=>$season->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>$kickoff->format('Y-m-d H:i:s'),'status'=>$status,'venue'=>$item['location']['locationStadium']??$item['location']['locationCity']??null,'created_at'=>$now,'updated_at'=>$now];$old=$oldMatches->get($key);$old?$metrics['matches_updated']++:$metrics['matches_new']++;$pendingResults[$key]=['item'=>$item,'home'=>$home,'away'=>$away,'status'=>$status];}
        foreach(array_chunk($matchRows,self::WRITE_BATCH_SIZE) as $batch)DB::table('matches')->upsert($batch,['external_id'],['league_id','season_id','home_team_id','away_team_id','kickoff_at','status','venue','updated_at']);
        $saved=FootballMatch::whereIn('external_id',array_column($matchRows,'external_id'))->get()->keyBy('external_id');$oldResults=DB::table('game_actual_results')->whereIn('game_id',$saved->pluck('id'))->get()->keyBy('game_id');$resultRows=[];$observations=[];
        foreach($pendingResults as $key=>$entry){$match=$saved->get($key);if(!$match)continue;$values=['match_status'=>$entry['status'],'result_updated_at'=>$now];$full=collect($entry['item']['matchResults']??[])->firstWhere('resultTypeID',2);$half=collect($entry['item']['matchResults']??[])->firstWhere('resultTypeID',1);if($full&&isset($full['pointsTeam1']))$values['home_score']=$full['pointsTeam1'];if($full&&isset($full['pointsTeam2']))$values['away_score']=$full['pointsTeam2'];if($half&&isset($half['pointsTeam1']))$values['home_score_ht']=$half['pointsTeam1'];if($half&&isset($half['pointsTeam2']))$values['away_score_ht']=$half['pointsTeam2'];$old=$oldResults->get($match->id);$resultRows[]=['game_id'=>$match->id,'home_score'=>$values['home_score']??$old?->home_score,'away_score'=>$values['away_score']??$old?->away_score,'home_score_ht'=>$values['home_score_ht']??$old?->home_score_ht,'away_score_ht'=>$values['away_score_ht']??$old?->away_score_ht]+$values+['created_at'=>$now,'updated_at'=>$now];if($full!==null)$metrics['results']++;$observations[]=[$match,$entry['home'],$entry['away'],$values];}
        foreach(array_chunk($resultRows,self::WRITE_BATCH_SIZE) as $batch)DB::table('game_actual_results')->upsert($batch,['game_id'],['match_status','result_updated_at','home_score','away_score','home_score_ht','away_score_ht','updated_at']);
        $projector=app(\App\Services\Coverage\ObservationProjector::class);$source=\App\Models\DataSource::where('slug','openligadb')->first();if($source)foreach($observations as [$match,$home,$away,$values])$projector->safely(fn()=>$projector->record($source,['external_id'=>substr($match->external_id,strlen('openligadb:')),'match_status'=>$values['match_status'],'values'=>['kickoff_at'=>$match->kickoff_at->copy()->utc()->toIso8601String(),'home_team'=>$home->name,'away_team'=>$away->name]+array_intersect_key($values,array_flip(\App\Models\GameActualResult::COMPARISON_COLUMNS))],$match,now()));
    }
    private function importMatch(League $league,Season $season,array $item,array &$metrics): void
    {
        if(empty($item['matchID'])||empty($item['team1']['teamId'])||empty($item['team2']['teamId']))return;
        $home=$this->team($league,$item['team1'],$metrics);$away=$this->team($league,$item['team2'],$metrics);$kickoff=Carbon::parse($item['matchDateTimeUTC']??$item['matchDateTime'])->setTimezone(config('app.timezone'));
        $externalId='openligadb:'.$item['matchID'];$existing=FootballMatch::where('external_id',$externalId)->first();$attributes=['league_id'=>$league->id,'season_id'=>$season->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>$kickoff,'status'=>($item['matchIsFinished']??false)?'finished':'scheduled','venue'=>$item['location']['locationStadium']??$item['location']['locationCity']??null];
        $match=FootballMatch::updateOrCreate(['external_id'=>$externalId],$attributes);$existing?$metrics['matches_updated']++:$metrics['matches_new']++;
        $values = ['match_status' => $attributes['status'], 'result_updated_at' => now()];
        $results = collect($item['matchResults'] ?? []);
        // OpenLigaDB: resultTypeID 1 = half time, 2 = full time. Never treat missing scores as 0.
        $fullTime = $results->firstWhere('resultTypeID', 2);
        $halfTime = $results->firstWhere('resultTypeID', 1);
        foreach (['home' => 'pointsTeam1', 'away' => 'pointsTeam2'] as $side => $key) {
            if ($fullTime !== null && isset($fullTime[$key])) $values[$side.'_score'] = $fullTime[$key];
            if ($halfTime !== null && isset($halfTime[$key])) $values[$side.'_score_ht'] = $halfTime[$key];
        }
        DB::table('game_actual_results')->upsert([['game_id'=>$match->id]+$values+['created_at'=>now(),'updated_at'=>now()]],['game_id'],array_values(array_unique(array_merge(array_keys($values),['updated_at']))));
        $projector=app(\App\Services\Coverage\ObservationProjector::class);
        $projector->safely(function()use($projector,$match,$home,$away,$values){
            $source=\App\Models\DataSource::where('slug','openligadb')->first();
            if($source)$projector->record($source,['external_id'=>substr($match->external_id,strlen('openligadb:')),'match_status'=>$values['match_status'],'values'=>['kickoff_at'=>$match->kickoff_at->copy()->utc()->toIso8601String(),'home_team'=>$home->name,'away_team'=>$away->name]+array_intersect_key($values,array_flip(\App\Models\GameActualResult::COMPARISON_COLUMNS))],$match,now());
        });
        if ($fullTime !== null) $metrics['results']++;

    }
    private function team(League $league,array $item,array &$metrics): Team
    {
        $key=['league_id'=>$league->id,'provider'=>'openligadb','external_id'=>(string)$item['teamId']];$team=Team::where($key)->first();if(!$team)$metrics['teams']++;
        $crest=isset($item['teamIconUrl'])&&str_starts_with($item['teamIconUrl'],'http')?$item['teamIconUrl']:null;
        DB::table('teams')->upsert([['league_id'=>$league->id,'provider'=>'openligadb','external_id'=>(string)$item['teamId'],'name'=>$item['teamName'],'short_name'=>mb_substr($item['shortName']?:$item['teamName'],0,12),'crest'=>$crest,'created_at'=>now(),'updated_at'=>now()]],['league_id','provider','external_id'],['name','short_name','crest','updated_at']);
        return Team::where($key)->firstOrFail();
    }
}

<?php
namespace App\Services;
use App\Models\FootballMatch;
use App\Models\GameActualResult;
use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
class OpenLigaDataImporter
{
    private const LEAGUES=['premier-league'=>'pl','la-liga'=>'la1','bundesliga'=>'bl1','champions-league'=>'ucl'];

    /** Import only future fixtures; repeated runs update fixtures but keep prediction snapshots. */
    public function importUpcomingMatches(int $days = 30, ?callable $progress = null): array
    {
        $days = max(1, min(90, $days));
        $seasonYear = now()->month >= 7 ? now()->year : now()->year - 1;
        $cutoff = now()->copy()->addDays($days);
        $metrics = ['leagues' => 0, 'read' => 0, 'matches_new' => 0, 'matches_updated' => 0, 'matches_unchanged' => 0,
            'predictions_new' => 0, 'predictions_unchanged' => 0, 'errors' => []];

        foreach (self::LEAGUES as $slug => $shortcut) {
            $league = League::where('slug', $slug)->first();
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
                    $this->importUpcomingMatch($league, $season, $item, $metrics);
                    if ($index % 25 === 0) $progress && $progress('writing', $league->name, $metrics);
                }
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

    private function importUpcomingMatch(League $league, Season $season, array $item, array &$metrics): void
    {
        if (empty($item['team1']['teamId']) || empty($item['team2']['teamId'])) return;
        $externalId = 'openligadb:'.$item['matchID'];
        $match = FootballMatch::where('external_id', $externalId)->first();
        if ($match?->status === 'finished' || $match?->actualResult?->match_status === 'finished') return;
        $teamMetrics = ['teams' => 0];
        $home = $this->team($league, $item['team1'], $teamMetrics);
        $away = $this->team($league, $item['team2'], $teamMetrics);
        $attributes = ['league_id' => $league->id, 'season_id' => $season->id,
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'kickoff_at' => Carbon::parse($item['matchDateTimeUTC'] ?? $item['matchDateTime'])->setTimezone(config('app.timezone')),
            'status' => 'scheduled', 'venue' => $item['location']['locationStadium'] ?? $item['location']['locationCity'] ?? null];
        $match = FootballMatch::updateOrCreate(['external_id' => $externalId], $attributes);
        if ($match->wasRecentlyCreated) $metrics['matches_new']++;
        elseif ($match->wasChanged()) $metrics['matches_updated']++;
        else $metrics['matches_unchanged']++;
        GameActualResult::firstOrCreate(['game_id' => $match->id], ['match_status' => 'scheduled']);

        $prediction = app(BetlensPredictionWriter::class)->write($match);
        if ($prediction === 'created') $metrics['predictions_new']++;
        elseif ($prediction === 'unchanged') $metrics['predictions_unchanged']++;
    }
    public function importCurrentSeason(int $season=2026): array
    {
        $metrics=['leagues'=>0,'teams'=>0,'matches_new'=>0,'matches_updated'=>0,'results'=>0,'errors'=>[]];
        foreach(self::LEAGUES as $slug=>$shortcut){
            $league=League::where('slug',$slug)->first(); if(!$league)continue;$auditStarted=now();$before=$metrics;$seasonModel=null;
            try{$response=Http::acceptJson()->timeout(30)->retry(2,300,throw:false)->get("https://api.openligadb.de/getmatchdata/{$shortcut}/{$season}");if(!$response->successful()){throw new \RuntimeException("HTTP {$response->status()}");}$matches=$response->json();if(!is_array($matches))continue;$metrics['leagues']++;$seasonModel=Season::updateOrCreate(['league_id'=>$league->id,'name'=>$season.'/'.substr((string)($season+1),-2)],['starts_at'=>"{$season}-07-01",'ends_at'=>($season+1).'-06-30','current'=>true]);foreach($matches as $item)$this->importMatch($league,$seasonModel,$item,$metrics);app(\App\Services\Coverage\ObservationProjector::class)->safely(fn()=>app(ImportAuditService::class)->record('openligadb','league_fixtures','healthy',['league_id'=>$league->id,'season_id'=>$seasonModel->id,'started_at'=>$auditStarted,'finished_at'=>now(),'read'=>count($matches),'new'=>$metrics['matches_new']-$before['matches_new'],'updated'=>$metrics['matches_updated']-$before['matches_updated'],'errors'=>0],'Import meciuri încheiat.'));}catch(\Throwable $e){$metrics['errors'][]=$league->name.': '.$e->getMessage();app(\App\Services\Coverage\ObservationProjector::class)->safely(fn()=>app(ImportAuditService::class)->record('openligadb','league_fixtures','unavailable',['league_id'=>$league->id,'season_id'=>$seasonModel?->id,'started_at'=>$auditStarted,'finished_at'=>now(),'errors'=>1],'Importul ligii nu a putut fi finalizat.'));}
        }
        app(\App\Services\Coverage\ObservationProjector::class)->flush();
        return $metrics;
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
        GameActualResult::updateOrCreate(['game_id' => $match->id], $values);
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
        return Team::updateOrCreate($key,['name'=>$item['teamName'],'short_name'=>mb_substr($item['shortName']?:$item['teamName'],0,12),'crest'=>$crest]);
    }
}

<?php

namespace App\Services\Providers;

use App\Contracts\FootballDataProviderInterface;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SportmonksProvider implements FootballDataProviderInterface
{
    public function verifyConnection(): array
    {
        $leagues=$this->request('/football/leagues',['include'=>'currentSeason','per_page'=>50]);
        $coverage=$this->superLigaCoverage($leagues);
        $coverageText=collect($coverage['capabilities'])->map(fn($state,$name)=>$name.'='.($state==='available'?'disponibil':'indisponibil'))->implode(', ');
        return ['records'=>$leagues,'coverage'=>$coverage,'message'=>'Conexiune Sportmonks confirmată. '.count($leagues).' ligi returnate. SuperLiga România: '.($coverage['found']?'identificată':'neidentificată').'; '.$coverageText.'.'];
    }

    public function upcomingMatches(): array { return $this->fixtures(['from'=>now()->toDateString(),'to'=>now()->addDays(30)->toDateString()]); }

    public function fixtures(array $filters=[]): array
    {
        $query=['include'=>'league;season;participants;scores;venue;statistics;lineups;odds','per_page'=>50];
        if(isset($filters['from'],$filters['to'])) $query['filters[between]']=$filters['from'].','.$filters['to'];
        if(isset($filters['league_id'])) $query['filters[fixtureLeagues]']=$filters['league_id'];
        return array_map(fn(array $fixture)=>$this->normalizeFixture($fixture),$this->request('/football/fixtures',$query));
    }

    public function statistics(array $filters=[]): array
    {
        $query=['include'=>'statistics;participants','per_page'=>50];
        if(isset($filters['fixture_id'])) return $this->request('/football/fixtures/'.$filters['fixture_id'],$query);
        return $this->request('/football/fixtures',$query);
    }

    public function absences(array $filters=[]): array
    {
        $query=['include'=>'player;team;fixture','per_page'=>50];
        if(isset($filters['league_id'])) $query['filters[leagues]']=$filters['league_id'];
        return $this->request('/football/injuries',$query);
    }

    public function standings(array $filters=[]): array
    {
        $query=['include'=>'participant;details;rule','per_page'=>50];
        if(isset($filters['season_id']))$query['filters[standingSeasons]']=$filters['season_id'];
        return $this->request('/football/standings',$query);
    }

    private function superLigaCoverage(array $initialLeagues): array
    {
        $leagues=collect($initialLeagues);
        $league=$leagues->first(fn($item)=>str_contains(strtolower((string)($item['name']??'')),'superliga')&&str_contains(strtolower(json_encode($item)),'rom'));
        if(!$league){try{$searched=$this->request('/football/leagues',['search'=>'SuperLiga','per_page'=>50]);$league=collect($searched)->first(fn($item)=>str_contains(strtolower((string)($item['name']??'')),'superliga'));}catch(RuntimeException){$league=null;}}
        $unavailable=['fixtures'=>'unavailable','statistics'=>'unavailable','absences'=>'unavailable','xg'=>'unavailable','odds'=>'unavailable'];
        if(!$league)return ['found'=>false,'league_id'=>null,'league_name'=>null,'capabilities'=>$unavailable,'checked_at'=>now()->toIso8601String()];
        try{$fixtures=$this->fixtures(['league_id'=>$league['id']]);}catch(RuntimeException){$fixtures=[];}
        $raw=$fixtures[0]['raw']??[];
        try{$injuries=$this->absences(['league_id'=>$league['id']]);}catch(RuntimeException){$injuries=[];}
        $hasStatistics=filled(data_get($raw,'statistics'));
        $statistics=json_encode(data_get($raw,'statistics',[]));
        return ['found'=>true,'league_id'=>(string)$league['id'],'league_name'=>$league['name']??'SuperLiga România','capabilities'=>[
            'fixtures'=>$fixtures?'available':'unavailable','statistics'=>$hasStatistics?'available':'unavailable','absences'=>$injuries?'available':'unavailable','xg'=>stripos($statistics,'expected')!==false||stripos($statistics,'xg')!==false?'available':'unavailable','odds'=>filled(data_get($raw,'odds'))?'available':'unavailable',
        ],'checked_at'=>now()->toIso8601String()];
    }

    private function normalizeFixture(array $fixture): array
    {
        $participants=collect(data_get($fixture,'participants',[]));
        $home=$participants->first(fn($team)=>data_get($team,'meta.location')==='home')??$participants->first();
        $away=$participants->first(fn($team)=>data_get($team,'meta.location')==='away')??$participants->skip(1)->first();
        $venue=data_get($fixture,'venue',[]);
        return ['external_id'=>(string)($fixture['id']??''),'league'=>(string)(data_get($fixture,'league.name')??data_get($fixture,'league_id')??''),'home_team'=>(string)($home['name']??''),'away_team'=>(string)($away['name']??''),'kickoff_at'=>isset($fixture['starting_at'])?Carbon::parse($fixture['starting_at'])->toIso8601String():null,'venue'=>['name'=>$venue['name']??null,'city'=>$venue['city_name']??null,'latitude'=>$venue['latitude']??null,'longitude'=>$venue['longitude']??null,'timezone'=>$venue['timezone']??null],'raw'=>$fixture];
    }

    private function request(string $path,array $query=[]): array
    {
        $token=(string)config('services.sportmonks.token');
        if($token==='')throw new RuntimeException('Tokenul Sportmonks nu este configurat.');
        $response=$this->client()->get($path,$query+['api_token'=>$token]);
        if($response->status()===429)throw new RuntimeException('Limita de cereri Sportmonks a fost atinsă; sincronizarea va fi reîncercată.');
        if(!$response->successful())throw new RuntimeException('Sportmonks a răspuns cu HTTP '.$response->status().'.');
        $data=$response->json('data');
        if($data===null)return [];
        return array_is_list($data)?$data:[$data];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string)config('services.sportmonks.base_url'),'/'))->acceptJson()->timeout(25)->retry(3,750,throw:false);
    }
}

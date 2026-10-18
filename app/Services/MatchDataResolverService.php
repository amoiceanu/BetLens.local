<?php

namespace App\Services;

use App\Models\ExternalIdMapping;
use App\Models\FootballMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MatchDataResolverService
{
    public function resolve(string $provider,array $fixture): array
    {
        $externalId=(string)($fixture['external_id']??'');$kickoff=isset($fixture['kickoff_at'])?Carbon::parse($fixture['kickoff_at']):null;
        if($externalId===''||!$kickoff)return ['status'=>'unavailable','match'=>null,'message'=>'Identificatorul extern sau ora de start lipsește.'];
        $mapped=ExternalIdMapping::where(['provider'=>$provider,'entity_type'=>'match','external_id'=>$externalId])->first();
        if($mapped){$match=FootballMatch::find($mapped->internal_id);if($match){$mapped->update(['last_verified_at'=>now()]);return ['status'=>$match->data_status,'match'=>$match,'message'=>'Mapare existentă verificată.'];}}
        $candidates=FootballMatch::with(['homeTeam','awayTeam','league'])->whereBetween('kickoff_at',[$kickoff->copy()->subHours(2),$kickoff->copy()->addHours(2)])->get();
        $home=$this->normalize((string)($fixture['home_team']??''));$away=$this->normalize((string)($fixture['away_team']??''));$league=$this->normalize((string)($fixture['league']??''));
        $teamMatch=$candidates->first(fn($match)=>$this->normalize($match->homeTeam->name)===$home&&$this->normalize($match->awayTeam->name)===$away);
        if(!$teamMatch)return ['status'=>'unavailable','match'=>null,'message'=>'Meciul secundar nu a putut fi asociat identității API-Football.'];
        $minutes=abs($teamMatch->kickoff_at->diffInMinutes($kickoff));$leagueMatches=$league===''||str_contains($this->normalize($teamMatch->league->name),$league)||str_contains($league,$this->normalize($teamMatch->league->name));
        if($minutes>15||!$leagueMatches){$details=['provider'=>$provider,'external_id'=>$externalId,'reported_kickoff'=>$kickoff->toIso8601String(),'primary_kickoff'=>$teamMatch->kickoff_at->toIso8601String(),'reported_league'=>$fixture['league']??null];$teamMatch->update(['data_status'=>'conflict','conflict_details'=>$details]);$teamMatch->recommendations()->update(['eligible'=>false]);$this->logConflict($provider,$details);return ['status'=>'conflict','match'=>$teamMatch,'message'=>'Conflict între surse; meciul a fost exclus din recomandări.'];}
        ExternalIdMapping::updateOrCreate(['provider'=>$provider,'entity_type'=>'match','external_id'=>$externalId],['internal_id'=>$teamMatch->id,'external_name'=>trim(($fixture['home_team']??'').' - '.($fixture['away_team']??'')),'metadata'=>['league'=>$fixture['league']??null,'kickoff_at'=>$fixture['kickoff_at']],'last_verified_at'=>now()]);
        $venue=$fixture['venue']??[];$updates=[];foreach(['latitude'=>'venue_latitude','longitude'=>'venue_longitude','city'=>'venue_city','timezone'=>'venue_timezone'] as $from=>$to)if(isset($venue[$from])&&$venue[$from]!=='')$updates[$to]=$venue[$from];if($updates)$teamMatch->update($updates);
        return ['status'=>'resolved','match'=>$teamMatch->fresh(),'message'=>'Meci asociat fără suprascrierea datelor principale.'];
    }

    private function normalize(string $value): string { return Str::of($value)->ascii()->lower()->replaceMatches('/\b(fc|fcsb|club|sc|afc)\b/','')->replaceMatches('/[^a-z0-9]+/','')->toString(); }
    private function logConflict(string $provider,array $details): void { DB::table('data_sync_logs')->insert(['provider'=>$provider,'type'=>'provider_conflict','status'=>'conflict','records'=>0,'message'=>'Conflict de identitate: '.json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'started_at'=>now(),'finished_at'=>now(),'created_at'=>now(),'updated_at'=>now()]); }
}

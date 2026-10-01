<?php
namespace App\Jobs;
use App\Models\DataSource;
use App\Models\SourceRecord;
use App\Services\OpenLigaDataImporter;
use App\Services\OddsImportService;
use App\Services\MatchAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;
class VerifyDataSource implements ShouldQueue
{
    use Queueable;
    public int $tries=3;
    public array $backoff=[30,120,300];
    public function __construct(public int $sourceId) {}
    public function handle(): void
    {
        $source=DataSource::findOrFail($this->sourceId); $started=microtime(true); $source->update(['status'=>'checking']);
        try {
            $key=$source->slug==='the-odds-api' ? config('services.odds_api.key') : ($source->credential_env ? env($source->credential_env) : null);
            if($source->credential_env && blank($key)){$this->finish($source,'not_configured',0,"Lipsește {$source->credential_env} din fișierul .env.",$started,['database'=>$source->records()->count(),'errors'=>1]);return;}
            $request=Http::acceptJson()->timeout(20)->retry(2,250,throw:false);
            if($source->slug==='football-data') $request=$request->withHeader('X-Auth-Token',$key);
            if($source->slug==='api-football') $request=$request->withHeader('x-apisports-key',$key);
            $url=$source->verification_url;
            if($source->slug==='the-odds-api') $url.='?apiKey='.urlencode($key);
            $response=$request->get($url);
            if(!$response->successful()){$this->finish($source,'degraded',0,"Sursa a răspuns cu HTTP {$response->status()}.",$started,['database'=>$source->records()->count(),'errors'=>1]);return;}
            $records=$this->extractRecords($source,$response);
            $metrics=$this->importRecords($source,$records);
            $message=count($records)>0 ? 'Conexiune confirmată; '.count($records).' înregistrări au fost sincronizate în MySQL.' : 'Conexiune confirmată, dar sursa nu a returnat înregistrări importabile.';
            if($source->slug==='openligadb'){$domain=app(OpenLigaDataImporter::class)->importCurrentSeason();$model=['created'=>0,'updated'=>0,'eligible'=>0];foreach(\App\Models\League::whereIn('slug',['premier-league','la-liga','bundesliga','champions-league'])->get() as $league){$generated=app(MatchAnalysisService::class)->generateModelEstimates($league);foreach(['created','updated','eligible'] as $metric)$model[$metric]+=$generated[$metric];}$message.=" Date operaționale: {$domain['teams']} echipe noi, {$domain['matches_new']} meciuri noi, {$domain['matches_updated']} actualizate. Analiză model: {$model['created']} recomandări noi, {$model['eligible']} eligibile.";if($domain['errors'])$message.=' Erori: '.implode('; ',$domain['errors']);}
            if($source->slug==='the-odds-api'){$odds=app(OddsImportService::class)->sync($key);$message.=" Cote: {$odds['odds_new']} noi, {$odds['odds_updated']} actualizate, {$odds['matched']} meciuri asociate. Analiză: {$odds['recommendations']} recomandări, {$odds['eligible']} eligibile.";if($odds['requests_remaining']!==null)$message.=" Credite API rămase: {$odds['requests_remaining']}.";if($odds['errors'])$message.=' Erori parțiale: '.implode('; ',$odds['errors']);}
            $this->finish($source,'healthy',count($records),$message,$started,$metrics);
        } catch(Throwable $e){$this->finish($source,'unavailable',0,'Eroare de conexiune: '.str($e->getMessage())->limit(180),$started,['database'=>$source->records()->count(),'errors'=>1]);}
    }
    private function extractRecords(DataSource $source,Response $response): array
    {
        if($source->slug==='football-data-uk') return $this->extractCsvRecords($response->body());
        $payload=$response->json();
        if(is_array($payload)){
            foreach(['competitions','response','sports','data','results'] as $key) if(isset($payload[$key])&&is_array($payload[$key])) return array_is_list($payload[$key])?$payload[$key]:[$payload[$key]];
            return array_is_list($payload)?$payload:[$payload];
        }
        $body=$response->body();
        return trim($body)===''?[]:[['url'=>$source->verification_url,'content_hash'=>hash('sha256',$body),'bytes'=>strlen($body)]];
    }
    private function extractCsvRecords(string $body): array
    {
        $lines=preg_split('/\r\n|\r|\n/',trim($body));
        if(!$lines||count($lines)<2)return [];
        $headers=array_map(fn($header)=>trim((string)$header),str_getcsv(array_shift($lines)));
        $records=[];
        foreach($lines as $line){
            if(trim($line)==='')continue;
            $values=str_getcsv($line);
            $values=array_pad($values,count($headers),null);
            $record=array_combine($headers,array_slice($values,0,count($headers)));
            if($record!==false)$records[]=$record;
        }
        return $records;
    }
    private function importRecords(DataSource $source,array $records): array
    {
        $new=$updated=$unchanged=0;
        DB::transaction(function() use($source,$records,&$new,&$updated,&$unchanged){foreach($records as $index=>$payload){if(!is_array($payload))$payload=['value'=>$payload];$normalized=$this->normalize($payload);$encoded=json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$checksum=hash('sha256',$encoded);$externalKey=$this->externalKey($normalized,$index);$record=SourceRecord::where('data_source_id',$source->id)->where('external_key',$externalKey)->first();if(!$record){SourceRecord::create(['data_source_id'=>$source->id,'external_key'=>$externalKey,'record_type'=>$this->recordType($source),'payload'=>$normalized,'checksum'=>$checksum,'first_seen_at'=>now(),'last_seen_at'=>now()]);$new++;}elseif($record->checksum!==$checksum){$record->update(['payload'=>$normalized,'checksum'=>$checksum,'last_seen_at'=>now()]);$updated++;}else{$record->update(['last_seen_at'=>now()]);$unchanged++;}}});
        return ['database'=>$source->records()->count(),'new'=>$new,'updated'=>$updated,'unchanged'=>$unchanged,'errors'=>0];
    }
    private function externalKey(array $record,int $index): string
    {
        if(isset($record['competition_id'],$record['season_id'])) return 'competition:'.$record['competition_id'].':season:'.$record['season_id'];
        if(isset($record['Div'],$record['Date'],$record['HomeTeam'],$record['AwayTeam'])) return substr('match:'.implode(':',[$record['Div'],$record['Date'],$record['HomeTeam'],$record['AwayTeam']]),0,191);
        foreach(['id','leagueId','sport_key','key','code','external_id','name'] as $key) if(isset($record[$key])&&is_scalar($record[$key])) return substr($key.':'.(string)$record[$key],0,191);
        return 'hash:'.hash('sha256',json_encode($record)).':'.$index;
    }
    private function recordType(DataSource $source): string { return match($source->slug){'openligadb','football-data'=>'competition','statsbomb-open-data'=>'competition_season','football-data-uk'=>'historical_match','the-odds-api'=>'sport','api-football'=>'api_status','understat'=>'page_snapshot',default=>'external_record'}; }
    private function normalize(array $value): array { foreach($value as &$item) if(is_array($item))$item=$this->normalize($item);unset($item);if(!array_is_list($value))ksort($value);return $value; }
    private function finish(DataSource $source,string $status,int $records,string $message,float $started,array $metrics=[]): void
    {
        $duration=(int)round((microtime(true)-$started)*1000);$source->update(['status'=>$status,'last_checked_at'=>now(),'last_duration_ms'=>$duration,'records_checked'=>$records,'last_message'=>$message,'last_database_records'=>$metrics['database']??0,'last_new_records'=>$metrics['new']??0,'last_updated_records'=>$metrics['updated']??0,'last_unchanged_records'=>$metrics['unchanged']??0,'last_error_records'=>$metrics['errors']??0]);
        DB::table('data_sync_logs')->insert(['provider'=>$source->slug,'type'=>'source_sync','status'=>$status,'records'=>$records,'message'=>$message,'started_at'=>now()->subMilliseconds($duration),'finished_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    }
}

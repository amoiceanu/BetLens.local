<?php
namespace App\Jobs;
use App\Exceptions\MasterImportCancelled;
use App\Models\DataSource;
use App\Models\ImportDocument;
use App\Models\ImportRun;
use App\Services\OpenLigaDataImporter;
use App\Services\ImportAuditService;
use App\Services\OddsImportService;
use App\Services\MatchAnalysisService;
use App\Services\Mindmap\MasterImportProgress;
use App\Services\Providers\OpenMeteoProvider;
use App\Services\Providers\SportmonksProvider;
use App\Services\SourcePayloadStoreService;
use App\Services\SuperbetOddsFeedService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;
class VerifyDataSource implements ShouldQueue
{
    use Queueable;
    private const RECORD_WRITE_BATCH_SIZE=100;
    public int $tries=3;
    public array $backoff=[30,120,300];
    public function __construct(public int $sourceId, public ?string $runId=null, public ?int $importRunId=null) {}
    public function handle(): void
    {
        $source=DataSource::findOrFail($this->sourceId);
        if($this->runId && $source->verification_run_id!==$this->runId)return;
        if ($this->importRunId) {
            $run = ImportRun::find($this->importRunId);
            if ($run && ! array_key_exists('source_status_before_import', $run->metadata ?? [])) {
                $run->update(['metadata' => array_merge($run->metadata ?? [], ['source_status_before_import' => $source->status])]);
            }
            if ($run && ! isset($run->metadata['master_progress'])) app(MasterImportProgress::class)->initialize($run);
            if ($run) $this->masterStep('connection', false, 'Inițierea conexiunii la furnizor.');
        }
        $this->checkMasterCancellation($source);
        if ($this->importRunId) $this->masterStep('connection',false,'Se inițiază conexiunea la furnizor.');
        $started=microtime(true); $source->update(['status'=>'checking']);
        $this->progress($source,'connecting','Se conectează la sursa de date.');
        try {
            $key=$source->credentialValue();
            if($source->credential_env && blank($key)){$this->finish($source,'not_configured',0,"Lipsește cheia pentru {$source->name}; configureaz-o în Admin sau .env.",$started,['database'=>$source->records()->count(),'errors'=>1]);return;}
            if($source->slug==='sportmonks'){
                $result=app(SportmonksProvider::class)->verifyConnection();
                $this->checkMasterCancellation($source);
                $this->masterStep('connection',true,'Conexiune confirmată.');
                $this->masterStep('collection',true,'Date colectate de la Sportmonks.',['detected'=>count($result['records'])]);
                $this->masterStep('persistence',false,'Se salvează competițiile în baza de date.',['detected'=>count($result['records'])]);
                $this->progress($source,'fetching','Răspuns primit de la sursă.', ['detected'=>count($result['records'])]);
                $this->progress($source,'importing','Date primite; se salvează ligile.');
                $metrics=app(SourcePayloadStoreService::class)->store($source,'sportmonks_league',$result['records']);
                $this->masterStep('persistence',true,'Datele Sportmonks au fost salvate.',$metrics);
                $this->checkMasterCancellation($source);
                $source->update(['metadata'=>array_merge($source->metadata??[],['superliga_coverage'=>$result['coverage']])]);
                $this->finish($source,'healthy',count($result['records']),$result['message'],$started,$metrics);return;
            }
            if($source->slug==='open-meteo'){
                $result=app(OpenMeteoProvider::class)->verifyConnection();
                $this->checkMasterCancellation($source);
                $this->masterStep('connection',true,'Conexiune Open-Meteo confirmată.');
                $this->masterStep('collection',true,'Răspunsul meteo a fost colectat.',['detected'=>count($result['records'])]);
                $this->masterStep('persistence',false,'Se salvează răspunsul de verificare.');
                $this->progress($source,'fetching','Răspuns primit de la sursă.', ['detected'=>count($result['records'])]);
                $this->progress($source,'importing','Date primite; se salvează răspunsul.');
                $metrics=app(SourcePayloadStoreService::class)->store($source,'provider_status',$result['records']);
                $this->masterStep('persistence',true,'Răspunsul a fost salvat în baza de date.',$metrics);
                $this->checkMasterCancellation($source);
                $this->finish($source,'healthy',count($result['records']),$result['message'],$started,$metrics);return;
            }
            if(in_array($source->slug,['odds-api-io-superbet','oddspapi-superbet'],true)){
                $this->progress($source,'fetching','Se descarcă și se sincronizează cotele.');
                $result=app(SuperbetOddsFeedService::class)->sync($source,$key);
                $this->checkMasterCancellation($source);
                $this->masterStep('connection',true,'Conexiunea cu furnizorul de cote a fost confirmată.');
                $this->masterStep('collection',true,'Evenimentele și cotele au fost colectate.',['detected'=>$result['detected']]);
                $this->masterStep('persistence',true,'Cotele asociate au fost salvate.',$result['metrics']);
                $this->finish($source,'healthy',$result['detected'],$result['message'],$started,$result['metrics']);return;
            }
            $request=Http::acceptJson()->timeout(min(30,max(5,$source->request_timeout ?? 20)))->retry(2,250,throw:false);
            if($source->slug==='football-data') $request=$request->withHeader('X-Auth-Token',$key);
            if($source->slug==='api-football') $request=$request->withHeader('x-apisports-key',$key);
            $url=$source->verification_url;
            if($source->slug==='the-odds-api') $url.='?apiKey='.urlencode($key);
            $cachedResponse=$this->cachedResponse();
            if($cachedResponse){
                $this->progress($source,'fetching','Se reutilizează răspunsul descărcat la încercarea anterioară.');
                $response=new Response(Http::psr7Response($cachedResponse['body'],200,['Content-Type'=>$cachedResponse['content_type']]));
            }else{
                $this->progress($source,'connecting','Se așteaptă răspunsul de la furnizor.');
                $response=$request->get($url);
            }
            $this->progress($source,'fetching','Răspunsul sursei a fost primit.', ['http_status'=>$response->status(),'response_bytes'=>strlen($response->body())]);
            $this->checkMasterCancellation($source);
            $this->masterStep('connection',$response->successful()||$response->status() < 500,
                $response->successful() ? 'Conexiune reușită; furnizorul a răspuns.' : 'Răspuns HTTP primit de la furnizor.',
                ['http_status'=>$response->status(),'response_bytes'=>strlen($response->body())]);
            if ($this->importRunId && $response->successful()) $this->storeRawResponse($response);
            if(!$response->successful()){$this->finish($source,'degraded',0,"Sursa a răspuns cu HTTP {$response->status()}.",$started,['database'=>$source->records()->count(),'errors'=>1]);return;}
            $this->masterStep('collection',false,'Se extrag și se validează înregistrările.');
            $records=$this->extractRecords($source,$response);
            $this->masterStep('collection',true,'Date colectate și validate.',['detected'=>count($records),'response_bytes'=>strlen($response->body())]);
            $this->masterStep('persistence',false,'Se compară înregistrările cu baza de date și se scriu în batchuri.',['detected'=>count($records)]);
            $this->progress($source,'importing','Se compară și se scriu înregistrările în baza de date.', ['detected'=>count($records)]);
            $metrics=$this->importRecords($source,$records,$this->importRunId);
            $this->masterStep('persistence',true,'Scrierea în baza de date a fost finalizată.',$metrics+['detected'=>count($records)]);
            $this->checkMasterCancellation($source);
            $message=count($records)>0 ? 'Conexiune confirmată; '.count($records).' înregistrări au fost sincronizate în MySQL.' : 'Conexiune confirmată, dar sursa nu a returnat înregistrări importabile.';
            if($source->slug==='openligadb'){$this->masterStep('persistence',false,'Se sincronizează meciurile și echipele operaționale.');$this->progress($source,'analyzing','Se importă meciuri și se actualizează analiza.');$domain=app(OpenLigaDataImporter::class)->importCurrentSeason();$metrics['new']+=$domain['teams']+$domain['matches_new'];$metrics['updated']+=$domain['matches_updated'];$this->masterStep('persistence',true,'Echipe și meciuri sincronizate.',['teams_new'=>$domain['teams'],'matches_new'=>$domain['matches_new'],'matches_updated'=>$domain['matches_updated']]);$this->masterStep('calculation',false,'Se recalculează estimările pentru ligile eligibile.');$model=['created'=>0,'updated'=>0,'eligible'=>0];$leagues=\App\Models\League::whereIn('slug',['premier-league','la-liga','bundesliga','champions-league'])->get();foreach($leagues as $index=>$league){$generated=app(MatchAnalysisService::class)->generateModelEstimates($league);foreach(['created','updated','eligible'] as $metric)$model[$metric]+=$generated[$metric];$this->masterStep('calculation',false,'Estimări calculate pentru '.$league->name.'.',['leagues_processed'=>$index+1,'leagues_total'=>$leagues->count(),'created'=>$model['created'],'updated'=>$model['updated'],'eligible'=>$model['eligible']]);}$this->masterStep('calculation',true,'Calculele modelului au fost finalizate.',['leagues_processed'=>$leagues->count(),'leagues_total'=>$leagues->count(),'created'=>$model['created'],'updated'=>$model['updated'],'eligible'=>$model['eligible']]);$message.=" Date operaționale: {$domain['teams']} echipe noi, {$domain['matches_new']} meciuri noi, {$domain['matches_updated']} actualizate. Analiză model: {$model['created']} recomandări noi, {$model['eligible']} eligibile.";if($domain['errors'])$message.=' Erori: '.implode('; ',$domain['errors']);}
            if($source->slug==='the-odds-api'){$this->masterStep('calculation',false,'Se sincronizează cotele și se recalculează recomandările.');$this->progress($source,'analyzing','Se importă cotele și se recalculează recomandările.');$odds=app(OddsImportService::class)->sync($key);$metrics['new']+=$odds['odds_new'];$metrics['updated']+=$odds['odds_updated'];$this->masterStep('calculation',true,'Analiza cotelor a fost finalizată.',['odds_new'=>$odds['odds_new'],'odds_updated'=>$odds['odds_updated'],'matched'=>$odds['matched'],'recommendations'=>$odds['recommendations'],'eligible'=>$odds['eligible'],'errors'=>count($odds['errors'])]);$message.=" Cote: {$odds['odds_new']} noi, {$odds['odds_updated']} actualizate, {$odds['matched']} meciuri asociate. Analiză: {$odds['recommendations']} recomandări, {$odds['eligible']} eligibile.";if($odds['requests_remaining']!==null)$message.=" Credite API rămase: {$odds['requests_remaining']}.";if($odds['errors'])$message.=' Erori parțiale: '.implode('; ',$odds['errors']);}
            $this->finish($source,'healthy',count($records),$message,$started,$metrics);
        } catch (MasterImportCancelled) {
            return;
        } catch(Throwable $e){$message=str_replace(array_filter([$key??null,config('services.odds_api.key')]),'[redactat]',$e->getMessage());$this->finish($source,'unavailable',0,'Eroare de conexiune: '.str($message)->limit(180),$started,['database'=>$source->records()->count(),'errors'=>1]);}
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
    private function storeRawResponse(Response $response): void
    {
        $disk = config('betlens.raw_import_disk', 'local');
        $body = $response->body();
        $path = 'imports/'.$this->importRunId.'/response'.(str_contains((string) $response->header('Content-Type'), 'csv') ? '.csv' : '.json');
        Storage::disk($disk)->put($path, $body);
        ImportDocument::updateOrCreate(['import_run_id' => $this->importRunId], [
            'disk' => $disk, 'path' => $path,
            'content_type' => substr((string) $response->header('Content-Type'), 0, 100),
            'checksum' => hash('sha256', $body), 'bytes' => strlen($body),
        ]);
    }
    private function cachedResponse(): ?array
    {
        if(! $this->importRunId)return null;
        $document=ImportDocument::where('import_run_id',$this->importRunId)->first();
        if(!$document||!Storage::disk($document->disk)->exists($document->path))return null;
        return ['body'=>Storage::disk($document->disk)->get($document->path),'content_type'=>$document->content_type?:'application/json'];
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
    private function importRecords(DataSource $source,array $records,?int $importRunId=null): array
    {
        $run=$importRunId?ImportRun::find($importRunId):null;
        $checkpoint=$run?->metadata??[];
        $cursor=min(count($records),(int)($checkpoint['source_records_cursor']??0));
        $total=count($records);
        $new=(int)($checkpoint['source_records_new']??0);
        $updated=(int)($checkpoint['source_records_updated']??0);
        $unchanged=(int)($checkpoint['source_records_unchanged']??0);
        $records=array_values(array_slice($records,$cursor));
        $processed=$cursor;
        // Bulk upsert avoids a SELECT + INSERT/UPDATE for every provider row.
        // 100 rows also stays below SQLite's conservative bind-parameter limit.
        foreach(array_chunk($records,self::RECORD_WRITE_BATCH_SIZE,true) as $chunk){
            $this->checkMasterCancellation($source);
            DB::transaction(function() use($source,$chunk,&$new,&$updated,&$unchanged){
                $prepared=[];
                foreach($chunk as $index=>$payload){
                    if(!is_array($payload))$payload=['value'=>$payload];
                    $normalized=$this->normalize($payload);
                    $encoded=json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    $externalKey=$this->externalKey($normalized,$index);
                    $prepared[$externalKey]=[
                        'data_source_id'=>$source->id,
                        'external_key'=>$externalKey,
                        'record_type'=>$this->recordType($source),
                        'payload'=>$encoded,
                        'checksum'=>hash('sha256',$encoded),
                    ];
                }

                $keys=array_keys($prepared);
                $existing=DB::table('source_records')
                    ->where('data_source_id',$source->id)
                    ->whereIn('external_key',$keys)
                    ->get(['external_key','checksum'])
                    ->keyBy('external_key');
                $knownChecksums=[];
                foreach($existing as $record)$knownChecksums[$record->external_key]=$record->checksum;

                $observedAt=now();
                foreach($prepared as $externalKey=>&$row){
                    $checksum=$row['checksum'];
                    if(!array_key_exists($externalKey,$knownChecksums))$new++;
                    elseif($knownChecksums[$externalKey]!==$checksum)$updated++;
                    else$unchanged++;
                    $knownChecksums[$externalKey]=$checksum;
                    $row['first_seen_at']=$observedAt;
                    $row['last_seen_at']=$observedAt;
                    $row['created_at']=$observedAt;
                    $row['updated_at']=$observedAt;
                }
                unset($row);

                DB::table('source_records')->upsert(
                    array_values($prepared),
                    ['data_source_id','external_key'],
                    ['record_type','payload','checksum','last_seen_at','updated_at'],
                );
            });
            $processed+=count($chunk);
            app(\App\Services\Coverage\ObservationProjector::class)->stored($source,$this->recordType($source),$chunk);
            if($run){
                $run->refresh();
                $checkpoint=array_merge($run->metadata??[],['source_records_cursor'=>$processed,'source_records_total'=>$total,
                    'source_records_new'=>$new,'source_records_updated'=>$updated,'source_records_unchanged'=>$unchanged]);
                $run->update(['metadata'=>$checkpoint,'records_fetched'=>$total,'records_new'=>$new,
                    'records_updated'=>$updated,'records_unchanged'=>$unchanged,'records_skipped'=>$unchanged]);
                $this->masterStep('persistence',false,'Lot scris în baza de date.',['processed'=>$processed,'total'=>$total,
                    'new'=>$new,'updated'=>$updated,'unchanged'=>$unchanged,'database'=>$source->records()->count()]);
            }
            $this->checkMasterCancellation($source);
            $this->progress($source,'importing','Se scriu înregistrările în loturi tranzacționale.', ['processed'=>$processed,'detected'=>$total,'new'=>$new,'updated'=>$updated,'unchanged'=>$unchanged,'database'=>$source->records()->count()]);
        }
        app(\App\Services\Coverage\ObservationProjector::class)->flush();
        return ['database'=>$source->records()->count(),'new'=>$new,'updated'=>$updated,'unchanged'=>$unchanged,'errors'=>0];
    }
    private function externalKey(array $record,int $index): string
    {
        if(isset($record['competition_id'],$record['season_id'])) return 'competition:'.$record['competition_id'].':season:'.$record['season_id'];
        if(isset($record['Div'],$record['Date'],$record['HomeTeam'],$record['AwayTeam'])) return substr('match:'.implode(':',[$record['Div'],$record['Date'],$record['HomeTeam'],$record['AwayTeam']]),0,191);
        foreach(['id','leagueId','sport_key','key','code','external_id','name'] as $key) if(isset($record[$key])&&is_scalar($record[$key])) return substr($key.':'.(string)$record[$key],0,191);
        return 'hash:'.hash('sha256',json_encode($record)).':'.$index;
    }
    private function recordType(DataSource $source): string { return match($source->slug){'openligadb','football-data'=>'competition','statsbomb-open-data'=>'competition_season','football-data-uk'=>'historical_match','the-odds-api'=>'sport','api-football'=>'api_status','understat'=>'page_snapshot','sportmonks'=>'sportmonks_league','open-meteo'=>'provider_status',default=>'external_record'}; }
    private function normalize(array $value): array { foreach($value as &$item) if(is_array($item))$item=$this->normalize($item);unset($item);if(!array_is_list($value))ksort($value);return $value; }
    private function finish(DataSource $source,string $status,int $records,string $message,float $started,array $metrics=[]): void
    {
        $message=app(\App\Services\Coverage\SafeImportMessage::class)->clean($message);
        $this->masterStep('finalization',true,'Sursa a fost finalizată; rezultatul și contoarele au fost salvate.',['records'=>$records,'database'=>$metrics['database']??0,'new'=>$metrics['new']??0,'updated'=>$metrics['updated']??0,'errors'=>$metrics['errors']??0]);
        app(\App\Services\Coverage\ObservationProjector::class)->flush();
        $this->progress($source,'finished',$message);
        $duration=(int)round((microtime(true)-$started)*1000);$source->update(['status'=>$status,'last_checked_at'=>now(),'last_duration_ms'=>$duration,'records_checked'=>$records,'last_message'=>$message,'last_database_records'=>$metrics['database']??0,'last_new_records'=>$metrics['new']??0,'last_updated_records'=>$metrics['updated']??0,'last_unchanged_records'=>$metrics['unchanged']??0,'last_error_records'=>$metrics['errors']??0,'verification_progress'=>array_merge($source->verification_progress??[],['stage'=>'finished','message'=>$message,'processed'=>$records,'detected'=>$records,'database'=>$metrics['database']??0,'new'=>$metrics['new']??0,'updated'=>$metrics['updated']??0,'unchanged'=>$metrics['unchanged']??0,'errors'=>$metrics['errors']??0])]);
        DB::table('data_sync_logs')->insert(['provider'=>$source->slug,'type'=>'source_sync','status'=>$status,'records'=>$records,'message'=>$message,'started_at'=>now()->subMilliseconds($duration),'finished_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        if ($this->runId) app(ImportAuditService::class)->syncVerification($source,$this->runId);
        elseif (!$this->importRunId) app(ImportAuditService::class)->record($source->slug,'source_sync',$status,$metrics+['read'=>$records],$message,$started,self::class);
    }

    private function checkMasterCancellation(DataSource $source): void
    {
        if (! $this->importRunId) return;

        $run = ImportRun::find($this->importRunId);
        if (! $run || $run->finished_at || blank(data_get($run->metadata ?? [], 'master_import_cancel_requested_at'))) return;

        $metadata = $run->metadata ?? [];
        $previousStatus = $metadata['source_status_before_import'] ?? 'never_checked';
        $message = 'Import oprit la cererea de anulare a Master Import; loturile deja confirmate au fost păstrate.';
        $run->update(['status' => 'cancelled', 'message' => $message, 'finished_at' => now()]);
        app(ImportAuditService::class)->syncRun($run->fresh());
        $source->update(['status' => $previousStatus, 'last_message' => $message]);

        throw new MasterImportCancelled($message);
    }

    private function masterStep(string $key, bool $complete, string $message, array $counts = []): void
    {
        if (! $this->importRunId) return;
        $run = ImportRun::find($this->importRunId);
        if (! $run) return;
        $progress = app(MasterImportProgress::class);
        if (! isset($run->metadata['master_progress'])) $progress->initialize($run);
        $run->refresh();
        $complete ? $progress->complete($run, $key, $message, $counts) : $progress->start($run, $key, $message, $counts);
    }

    private function progress(DataSource $source,string $stage,string $message,array $counts=[]): void
    {
        $current=$source->verification_progress??[];
        $steps=$current['steps']??[];
        if (($current['stage']??null)!==$stage) {
            $previous=$current['stage']??null;
            if ($previous && isset($steps[$previous]) && !isset($steps[$previous]['finished_at'])) $steps[$previous]['finished_at']=now()->toIso8601String();
            $steps[$stage]=['started_at'=>now()->toIso8601String(),'message'=>$message];
        } else {
            $steps[$stage]['message']=$message;
        }
        $source->update(['verification_progress'=>array_merge($current,['stage'=>$stage,'message'=>$message,'steps'=>$steps],$counts)]);
        if ($this->runId && $stage !== 'finished') app(ImportAuditService::class)->syncVerification($source,$this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        $source=DataSource::find($this->sourceId);
        if(!$source || ($this->runId && $source->verification_run_id!==$this->runId) || $source->status!=='checking')return;
        $message='Importul s-a oprit după încercări repetate. Verifică workerul și logurile serverului.';
        $this->progress($source,'finished',$message,['errors'=>1]);
        $source->update(['status'=>'unavailable','last_checked_at'=>now(),'last_message'=>$message,
            'verification_progress'=>array_merge($source->verification_progress??[],['stage'=>'finished','message'=>$message,'errors'=>1])]);
        if ($this->runId) app(ImportAuditService::class)->syncVerification($source,$this->runId);
        elseif (!$this->importRunId) app(ImportAuditService::class)->record($source->slug,'source_sync','failed',['errors'=>1],$message,null,self::class);
    }
}

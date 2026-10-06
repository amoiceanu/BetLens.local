<?php
namespace App\Jobs;
use App\Models\DataSource;
use App\Models\ExternalIdMapping;
use App\Services\Providers\SportmonksProvider;
use App\Services\SourcePayloadStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;
class SyncSportmonksStatisticsJob implements ShouldQueue
{
    use Queueable;
    public int $tries=3; public array $backoff=[60,300,900];
    public function handle(SportmonksProvider $provider,SourcePayloadStoreService $store): void
    {
        $started=microtime(true);$source=DataSource::where('slug','sportmonks')->firstOrFail();
        if(blank(config('services.sportmonks.token'))){$store->log('sportmonks','statistics','not_configured',0,'SPORTMONKS_API_TOKEN nu este configurat.',$started);return;}
        try{$records=[];foreach(ExternalIdMapping::where(['provider'=>'sportmonks','entity_type'=>'match'])->latest('last_verified_at')->limit(50)->get() as $mapping)$records=array_merge($records,$provider->statistics(['fixture_id'=>$mapping->external_id]));$standings=$provider->standings();$store->store($source,'sportmonks_statistics',$records);$standingMetrics=$store->store($source,'sportmonks_standing',$standings);$total=count($records)+count($standings);$store->log('sportmonks','statistics','healthy',$total,'Statistici, line-up-uri, xG și clasamente sincronizate unde planul și liga le oferă. Datele lipsă rămân indisponibile.',$started);$source->update(['last_database_records'=>$standingMetrics['database']]);}catch(Throwable $e){$store->log('sportmonks','statistics','unavailable',0,'Sincronizarea statisticilor a eșuat: '.str($e->getMessage())->limit(160),$started);throw $e;}
    }
}

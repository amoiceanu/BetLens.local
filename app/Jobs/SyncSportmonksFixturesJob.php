<?php
namespace App\Jobs;
use App\Models\DataSource;
use App\Services\MatchDataResolverService;
use App\Services\Providers\SportmonksProvider;
use App\Services\SourcePayloadStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;
class SyncSportmonksFixturesJob implements ShouldQueue
{
    use Queueable;
    public int $tries=3; public array $backoff=[60,300,900];
    public function handle(SportmonksProvider $provider,MatchDataResolverService $resolver,SourcePayloadStoreService $store): void
    {
        $started=microtime(true);$source=DataSource::where('slug','sportmonks')->firstOrFail();
        if(blank(config('services.sportmonks.token'))){$store->log('sportmonks','fixtures','not_configured',0,'SPORTMONKS_API_TOKEN nu este configurat.',$started);return;}
        try{$fixtures=$provider->upcomingMatches();$metrics=$store->store($source,'sportmonks_fixture',$fixtures);$matched=$conflicts=0;foreach($fixtures as $fixture){$result=$resolver->resolve('sportmonks',$fixture);$matched+=(int)($result['status']==='resolved');$conflicts+=(int)($result['status']==='conflict');}$message=count($fixtures)." fixtures Sportmonks procesate; $matched asociate, $conflicts conflicte.";$source->update(['status'=>'healthy','last_checked_at'=>now(),'last_message'=>$message,'last_database_records'=>$metrics['database']]);$store->log('sportmonks','fixtures','healthy',count($fixtures),$message,$started);}catch(Throwable $e){$store->log('sportmonks','fixtures','unavailable',0,'Sincronizarea fixtures a eșuat: '.str($e->getMessage())->limit(160),$started);throw $e;}
    }
}

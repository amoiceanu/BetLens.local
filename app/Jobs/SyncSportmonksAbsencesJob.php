<?php
namespace App\Jobs;
use App\Models\DataSource;
use App\Services\Providers\SportmonksProvider;
use App\Services\SourcePayloadStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;
class SyncSportmonksAbsencesJob implements ShouldQueue
{
    use Queueable;
    public int $tries=3; public array $backoff=[60,300,900];
    public function handle(SportmonksProvider $provider,SourcePayloadStoreService $store): void
    {
        $started=microtime(true);$source=DataSource::where('slug','sportmonks')->firstOrFail();
        if(blank(config('services.sportmonks.token'))){$store->log('sportmonks','absences','not_configured',0,'SPORTMONKS_API_TOKEN nu este configurat.',$started);return;}
        try{$records=$provider->absences();$metrics=$store->store($source,'sportmonks_absence',$records);$store->log('sportmonks','absences','healthy',count($records),'Accidentări și suspendări sincronizate unde sunt disponibile.',$started);$source->update(['last_database_records'=>$metrics['database']]);}catch(Throwable $e){$store->log('sportmonks','absences','unavailable',0,'Sincronizarea absențelor a eșuat: '.str($e->getMessage())->limit(160),$started);throw $e;}
    }
}

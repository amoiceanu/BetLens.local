<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\SourceRecord;
use Illuminate\Support\Facades\DB;

class SourcePayloadStoreService
{
    private const WRITE_BATCH_SIZE = 200;

    public function store(DataSource $source,string $type,array $records): array
    {
        $new=$updated=$unchanged=0;
        DB::transaction(function()use($source,$type,$records,&$new,&$updated,&$unchanged){
            foreach(array_chunk($records,self::WRITE_BATCH_SIZE,true) as $batch){
                $prepared=[];$keys=[];$now=now();
                foreach($batch as $index=>$payload){
                    if(!is_array($payload))$payload=['value'=>$payload];
                    $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                    $checksum=hash('sha256',$encoded);
                    $externalId=(string)($payload['external_id']??$payload['id']??hash('sha256',$encoded).':'.$index);
                    $key=substr($type.':'.$externalId,0,191);$keys[]=$key;
                    $prepared[$key]=['data_source_id'=>$source->id,'external_key'=>$key,'record_type'=>$type,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'checksum'=>$checksum,'first_seen_at'=>$now,'last_seen_at'=>$now,'created_at'=>$now,'updated_at'=>$now];
                }
                if(!$prepared)continue;
                $existing=DB::table('source_records')->where('data_source_id',$source->id)->whereIn('external_key',$keys)->get(['external_key','checksum']);
                $checksums=$existing->keyBy('external_key');
                foreach($prepared as $key=>$row){
                    $old=$checksums->get($key);
                    if(!$old)$new++;
                    elseif($old->checksum!==$row['checksum'])$updated++;
                    else $unchanged++;
                }
                DB::table('source_records')->upsert(array_values($prepared),['data_source_id','external_key'],['record_type','payload','checksum','last_seen_at','updated_at']);
            }
        });
        app(\App\Services\Coverage\ObservationProjector::class)->stored($source,$type,$records);
        app(\App\Services\Coverage\ObservationProjector::class)->flush();
        return ['database'=>$source->records()->count(),'new'=>$new,'updated'=>$updated,'unchanged'=>$unchanged,'errors'=>0];
    }

    public function log(string $provider,string $type,string $status,int $records,string $message,float $started,array $metrics=[]): void
    {
        $message=app(\App\Services\Coverage\SafeImportMessage::class)->clean($message);
        app(\App\Services\Coverage\ObservationProjector::class)->flush();
        DB::table('data_sync_logs')->insert(['provider'=>$provider,'type'=>$type,'status'=>$status,'records'=>$records,'message'=>$message,'started_at'=>now()->subMilliseconds((int)((microtime(true)-$started)*1000)),'finished_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $job = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8))
            ->pluck('class')->first(fn ($class) => is_string($class) && str_starts_with($class, 'App\\Jobs\\'));
        app(ImportAuditService::class)->record($provider,$type,$status,$metrics+['read'=>$records],$message,$started,$job ?? $type);
    }
}

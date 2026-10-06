<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\SourceRecord;
use Illuminate\Support\Facades\DB;

class SourcePayloadStoreService
{
    public function store(DataSource $source,string $type,array $records): array
    {
        $new=$updated=$unchanged=0;
        DB::transaction(function()use($source,$type,$records,&$new,&$updated,&$unchanged){foreach($records as $index=>$payload){if(!is_array($payload))$payload=['value'=>$payload];$encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$checksum=hash('sha256',$encoded);$externalId=(string)($payload['external_id']??$payload['id']??hash('sha256',$encoded).':'.$index);$key=substr($type.':'.$externalId,0,191);$record=SourceRecord::where(['data_source_id'=>$source->id,'external_key'=>$key])->first();if(!$record){SourceRecord::create(['data_source_id'=>$source->id,'external_key'=>$key,'record_type'=>$type,'payload'=>$payload,'checksum'=>$checksum,'first_seen_at'=>now(),'last_seen_at'=>now()]);$new++;}elseif($record->checksum!==$checksum){$record->update(['payload'=>$payload,'checksum'=>$checksum,'last_seen_at'=>now()]);$updated++;}else{$record->update(['last_seen_at'=>now()]);$unchanged++;}}});
        return ['database'=>$source->records()->count(),'new'=>$new,'updated'=>$updated,'unchanged'=>$unchanged,'errors'=>0];
    }

    public function log(string $provider,string $type,string $status,int $records,string $message,float $started): void
    {
        DB::table('data_sync_logs')->insert(['provider'=>$provider,'type'=>$type,'status'=>$status,'records'=>$records,'message'=>$message,'started_at'=>now()->subMilliseconds((int)((microtime(true)-$started)*1000)),'finished_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    }
}

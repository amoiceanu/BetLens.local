<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ImportAuditService
{
    public function record(string $provider, string $type, string $status, array $metrics = [], ?string $message = null): void
    {
        $message=app(\App\Services\Coverage\SafeImportMessage::class)->clean($message);
        $extra=[];
        if(\Illuminate\Support\Facades\Schema::hasColumn('import_audits','finished_at'))$extra=['league_id'=>$metrics['league_id']??null,'season_id'=>$metrics['season_id']??null,'started_at'=>$metrics['started_at']??null,'finished_at'=>$metrics['finished_at']??now(),'records_read'=>$metrics['read']??null,'records_ignored'=>$metrics['unchanged']??null,'errors_count'=>$metrics['errors']??null];
        DB::table('import_audits')->insert($extra+[
            'provider' => $provider,
            'import_type' => $type,
            'status' => $status,
            'imported_at' => now(),
            'records_new' => $metrics['new'] ?? 0,
            'records_updated' => $metrics['updated'] ?? 0,
            'records_deleted' => $metrics['deleted'] ?? 0,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ImportAuditService
{
    public function record(string $provider, string $type, string $status, array $metrics = [], ?string $message = null): void
    {
        DB::table('import_audits')->insert([
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

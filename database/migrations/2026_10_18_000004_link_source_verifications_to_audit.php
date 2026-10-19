<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('import_audits', function (Blueprint $table) {
            $table->uuid('verification_run_id')->nullable()->unique();
        });

        // Recover verifications already waiting in the queue when this migration runs.
        foreach (DB::table('data_sources')->where('status', 'checking')->whereNotNull('verification_run_id')->get() as $source) {
            $progress = json_decode($source->verification_progress ?? '{}', true) ?: [];
            $started = isset($progress['started_at']) ? date('Y-m-d H:i:s', strtotime($progress['started_at'])) : now();
            DB::table('import_audits')->insertOrIgnore([
                'verification_run_id' => $source->verification_run_id,
                'provider' => $source->slug, 'import_type' => 'source_sync',
                'execution_source' => config('betlens.import_execution_source', 'Laravel-local'),
                'job_name' => 'VerifyDataSource', 'status' => 'queued', 'overall_status' => 'queued',
                'imported_at' => $started, 'started_at' => $started, 'finished_at' => null,
                'records_new' => 0, 'records_updated' => 0, 'records_deleted' => 0,
                'records_imported' => 0, 'message' => $progress['message'] ?? 'Verificarea este în coadă.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('import_audits', fn (Blueprint $table) => $table->dropUnique(['verification_run_id']));
        Schema::table('import_audits', fn (Blueprint $table) => $table->dropColumn('verification_run_id'));
    }
};

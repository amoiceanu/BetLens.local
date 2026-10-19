<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('import_audits', function (Blueprint $table) {
            $table->foreignId('import_run_id')->nullable()->unique()->constrained('import_runs')->nullOnDelete();
        });

        // Make runs started before this migration visible in the audit too.
        foreach (DB::table('import_runs')->orderBy('id')->get() as $run) {
            $provider = DB::table('data_sources')->where('id', $run->data_source_id)->value('slug') ?? 'sursa-ștearsă';
            $existing = DB::table('import_audits')->whereNull('import_run_id')->where('provider', $provider)
                ->where('imported_at', '>=', $run->created_at)
                ->where('imported_at', '<=', date('Y-m-d H:i:s', strtotime($run->finished_at ?? $run->updated_at) + 5))
                ->orderByDesc('id')->first();
            if ($existing) {
                DB::table('import_audits')->where('id', $existing->id)->update(['import_run_id' => $run->id]);
                continue;
            }
            DB::table('import_audits')->insert([
                'import_run_id' => $run->id, 'provider' => $provider, 'import_type' => $run->import_method,
                'execution_source' => $run->execution_source, 'job_name' => 'RunMindmapImport',
                'status' => $run->status, 'overall_status' => match ($run->status) {
                    'completed' => 'success', 'failed' => 'failed', 'partial' => 'warning', default => $run->status,
                },
                'imported_at' => $run->created_at, 'started_at' => $run->started_at ?? $run->created_at,
                'finished_at' => $run->finished_at, 'records_new' => $run->records_new ?? 0,
                'records_updated' => $run->records_updated ?? 0, 'records_deleted' => 0,
                'records_imported' => ($run->records_new ?? 0) + ($run->records_updated ?? 0),
                'message' => $run->message, 'created_at' => $run->created_at, 'updated_at' => $run->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('import_audits', fn (Blueprint $table) => $table->dropConstrainedForeignId('import_run_id'));
    }
};

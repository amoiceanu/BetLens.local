<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('import_audits', function (Blueprint $table) {
            $table->string('execution_source', 32)->nullable()->after('import_type');
            $table->string('job_name')->nullable()->after('execution_source');
            $table->string('overall_status', 16)->nullable()->after('status');
            $table->dateTime('started_at')->nullable()->after('imported_at');
            $table->dateTime('finished_at')->nullable()->after('started_at');
            $table->unsignedInteger('records_imported')->default(0)->after('records_deleted');
            $table->index(['execution_source', 'imported_at']);
        });
    }

    public function down(): void
    {
        Schema::table('import_audits', function (Blueprint $table) {
            $table->dropIndex(['execution_source', 'imported_at']);
            $table->dropColumn(['execution_source', 'job_name', 'overall_status', 'started_at', 'finished_at', 'records_imported']);
        });
    }
};

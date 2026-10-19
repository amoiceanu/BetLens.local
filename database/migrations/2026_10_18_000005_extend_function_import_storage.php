<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('source_records', function (Blueprint $table) {
            $table->foreignId('parent_source_record_id')->nullable()->constrained('source_records')->nullOnDelete();
        });
        Schema::table('import_runs', function (Blueprint $table) {
            $table->string('function_name')->nullable();
            $table->unsignedTinyInteger('level')->nullable();
            $table->string('data_format', 16)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('records_unchanged')->default(0);
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', fn (Blueprint $table) => $table->dropColumn(['function_name', 'level', 'data_format', 'duration_ms', 'records_unchanged', 'metadata']));
        Schema::table('source_records', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_source_record_id'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->uuid('verification_run_id')->nullable();
            $table->json('verification_progress')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', fn(Blueprint $table) => $table->dropColumn(['verification_run_id','verification_progress']));
    }
};

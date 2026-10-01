<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('provider_match_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('external_id', 120);
            $table->string('sport_key', 100)->nullable();
            $table->string('home_name')->nullable();
            $table->string('away_name')->nullable();
            $table->dateTime('kickoff_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
            $table->unique(['match_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_match_mappings');
    }
};

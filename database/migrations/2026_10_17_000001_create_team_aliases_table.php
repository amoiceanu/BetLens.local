<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('team_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('all');
            $table->string('alias');
            $table->string('normalized_alias');
            $table->timestamps();
            $table->unique(['league_id', 'provider', 'normalized_alias'], 'team_alias_unique_per_league_provider');
            $table->index(['normalized_alias', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_aliases');
    }
};

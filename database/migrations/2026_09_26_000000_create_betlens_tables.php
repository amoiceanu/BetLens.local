<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leagues', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('slug')->unique(); $t->string('country')->nullable(); $t->string('code', 8); $t->string('accent', 16)->default('#22c55e'); $t->boolean('active')->default(true); $t->timestamps(); });
        Schema::create('teams', function (Blueprint $t) { $t->id(); $t->foreignId('league_id')->constrained()->cascadeOnDelete(); $t->string('name'); $t->string('short_name', 12); $t->string('crest')->nullable(); $t->timestamps(); });
        Schema::create('seasons', function (Blueprint $t) { $t->id(); $t->foreignId('league_id')->constrained()->cascadeOnDelete(); $t->string('name'); $t->date('starts_at'); $t->date('ends_at'); $t->boolean('current')->default(false); $t->timestamps(); });
        Schema::create('matches', function (Blueprint $t) { $t->id(); $t->foreignId('league_id')->constrained()->cascadeOnDelete(); $t->foreignId('season_id')->nullable()->constrained()->nullOnDelete(); $t->foreignId('home_team_id')->constrained('teams')->cascadeOnDelete(); $t->foreignId('away_team_id')->constrained('teams')->cascadeOnDelete(); $t->dateTime('kickoff_at'); $t->string('status')->default('scheduled'); $t->string('venue')->nullable(); $t->string('external_id')->nullable()->unique(); $t->timestamps(); });
        Schema::create('match_results', function (Blueprint $t) { $t->id(); $t->foreignId('match_id')->unique()->constrained()->cascadeOnDelete(); $t->unsignedTinyInteger('home_score'); $t->unsignedTinyInteger('away_score'); $t->json('details')->nullable(); $t->timestamps(); });
        Schema::create('markets', function (Blueprint $t) { $t->id(); $t->string('key')->unique(); $t->string('name'); $t->boolean('active')->default(true); $t->timestamps(); });
        Schema::create('odds', function (Blueprint $t) { $t->id(); $t->foreignId('match_id')->constrained()->cascadeOnDelete(); $t->foreignId('market_id')->constrained()->cascadeOnDelete(); $t->string('selection'); $t->decimal('price', 7, 2); $t->string('provider')->default('unknown'); $t->dateTime('captured_at'); $t->timestamps(); $t->unique(['match_id','market_id','selection','provider']); });
        Schema::create('odds_snapshots', function (Blueprint $t) { $t->id(); $t->foreignId('odds_id')->constrained()->cascadeOnDelete(); $t->decimal('price', 7, 2); $t->dateTime('captured_at'); $t->timestamps(); });
        Schema::create('team_statistics', function (Blueprint $t) { $t->id(); $t->foreignId('team_id')->constrained()->cascadeOnDelete(); $t->foreignId('season_id')->nullable()->constrained()->nullOnDelete(); $t->string('scope')->default('overall'); $t->unsignedSmallInteger('played')->default(0); $t->unsignedSmallInteger('goals_for')->default(0); $t->unsignedSmallInteger('goals_against')->default(0); $t->decimal('xg', 6, 2)->nullable(); $t->decimal('xga', 6, 2)->nullable(); $t->json('recent_form')->nullable(); $t->timestamps(); });
        Schema::create('player_absences', function (Blueprint $t) { $t->id(); $t->foreignId('team_id')->constrained()->cascadeOnDelete(); $t->string('player_name'); $t->string('reason'); $t->date('expected_return')->nullable(); $t->string('importance')->default('medium'); $t->timestamps(); });
        Schema::create('recommendations', function (Blueprint $t) { $t->id(); $t->foreignId('match_id')->constrained()->cascadeOnDelete(); $t->foreignId('market_id')->constrained()->cascadeOnDelete(); $t->string('selection'); $t->decimal('odds', 7, 2); $t->decimal('model_probability', 6, 4); $t->decimal('implied_probability', 6, 4); $t->decimal('value', 7, 4); $t->string('confidence'); $t->unsignedTinyInteger('score'); $t->text('explanation'); $t->json('factors')->nullable(); $t->string('model_version')->default('v1'); $t->boolean('eligible')->default(true); $t->timestamps(); });
        Schema::create('generated_tickets', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $t->foreignId('league_id')->nullable()->constrained()->nullOnDelete(); $t->string('reference')->unique(); $t->string('risk_profile'); $t->decimal('total_odds', 10, 2); $t->decimal('combined_probability', 10, 6); $t->decimal('stake', 10, 2)->nullable(); $t->string('status')->default('pending'); $t->timestamps(); });
        Schema::create('generated_ticket_selections', function (Blueprint $t) { $t->id(); $t->foreignId('generated_ticket_id')->constrained()->cascadeOnDelete(); $t->foreignId('recommendation_id')->constrained()->cascadeOnDelete(); $t->decimal('odds_at_creation', 7, 2); $t->string('result')->default('pending'); $t->timestamps(); });
        Schema::create('user_ticket_results', function (Blueprint $t) { $t->id(); $t->foreignId('generated_ticket_id')->unique()->constrained()->cascadeOnDelete(); $t->string('status'); $t->decimal('return_amount', 10, 2)->nullable(); $t->text('notes')->nullable(); $t->timestamps(); });
        Schema::create('data_sync_logs', function (Blueprint $t) { $t->id(); $t->string('provider'); $t->string('type'); $t->string('status'); $t->unsignedInteger('records')->default(0); $t->text('message')->nullable(); $t->dateTime('started_at'); $t->dateTime('finished_at')->nullable(); $t->timestamps(); });
        Schema::create('application_settings', function (Blueprint $t) { $t->id(); $t->string('key')->unique(); $t->json('value'); $t->string('group')->default('general'); $t->timestamps(); });
    }

    public function down(): void
    {
        foreach (['application_settings','data_sync_logs','user_ticket_results','generated_ticket_selections','generated_tickets','recommendations','player_absences','team_statistics','odds_snapshots','odds','markets','match_results','matches','seasons','teams','leagues'] as $table) Schema::dropIfExists($table);
    }
};

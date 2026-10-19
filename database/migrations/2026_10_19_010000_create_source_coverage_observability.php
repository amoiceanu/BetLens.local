<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_match_observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('data_source_id')->constrained()->restrictOnDelete();
            $t->string('external_id', 160);
            $t->foreignId('game_id')->nullable()->constrained('matches')->restrictOnDelete();
            $t->foreignId('league_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('season_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('association')->default('unmatched');
            $t->string('match_status')->nullable();
            $t->json('values');
            $t->json('field_observed_at');
            $t->dateTime('observed_at');
            $t->timestamps();
            $t->unique(['data_source_id', 'external_id'], 'coverage_source_external_unique');
            $t->index(['data_source_id', 'league_id', 'season_id'], 'coverage_observation_scope');
            $t->index(['game_id', 'data_source_id', 'observed_at'], 'coverage_observation_game');
        });
        Schema::create('source_coverage_cells', function (Blueprint $t) {
            $t->id();
            $t->string('scope_key', 64)->unique();
            $t->foreignId('data_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('league_id')->constrained()->restrictOnDelete();
            $t->foreignId('season_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('observed_count')->default(0);
            $t->unsignedInteger('found_count')->default(0);
            $t->unsignedInteger('scheduled_count')->nullable();
            $t->unsignedInteger('ambiguous_count')->default(0);
            $t->unsignedInteger('completed_slots')->default(0);
            $t->unsignedInteger('expected_slots')->default(0);
            $t->json('available_counts');
            $t->json('eligible_counts');
            $t->dateTime('last_observation_at')->nullable();
            $t->timestamps();
            $t->index(['data_source_id', 'league_id', 'season_id'], 'coverage_cell_scope');
        });
        Schema::create('source_data_discrepancies', function (Blueprint $t) {
            $t->id();
            $t->string('identity_key', 64)->unique();
            $t->foreignId('game_id')->constrained('matches')->restrictOnDelete();
            $t->foreignId('source_a_id')->constrained('data_sources')->restrictOnDelete();
            $t->foreignId('source_b_id')->constrained('data_sources')->restrictOnDelete();
            $t->string('external_a_id', 160);
            $t->string('external_b_id', 160);
            $t->string('field', 64);
            $t->json('value_a')->nullable();
            $t->json('value_b')->nullable();
            $t->string('severity', 20);
            $t->string('status', 20)->default('new');
            $t->dateTime('detected_at');
            $t->dateTime('last_detected_at');
            $t->dateTime('resolved_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'severity', 'field'], 'coverage_discrepancy_filters');
            $t->index(['game_id', 'status'], 'coverage_discrepancy_game');
            $t->index(['source_a_id', 'status']);
            $t->index(['source_b_id', 'status']);
        });
        Schema::table('import_audits', function (Blueprint $t) {
            $t->foreignId('league_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('season_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('records_read')->nullable();
            $t->unsignedInteger('records_ignored')->nullable();
            $t->unsignedInteger('errors_count')->nullable();
            $t->index(['provider', 'league_id', 'season_id', 'finished_at'], 'coverage_audit_scope');
        });
        Schema::table('data_sync_logs', fn (Blueprint $t) => $t->index(['provider', 'finished_at'], 'coverage_sync_provider_time'));
    }

    public function down(): void
    {
        Schema::table('data_sync_logs', fn (Blueprint $t) => $t->dropIndex('coverage_sync_provider_time'));
        Schema::table('import_audits', function (Blueprint $t) {
            $t->dropIndex('coverage_audit_scope');
            $t->dropConstrainedForeignId('league_id');
            $t->dropConstrainedForeignId('season_id');
            $t->dropColumn(['records_read', 'records_ignored', 'errors_count']);
        });
        Schema::dropIfExists('source_data_discrepancies');
        Schema::dropIfExists('source_coverage_cells');
        Schema::dropIfExists('source_match_observations');
    }
};

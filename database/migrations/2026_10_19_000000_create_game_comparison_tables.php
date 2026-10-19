<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_actual_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->unique()->constrained('matches')->restrictOnDelete();
            foreach ($this->comparisonColumns() as $column) {
                $table->unsignedInteger($column)->nullable();
            }
            $table->string('match_status')->default('scheduled');
            $table->dateTime('result_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('betlens_predictions', function (Blueprint $table) {
            $this->predictionColumns($table);
            $table->string('model_version')->nullable();
        });

        Schema::create('external_platform_predictions', function (Blueprint $table) {
            $this->predictionColumns($table);
            $table->string('platform_name')->index();
            $table->string('source_prediction_id')->nullable();
            $table->index(['game_id', 'platform_name', 'predicted_at'], 'external_predictions_game_platform_time');
        });
        foreach (['betlens_predictions', 'external_platform_predictions'] as $table) {
            $this->probabilityConstraints($table);
        }
    }

    private function probabilityConstraints(string $table): void
    {
        $columns = ['home_win_probability', 'draw_probability', 'away_win_probability'];
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite cannot add CHECK constraints after CREATE TABLE; equivalent guards cover bulk writes.
            $condition = implode(' OR ', array_map(fn ($column) => "NEW.{$column} < 0 OR NEW.{$column} > 1", $columns));
            foreach (['INSERT', 'UPDATE'] as $event) {
                $name = $table.'_probabilities_'.strtolower($event);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Probability must be between 0 and 1'); END");
            }

            return;
        }
        foreach ($columns as $column) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IS NULL OR ({$column} >= 0 AND {$column} <= 1))");
        }
    }

    private function predictionColumns(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('game_id')->index()->constrained('matches')->restrictOnDelete();
        foreach ($this->comparisonColumns() as $column) {
            $table->decimal($column, 7, 2)->nullable();
        }
        foreach (['home_win_probability', 'draw_probability', 'away_win_probability'] as $column) {
            $table->decimal($column, 7, 6)->nullable();
        }
        foreach (['home_win_odds', 'draw_odds', 'away_win_odds'] as $column) {
            $table->decimal($column, 10, 4)->nullable();
        }
        $table->dateTime('predicted_at')->index();
        $table->timestamps();
        $table->index(['game_id', 'predicted_at']);
    }

    private function comparisonColumns(): array
    {
        return [
            'home_score', 'away_score', 'home_score_ht', 'away_score_ht', 'home_score_2h', 'away_score_2h',
            'home_corners', 'away_corners', 'home_corners_ht', 'away_corners_ht', 'home_corners_2h', 'away_corners_2h',
        ];
    }

    public function down(): void
    {
        Schema::dropIfExists('external_platform_predictions');
        Schema::dropIfExists('betlens_predictions');
        Schema::dropIfExists('game_actual_results');
    }
};

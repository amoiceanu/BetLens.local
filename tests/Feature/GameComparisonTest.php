<?php

namespace Tests\Feature;

use App\Models\BetlensPrediction;
use App\Models\ExternalPlatformPrediction;
use App\Models\FootballMatch;
use App\Models\GameActualResult;
use App\Models\League;
use App\Models\Team;
use App\Services\MatchResultHistory;
use App\Services\OpenLigaDataImporter;
use App\Services\TeamStatisticsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GameComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function game(): FootballMatch
    {
        $league = League::firstOrCreate(['slug' => 'bundesliga'], ['name' => 'Bundesliga', 'code' => 'BL']);
        $home = Team::create(['league_id' => $league->id, 'name' => 'Home', 'short_name' => 'H']);
        $away = Team::create(['league_id' => $league->id, 'name' => 'Away', 'short_name' => 'A']);

        return FootballMatch::create(['league_id' => $league->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id, 'kickoff_at' => now()->addDay()]);
    }

    public function test_null_statistics_and_decimal_snapshots_share_the_existing_match(): void
    {
        $game = $this->game();
        $result = $game->actualResult()->create(['match_status' => 'scheduled']);
        foreach (GameActualResult::COMPARISON_COLUMNS as $column) {
            $this->assertNull($result->fresh()->$column);
        }
        $values = array_fill_keys(BetlensPrediction::COMPARISON_COLUMNS, '1.45');
        $betlens = $game->betlensPredictions()->create($values + ['predicted_at' => now(), 'model_version' => 'v1', 'home_win_probability' => '0.45', 'home_win_odds' => '2.10']);
        $external = $game->externalPlatformPredictions()->create($values + ['predicted_at' => now(), 'platform_name' => 'Forebet', 'source_prediction_id' => 'ext-12']);
        foreach (BetlensPrediction::COMPARISON_COLUMNS as $column) {
            $this->assertSame('1.45', $betlens->fresh()->$column);
            $this->assertSame('1.45', $external->fresh()->$column);
        }
        $this->assertSame('0.450000', $betlens->fresh()->home_win_probability);
        $this->assertNull($external->fresh()->home_win_probability);
        $this->assertSame($game->id, $betlens->game->id);
        $this->assertSame($game->id, $external->game->id);
        $this->assertSame($game->id, $result->game->id);
        $this->assertFalse(Schema::hasTable('games'));
    }

    public function test_prediction_history_and_model_versions_survive_actual_result_updates(): void
    {
        $game = $this->game();
        foreach (['v1', 'v2'] as $version) {
            $game->betlensPredictions()->create(['predicted_at' => now(), 'model_version' => $version, 'home_score' => '1.45']);
            $game->externalPlatformPredictions()->create(['predicted_at' => now(), 'platform_name' => 'Forebet', 'source_prediction_id' => 'same-id', 'home_score' => '1.85']);
        }
        $before = BetlensPrediction::all()->toArray();
        $externalBefore = ExternalPlatformPrediction::all()->toArray();
        $this->travel(5)->minutes();
        $game->actualResult()->create(['match_status' => 'live', 'home_score' => 0, 'result_updated_at' => now()]);
        $game->actualResult->update(['match_status' => 'finished', 'home_score' => 2, 'away_score' => 1, 'home_corners' => 9, 'result_updated_at' => now()]);
        $this->assertSame($before, BetlensPrediction::all()->toArray());
        $this->assertSame($externalBefore, ExternalPlatformPrediction::all()->toArray());
        $this->assertCount(2, $game->betlensPredictions);
        $this->assertCount(2, $game->externalPlatformPredictions);
        $this->assertSame(2, $game->actualResult->fresh()->home_score);
        $this->assertSame(9, $game->actualResult->fresh()->home_corners);
    }

    public function test_probability_range_is_validated_on_create_and_update_for_both_models(): void
    {
        $game = $this->game();
        foreach ([BetlensPrediction::class, ExternalPlatformPrediction::class] as $class) {
            foreach ($class::PROBABILITY_COLUMNS as $field) {
                foreach ([-0.01, 1.000001, 'not-numeric'] as $invalid) {
                    try {
                        $class::create(['game_id' => $game->id, 'predicted_at' => now(), 'platform_name' => 'Forebet', $field => $invalid]);
                        $this->fail('Invalid probability was accepted.');
                    } catch (ValidationException $exception) {
                        $this->assertArrayHasKey($field, $exception->errors());
                    }
                }
            }
            $attributes = ['game_id' => $game->id, 'predicted_at' => now(), 'home_win_probability' => 0, 'draw_probability' => 1, 'away_win_probability' => null];
            if ($class === ExternalPlatformPrediction::class) {
                $attributes['platform_name'] = 'Forebet';
            }
            $prediction = $class::create($attributes);
            $this->assertSame('0.000000', $prediction->fresh()->home_win_probability);
            $this->assertSame('1.000000', $prediction->fresh()->draw_probability);
            try {
                $prediction->update(['draw_probability' => 1.1]);
                $this->fail('Invalid update was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('draw_probability', $exception->errors());
            }
        }
    }

    public function test_actual_result_is_unique_per_game(): void
    {
        $game = $this->game();
        $game->actualResult()->create([]);
        $this->expectException(QueryException::class);
        $game->actualResult()->create([]);
    }

    public function test_foreign_keys_and_indexes_are_defined_without_unique_prediction_keys(): void
    {
        foreach (['game_actual_results', 'betlens_predictions', 'external_platform_predictions'] as $table) {
            $foreign = collect(Schema::getForeignKeys($table))->firstWhere('columns', ['game_id']);
            $this->assertSame('matches', $foreign['foreign_table']);
            $this->assertTrue(Schema::hasIndex($table, ['game_id']));
        }
        foreach (['betlens_predictions', 'external_platform_predictions'] as $table) {
            $this->assertTrue(Schema::hasIndex($table, ['predicted_at']));
            $this->assertTrue(Schema::hasIndex($table, ['game_id', 'predicted_at']));
            $this->assertFalse(Schema::hasIndex($table, ['game_id'], 'unique'));
        }
        $this->assertTrue(Schema::hasIndex('external_platform_predictions', ['platform_name']));
        $this->expectException(QueryException::class);
        DB::table('betlens_predictions')->insert(['game_id' => 999999, 'predicted_at' => now()]);
    }

    public function test_new_result_precedes_legacy_history_and_unknown_scores_are_not_zeroes(): void
    {
        $game = $this->game();
        DB::table('match_results')->insert(['match_id' => $game->id, 'home_score' => 4, 'away_score' => 0]);
        $this->assertEquals(4, (new MatchResultHistory)->query()->first()->home_score);
        $game->actualResult()->create(['match_status' => 'live', 'home_score' => 1]);
        $this->assertSame(0, (new MatchResultHistory)->query()->count());
        $game->actualResult->update(['match_status' => 'finished', 'home_score' => 2, 'away_score' => 1]);
        $this->assertSame(1, (new MatchResultHistory)->query()->count());
        (new TeamStatisticsService)->rebuild($game->league);
        $this->assertDatabaseHas('team_statistics', ['team_id' => $game->home_team_id, 'played' => 1, 'goals_for' => 2]);
        $this->assertDatabaseHas('match_results', ['match_id' => $game->id, 'home_score' => 4]);
    }

    public function test_openliga_import_writes_only_actual_results_and_keeps_missing_statistics_null(): void
    {
        League::create(['slug' => 'bundesliga', 'name' => 'Bundesliga', 'code' => 'BL']);
        Http::fake(['api.openligadb.de/*' => Http::response([[
            'matchID' => 123, 'matchDateTimeUTC' => '2026-10-01T18:00:00Z', 'matchIsFinished' => true,
            'team1' => ['teamId' => 1, 'teamName' => 'Home', 'shortName' => 'H'],
            'team2' => ['teamId' => 2, 'teamName' => 'Away', 'shortName' => 'A'],
            'matchResults' => [['resultTypeID' => 1, 'pointsTeam1' => 0, 'pointsTeam2' => 0], ['resultTypeID' => 2, 'pointsTeam1' => 2]],
        ]], 200)]);
        $metrics = (new OpenLigaDataImporter)->importCurrentSeason();
        $this->assertSame([], $metrics['errors']);
        $actual = GameActualResult::firstOrFail();
        $this->assertSame(2, $actual->home_score);
        $this->assertNull($actual->away_score);
        $this->assertSame(0, $actual->home_score_ht);
        $this->assertNull($actual->home_corners);
        $this->assertNotNull($actual->result_updated_at);
        $this->assertDatabaseCount('match_results', 0);
        $this->assertDatabaseCount('betlens_predictions', 0);
        $this->assertDatabaseCount('external_platform_predictions', 0);
        (new OpenLigaDataImporter)->importCurrentSeason();
        $this->assertDatabaseCount('game_actual_results', 1);
    }

    public function test_database_rejects_out_of_range_probability_inserts_and_bulk_updates(): void
    {
        $game = $this->game();
        foreach (['betlens_predictions', 'external_platform_predictions'] as $table) {
            $attributes = ['game_id' => $game->id, 'predicted_at' => now(), 'home_win_probability' => 0.45];
            if ($table === 'external_platform_predictions') {
                $attributes['platform_name'] = 'Forebet';
            }
            $id = DB::table($table)->insertGetId($attributes);
            try {
                DB::table($table)->where('id', $id)->update(['home_win_probability' => 1.1]);
                $this->fail('Database allowed an invalid bulk update.');
            } catch (QueryException $exception) {
                $this->assertEquals(0.45, DB::table($table)->where('id', $id)->value('home_win_probability'));
            }
            try {
                DB::table($table)->insert(array_replace($attributes, ['home_win_probability' => -0.1]));
                $this->fail('Database allowed an invalid insert.');
            } catch (QueryException $exception) {
                $this->assertSame(1, DB::table($table)->count());
            }
        }
    }

    public function test_actual_values_must_be_non_negative_integers(): void
    {
        $game = $this->game();
        foreach ([-1, 1.45] as $invalid) {
            try {
                $game->actualResult()->create(['home_corners' => $invalid]);
                $this->fail('Invalid actual count was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('home_corners', $exception->errors());
            }
        }
    }

    public function test_existing_predictions_are_locked_after_match_completion(): void
    {
        foreach (['match_status', 'actual_result_status'] as $completion) {
            $game = $this->game();
            $predictions = [
                $game->betlensPredictions()->create(['predicted_at' => now(), 'home_score' => 1.45]),
                $game->externalPlatformPredictions()->create(['predicted_at' => now(), 'platform_name' => 'Forebet', 'home_score' => 1.65]),
            ];
            if ($completion === 'match_status') {
                $game->update(['status' => 'finished']);
            } else {
                $game->actualResult()->create(['match_status' => 'finished']);
            }
            foreach ($predictions as $prediction) {
                $original = $prediction->home_score;
                try {
                    $prediction->update(['home_score' => 9.99]);
                    $this->fail('A completed game prediction was modified.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('prediction', $exception->errors());
                }
                $this->assertSame($original, $prediction->fresh()->home_score);
            }
        }
    }
}

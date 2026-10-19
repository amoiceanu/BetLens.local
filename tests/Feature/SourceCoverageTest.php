<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\ExternalIdMapping;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchWeatherSnapshot;
use App\Models\Season;
use App\Models\SourceCoverageCell;
use App\Models\SourceDataDiscrepancy;
use App\Models\SourceMatchObservation;
use App\Models\Team;
use App\Models\User;
use App\Services\Coverage\CoverageMatrix;
use App\Services\Coverage\FieldRegistry;
use App\Services\Coverage\ObservationProjector;
use App\Services\Coverage\SafeImportMessage;
use App\Services\ImportAuditService;
use App\Services\SourcePayloadStoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SourceCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): static
    {
        $this->withSession(['admin_user_id' => User::factory()->create(['is_admin' => true])->id]);

        return $this;
    }

    private function game(?Season $season = null): FootballMatch
    {
        $league = $season ? League::find($season->league_id) : League::firstOrCreate(['slug' => 'bundesliga'], ['name' => 'Bundesliga', 'code' => 'BL']);
        $season ??= Season::firstOrCreate(['league_id' => $league->id, 'name' => '2026/27'], ['starts_at' => '2026-07-01', 'ends_at' => '2027-06-30']);
        $home = Team::firstOrCreate(['league_id' => $league->id, 'name' => 'Home'], ['short_name' => 'H']);
        $away = Team::firstOrCreate(['league_id' => $league->id, 'name' => 'Away'], ['short_name' => 'A']);

        return FootballMatch::create(['league_id' => $league->id, 'season_id' => $season->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id, 'kickoff_at' => '2026-10-19 18:00:00', 'status' => 'finished']);
    }

    private function fixture(FootballMatch $game, array $values = []): array
    {
        return ['external_id' => 'id-'.$game->id, 'match_status' => 'finished', 'values' => array_replace(['home_team' => 'Home', 'away_team' => 'Away', 'kickoff_at' => '2026-10-19T18:00:00Z', 'home_score' => 2, 'away_score' => 0], $values)];
    }

    private function observe(DataSource $source, FootballMatch $game, array $values = []): void
    {
        $projector = app(ObservationProjector::class);
        $projector->record($source, $this->fixture($game, $values), $game, now());
        $projector->flush();
    }

    public function test_admin_routes_exports_and_reviews_reject_unauthorized_users(): void
    {
        foreach (['admin.coverage', 'admin.coverage.export', 'admin.coverage.discrepancies', 'admin.coverage.discrepancies.export'] as $route) {
            $this->get(route($route))->assertRedirect(route('admin.login'));
        }
        $source = DataSource::first();
        $this->get(route('admin.coverage.details', $source))->assertRedirect(route('admin.login'));
        $this->get('/admin/acoperire/neconcordante/999')->assertRedirect(route('admin.login'));
        $this->patch('/admin/acoperire/neconcordante/999', ['status' => 'ignored'])->assertRedirect(route('admin.login'));
        $this->withSession(['admin_user_id' => User::factory()->create(['is_admin' => false])->id])->get(route('admin.coverage.export'))->assertRedirect(route('admin.login'));
    }

    public function test_empty_matrix_combined_filters_and_exports_do_not_call_providers(): void
    {
        Http::preventStrayRequests();
        $game = $this->game();
        $source = DataSource::where('slug', 'api-football')->firstOrFail();
        $this->admin();
        $filters = ['league' => $game->league_id, 'season' => $game->season_id, 'source' => $source->id, 'state' => 'empty', 'field' => 'home_score', 'q' => 'API-Football'];
        $this->get(route('admin.coverage', $filters))->assertOk()->assertSee('Acoperirea surselor de date')->assertSee('Fără date')->assertSee('Necunoscut');
        $csv = $this->get(route('admin.coverage.export', $filters))->assertOk()->streamedContent();
        $this->assertStringContainsString('API-Football', $csv);
        $this->assertStringNotContainsString('Sportmonks Football API', $csv);
        $this->get(route('admin.coverage', ['api_token' => 'must-not-be-reflected']))->assertOk()->assertDontSee('must-not-be-reflected');
        $this->get(route('admin.coverage', ['q' => 'no-match-unique']))->assertOk()->assertSee('Nicio combinație');
        Http::assertNothingSent();
    }

    public function test_completeness_uses_eligible_slots_zero_is_present_and_profiles_are_explicit(): void
    {
        $game = $this->game();
        $source = DataSource::where('slug', 'api-football')->firstOrFail();
        $this->observe($source, $game);
        $cell = SourceCoverageCell::firstOrFail();
        $this->assertSame(1, $cell->found_count);
        $this->assertSame(1, $cell->available_counts['away_score']);
        $this->assertSame(0, $cell->available_counts['home_corners']);
        $this->assertGreaterThan($cell->completed_slots, $cell->expected_slots);
        $query = app(CoverageMatrix::class)->query(['source' => $source->id, 'league' => $game->league_id, 'season' => $game->season_id, 'field' => 'away_score']);
        $row = app(CoverageMatrix::class)->present($query->first());
        $this->assertSame(100.0, $row->percent);
        $this->assertSame(1, $row->denominator);
        $this->admin()->get(route('admin.coverage', ['source' => $source->id, 'league' => $game->league_id, 'field' => 'home_corners']))->assertOk()->assertSee('Suportat, neacoperit');
    }

    public function test_conflicts_are_stable_ignored_resolved_and_reopened_without_overwriting_sources(): void
    {
        $game = $this->game();
        $a = DataSource::where('slug', 'api-football')->firstOrFail();
        $b = DataSource::where('slug', 'football-data')->firstOrFail();
        $this->observe($a, $game);
        $this->observe($b, $game, ['home_score' => 3, 'kickoff_at' => '2026-10-19T18:40:00Z', 'home_team' => 'Different team', 'home_score_ht' => 1, 'home_corners' => 5]);
        foreach (['home_score', 'kickoff_at', 'home_team', 'home_score_ht', 'home_corners'] as $field) {
            $this->assertDatabaseHas('source_data_discrepancies', ['game_id' => $game->id, 'field' => $field, 'status' => 'new']);
        }
        $count = SourceDataDiscrepancy::count();
        $score = SourceDataDiscrepancy::where('field', 'home_score')->firstOrFail();
        $detected = $score->detected_at->toIso8601String();
        $this->admin()->from(route('admin.coverage.discrepancy', $score))->patch(route('admin.coverage.review', $score), ['status' => 'ignored'])->assertRedirect();
        $this->observe($b, $game, ['home_score' => 3, 'kickoff_at' => '2026-10-19T18:40:00Z', 'home_team' => 'Different team', 'home_score_ht' => 1, 'home_corners' => 5]);
        $this->assertSame($count, SourceDataDiscrepancy::count());
        $this->assertSame('ignored', $score->fresh()->status);
        $this->assertSame($detected, $score->fresh()->detected_at->toIso8601String());
        $this->observe($b, $game, ['home_score' => 2, 'home_score_ht' => null, 'home_corners' => null]);
        $this->assertSame('resolved', $score->fresh()->status);
        $this->observe($b, $game, ['home_score' => 4]);
        $this->assertSame('new', $score->fresh()->status);
        $this->assertSame(2, SourceMatchObservation::where('data_source_id', $a->id)->first()->values['home_score']);
        $this->get(route('admin.coverage.discrepancies', ['source' => $a->id, 'league' => $game->league_id, 'field' => 'home_score', 'severity' => 'critical', 'status' => 'new']))->assertOk()->assertSee('Scor final gazde');
        $this->get(route('admin.coverage.discrepancy', $score))->assertOk()->assertSee('Salvează statusul');
    }

    public function test_mapping_and_prudent_matching_leave_ambiguous_fixtures_unassociated(): void
    {
        $game = $this->game();
        $source = DataSource::where('slug', 'sportmonks')->firstOrFail();
        $projector = app(ObservationProjector::class);
        $payload = ['external_id' => '456', 'league' => 'Bundesliga', 'home_team' => 'Home', 'away_team' => 'Away', 'kickoff_at' => '2026-10-19T18:00:00Z', 'raw' => ['state_id' => 5]];
        $duplicate = $game->replicate();
        $duplicate->save();
        $projector->stored($source, 'sportmonks_fixture', [$payload]);
        $projector->flush();
        $observation = SourceMatchObservation::firstOrFail();
        $this->assertNull($observation->game_id);
        $this->assertSame('ambiguous', $observation->association);
        $this->assertDatabaseCount('source_data_discrepancies', 0);
        ExternalIdMapping::create(['provider' => 'sportmonks', 'entity_type' => 'match', 'external_id' => '456', 'internal_id' => $game->id]);
        $projector->flush();
        $this->assertSame($game->id, $observation->fresh()->game_id);
        $this->assertSame('mapped', $observation->fresh()->association);
    }

    public function test_freshness_and_interval_are_calculated_from_real_audit_history(): void
    {
        $game = $this->game();
        $source = DataSource::where('slug', 'api-football')->firstOrFail();
        $this->observe($source, $game);
        foreach ([now()->subHours(80), now()->subHours(78)] as $time) {
            DB::table('data_sync_logs')->insert(['provider' => $source->slug, 'type' => 'fixtures', 'status' => 'healthy', 'records' => 1, 'started_at' => $time->copy()->subMinute(), 'finished_at' => $time]);
        }
        DB::table('data_sync_logs')->insert(['provider' => $source->slug, 'type' => 'fixtures', 'status' => 'unavailable', 'records' => 0, 'started_at' => now()->subMinutes(2), 'finished_at' => now()->subMinute()]);
        $matrix = app(CoverageMatrix::class);
        $row = $matrix->present($matrix->query(['source' => $source->id, 'league' => $game->league_id, 'season' => $game->season_id])->first());
        $this->assertSame('Critică', $row->freshness);
        $this->assertSame(120.0, $row->frequency);
        $this->assertEquals(1, $row->recent_errors);
        $this->assertSame('unavailable', $row->attempt_status);
        $this->assertSame('global', $row->audit_scope);
        $this->assertSame('old', $row->coverage_state);
        app(ImportAuditService::class)->record($source->slug, 'fixtures', 'healthy', ['league_id' => $game->league_id, 'season_id' => $game->season_id, 'started_at' => now()->subMinute(), 'finished_at' => now(), 'read' => 1, 'unchanged' => 1]);
        $row = $matrix->present($matrix->query(['source' => $source->id, 'league' => $game->league_id, 'season' => $game->season_id])->first());
        $this->assertSame('league', $row->audit_scope);
        $this->assertSame('Proaspătă', $row->freshness);
        $this->assertNull($row->frequency);
    }

    public function test_csv_export_filters_and_formula_injection_and_xss_escaping(): void
    {
        $game = $this->game();
        $source = DataSource::where('slug', 'api-football')->firstOrFail();
        $source->update(['name' => '=DANGEROUS() <script>alert(1)</script>']);
        $this->observe($source, $game);
        $this->admin();
        $this->get(route('admin.coverage', ['source' => $source->id]))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $csv = $this->get(route('admin.coverage.export', ['source' => $source->id, 'league' => $game->league_id]))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=DANGEROUS()", $csv);
        $other = DataSource::where('slug', 'football-data')->firstOrFail();
        $this->observe($other, $game, ['home_score' => 3]);
        $csv = $this->get(route('admin.coverage.discrepancies.export', ['source' => $source->id, 'field' => 'home_score', 'status' => 'new']))->assertOk()->streamedContent();
        $this->assertStringContainsString('home_score', $csv);
        $this->assertStringNotContainsString('away_corners', $csv);
        config(['coverage.export_limit' => 0]);
        $this->get(route('admin.coverage.export', ['source' => $source->id]))->assertStatus(422);
    }

    public function test_existing_store_hooks_backfill_and_import_metadata_are_idempotent_and_secret_safe(): void
    {
        Http::preventStrayRequests();
        $game = $this->game();
        $source = DataSource::where('slug', 'football-data-uk')->firstOrFail();
        $payload = ['Div' => 'D1', 'Date' => '19/10/2026', 'HomeTeam' => 'Home', 'AwayTeam' => 'Away', 'FTHG' => '2', 'FTAG' => '0', 'HC' => '0', 'AC' => '4', 'HS' => '8'];
        $store = app(SourcePayloadStoreService::class);
        $store->store($source, 'historical_match', [$payload]);
        $store->store($source, 'historical_match', [$payload]);
        $this->assertDatabaseCount('source_records', 1);
        $this->assertDatabaseCount('source_match_observations', 1);
        $this->assertDatabaseCount('source_coverage_cells', 1);
        $this->assertSame(0, SourceMatchObservation::first()->values['home_corners']);
        $this->artisan('betlens:coverage-rebuild', ['--source' => 'football-data-uk'])->assertSuccessful();
        $this->artisan('betlens:coverage-rebuild', ['--source' => 'football-data-uk'])->assertSuccessful();
        $this->assertDatabaseCount('source_match_observations', 1);
        config(['services.sportmonks.token' => 'secret-value-983']);
        $store->log('sportmonks', 'fixtures', 'unavailable', 0, 'Token secret-value-983 https://example.test/?api_token=another-secret', microtime(true), ['errors' => 1]);
        $message = DB::table('import_audits')->where('provider', 'sportmonks')->value('message');
        $this->assertStringNotContainsString('secret-value-983', $message);
        $this->assertStringNotContainsString('another-secret', $message);
        $this->assertNotNull(DB::table('import_audits')->where('provider', 'sportmonks')->value('started_at'));
        $this->admin()->get(route('admin.coverage'))->assertOk()->assertDontSee('secret-value-983');
        Http::assertNothingSent();
    }

    public function test_pagination_validation_details_and_missing_schema_are_explicit(): void
    {
        $game = $this->game();
        $source = DataSource::where('slug', 'api-football')->firstOrFail();
        $this->observe($source, $game);
        $this->admin();
        foreach (range(1, 3) as $i) {
            League::create(['name' => 'Extra '.$i, 'slug' => 'extra-'.$i, 'code' => 'E'.$i]);
        }
        $this->get(route('admin.coverage', ['per_page' => 15, 'page' => 2]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->currentPage() === 2 && $rows->count() > 0);
        $this->get(route('admin.coverage.details', ['source' => $source->id, 'league' => $game->league_id, 'issue' => 'incomplete']))->assertOk()->assertSee('Nedisponibilă');
        foreach ([['field' => 'secret'], ['per_page' => 999], ['q' => str_repeat('a', 101)], ['league' => 999999]] as $invalid) {
            $this->get(route('admin.coverage', $invalid))->assertSessionHasErrors();
        }
        Schema::drop('source_coverage_cells');
        $this->get(route('admin.coverage'))->assertStatus(503)->assertSee('Evidență indisponibilă');
        $this->assertDatabaseCount('matches', 1);
    }

    public function test_unknown_seasons_and_missing_sources_never_become_fake_counts(): void
    {
        $game = $this->game();
        $game->update(['season_id' => null]);
        $source = DataSource::where('slug', 'api-football')->firstOrFail();
        $this->observe($source, $game);
        $rows = app(CoverageMatrix::class)->query(['source' => $source->id, 'league' => $game->league_id])->get();
        $this->assertNotNull($rows->first(fn ($row) => $row->season_id === null && $row->found_count == 1));
        $this->admin()->get(route('admin.coverage.details', ['source' => $source->id, 'issue' => 'conflict']))->assertOk()->assertSee('Nu există observații');
        $this->get(route('admin.coverage', ['source' => 999999]))->assertSessionHasErrors('source');
    }

    public function test_old_tokens_in_json_headers_and_urls_are_redacted_without_configuration(): void
    {
        $clean = app(SafeImportMessage::class)->clean('Authorization: Bearer old-header-token {"api_token":"old-json-token"} token=old-param https://user:pass@example.test/?key=old-url');
        foreach (['old-header-token', 'old-json-token', 'old-param', 'old-url', 'user:pass'] as $secret) {
            $this->assertStringNotContainsString($secret, $clean);
        }
    }

    public function test_stored_api_payloads_and_weather_are_projected_using_existing_mapping_ids(): void
    {
        $game = $this->game();
        foreach (['api-football', 'football-data', 'sportmonks'] as $provider) {
            ExternalIdMapping::create(['provider' => $provider, 'entity_type' => 'match', 'external_id' => '900', 'internal_id' => $game->id]);
        }
        $p = app(ObservationProjector::class);
        $a = DataSource::where('slug', 'api-football')->firstOrFail();
        $p->stored($a, 'external_record', [['fixture' => ['id' => 900, 'date' => '2026-10-19T18:00:00Z', 'status' => ['short' => 'FT']], 'league' => ['name' => 'Bundesliga'], 'teams' => ['home' => ['name' => 'Home'], 'away' => ['name' => 'Away']], 'goals' => ['home' => 2, 'away' => 0], 'score' => ['halftime' => ['home' => 1, 'away' => 0]]]]);
        $b = DataSource::where('slug', 'football-data')->firstOrFail();
        $p->stored($b, 'external_record', [['id' => 900, 'utcDate' => '2026-10-19T18:00:00Z', 'competition' => ['name' => 'Bundesliga'], 'homeTeam' => ['name' => 'Home'], 'awayTeam' => ['name' => 'Away'], 'status' => 'FINISHED', 'score' => ['fullTime' => ['home' => 2, 'away' => 0], 'halfTime' => ['home' => 1, 'away' => 0]]]]);
        $sport = DataSource::where('slug', 'sportmonks')->firstOrFail();
        $p->stored($sport, 'sportmonks_statistics', [['id' => 900, 'state_id' => 5, 'starting_at' => '2026-10-19T18:00:00Z', 'participants' => [['id' => 1, 'name' => 'Home', 'meta' => ['location' => 'home']], ['id' => 2, 'name' => 'Away', 'meta' => ['location' => 'away']]], 'scores' => [['participant_id' => '1', 'description' => 'CURRENT', 'score' => ['goals' => 2]], ['participant_id' => '2', 'description' => 'CURRENT', 'score' => ['goals' => 0]]], 'lineups' => [['id' => 1]]]]);
        $p->flush();
        $this->assertDatabaseCount('source_match_observations', 3);
        $this->assertSame(2, SourceMatchObservation::where('data_source_id', $sport->id)->first()->values['home_score']);
        MatchWeatherSnapshot::create(['match_id' => $game->id, 'provider' => 'open-meteo', 'forecast_for' => $game->kickoff_at, 'fetched_at' => now(), 'temperature_c' => -4, 'precipitation_mm' => 0, 'wind_speed_kmh' => 5]);
        $p->flush();
        $weather = DataSource::where('slug', 'open-meteo')->firstOrFail();
        $this->assertEquals(-4, SourceMatchObservation::where('data_source_id', $weather->id)->first()->values['temperature_c']);
        $row = app(CoverageMatrix::class)->present(app(CoverageMatrix::class)->query(['source' => $weather->id, 'league' => $game->league_id, 'season' => $game->season_id])->first());
        $this->assertEquals(100, $row->percent);
        $this->assertEquals(3, $row->denominator);
    }

    public function test_matrix_query_count_is_bounded_when_page_size_grows(): void
    {
        $this->game();
        foreach (range(1, 4) as $i) {
            League::create(['slug' => 'perf-'.$i, 'name' => 'Performance '.$i, 'code' => 'P'.$i]);
        }
        $this->admin();
        app(FieldRegistry::class)->fields();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('admin.coverage', ['per_page' => 15]))->assertOk();
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->get(route('admin.coverage', ['per_page' => 30]))->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small + 3, $large);
    }

    public function test_projection_failure_cannot_break_the_original_payload_store(): void
    {
        $this->game();
        $source = DataSource::where('slug', 'football-data-uk')->firstOrFail();
        Schema::drop('source_coverage_cells');
        $metrics = app(SourcePayloadStoreService::class)->store($source, 'historical_match', [['Div' => 'D1', 'Date' => '19/10/2026', 'HomeTeam' => 'Home', 'AwayTeam' => 'Away', 'FTHG' => 2, 'FTAG' => 0]]);
        $this->assertSame(1, $metrics['new']);
        $this->assertDatabaseCount('source_records', 1);
    }
}

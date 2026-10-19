<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuperbetOddsFeedsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['admin_user_id'=>\App\Models\User::factory()->create(['is_admin'=>true])->id]);
    }

    private function matchFixture(): FootballMatch
    {
        $league = League::create(['name' => 'Bundesliga', 'slug' => 'bundesliga', 'code' => 'BL', 'active' => true]);
        $home = Team::create(['league_id' => $league->id, 'name' => 'FC Bayern München', 'short_name' => 'Bayern']);
        $away = Team::create(['league_id' => $league->id, 'name' => 'RB Leipzig', 'short_name' => 'Leipzig']);
        return FootballMatch::create(['league_id' => $league->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id, 'kickoff_at' => now()->addHours(3)->startOfMinute()]);
    }

    public function test_sources_are_unconfigured_without_separate_keys(): void
    {
        config(['services.odds_api_io.key' => null, 'services.oddspapi.key' => null]);
        Http::preventStrayRequests();
        $first = DataSource::where('slug', 'odds-api-io-superbet')->firstOrFail();
        $second = DataSource::where('slug', 'oddspapi-superbet')->firstOrFail();

        $this->get('/surse-date')->assertOk()
            ->assertSee('Odds-API.io (Superbet)')
            ->assertSee('OddsPapi (Superbet RO)')
            ->assertDontSee(route('data-sources.verify', $first), false)
            ->assertDontSee(route('data-sources.verify', $second), false);
        $this->post(route('data-sources.verify', $first))->assertRedirect()->assertSessionHas('warning');
        Http::assertNothingSent();
    }

    public function test_odds_api_io_imports_only_matched_superbet_snapshots_without_replacing_primary_odds(): void
    {
        config(['services.odds_api_io.key' => 'fake-io-key']);
        $match = $this->matchFixture();
        $this->seed(\Database\Seeders\KnownTeamAliasesSeeder::class);
        $date = $match->kickoff_at->copy()->utc()->toIso8601String();
        Http::fake([
            'api.odds-api.io/v3/events*' => Http::response([
                ['id' => 71, 'home' => 'Bayern Munich', 'away' => 'RB Leipzig', 'date' => $date, 'league' => ['name' => 'Germany - Bundesliga']],
                ['id' => 72, 'home' => 'Bayern Munich', 'away' => 'RB Leipzig', 'date' => $match->kickoff_at->copy()->addDay()->utc()->toIso8601String(), 'league' => ['name' => 'Germany - Bundesliga']],
            ]),
            'api.odds-api.io/v3/odds/multi*' => Http::response([
                ['id' => 71, 'bookmakers' => ['Superbet' => [['name' => 'ML', 'odds' => [['home' => '1.80', 'draw' => '3.60', 'away' => '4.20']]]]]],
            ]),
        ]);
        $source = DataSource::where('slug', 'odds-api-io-superbet')->firstOrFail();

        $this->post(route('data-sources.verify', $source))
            ->assertRedirect()
            ->assertSessionHas('verification_result', fn ($result) => $result['status'] === 'healthy' && $result['detected'] === 2 && $result['new'] === 1);
        $this->assertDatabaseHas('source_records', ['data_source_id' => $source->id, 'external_key' => 'superbet_odds_snapshot:71']);
        $this->assertDatabaseCount('odds', 0);
        $this->assertDatabaseHas('import_audits', ['provider' => 'odds-api-io-superbet', 'records_new' => 1]);
        $this->assertSame($match->id, $source->records()->first()->payload['internal_match_id']);
    }

    public function test_oddspapi_imports_matched_superbet_ro_snapshot(): void
    {
        config(['services.oddspapi.key' => 'fake-papi-key']);
        $match = $this->matchFixture();
        Http::fake([
            'api.oddspapi.io/v4/tournaments*' => Http::response([
                ['tournamentId' => 42, 'tournamentName' => 'Bundesliga', 'categorySlug' => 'germany'],
                ['tournamentId' => 43, 'tournamentName' => 'Bundesliga', 'categorySlug' => 'austria'],
            ]),
            'api.oddspapi.io/v4/fixtures*' => Http::response([
                ['fixtureId' => 'id123', 'participant1Name' => 'FC Bayern München', 'participant2Name' => 'RB Leipzig', 'startTime' => $match->kickoff_at->copy()->utc()->toIso8601String(), 'tournamentName' => 'Bundesliga'],
            ]),
            'api.oddspapi.io/v4/odds-by-tournaments*' => Http::response([
                ['fixtureId' => 'id123', 'bookmakerOdds' => ['superbet.ro' => ['bookmakerFixtureId' => '14127662', 'markets' => ['101' => ['outcomes' => []]]]]],
            ]),
        ]);
        $source = DataSource::where('slug', 'oddspapi-superbet')->firstOrFail();

        $this->post(route('data-sources.verify', $source))
            ->assertRedirect()
            ->assertSessionHas('verification_result', fn ($result) => $result['status'] === 'healthy' && $result['detected'] === 1 && $result['new'] === 1);
        $this->assertDatabaseHas('source_records', ['data_source_id' => $source->id, 'external_key' => 'superbet_odds_snapshot:id123']);
        $this->assertSame('14127662', $source->records()->first()->payload['odds_payload']['bookmakerFixtureId']);
        $this->assertDatabaseCount('odds', 0);
    }

    public function test_api_failure_does_not_expose_the_key_in_status_or_logs(): void
    {
        config(['services.oddspapi.key' => 'secret-test-key']);
        Http::fake(['api.oddspapi.io/*' => Http::response(['message' => 'Unauthorized'], 401)]);
        $source = DataSource::where('slug', 'oddspapi-superbet')->firstOrFail();

        $this->post(route('data-sources.verify', $source))->assertRedirect();
        $this->assertSame('unavailable', $source->fresh()->status);
        $this->assertStringNotContainsString('secret-test-key', $source->fresh()->last_message);
        $this->assertStringNotContainsString('secret-test-key', (string) \DB::table('data_sync_logs')->where('provider', $source->slug)->value('message'));
    }
}

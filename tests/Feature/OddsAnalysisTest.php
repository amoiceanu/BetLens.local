<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use App\Services\OddsImportService;
use App\Services\MatchAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OddsAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_provider_payload_creates_odds_snapshots_and_recommendations(): void
    {
        $this->seed();
        config(['services.odds_api.regions' => 'eu', 'services.odds_api.markets' => 'h2h,totals']);
        $league = League::where('slug', 'bundesliga')->firstOrFail();
        $season = Season::create(['league_id' => $league->id, 'name' => '2026/27', 'starts_at' => '2026-07-01', 'ends_at' => '2027-06-30', 'current' => true]);
        $home = Team::create(['league_id' => $league->id, 'name' => 'Bayern Munich', 'short_name' => 'Bayern']);
        $away = Team::create(['league_id' => $league->id, 'name' => 'Borussia Dortmund', 'short_name' => 'Dortmund']);

        foreach (range(1, 6) as $index) {
            $finished = FootballMatch::create(['league_id' => $league->id, 'season_id' => $season->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id, 'kickoff_at' => now()->subDays(10 + $index), 'status' => 'finished', 'external_id' => 'history-'.$index]);
            DB::table('match_results')->insert(['match_id' => $finished->id, 'home_score' => 4, 'away_score' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $kickoff = now()->addDays(2)->startOfHour();
        $future = FootballMatch::create(['league_id' => $league->id, 'season_id' => $season->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id, 'kickoff_at' => $kickoff, 'status' => 'scheduled', 'external_id' => 'future-1']);

        $modelMetrics = app(MatchAnalysisService::class)->generateModelEstimates($league);
        $this->assertGreaterThanOrEqual(1, $modelMetrics['eligible']);
        $this->assertDatabaseHas('recommendations', ['match_id' => $future->id, 'model_version' => 'poisson-fair-v1', 'eligible' => true]);

        Http::fake(['api.the-odds-api.com/*' => Http::response([[
            'id' => 'odds-event-1', 'sport_key' => 'soccer_germany_bundesliga', 'commence_time' => $kickoff->copy()->utc()->toIso8601String(),
            'home_team' => 'Bayern Munich', 'away_team' => 'Borussia Dortmund',
            'bookmakers' => [[
                'key' => 'pinnacle', 'last_update' => now()->utc()->toIso8601String(),
                'markets' => [
                    ['key' => 'h2h', 'outcomes' => [['name' => 'Bayern Munich', 'price' => 1.80], ['name' => 'Draw', 'price' => 4.20], ['name' => 'Borussia Dortmund', 'price' => 5.00]]],
                    ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'price' => 1.90, 'point' => 2.5], ['name' => 'Under', 'price' => 2.00, 'point' => 2.5]]],
                ],
            ]],
        ]], 200, ['x-requests-remaining' => '499'])]);

        $metrics = app(OddsImportService::class)->sync('real-provider-key');

        $this->assertSame(1, $metrics['matched']);
        $this->assertSame(5, $metrics['odds_new']);
        $this->assertGreaterThanOrEqual(1, $metrics['eligible']);
        $this->assertDatabaseHas('provider_match_mappings', ['match_id' => $future->id, 'provider' => 'the-odds-api', 'external_id' => 'odds-event-1']);
        $this->assertDatabaseHas('odds', ['match_id' => $future->id, 'selection' => '1', 'provider' => 'pinnacle']);
        $this->assertDatabaseHas('recommendations', ['match_id' => $future->id, 'selection' => '1', 'model_version' => 'poisson-v1', 'eligible' => true]);
        $this->assertDatabaseHas('recommendations', ['match_id' => $future->id, 'model_version' => 'poisson-fair-v1', 'eligible' => false]);
        $this->assertDatabaseCount('odds_snapshots', 5);
    }
}

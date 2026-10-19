<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\GameActualResult;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use App\Jobs\ImportUpcomingMatchesJob;
use App\Models\ImportRun;
use App\Services\OpenLigaDataImporter;
use App\Services\UpcomingPredictionBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UpcomingMatchesImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $league = League::create(['slug' => 'bundesliga', 'name' => 'Bundesliga', 'code' => 'BL']);
        $home = Team::create(['league_id' => $league->id, 'name' => 'Home', 'short_name' => 'Home']);
        $away = Team::create(['league_id' => $league->id, 'name' => 'Away', 'short_name' => 'Away']);
        $history = FootballMatch::create(['league_id' => $league->id, 'home_team_id' => $home->id,
            'away_team_id' => $away->id, 'kickoff_at' => now()->subMonth(), 'status' => 'finished']);
        GameActualResult::create(['game_id' => $history->id, 'match_status' => 'finished', 'home_score' => 2, 'away_score' => 1]);
        $upcoming = ['matchID' => 8675309, 'matchDateTimeUTC' => now()->addDays(2)->utc()->format('Y-m-d\TH:i:s\Z'),
            'matchIsFinished' => false, 'team1' => ['teamId' => 101, 'teamName' => 'Bayern', 'shortName' => 'BAY'],
            'team2' => ['teamId' => 102, 'teamName' => 'Leipzig', 'shortName' => 'RBL'], 'matchResults' => []];
        $finished = $upcoming;
        $finished['matchIsFinished'] = true;
        $finished['matchResults'] = [['resultTypeID' => 2, 'pointsTeam1' => 3, 'pointsTeam2' => 1]];
        Http::fakeSequence()->push([$upcoming])->push([$upcoming])->push([$finished]);

        return [$league, $upcoming];
    }

    public function test_import_is_idempotent_and_actual_results_are_filled_later(): void
    {
        $this->fixture();
        $importer = app(OpenLigaDataImporter::class);
        $first = $importer->importUpcomingMatches();
        $this->assertSame(1, $first['matches_new']);
        $match = FootballMatch::where('external_id', 'openligadb:8675309')->firstOrFail();
        $this->assertNull($match->actualResult->home_score);
        $this->assertNotNull($match->latestBetlensPrediction->home_win_odds);
        $this->assertNull($match->latestBetlensPrediction->home_corners);
        $second = $importer->importUpcomingMatches();
        $this->assertSame(0, $second['matches_new']);
        $this->assertSame(1, $match->betlensPredictions()->count());

        $importer->importUpcomingMatches();
        $this->assertSame('finished', $match->fresh()->status);
        $this->assertSame(3, $match->actualResult->fresh()->home_score);
        $this->assertSame(1, $match->betlensPredictions()->count());
    }

    public function test_admin_page_filters_and_shows_exactly_two_rows_per_match(): void
    {
        [$league] = $this->fixture();
        app(OpenLigaDataImporter::class)->importUpcomingMatches();
        $this->get(route('admin.upcoming-matches'))->assertRedirect(route('user.login'));
        $admin = User::factory()->create(['is_admin' => true]);
        $response = $this->withSession(['admin_user_id' => $admin->id])->get(route('admin.upcoming-matches', [
            'league' => $league->id, 'team' => 'Bayern',
        ]));
        $response->assertOk()->assertSee('Bayern')->assertSee('Leipzig')
            ->assertSee('Rezultat real')->assertSee('BetLens')->assertSee('1 meciuri');
        $this->withSession(['admin_user_id' => $admin->id])->get(route('admin.upcoming-matches', [
            'league' => $league->id, 'team' => 'Unrelated',
        ]))->assertOk()->assertDontSee('Bayern');
    }

    public function test_backfill_adds_fair_odds_to_existing_matches_without_duplicates(): void
    {
        [$league] = $this->fixture();
        $home = Team::create(['league_id' => $league->id, 'name' => 'Bologna', 'short_name' => 'BOL']);
        $away = Team::create(['league_id' => $league->id, 'name' => 'Inter', 'short_name' => 'INT']);
        $match = FootballMatch::create(['league_id' => $league->id, 'home_team_id' => $home->id,
            'away_team_id' => $away->id, 'kickoff_at' => now()->addDays(5)]);
        $backfill = app(UpcomingPredictionBackfill::class);
        $preview = $backfill->run(true);
        $this->assertSame(1, $preview['created']);
        $this->assertSame(0, $match->betlensPredictions()->count());
        $this->assertSame(1, $backfill->run()['created']);
        $this->assertSame(1, $backfill->run()['unchanged']);
        $this->assertSame(1, $match->betlensPredictions()->count());
        $this->assertGreaterThan(1, (float) $match->latestBetlensPrediction->fresh()->home_win_odds);
    }

    public function test_admin_import_is_queued_and_exposes_progress(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $response = $this->withSession(['admin_user_id' => $admin->id])
            ->postJson(route('admin.upcoming-matches.import'), ['days' => 30]);
        $response->assertStatus(202)->assertJsonStructure(['run_id', 'progress_url']);
        Queue::assertPushed(ImportUpcomingMatchesJob::class, 1);
        $run = ImportRun::findOrFail($response->json('run_id'));
        $this->assertSame('queued', $run->status);
        $this->get(route('admin.upcoming-matches.progress', $run))
            ->assertOk()->assertJsonPath('status', 'queued')->assertJsonPath('metadata.stage', 'queued');
    }
}

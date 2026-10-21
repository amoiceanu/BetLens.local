<?php

namespace Tests\Feature;

use App\Jobs\RunMindmapImport;
use App\Models\DataSource;
use App\Models\League;
use App\Services\OpenLigaDataImporter;
use App\Services\Mindmap\ImportManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProviderIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_football_data_org_import_runs_through_queue_adapter_and_persists_provider_payload(): void
    {
        config(['services.football_data.key' => 'football-data-test-key']);
        Http::preventStrayRequests();
        Http::fake(['api.football-data.org/v4/competitions*' => Http::response([
            'count' => 1,
            'competitions' => [[
                'id' => 2001, 'name' => 'Premier League', 'code' => 'PL', 'type' => 'LEAGUE',
                'area' => ['name' => 'England', 'code' => 'ENG'],
                'currentSeason' => ['id' => 2026, 'startDate' => '2026-08-01', 'endDate' => '2027-05-31'],
            ]],
        ], 200)]);
        Queue::fake();
        $source = DataSource::where('slug', 'football-data')->firstOrFail();

        $run = app(ImportManager::class)->enqueue($source);
        Queue::assertPushed(RunMindmapImport::class, fn (RunMindmapImport $job) => $job->runId === $run->id);
        app()->call([new RunMindmapImport($run->id), 'handle']);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->records_new);
        $this->assertDatabaseHas('source_records', [
            'data_source_id' => $source->id,
            'record_type' => 'competition',
            'external_key' => 'id:2001',
        ]);
        $this->assertDatabaseHas('import_audits', [
            'import_run_id' => $run->id, 'provider' => 'football-data', 'overall_status' => 'success',
        ]);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Auth-Token', 'football-data-test-key'));
    }

    public function test_openligadb_fixture_import_writes_teams_matches_and_results_idempotently(): void
    {
        League::create(['name' => 'Bundesliga', 'slug' => 'bundesliga', 'country' => 'Germania', 'code' => 'BL', 'active' => true]);
        $fixture = [[
            'matchID' => 987654,
            'matchDateTimeUTC' => now()->addDays(3)->utc()->toIso8601String(),
            'matchIsFinished' => true,
            'team1' => ['teamId' => 8101, 'teamName' => 'Test Home FC', 'shortName' => 'Home'],
            'team2' => ['teamId' => 8102, 'teamName' => 'Test Away FC', 'shortName' => 'Away'],
            'matchResults' => [
                ['resultTypeID' => 1, 'pointsTeam1' => 1, 'pointsTeam2' => 0],
                ['resultTypeID' => 2, 'pointsTeam1' => 2, 'pointsTeam2' => 1],
            ],
            'location' => ['locationStadium' => 'Test Stadium'],
        ]];
        Http::preventStrayRequests();
        Http::fake(['api.openligadb.de/getmatchdata/bl1/2026' => Http::response($fixture, 200)]);

        $first = app(OpenLigaDataImporter::class)->importCurrentSeason(2026);
        $this->assertSame(2, $first['teams']);
        $this->assertSame(1, $first['matches_new']);
        $this->assertSame(1, $first['results']);
        $this->assertDatabaseHas('teams', ['provider' => 'openligadb', 'external_id' => '8101', 'name' => 'Test Home FC']);
        $this->assertDatabaseHas('matches', ['external_id' => 'openligadb:987654', 'status' => 'finished']);
        $match = \App\Models\FootballMatch::where('external_id', 'openligadb:987654')->firstOrFail();
        $this->assertDatabaseHas('game_actual_results', ['game_id' => $match->id, 'home_score' => 2, 'away_score' => 1, 'home_score_ht' => 1, 'away_score_ht' => 0]);

        $again = app(OpenLigaDataImporter::class)->importCurrentSeason(2026);
        $this->assertSame(0, $again['teams']);
        $this->assertSame(1, $again['matches_updated']);
        $this->assertDatabaseCount('matches', 1);
        $this->assertDatabaseCount('teams', 2);
    }
}

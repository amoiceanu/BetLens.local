<?php

namespace Tests\Feature;

use App\Jobs\RunMindmapImport;
use App\Models\DataSource;
use App\Models\ImportRun;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\Mindmap\ImportManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportControlCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['admin_user_id' => User::factory()->create(['is_admin' => true])->id]);
    }

    public function test_mindmap_no_longer_displays_recent_imports_section(): void
    {
        $this->get(route('admin.mindmap'))->assertOk()
            ->assertDontSee('id="recent-imports"', false)
            ->assertDontSee('Ultimele importuri')
            ->assertSee(route('admin.import-audit'), false);

        $this->get(route('admin.import-audit'))->assertOk()->assertSee('Audit importuri');
    }

    public function test_existing_adapter_runs_asynchronously_stores_raw_response_and_is_idempotent(): void
    {
        Queue::fake();
        Storage::fake('local');
        Http::fake(['raw.githubusercontent.com/*' => Http::response([['competition_id' => 9, 'season_id' => 1, 'name' => 'Liga test']], 200)]);
        $source = DataSource::where('slug', 'statsbomb-open-data')->firstOrFail();
        $manager = app(ImportManager::class);

        $run = $manager->enqueue($source);
        Queue::assertPushed(RunMindmapImport::class, fn ($job) => $job->runId === $run->id);
        $this->assertSame('queued', $run->status);
        $this->get(route('admin.mindmap'))->assertOk()
            ->assertViewHas('rows', fn ($rows) => collect($rows)->firstWhere('id', $source->id)['activeRunId'] === $run->id)
            ->assertSee('data-import-source="'.$source->id.'"', false)
            ->assertSee('În coadă');
        try {
            $manager->enqueue($source);
            $this->fail('A second active run should be rejected.');
        } catch (ValidationException) {}

        app()->call([new RunMindmapImport($run->id), 'handle']);
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->records_new);
        $this->assertSame(1, SourceRecord::where('data_source_id', $source->id)->count());
        $document = $run->documents()->firstOrFail();
        Storage::disk('local')->assertExists($document->path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($document->path)), $document->checksum);

        $next = $manager->enqueue($source);
        app()->call([new RunMindmapImport($next->id), 'handle']);
        $this->assertSame(0, $next->fresh()->records_new);
        $this->assertSame(1, SourceRecord::where('data_source_id', $source->id)->count());
    }

    public function test_unsupported_source_and_league_scoped_external_import_are_rejected(): void
    {
        Queue::fake();
        $candidate = DataSource::where('slug', 'gsp-candidate')->firstOrFail();
        $this->postJson(route('admin.mindmap.import', $candidate))->assertStatus(422);

        $league = \App\Models\League::create(['name' => 'Liga test', 'slug' => 'liga-test', 'code' => 'TST']);
        $source = DataSource::where('slug', 'statsbomb-open-data')->firstOrFail();
        $this->postJson(route('admin.mindmap.import', $source), ['league' => $league->id])->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_connection_probe_does_not_import_sports_data(): void
    {
        Http::fake(['raw.githubusercontent.com/*' => Http::response([['competition_id' => 9]], 200)]);
        $source = DataSource::where('slug', 'statsbomb-open-data')->firstOrFail();
        $this->postJson(route('admin.mindmap.source.test', $source))->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseCount('source_records', 0);
        $this->assertDatabaseCount('import_runs', 0);
    }

    public function test_manual_json_source_uses_field_mapping_and_private_raw_storage(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->post(route('admin.mindmap.store'), [
            'name' => 'Fișier autorizat', 'level' => 4, 'group' => 'news', 'type' => 'manual', 'import_method' => 'manual_json',
        ])->assertRedirect();
        $source = DataSource::where('name', 'Fișier autorizat')->firstOrFail();
        $source->update(['active' => true]);
        $this->postJson(route('admin.mindmap.import', $source))->assertStatus(422);
        $this->assertDatabaseCount('import_runs', 0);
        $this->post(route('admin.mindmap.source.mapping', $source), ['external_field' => 'home.name', 'internal_field' => 'home_team_name'])->assertRedirect();

        $file = UploadedFile::fake()->createWithContent('feed.json', '[{"id":1,"home":{"name":"Gazde"}}]');
        $this->post(route('admin.mindmap.upload', $source), ['file' => $file])->assertRedirect();
        $run = ImportRun::where('data_source_id', $source->id)->firstOrFail();
        Queue::assertPushed(RunMindmapImport::class);
        app()->call([new RunMindmapImport($run->id), 'handle']);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('Gazde', SourceRecord::where('data_source_id', $source->id)->firstOrFail()->payload['_mapped']['home_team_name']);
        Storage::disk('local')->assertExists($run->documents()->firstOrFail()->path);
        $this->get(route('admin.mindmap'))->assertOk()->assertSee('Fișier autorizat')->assertDontSee('id="recent-imports"', false);
    }

    public function test_level_import_only_queues_supported_sources(): void
    {
        Queue::fake();
        $this->postJson(route('admin.mindmap.level.import', ['level' => 4]))
            ->assertStatus(202)->assertJsonPath('run_ids', []);
        Queue::assertNothingPushed();

        $result = $this->postJson(route('admin.mindmap.level.import', ['level' => 1]))->assertStatus(202);
        $this->assertNotEmpty($result->json('run_ids'));
        Queue::assertPushed(RunMindmapImport::class);
    }

    public function test_per_source_scheduler_queues_only_due_imports(): void
    {
        Queue::fake();
        $source = DataSource::where('slug', 'statsbomb-open-data')->firstOrFail();
        $source->update(['import_frequency' => 'hourly']);
        app(\App\Services\Mindmap\ImportScheduler::class)->dispatchDue();
        Queue::assertPushed(RunMindmapImport::class, fn ($job) => ImportRun::find($job->runId)?->data_source_id === $source->id);

        $count = ImportRun::where('data_source_id', $source->id)->count();
        app(\App\Services\Mindmap\ImportScheduler::class)->dispatchDue();
        $this->assertSame($count, ImportRun::where('data_source_id', $source->id)->count());
    }

    public function test_competition_coverage_mapping_does_not_enable_unscoped_adapter(): void
    {
        Queue::fake();
        $league = \App\Models\League::create(['name' => 'Liga test', 'slug' => 'liga-test', 'code' => 'TST']);
        $source = DataSource::where('slug', 'statsbomb-open-data')->firstOrFail();
        $this->post(route('admin.mindmap.source.competition', $source), [
            'league_id' => $league->id, 'coverage_status' => 'supported', 'external_competition_id' => '9',
        ])->assertRedirect();
        $this->assertDatabaseHas('data_source_competitions', ['data_source_id' => $source->id, 'league_id' => $league->id, 'coverage_status' => 'supported']);
        $this->postJson(route('admin.mindmap.import', $source), ['league' => $league->id])->assertStatus(422);
        Queue::assertNothingPushed();
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\AdvanceMasterImport;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use App\Jobs\VerifyDataSource;
use App\Exceptions\MasterImportCancelled;
use App\Models\DataSource;
use App\Models\ImportDocument;
use App\Models\ImportRun;
use App\Models\MasterImport;
use App\Models\User;
use App\Services\Mindmap\MasterImportCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MasterImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_coordinator_jobs_are_deduplicated_and_serialized_per_run(): void
    {
        $job = new AdvanceMasterImport(123);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame('master-import-123', $job->uniqueId());
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
        $this->assertSame(10, $job->tries);
    }

    public function test_master_import_is_persisted_and_orders_available_sources_by_level(): void
    {
        Queue::fake();
        $openLiga = DataSource::where('slug', 'openligadb')->firstOrFail();
        $openLiga->levels()->firstOrCreate(['level' => 2]);

        $master = app(MasterImportCoordinator::class)->start();
        $items = collect($master->items);
        $scheduled = $items->where('status', 'pending');

        $this->assertSame('queued', $master->status);
        $this->assertNotNull($master->started_at);
        $this->assertSame(1, $items->where('slug', 'openligadb')->count());
        $this->assertSame(1, $items->firstWhere('slug', 'openligadb')['level']);
        $this->assertSame($scheduled->pluck('level')->sort()->values()->all(), $scheduled->pluck('level')->values()->all());
        $this->assertTrue($items->contains(fn (array $item) => $item['status'] === 'skipped'));
        Queue::assertPushed(AdvanceMasterImport::class, fn (AdvanceMasterImport $job) => $job->masterImportId === $master->id);
    }

    public function test_master_progress_can_be_reloaded_and_is_admin_only(): void
    {
        $master = MasterImport::create(['status' => 'running', 'items' => [[
            'source_id' => 1, 'slug' => 'openligadb', 'name' => 'OpenLigaDB', 'level' => 1,
            'status' => 'running', 'message' => 'Se importă', 'import_run_id' => null,
        ]], 'current_index' => 0, 'started_at' => now()]);

        $this->getJson(route('data-sources.master-import.progress', $master))->assertRedirect(route('user.login'));

        $admin = User::factory()->create(['is_admin' => true]);
        $this->withSession(['admin_user_id' => $admin->id])
            ->getJson(route('data-sources.master-import.progress', $master))
            ->assertOk()->assertJsonPath('status', 'running')->assertJsonPath('items.0.name', 'OpenLigaDB')
            ->assertJsonPath('current_index', 0)->assertJsonPath('is_stalled', false)
            ->assertJsonPath('progress_total', 1)->assertJsonPath('progress_done', 0)
            ->assertJsonPath('items.0.steps.0.label', 'Conectare la furnizor')
            ->assertJsonPath('items.0.steps.0.status', 'pending');
    }

    public function test_master_progress_exposes_persisted_stage_counters(): void
    {
        $source = DataSource::where('slug', 'openligadb')->firstOrFail();
        $run = $source->importRuns()->create(['status' => 'running', 'execution_source' => 'Laravel-local',
            'import_method' => 'existing', 'metadata' => ['master_progress' => [
                'current_step' => 'persistence', 'message' => 'Lot scris.', 'steps' => [
                    ['key' => 'connection', 'label' => 'Conectare la furnizor', 'status' => 'completed', 'message' => 'HTTP 200', 'counts' => ['http_status' => 200]],
                    ['key' => 'collection', 'label' => 'Colectare și validare date', 'status' => 'completed', 'message' => 'Date colectate', 'counts' => ['detected' => 24]],
                    ['key' => 'persistence', 'label' => 'Scriere în baza de date', 'status' => 'running', 'message' => 'Lot 2', 'counts' => ['processed' => 200, 'total' => 240]],
                    ['key' => 'calculation', 'label' => 'Calcul estimări BetLens', 'status' => 'pending', 'message' => 'În așteptare', 'counts' => []],
                    ['key' => 'finalization', 'label' => 'Finalizare și audit', 'status' => 'pending', 'message' => 'În așteptare', 'counts' => []],
                ],
            ]]]);
        $master = MasterImport::create(['status' => 'running', 'items' => [[
            'source_id' => $source->id, 'slug' => $source->slug, 'name' => $source->name, 'level' => 1,
            'status' => 'running', 'message' => 'Import în desfășurare.', 'import_run_id' => $run->id,
        ]], 'current_index' => 0, 'current_import_run_id' => $run->id, 'started_at' => now()]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withSession(['admin_user_id' => $admin->id])
            ->getJson(route('data-sources.master-import.progress', $master))
            ->assertOk()->assertJsonPath('items.0.current_step_key', 'persistence')
            ->assertJsonPath('items.0.steps.0.status', 'completed')
            ->assertJsonPath('items.0.steps.1.counts.detected', 24)
            ->assertJsonPath('items.0.steps.2.counts.processed', 200)
            ->assertJsonPath('items.0.steps.3.status', 'pending');
    }

    public function test_cancelled_master_resets_api_football_steps_to_local_pending_plan(): void
    {
        $source = DataSource::where('slug', 'api-football')->firstOrFail();
        $run = $source->importRuns()->create(['status' => 'cancelled', 'execution_source' => 'Laravel-local',
            'import_method' => 'digitalocean_function', 'finished_at' => now(), 'metadata' => ['master_progress' => [
                'current_step' => 'connection', 'steps' => [[
                    'key' => 'connection', 'label' => 'Conectare la Function-DO', 'status' => 'running',
                    'message' => 'Apel Function-DO', 'counts' => [],
                ]],
            ]]]);
        $master = MasterImport::create(['status' => 'cancelled', 'items' => [[
            'source_id' => $source->id, 'slug' => $source->slug, 'name' => $source->name, 'level' => 1,
            'status' => 'cancelled', 'message' => 'Oprit', 'import_run_id' => $run->id,
        ]], 'current_index' => 0, 'current_import_run_id' => $run->id, 'started_at' => now()->subMinute(), 'finished_at' => now()]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withSession(['admin_user_id' => $admin->id])
            ->getJson(route('data-sources.master-import.progress', $master))
            ->assertOk()->assertJsonPath('items.0.current_step_key', null)
            ->assertJsonPath('items.0.steps.0.label', 'Conectare locală la API-Football')
            ->assertJsonPath('items.0.steps.0.status', 'pending')
            ->assertJsonMissing(['label' => 'Conectare la Function-DO']);
    }

    public function test_admin_can_requeue_coordinator_for_a_stalled_master_import(): void
    {
        Queue::fake();
        $master = MasterImport::create(['status' => 'queued', 'items' => [[
            'source_id' => 1, 'slug' => 'openligadb', 'name' => 'OpenLigaDB', 'level' => 1,
            'status' => 'pending', 'message' => null, 'import_run_id' => null,
        ]], 'current_index' => 0, 'started_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5)]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withSession(['admin_user_id' => $admin->id])
            ->getJson(route('data-sources.master-import.progress', $master))
            ->assertOk()->assertJsonPath('is_stalled', true);

        $this->withSession(['admin_user_id' => $admin->id])
            ->postJson(route('data-sources.master-import.recover', $master))
            ->assertAccepted()->assertJsonPath('status', 'queued');

        Queue::assertPushed(AdvanceMasterImport::class, fn (AdvanceMasterImport $job) => $job->masterImportId === $master->id);
    }

    public function test_admin_can_cancel_master_import_and_a_new_run_starts_from_zero(): void
    {
        Queue::fake();
        $source = DataSource::where('slug', 'openligadb')->firstOrFail();
        $run = $source->importRuns()->create(['status' => 'queued', 'execution_source' => 'Laravel-local', 'import_method' => 'existing']);
        $master = MasterImport::create(['status' => 'running', 'items' => [[
            'source_id' => $source->id, 'slug' => $source->slug, 'name' => $source->name, 'level' => 1,
            'status' => 'queued', 'message' => 'Se așteaptă workerul.', 'import_run_id' => $run->id,
            'records_fetched' => 42,
        ]], 'current_index' => 0, 'current_import_run_id' => $run->id, 'started_at' => now()->subMinute()]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withSession(['admin_user_id' => $admin->id])
            ->postJson(route('data-sources.master-import.cancel', $master))
            ->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame('cancelled', $master->fresh()->status);
        $this->assertSame('cancelled', $master->fresh()->items[0]['status']);
        $this->assertSame(42, $master->fresh()->items[0]['records_fetched']);
        $this->assertSame('cancelled', $run->fresh()->status);

        $next = app(MasterImportCoordinator::class)->start();
        $this->assertNotSame($master->id, $next->id);
        $this->assertSame(0, $next->current_index);
        $this->assertSame('queued', $next->status);
    }

    public function test_admin_can_restart_master_import_immediately_after_cancelling(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->withSession(['admin_user_id' => $admin->id]);

        $first = $this->postJson(route('data-sources.master-import.start'))
            ->assertAccepted()->json('id');
        $this->postJson(route('data-sources.master-import.cancel', $first))
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $secondResponse = $this->postJson(route('data-sources.master-import.start'))
            ->assertAccepted()->assertJsonPath('status', 'queued');

        $this->assertNotSame($first, $secondResponse->json('id'));
        $this->assertSame('cancelled', MasterImport::findOrFail($first)->status);
        $this->assertSame('queued', MasterImport::findOrFail($secondResponse->json('id'))->status);
    }

    public function test_generic_source_reuses_saved_download_and_persists_batch_checkpoint(): void
    {
        $source = DataSource::where('slug', 'football-data-uk')->firstOrFail();
        $run = $source->importRuns()->create(['status' => 'running', 'execution_source' => 'Laravel-local', 'import_method' => 'existing']);
        $body = "Div,Date,HomeTeam,AwayTeam,FTHG,FTAG\nE0,15/08/2026,Arsenal,Chelsea,2,1\n";
        Storage::fake('local');
        $path = 'imports/'.$run->id.'/response.csv';
        Storage::disk('local')->put($path, $body);
        ImportDocument::create(['import_run_id' => $run->id, 'disk' => 'local', 'path' => $path,
            'content_type' => 'text/csv', 'checksum' => hash('sha256', $body), 'bytes' => strlen($body)]);
        Http::preventStrayRequests();

        (new VerifyDataSource($source->id, null, $run->id))->handle();

        if ($source->fresh()->records_checked === 0) $this->fail((string) $source->fresh()->last_message);
        $this->assertDatabaseCount('source_records', 1);
        $checkpoint = $run->fresh()->metadata;
        $this->assertSame(1, $checkpoint['source_records_cursor']);
        $this->assertSame(1, $checkpoint['source_records_total']);
        $this->assertSame(1, $checkpoint['source_records_new']);
    }

    public function test_running_source_import_honors_master_cancel_request_and_restores_source_status(): void
    {
        $source = DataSource::where('slug', 'football-data-uk')->firstOrFail();
        $source->update(['status' => 'healthy']);
        $run = $source->importRuns()->create([
            'status' => 'running', 'execution_source' => 'Laravel-local', 'import_method' => 'existing',
            'metadata' => ['source_status_before_import' => 'healthy', 'master_import_cancel_requested_at' => now()->toIso8601String()],
        ]);
        Http::preventStrayRequests();

        try {
            (new VerifyDataSource($source->id, null, $run->id))->handle();
            $this->fail('Importul trebuia să observe cererea de anulare înainte de a descărca date.');
        } catch (MasterImportCancelled) {
            // The worker catches this signal and exits without marking the run as failed.
        }

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
        $this->assertSame('healthy', $source->fresh()->status);
    }
}

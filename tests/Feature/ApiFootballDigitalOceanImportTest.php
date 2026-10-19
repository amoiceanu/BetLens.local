<?php

namespace Tests\Feature;

use App\Jobs\ImportApiFootballFromDigitalOcean;
use App\Models\DataSource;
use App\Models\ImportRun;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\Imports\DigitalOceanFunctionService;
use App\Services\Mindmap\ImportManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ApiFootballDigitalOceanImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.digitalocean_functions.api_football_l1.url' => 'https://functions.example.test/api-football',
            'services.digitalocean_functions.api_football_l1.token' => 'secret-test-token']);
        Http::preventStrayRequests();
    }

    private function payload(): array
    {
        return ['country' => ['name' => 'Romania', 'code' => 'RO'],
            'league' => ['id' => 283, 'name' => 'Liga I', 'type' => 'League', 'logo' => 'https://example.test/liga.png'],
            'seasons' => [['year' => 2026, 'start' => '2026-07-17', 'end' => '2027-03-13',
                'current' => true, 'coverage' => ['fixtures' => ['events' => true]]]]];
    }

    public function test_function_response_is_authenticated_and_unwrapped(): void
    {
        Http::fake(['functions.example.test/*' => Http::response(['statusCode' => 200,
            'body' => json_encode(['response' => [$this->payload()]])], 200)]);
        $items = app(DigitalOceanFunctionService::class)->fetch('api_football_l1');
        $this->assertCount(1, $items);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Require-Whisk-Auth', 'secret-test-token'));
    }

    public function test_live_function_records_envelope_is_supported(): void
    {
        Http::fake(['functions.example.test/*' => Http::response(['success' => true,
            'function' => 'BetLens-Import-L1-APIFootball-JSON', 'recordsFound' => 1,
            'records' => [$this->payload()]], 200)]);
        $this->assertCount(1, app(DigitalOceanFunctionService::class)->fetch('api_football_l1'));
    }

    public function test_missing_token_http_auth_errors_timeout_and_invalid_json_are_rejected(): void
    {
        config(['services.digitalocean_functions.api_football_l1.token' => null]);
        $this->expectException(RuntimeException::class);
        app(DigitalOceanFunctionService::class)->fetch('api_football_l1');
    }

    public function test_http_401_does_not_retry(): void
    {
        Http::fake(['functions.example.test/*' => Http::response([], 401)]);
        try { app(DigitalOceanFunctionService::class)->fetch('api_football_l1'); $this->fail('HTTP error expected'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('401', $e->getMessage()); }
        Http::assertSentCount(1);
    }

    public function test_http_403_does_not_retry(): void
    {
        Http::fake(['functions.example.test/*' => Http::response([], 403)]);
        try { app(DigitalOceanFunctionService::class)->fetch('api_football_l1'); $this->fail('HTTP error expected'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('403', $e->getMessage()); }
        Http::assertSentCount(1);
    }

    public function test_timeout_is_rejected(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        try { app(DigitalOceanFunctionService::class)->fetch('api_football_l1'); $this->fail('Timeout expected'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('nu a răspuns', $e->getMessage()); }
    }

    public function test_invalid_json_is_rejected(): void
    {
        Http::fake(['functions.example.test/*' => Http::response('{invalid', 200)]);
        try { app(DigitalOceanFunctionService::class)->fetch('api_football_l1'); $this->fail('Invalid JSON expected'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('JSON invalid', $e->getMessage()); }
    }

    public function test_import_is_queued_audited_idempotent_and_keeps_other_sources_untouched(): void
    {
        Queue::fake();
        $source = DataSource::where('slug', 'api-football')->firstOrFail();
        $other = DataSource::where('slug', 'openligadb')->firstOrFail();
        SourceRecord::create(['data_source_id' => $other->id, 'external_key' => 'competition:283',
            'record_type' => 'competition', 'payload' => ['name' => 'Other provider'], 'checksum' => hash('sha256', 'other'),
            'first_seen_at' => now(), 'last_seen_at' => now()]);
        $manager = app(ImportManager::class);
        $run = $manager->enqueue($source);
        Queue::assertPushed(ImportApiFootballFromDigitalOcean::class,
            fn ($job) => $job->runId === $run->id && $job->queue === 'betlens-api-football-l1');
        $this->assertDatabaseHas('import_audits', ['import_run_id' => $run->id, 'overall_status' => 'queued']);
        Http::fake(['functions.example.test/*' => Http::response(['statusCode' => 200, 'body' => ['response' => [$this->payload()]]], 200)]);
        app()->call([new ImportApiFootballFromDigitalOcean($run->id), 'handle']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->records_new);
        $this->assertSame(1, $run->fresh()->metadata['competitions_saved']);
        $this->assertSame(1, $run->fresh()->metadata['seasons_saved']);
        $competition = SourceRecord::where('data_source_id', $source->id)->where('external_key', 'competition:283')->firstOrFail();
        $season = SourceRecord::where('data_source_id', $source->id)->where('external_key', 'season:283:2026')->firstOrFail();
        $this->assertSame($competition->id, $season->parent_source_record_id);
        $this->assertSame(['fixtures' => ['events' => true]], $season->payload['coverage']);
        $this->assertSame('Other provider', SourceRecord::where('data_source_id', $other->id)->firstOrFail()->payload['name']);

        $second = $manager->enqueue($source);
        app()->call([new ImportApiFootballFromDigitalOcean($second->id), 'handle']);
        $this->assertSame(0, $second->fresh()->records_new);
        $this->assertSame(2, $second->fresh()->records_unchanged);
        $this->assertSame(2, SourceRecord::where('data_source_id', $source->id)->count());
    }

    public function test_admin_import_route_is_protected_and_connection_test_does_not_write(): void
    {
        $source = DataSource::where('slug', 'api-football')->firstOrFail();
        $this->postJson(route('admin.mindmap.import', $source))->assertRedirect(route('user.login'));
        $admin = User::factory()->create(['is_admin' => true]);
        $this->withSession(['admin_user_id' => $admin->id]);
        Http::fake(['functions.example.test/*' => Http::response([$this->payload()], 200)]);
        $this->postJson(route('admin.mindmap.source.test', $source))->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseCount('source_records', 0);
    }

    public function test_selected_mindmap_league_does_not_narrow_the_romanian_function_import(): void
    {
        Queue::fake();
        $source = DataSource::where('slug', 'api-football')->firstOrFail();
        $league = \App\Models\League::create(['name' => 'Liga test', 'slug' => 'liga-test', 'code' => 'TST']);
        $run = app(ImportManager::class)->enqueue($source, $league->id);
        $this->assertNull($run->league_id);
        Queue::assertPushed(ImportApiFootballFromDigitalOcean::class);
    }
}

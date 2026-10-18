<?php

namespace Tests\Feature;

use App\Jobs\VerifyDataSource;
use App\Models\ApplicationSetting;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Season;
use App\Models\SourceRecord;
use App\Models\Team;
use App\Models\User;
use App\Services\Mindmap\MindmapData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminMindmapTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_mindmap_actions_require_admin(): void
    {
        $source = DataSource::first();
        $this->get(route('admin.mindmap'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.mindmap.store'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.mindmap.import', $source))->assertRedirect(route('admin.login'));
    }

    public function test_empty_imports_show_demo_catalog_and_unavailable_metrics_without_writes(): void
    {
        $before = DataSource::count();
        $this->withSession(['admin_user_id' => User::factory()->create(['is_admin' => true])->id])->get(route('admin.mindmap'))
            ->assertOk()->assertSee('Mindmap date BetLens')->assertSee('Demo')
            ->assertSee('Sportmonks')->assertSee('NewsAPI')->assertSee('unavailable')
            ->assertSee('De configurat')->assertDontSee('the-odds-api');
        $this->assertSame($before, DataSource::count());
        $this->assertSame(0, SourceRecord::count());
    }

    public function test_source_creation_preserves_existing_data_and_requires_html_permission(): void
    {
        $this->withSession(['admin_user_id' => User::factory()->create(['is_admin' => true])->id])->post(route('admin.mindmap.store'), [
            'name' => 'Sursă locală', 'group' => 'romania', 'type' => 'html',
        ])->assertSessionHasErrors('html_permitted');
        $this->post(route('admin.mindmap.store'), [
            'name' => 'Sursă locală', 'group' => 'romania', 'type' => 'html', 'html_permitted' => '1', 'competition' => 'Liga 2 · 2026/27',
        ])->assertRedirect(route('admin.mindmap'));
        $source = DataSource::where('name', 'Sursă locală')->firstOrFail();
        $this->assertFalse($source->active);
        $this->assertNull($source->verification_url);
        $this->assertSame('Liga 2 · 2026/27', ApplicationSetting::where('key', 'mindmap.source.'.$source->id)->first()->value['competition']);
        $this->assertDatabaseHas('data_sources', ['slug' => 'football-data']);
    }

    public function test_import_requires_real_approved_adapter_and_does_not_request_arbitrary_urls(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $source = DataSource::create(['name' => 'Local', 'slug' => 'local', 'category' => 'Context', 'description' => 'Local', 'verification_url' => 'http://127.0.0.1/private', 'active' => true]);
        $this->withSession(['admin_user_id' => User::factory()->create(['is_admin' => true])->id])->from(route('admin.mindmap'))->post(route('admin.mindmap.import', $source))->assertSessionHas('warning');
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
        $source = DataSource::where('slug', 'statsbomb-open-data')->firstOrFail();
        $this->post(route('admin.mindmap.import', $source))->assertSessionHas('success');
        Bus::assertDispatchedSync(VerifyDataSource::class, fn ($job) => $job->sourceId === $source->id);
        $source->update(['verification_url' => 'https://untrusted.example/data']);
        $this->post(route('admin.mindmap.import', $source))->assertSessionHas('warning');
    }

    public function test_statistics_use_selected_fixtures_and_detect_integrity_conflicts_without_exposing_raw_errors(): void
    {
        $league = League::create(['name' => 'Liga test', 'slug' => 'test', 'code' => 'T']);
        $season = Season::create(['league_id' => $league->id, 'name' => '2026/27', 'starts_at' => '2026-07-01', 'ends_at' => '2027-06-30']);
        $a = Team::create(['league_id' => $league->id, 'name' => 'A', 'short_name' => 'A']);
        $b = Team::create(['league_id' => $league->id, 'name' => 'B', 'short_name' => 'B']);
        FootballMatch::create(['league_id' => $league->id, 'season_id' => $season->id, 'home_team_id' => $a->id, 'away_team_id' => $b->id, 'kickoff_at' => now(), 'venue' => null]);
        $source = DataSource::where('slug', 'api-football')->firstOrFail();
        $source->update(['last_message' => 'SECRET_TOKEN_DO_NOT_RENDER']);
        SourceRecord::create(['data_source_id' => $source->id, 'external_key' => 'test', 'record_type' => 'competition', 'payload' => ['id' => 1], 'checksum' => str_repeat('0', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $data = app(MindmapData::class)->snapshot($league->id, $season->id);
        $this->assertFalse($data['demo']);
        $this->assertSame('75%', $data['metrics']['Acoperirea câmpurilor'][0]);
        $this->assertSame('0%', $data['metrics']['Calitatea datelor'][0]);
        $this->assertSame(1, $data['metrics']['Conflicte de date'][0]);
        $this->assertSame('conflict', collect($data['rows'])->firstWhere('id', $source->id)['status']);
        $this->withSession(['admin_user_id' => User::factory()->create(['is_admin' => true])->id])->get(route('admin.mindmap', ['league' => $league->id, 'season' => $season->id]))->assertOk()->assertDontSee('SECRET_TOKEN_DO_NOT_RENDER');
        $this->get(route('admin.mindmap', ['league' => 999999]))->assertSessionHasErrors('league');
    }
}

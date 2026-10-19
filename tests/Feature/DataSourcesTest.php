<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
class DataSourcesTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['admin_user_id'=>\App\Models\User::factory()->create(['is_admin'=>true])->id]);
    }
    public function test_json_verification_exposes_live_progress_and_final_counters(): void
    {
        Queue::fake();
        $source=\App\Models\DataSource::where('slug','football-data-uk')->firstOrFail();
        $start=$this->postJson(route('data-sources.verify',$source))->assertStatus(202);
        $runId=$start->json('run_id');
        Queue::assertPushed(\App\Jobs\VerifyDataSource::class);
        $this->assertDatabaseHas('import_audits',['verification_run_id'=>$runId,'provider'=>'football-data-uk','overall_status'=>'queued']);
        $this->getJson(route('data-sources.progress',$source))
            ->assertOk()->assertJsonPath('run_id',$runId)->assertJsonPath('stage','queued')
            ->assertJsonStructure(['steps'=>['queued'=>['started_at','message']]]);

        Http::fake(['www.football-data.co.uk/*'=>Http::response("Div,Date,HomeTeam,AwayTeam,FTHG,FTAG\nE0,15/08/2026,Arsenal,Chelsea,2,1\n",200)]);
        (new \App\Jobs\VerifyDataSource($source->id,$runId))->handle();
        $this->getJson(route('data-sources.progress',$source))
            ->assertOk()->assertJsonPath('stage','finished')->assertJsonPath('new',1)
            ->assertJsonPath('detected',1)->assertJsonPath('database',1)
            ->assertJsonPath('http_status',200)
            ->assertJsonStructure(['steps'=>['connecting'=>['started_at','finished_at'], 'fetching'=>['started_at','finished_at'], 'importing'=>['started_at','finished_at'], 'finished'=>['started_at']]]);
        $this->assertDatabaseCount('import_audits',1);
        $this->assertDatabaseHas('import_audits',['verification_run_id'=>$runId,'overall_status'=>'success','records_new'=>1]);
    }
    public function test_api_football_verification_is_audited_while_queued_and_after_failure(): void
    {
        Queue::fake();
        config(['services.api_football.key'=>'test-key']);
        $source=\App\Models\DataSource::where('slug','api-football')->firstOrFail();
        $runId=$this->postJson(route('data-sources.verify',$source))->assertStatus(202)->json('run_id');
        $this->get(route('admin.import-audit'))->assertOk()->assertSee('api-football')->assertSee('queued');
        Http::fake(['v3.football.api-sports.io/*'=>Http::response([],503)]);
        (new \App\Jobs\VerifyDataSource($source->id,$runId))->handle();
        $this->assertDatabaseCount('import_audits',1);
        $this->assertDatabaseHas('import_audits',['verification_run_id'=>$runId,'provider'=>'api-football','overall_status'=>'warning']);
    }
    public function test_data_sources_registry_is_visible(): void
    {
        $this->get('/surse-date')
            ->assertOk()
            ->assertSee('Sursa datelor')
            ->assertSee('football-data.org')
            ->assertSee('Sportmonks Football API')
            ->assertSee('Open-Meteo API')
            ->assertSee('<th>Format</th>',false)
            ->assertSee('data-label="Format"',false)
            ->assertSeeInOrder(['L1 – Open APIs', 'L2 – Statistici avansate', 'L3 – Echipe și jucători', 'L4 – Știri și info externe', 'L5 – Contextul meciului', 'L6 – Inteligență predictivă'])
            ->assertSee('role="tablist"', false)
            ->assertSee('id="source-tab-1" role="tab"', false)
            ->assertSee('id="source-tab-6" role="tab"', false)
            ->assertSee('id="source-panel-1" role="tabpanel"', false)
            ->assertSee('id="source-panel-6" role="tabpanel"', false)
            ->assertSee('GSP.ro · candidat')
            ->assertSee('BetLens · model intern')
            ->assertDontSee('id="import-audit"', false)
            ->assertSee('Verifică acum');
        $this->assertSame('JSON',\App\Models\DataSource::where('slug','api-football')->firstOrFail()->data_format);
        $this->assertSame('CSV',\App\Models\DataSource::where('slug','football-data-uk')->firstOrFail()->data_format);
        $this->assertSame('Calcul intern',\App\Models\DataSource::where('slug','betlens-model')->firstOrFail()->data_format);
    }
    public function test_configured_sources_are_alphabetical_before_unconfigured_sources(): void
    {
        config([
            'services.api_football.key'=>null,
            'services.football_data.key'=>null,
            'services.odds_api.key'=>null,
            'services.odds_api_io.key'=>null,
            'services.oddspapi.key'=>null,
            'services.sportmonks.token'=>null,
        ]);

        $this->get('/surse-date')->assertOk()->assertViewHas('levelSections', function ($sections) {
            $l1 = $sections->get(1)['sources']->pluck('slug')->all();
            $l2 = $sections->get(2)['sources']->pluck('slug')->all();

            return $l1 === ['football-data-uk', 'openligadb', 'statsbomb-open-data', 'api-football', 'football-data']
                && in_array('football-data-uk', $l2, true)
                && in_array('betlens-model', $sections->get(6)['sources']->pluck('slug')->all(), true);
        });
    }
    public function test_candidate_and_internal_sources_are_visible_but_cannot_be_verified_as_external_sources(): void
    {
        $candidate=\App\Models\DataSource::where('slug','gsp-candidate')->firstOrFail();
        $model=\App\Models\DataSource::where('slug','betlens-model')->firstOrFail();

        $this->get('/surse-date')->assertOk()
            ->assertViewHas('levelSections', fn($sections) => $sections->get(4)['sources']->contains('id',$candidate->id)
                && $sections->get(6)['sources']->contains('id',$model->id))
            ->assertSee('Adaptor indisponibil')
            ->assertSee('Model intern')
            ->assertDontSee(route('data-sources.verify',$candidate),false)
            ->assertDontSee(route('data-sources.verify',$model),false);
        $this->post(route('data-sources.verify',$candidate))->assertSessionHas('warning');
        $this->post(route('data-sources.verify',$model))->assertSessionHas('warning');
        $this->assertDatabaseCount('import_audits',0);
    }
    public function test_verification_action_is_hidden_for_an_unconfigured_source(): void
    {
        config(['services.api_football.key'=>null]);
        $source=\App\Models\DataSource::where('slug','api-football')->firstOrFail();

        $this->get('/surse-date')
            ->assertOk()
            ->assertDontSee(route('data-sources.verify',$source),false)
            ->assertSee('Necesită configurare');
        $this->post(route('data-sources.verify',$source))->assertSessionHas('warning');
        $this->assertDatabaseCount('import_audits',0);
    }
    public function test_each_tab_puts_unconfigured_sources_last_and_hides_their_verification_actions(): void
    {
        config([
            'services.api_football.key'=>null,
            'services.sportmonks.token'=>null,
        ]);
        $api=\App\Models\DataSource::where('slug','api-football')->firstOrFail();
        $understat=\App\Models\DataSource::where('slug','understat')->firstOrFail();
        $understat->update(['verification_url'=>null]);

        $this->get('/surse-date')->assertOk()
            ->assertViewHas('levelSections', function ($sections) {
                foreach ($sections as $section) {
                    $configured=$section['sources']->map(fn($source)=>(int)$source->is_verification_configured)->all();
                    if ($configured !== collect($configured)->sortDesc()->values()->all()) return false;
                }
                return true;
            })
            ->assertDontSee(route('data-sources.verify',$api),false)
            ->assertDontSee(route('data-sources.verify',$understat),false)
            ->assertSee('Neconfigurată');
        $this->post(route('data-sources.verify',$understat))->assertSessionHas('warning');
        $this->assertDatabaseCount('import_audits',0);
    }
    public function test_verification_action_is_visible_when_the_source_is_configured(): void
    {
        config(['services.api_football.key'=>'test-key']);
        $source=\App\Models\DataSource::where('slug','api-football')->firstOrFail();

        $this->get('/surse-date')
            ->assertOk()
            ->assertSee(route('data-sources.verify',$source),false);
    }
    public function test_manual_verification_is_hidden_and_blocked_for_five_minutes_per_source(): void
    {
        Http::fake(['raw.githubusercontent.com/*'=>Http::response([['competition_id'=>9,'season_id'=>1]],200)]);
        $source=\App\Models\DataSource::where('slug','statsbomb-open-data')->firstOrFail();
        $other=\App\Models\DataSource::where('slug','openligadb')->firstOrFail();

        $this->post(route('data-sources.verify',$source))->assertRedirect();
        $this->get('/surse-date')->assertOk()
            ->assertDontSee(route('data-sources.verify',$source),false)
            ->assertSee(route('data-sources.verify',$other),false);
        $this->post(route('data-sources.verify',$source))->assertRedirect()->assertSessionHas('warning');
        $this->assertDatabaseCount('import_audits',1);

        $this->travel(4)->minutes();
        $this->get('/surse-date')->assertDontSee(route('data-sources.verify',$source),false);
        $this->travel(1)->minutes();
        $this->get('/surse-date')->assertSee(route('data-sources.verify',$source),false);
        $this->post(route('data-sources.verify',$source))->assertRedirect();
        $this->assertDatabaseCount('import_audits',2);
    }
    public function test_the_table_shows_the_number_of_imported_records_for_each_source(): void
    {
        $source=\App\Models\DataSource::where('slug','openligadb')->firstOrFail();
        $source->records()->create(['external_key'=>'competition:test','record_type'=>'competition','payload'=>['name'=>'Test'],'checksum'=>hash('sha256','test'),'first_seen_at'=>now(),'last_seen_at'=>now()]);

        $this->get('/surse-date')
            ->assertOk()
            ->assertSee('Date importate')
            ->assertSee('1')
            ->assertSee('înregistrări în MySQL');
    }
    public function test_an_outdated_last_check_is_highlighted(): void
    {
        $source=\App\Models\DataSource::where('slug','openligadb')->firstOrFail();
        $source->update(['last_checked_at'=>now()->subDay(),'last_duration_ms'=>100]);

        $this->get('/surse-date')
            ->assertOk()
            ->assertSee('source-last-check is-stale',false);
    }
    public function test_a_real_public_source_can_be_verified_immediately(): void
    {
        Http::fake(['api.openligadb.de/*'=>Http::response([['id'=>1,'leagueShortcut'=>'bl1']],200)]);
        $source=\App\Models\DataSource::where('slug','openligadb')->firstOrFail();
        $this->post("/surse-date/{$source->id}/verifica")->assertRedirect()->assertSessionHas('verification_result',fn($result)=>$result['detected']===1 && $result['new']===1 && $result['database']===1 && $result['status']==='healthy');
        $this->assertDatabaseHas('data_sources',['id'=>$source->id,'status'=>'healthy']);
        $this->assertDatabaseHas('source_records',['data_source_id'=>$source->id,'record_type'=>'competition']);
        $this->assertDatabaseHas('data_sync_logs',['provider'=>'openligadb','type'=>'source_sync','status'=>'healthy']);
        $this->assertDatabaseHas('import_audits',['provider'=>'openligadb','import_type'=>'source_sync','status'=>'healthy','records_new'=>1,'records_updated'=>0,'records_deleted'=>0]);
        $this->travel(5)->minutes();
        $this->post("/surse-date/{$source->id}/verifica")->assertSessionHas('verification_result',fn($result)=>$result['new']===0 && $result['unchanged']===1 && $result['database']===1);
        $this->assertDatabaseCount('source_records',1);
        $this->assertDatabaseHas('import_audits',['provider'=>'openligadb','import_type'=>'source_sync','records_new'=>0,'records_updated'=>0,'records_deleted'=>0]);
        $this->assertDatabaseCount('import_audits',2);
    }
    public function test_import_audit_records_updated_payloads(): void
    {
        $source=\App\Models\DataSource::where('slug','sportmonks')->firstOrFail();
        $store=app(\App\Services\SourcePayloadStoreService::class);
        $store->store($source,'test_fixture',[['id'=>123,'score'=>0]]);
        $metrics=$store->store($source,'test_fixture',[['id'=>123,'score'=>1]]);
        $store->log('sportmonks','test_fixture','healthy',1,'Test import',microtime(true),$metrics);

        $this->assertDatabaseHas('import_audits',['provider'=>'sportmonks','import_type'=>'test_fixture','records_new'=>0,'records_updated'=>1,'records_deleted'=>0,'records_imported'=>1,'overall_status'=>'success']);
    }
    public function test_import_audit_shows_execution_source_and_failed_status(): void
    {
        config()->set('betlens.import_execution_source', 'Laravel-DO');
        app(\App\Services\ImportAuditService::class)->record('sportmonks', 'fixtures', 'unavailable', ['new' => 2, 'updated' => 1], 'Import întrerupt', microtime(true), \App\Jobs\SyncSportmonksFixturesJob::class);

        $this->assertDatabaseHas('import_audits', [
            'provider' => 'sportmonks',
            'execution_source' => 'Laravel-DO',
            'job_name' => \App\Jobs\SyncSportmonksFixturesJob::class,
            'overall_status' => 'failed',
            'records_imported' => 3,
        ]);
        $this->get('/surse-date')->assertOk()->assertDontSee('id="import-audit"', false);
        $this->get(route('admin.import-audit'))->assertOk()->assertSee('ImportAudit')->assertSee('Laravel-DO')->assertSee('SyncSportmonksFixturesJob');
    }
    public function test_statsbomb_open_data_imports_competition_seasons(): void
    {
        Http::fake(['raw.githubusercontent.com/*'=>Http::response([
            ['competition_id'=>9,'season_id'=>281,'competition_name'=>'1. Bundesliga','season_name'=>'2023/2024'],
            ['competition_id'=>16,'season_id'=>4,'competition_name'=>'Champions League','season_name'=>'2018/2019'],
        ],200)]);
        $source=\App\Models\DataSource::where('slug','statsbomb-open-data')->firstOrFail();

        $this->post("/surse-date/{$source->id}/verifica")
            ->assertRedirect()
            ->assertSessionHas('verification_result',fn($result)=>$result['detected']===2&&$result['new']===2&&$result['database']===2&&$result['status']==='healthy');

        $this->assertDatabaseHas('source_records',['data_source_id'=>$source->id,'record_type'=>'competition_season','external_key'=>'competition:9:season:281']);
    }
    public function test_football_data_csv_imports_each_match(): void
    {
        $csv="Div,Date,HomeTeam,AwayTeam,FTHG,FTAG\nE0,15/08/2026,Arsenal,Chelsea,2,1\nE0,16/08/2026,Liverpool,Everton,1,0\n";
        Http::fake(['www.football-data.co.uk/*'=>Http::response($csv,200,['Content-Type'=>'text/csv'])]);
        $source=\App\Models\DataSource::where('slug','football-data-uk')->firstOrFail();

        $this->post("/surse-date/{$source->id}/verifica")
            ->assertRedirect()
            ->assertSessionHas('verification_result',fn($result)=>$result['detected']===2&&$result['new']===2&&$result['database']===2&&$result['status']==='healthy');

        $this->assertDatabaseHas('source_records',['data_source_id'=>$source->id,'record_type'=>'historical_match','external_key'=>'match:E0:15/08/2026:Arsenal:Chelsea']);
    }
}

<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class DataSourcesTest extends TestCase
{
    use RefreshDatabase;
    public function test_data_sources_registry_is_visible(): void
    {
        $this->get('/surse-date')
            ->assertOk()
            ->assertSee('Sursa datelor')
            ->assertSee('football-data.org')
            ->assertSee('Verifică acum')
            ->assertSeeInOrder(['API-Football','Football-Data.co.uk','football-data.org','OpenLigaDB','StatsBomb Open Data','The Odds API','Understat']);
    }
    public function test_a_real_public_source_can_be_verified_immediately(): void
    {
        Http::fake(['api.openligadb.de/*'=>Http::response([['leagueShortcut'=>'bl1']],200)]);
        $source=\App\Models\DataSource::where('slug','openligadb')->firstOrFail();
        $this->post("/surse-date/{$source->id}/verifica")->assertRedirect()->assertSessionHas('verification_result',fn($result)=>$result['detected']===1 && $result['new']===1 && $result['database']===1 && $result['status']==='healthy');
        $this->assertDatabaseHas('data_sources',['id'=>$source->id,'status'=>'healthy']);
        $this->assertDatabaseHas('source_records',['data_source_id'=>$source->id,'record_type'=>'competition']);
        $this->assertDatabaseHas('data_sync_logs',['provider'=>'openligadb','type'=>'source_sync','status'=>'healthy']);
        $this->post("/surse-date/{$source->id}/verifica")->assertSessionHas('verification_result',fn($result)=>$result['new']===0 && $result['unchanged']===1 && $result['database']===1);
        $this->assertDatabaseCount('source_records',1);
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

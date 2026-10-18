<?php
namespace Tests\Feature;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchWeatherSnapshot;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class ProviderExtensionsTest extends TestCase
{
    use RefreshDatabase;
    public function test_new_sources_are_listed_and_open_meteo_can_be_verified(): void
    {
        Http::fake(['api.open-meteo.com/*'=>Http::response(['timezone'=>'Europe/Bucharest','latitude'=>44.42,'longitude'=>26.10,'hourly'=>['time'=>[]]],200)]);
        $this->get('/surse-date')->assertOk()->assertSee('Sportmonks Football API')->assertSee('Open-Meteo API');
        $source=DataSource::where('slug','open-meteo')->firstOrFail();
        $this->post(route('data-sources.verify',$source))->assertRedirect()->assertSessionHas('verification_result',fn($result)=>$result['status']==='healthy');
    }
    public function test_sportmonks_verification_stores_superliga_coverage(): void
    {
        config(['services.sportmonks.token'=>'test-token']);
        Http::fake([
            'api.sportmonks.com/v3/football/leagues*'=>Http::response(['data'=>[['id'=>10,'name'=>'SuperLiga','country'=>['name'=>'Romania']]]],200),
            'api.sportmonks.com/v3/football/fixtures*'=>Http::response(['data'=>[['id'=>20,'starting_at'=>now()->addDay()->toIso8601String(),'participants'=>[],'statistics'=>[['type'=>['developer_name'=>'EXPECTED_GOALS']]],'odds'=>[['id'=>1]]]]],200),
            'api.sportmonks.com/v3/football/injuries*'=>Http::response(['data'=>[['id'=>30]]],200),
        ]);
        $source=DataSource::where('slug','sportmonks')->firstOrFail();
        $this->post(route('data-sources.verify',$source))->assertRedirect();
        $source->refresh();
        $this->assertTrue((bool)data_get($source->metadata,'superliga_coverage.found'));
        $this->assertSame('available',data_get($source->metadata,'superliga_coverage.capabilities.fixtures'));
        $this->assertDatabaseHas('data_sync_logs',['provider'=>'sportmonks','status'=>'healthy']);
    }
    public function test_match_page_shows_weather_or_explicitly_marks_it_unavailable(): void
    {
        $league=League::create(['name'=>'Liga Test','slug'=>'liga-weather','country'=>'RO','code'=>'WTH','active'=>true]);
        $home=Team::create(['league_id'=>$league->id,'name'=>'Gazde','short_name'=>'GAZ']);$away=Team::create(['league_id'=>$league->id,'name'=>'Oaspeți','short_name'=>'OAS']);
        $match=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>now()->addDay(),'status'=>'scheduled']);
        $this->get(route('matches.show',$match))->assertOk()->assertSee('Condiții meteo estimate')->assertSee('Indisponibil');
        MatchWeatherSnapshot::create(['match_id'=>$match->id,'provider'=>'open-meteo','forecast_for'=>$match->kickoff_at,'fetched_at'=>now(),'temperature_c'=>18.5,'precipitation_mm'=>0,'precipitation_probability'=>5,'wind_speed_kmh'=>8,'wind_direction'=>180,'weather_code'=>1]);
        $this->get(route('matches.show',$match))->assertOk()->assertSee('18,5°C')->assertSee('Open-Meteo');
    }
}

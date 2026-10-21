<?php
namespace Tests\Feature;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchWeatherSnapshot;
use App\Models\Team;
use App\Services\Providers\OpenMeteoProvider;
use App\Services\SourcePayloadStoreService;
use App\Jobs\SyncMatchWeatherJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class ProviderExtensionsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['admin_user_id'=>\App\Models\User::factory()->create(['is_admin'=>true])->id]);
    }
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

    public function test_open_meteo_provider_matches_hourly_forecast_and_handles_missing_coordinates(): void
    {
        $league=League::create(['name'=>'Liga Test','slug'=>'liga-open-meteo','country'=>'RO','code'=>'MET','active'=>true]);
        $home=Team::create(['league_id'=>$league->id,'name'=>'Gazde','short_name'=>'GAZ','latitude'=>44.43,'longitude'=>26.10,'timezone'=>'Europe/Bucharest']);
        $away=Team::create(['league_id'=>$league->id,'name'=>'Oaspeți','short_name'=>'OAS']);
        $match=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>now()->addDay()->setTime(18,20),'status'=>'scheduled']);
        Http::fake(['api.open-meteo.com/*'=>Http::response(['timezone'=>'Europe/Bucharest','hourly'=>[
            'time'=>[now()->addDay()->setTime(18,0)->format('Y-m-d\\TH:i'),'2026-10-22T19:00'],
            'temperature_2m'=>[17.5,18.0],'precipitation'=>[0,1.2],'precipitation_probability'=>[5,45],
            'wind_speed_10m'=>[7,12],'wind_direction_10m'=>[180,220],'weather_code'=>[1,3],
        ]],200)]);

        $weather=app(OpenMeteoProvider::class)->weatherForMatch($match);

        $this->assertSame('available',$weather['status']);
        $this->assertSame(17.5,$weather['temperature_c']);
        $this->assertSame(0,$weather['precipitation_mm']);
        $this->assertSame(44.43,$weather['coordinates']['latitude']);
        $this->assertSame('Europe/Bucharest',$weather['coordinates']['timezone']);
        Http::assertSent(fn($request)=>str_starts_with($request->url(),config('services.open_meteo.forecast_url')) && $request['timezone']==='Europe/Bucharest');

        $match->homeTeam->update(['latitude'=>null,'longitude'=>null]);
        $withoutCoordinates=app(OpenMeteoProvider::class)->weatherForMatch($match->fresh(['homeTeam']));
        $this->assertSame('unavailable',$withoutCoordinates['status']);
        $this->assertStringContainsString('Coordonatele',$withoutCoordinates['message']);
    }

    public function test_open_meteo_connection_errors_are_explicit(): void
    {
        Http::fake(['api.open-meteo.com/*'=>Http::response([],429)]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Limita de cereri Open-Meteo');

        app(OpenMeteoProvider::class)->weatherForMatch(new FootballMatch([
            'venue_latitude'=>44.4,'venue_longitude'=>26.1,'kickoff_at'=>now()->addDay(),
        ]));
    }

    public function test_weather_sync_saves_available_forecasts_and_counts_unavailable_matches(): void
    {
        $league=League::create(['name'=>'Liga Test','slug'=>'liga-weather-sync','country'=>'RO','code'=>'WSY','active'=>true]);
        $home=Team::create(['league_id'=>$league->id,'name'=>'Gazde','short_name'=>'GAZ']);
        $away=Team::create(['league_id'=>$league->id,'name'=>'Oaspeți','short_name'=>'OAS']);
        $availableMatch=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>now()->addHours(8),'status'=>'scheduled']);
        FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$away->id,'away_team_id'=>$home->id,'kickoff_at'=>now()->addHours(12),'status'=>'scheduled']);
        $weather=['status'=>'available','forecast_for'=>now()->addHours(8),'temperature_c'=>16.5,'precipitation_mm'=>0,
            'precipitation_probability'=>5,'wind_speed_kmh'=>8,'wind_direction'=>180,'weather_code'=>1,
            'raw_payload'=>['hourly'=>[]],'coordinates'=>['latitude'=>44.4,'longitude'=>26.1,'timezone'=>'Europe/Bucharest']];
        $provider=\Mockery::mock(OpenMeteoProvider::class);
        $provider->shouldReceive('weatherForMatch')->twice()->andReturn($weather,['status'=>'unavailable']);
        $store=\Mockery::mock(SourcePayloadStoreService::class);
        $store->shouldReceive('log')->once()->withArgs(fn($slug,$type,$status,$count,$message,$started,$counts)=>$slug==='open-meteo' && $type==='weather' && $status==='healthy' && $count===1 && $counts===['new'=>1]);

        (new SyncMatchWeatherJob(imminentOnly:true))->handle($provider,$store);

        $this->assertDatabaseHas('match_weather_snapshots',['match_id'=>$availableMatch->id,'provider'=>'open-meteo','temperature_c'=>16.5]);
        $this->assertDatabaseHas('matches',['id'=>$availableMatch->id,'venue_latitude'=>44.4,'venue_longitude'=>26.1,'venue_timezone'=>'Europe/Bucharest']);
        $this->assertSame('healthy',DataSource::where('slug','open-meteo')->value('status'));
    }

    public function test_weather_sync_logs_and_rethrows_provider_failures(): void
    {
        $league=League::create(['name'=>'Liga Test','slug'=>'liga-weather-error','country'=>'RO','code'=>'WER','active'=>true]);
        $home=Team::create(['league_id'=>$league->id,'name'=>'Gazde','short_name'=>'GAZ']);
        $away=Team::create(['league_id'=>$league->id,'name'=>'Oaspeți','short_name'=>'OAS']);
        FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>now()->addHours(8),'status'=>'scheduled']);
        $provider=\Mockery::mock(OpenMeteoProvider::class);
        $provider->shouldReceive('weatherForMatch')->once()->andThrow(new \RuntimeException('provider unavailable'));
        $store=\Mockery::mock(SourcePayloadStoreService::class);
        $store->shouldReceive('log')->once()->withArgs(fn($slug,$type,$status,$count)=>$slug==='open-meteo' && $type==='weather' && $status==='unavailable' && $count===0);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider unavailable');

        (new SyncMatchWeatherJob())->handle($provider,$store);
    }
}

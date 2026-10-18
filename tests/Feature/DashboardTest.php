<?php
namespace Tests\Feature;
use App\Models\League;
use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\Recommendation;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_available():void
    {
        $this->seed();
        $this->get('/')->assertOk()->assertSee('Găsește selecții bazate pe');
    }

    public function test_matches_league_filter_displays_match_counts():void
    {
        League::create(['name'=>'Liga Test','slug'=>'liga-test','code'=>'LT','active'=>true]);

        $this->get('/meciuri')
            ->assertOk()
            ->assertSee('Toate ligile (0)')
            ->assertSee('Liga Test (0)');
    }

    public function test_matches_list_displays_model_probability_for_the_shown_recommendation(): void
    {
        $league=League::create(['name'=>'Liga Test','slug'=>'model-probability-test','code'=>'LT','active'=>true]);
        $home=Team::create(['league_id'=>$league->id,'name'=>'Viking','short_name'=>'Viking']);
        $away=Team::create(['league_id'=>$league->id,'name'=>'Aston Villa','short_name'=>'Aston']);
        $match=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>now()->addDay()]);
        $market=Market::create(['key'=>'h2h','name'=>'1X2']);
        Recommendation::create(['match_id'=>$match->id,'market_id'=>$market->id,'selection'=>'1','odds'=>1.8,'model_probability'=>0.7341,'implied_probability'=>0.5556,'value'=>0.1785,'confidence'=>'medium','score'=>80,'explanation'=>'Test']);

        $this->get('/meciuri?team1=viking&team2=aston')
            ->assertOk()
            ->assertSee('Probabilitate model')
            ->assertSee('73,4%')
            ->assertSee('matches-probability-bar', false)
            ->assertSee('value="73.41"', false);
    }

    public function test_two_team_search_ignores_special_characters_and_sorts_all_matching_dates(): void
    {
        $league=League::create(['name'=>'Bundesliga','slug'=>'bundesliga-test','code'=>'BL','active'=>true]);
        $bayern=Team::create(['league_id'=>$league->id,'name'=>'FC Bayern München','short_name'=>'Bayern']);
        $leipzig=Team::create(['league_id'=>$league->id,'name'=>'RB Leipzig','short_name'=>'Leipzig']);
        $other=Team::create(['league_id'=>$league->id,'name'=>'Borussia Dortmund','short_name'=>'Dortmund']);
        $today=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$bayern->id,'away_team_id'=>$leipzig->id,'kickoff_at'=>now()->subMinute()]);
        $later=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$leipzig->id,'away_team_id'=>$bayern->id,'kickoff_at'=>now()->addMonths(4)]);
        FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$bayern->id,'away_team_id'=>$other->id,'kickoff_at'=>now()->addDay()]);

        $this->get('/meciuri')->assertOk()->assertSee('Resetează filtrele')->assertSee('href="'.route('matches').'"',false)->assertViewHas('matches',fn($matches)=>!$matches->contains('id',$today->id));
        $this->get('/meciuri?team1=Bayern+Munchen&team2=R.B.+Leipzig')
            ->assertOk()
            ->assertSee('Echipa 1')
            ->assertSee('Echipa 2')
            ->assertSee('matches-table',false)
            ->assertSee('matches-fixture',false)
            ->assertSee('matches-logo-fallback',false)
            ->assertSee(route('matches.show',$today),false)
            ->assertViewHas('matches',fn($matches)=>$matches->pluck('id')->all()===[$today->id,$later->id]);
        $this->get('/meciuri?team1=echipa-inexistenta')->assertOk()->assertSee('Niciun meci găsit');
    }

    public function test_live_badge_only_appears_during_the_estimated_match_window(): void
    {
        $league=League::create(['name'=>'Bundesliga','slug'=>'bundesliga-live','code'=>'BL','active'=>true]);
        $bayern=Team::create(['league_id'=>$league->id,'name'=>'Bayern München','short_name'=>'Bayern']);
        $leipzig=Team::create(['league_id'=>$league->id,'name'=>'RB Leipzig','short_name'=>'Leipzig']);
        $create=fn($kickoff,$status)=>FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$bayern->id,'away_team_id'=>$leipzig->id,'kickoff_at'=>$kickoff,'status'=>$status]);
        $live=$create(now()->subMinutes(30),'scheduled');
        $finished=$create(now()->subMinutes(40),'finished');
        $expired=$create(now()->subHours(3),'scheduled');
        $future=$create(now()->addHour(),'scheduled');

        $this->assertTrue($live->is_live_window);
        $this->assertFalse($finished->is_live_window);
        $this->assertFalse($expired->is_live_window);
        $this->assertFalse($future->is_live_window);
        $response=$this->get('/meciuri?team1=bayern&team2=leipzig')->assertOk()->assertSee('Live');
        $this->assertSame(1,substr_count($response->getContent(),'class="match-live-badge"'));
    }

    public function test_superbet_url_search_uses_existing_matches_without_fetching_external_data(): void
    {
        $league=League::create(['name'=>'Serie A','slug'=>'serie-a-test','code'=>'SA','active'=>true]);
        $bologna=Team::create(['league_id'=>$league->id,'name'=>'Bologna','short_name'=>'Bologna']);
        $inter=Team::create(['league_id'=>$league->id,'name'=>'Inter Mailand','short_name'=>'Inter']);
        $match=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$bologna->id,'away_team_id'=>$inter->id,'kickoff_at'=>now()->subHour()]);
        $reverse=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$inter->id,'away_team_id'=>$bologna->id,'kickoff_at'=>now()->addMonths(4)]);
        $url='https://superbet.ro/cote/fotbal/bologna-vs-inter-milano-14127680?t=offer-live-104&mdt=o';

        $this->get('/meciuri?'.http_build_query(['match_url'=>$url]))
            ->assertOk()
            ->assertSee('Căutare din URL: bologna — inter milano')
            ->assertSee(route('matches.show',$match),false)
            ->assertViewHas('matches',fn($matches)=>$matches->pluck('id')->all()===[$match->id] && !$matches->contains('id',$reverse->id));
        $this->get('/meciuri?'.http_build_query(['match_url'=>'https://superbet.ro.evil.test/cote/fotbal/bologna-vs-inter-milano-14127680']))
            ->assertOk()->assertSee('Introdu un URL valid de meci Superbet')->assertSee('Niciun meci găsit');
    }

    public function test_winbet_url_search_uses_existing_matches_and_rejects_other_hosts(): void
    {
        $league=League::create(['name'=>'Liga Test','slug'=>'liga-winbet-test','code'=>'LT','active'=>true]);
        $home=Team::create(['league_id'=>$league->id,'name'=>'Águilas FC','short_name'=>'Aguilas']);
        $away=Team::create(['league_id'=>$league->id,'name'=>'UD Ibiza','short_name'=>'Ibiza']);
        $match=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>now()->subHour()]);
        $url='https://winbet.ro/in-play/aguilas-fc-vs-ud-ibiza-50008044281';

        $this->get('/meciuri?'.http_build_query(['match_url'=>$url]))
            ->assertOk()
            ->assertSee('URL meci Superbet sau Winbet')
            ->assertSee('Căutare din URL: aguilas fc — ud ibiza')
            ->assertSee(route('matches.show',$match),false)
            ->assertViewHas('matches',fn($matches)=>$matches->pluck('id')->all()===[$match->id]);
        $this->get('/meciuri?'.http_build_query(['match_url'=>'https://winbet.ro.evil.test/in-play/aguilas-fc-vs-ud-ibiza-50008044281']))
            ->assertOk()->assertSee('Introdu un URL valid de meci Superbet sau Winbet')->assertSee('Niciun meci găsit');
    }

    public function test_winbet_utd_abbreviation_matches_united_without_showing_reverse_fixture(): void
    {
        $league=League::create(['name'=>'Premier League','slug'=>'premier-league-url-test','code'=>'PL','active'=>true]);
        $newcastle=Team::create(['league_id'=>$league->id,'name'=>'Newcastle United FC','short_name'=>'Newcastle']);
        $villa=Team::create(['league_id'=>$league->id,'name'=>'Aston Villa','short_name'=>'Villa']);
        $match=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$newcastle->id,'away_team_id'=>$villa->id,'kickoff_at'=>now()->subHour()]);
        FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$villa->id,'away_team_id'=>$newcastle->id,'kickoff_at'=>now()->addMonths(4)]);

        $this->get('/meciuri?'.http_build_query(['match_url'=>'https://winbet.ro/in-play/newcastle-utd-vs-aston-villa-50007948549']))
            ->assertOk()
            ->assertSee(route('matches.show',$match),false)
            ->assertViewHas('matches',fn($matches)=>$matches->pluck('id')->all()===[$match->id]);
    }
}

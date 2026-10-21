<?php
namespace Tests\Feature;
use App\Models\League;
use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\Recommendation;
use App\Models\BetlensPrediction;
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

    public function test_matches_league_filter_displays_only_leagues_with_listed_matches():void
    {
        League::create(['name'=>'Liga Test','slug'=>'liga-test','code'=>'LT','active'=>true]);

        $this->get('/meciuri')
            ->assertOk()
            ->assertSee('Toate ligile (0)')
            ->assertDontSee('Liga Test (0)');
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

    public function test_probability_checkbox_filters_matches_to_those_with_a_model_probability(): void
    {
        $league=League::create(['name'=>'Liga Test','slug'=>'probability-filter-test','code'=>'LT','active'=>true]);
        $home=Team::create(['league_id'=>$league->id,'name'=>'Home','short_name'=>'Home']);
        $away=Team::create(['league_id'=>$league->id,'name'=>'Away','short_name'=>'Away']);
        $withProbability=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>now()->addDay()]);
        $withoutProbability=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$away->id,'away_team_id'=>$home->id,'kickoff_at'=>now()->addDays(2)]);
        $market=Market::create(['key'=>'h2h','name'=>'1X2']);
        Recommendation::create(['match_id'=>$withProbability->id,'market_id'=>$market->id,'selection'=>'1','odds'=>1.8,'model_probability'=>0.62,'implied_probability'=>0.55,'value'=>0.07,'confidence'=>'medium','score'=>70,'explanation'=>'Test']);
        $this->get('/meciuri?with_probabilities=1')
            ->assertOk()
            ->assertSee('Doar date cu probabilități')
            ->assertViewHas('onlyWithProbabilities',true)
            ->assertViewHas('matches',fn($matches)=>$matches->pluck('id')->all()===[$withProbability->id]);
    }

    public function test_probability_checkbox_includes_saved_betlens_prediction_snapshots(): void
    {
        $league=League::create(['name'=>'Liga Test','slug'=>'saved-probability-filter-test','code'=>'LT','active'=>true]);
        $home=Team::create(['league_id'=>$league->id,'name'=>'Home','short_name'=>'Home']);
        $away=Team::create(['league_id'=>$league->id,'name'=>'Away','short_name'=>'Away']);
        $match=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>now()->addDay()]);
        BetlensPrediction::create(['game_id'=>$match->id,'predicted_at'=>now(),'home_win_probability'=>0.41,'draw_probability'=>0.29,'away_win_probability'=>0.30]);
        $this->assertDatabaseHas('betlens_predictions', ['game_id'=>$match->id,'home_win_probability'=>'0.410000']);
        $this->assertNotNull($match->fresh()->latestBetlensPrediction);

        $this->get('/meciuri?with_probabilities=1')
            ->assertOk()
            ->assertSee('Estimare BetLens (1/X/2)')
            ->assertSee('41,0%')
            ->assertViewHas('matches',fn($matches)=>$matches->pluck('id')->all()===[$match->id]);
    }

    public function test_match_detail_displays_saved_betlens_probabilities_without_a_recommendation(): void
    {
        $league=League::create(['name'=>'Premier League Test','slug'=>'prediction-detail-test','code'=>'PL','active'=>true]);
        $home=Team::create(['league_id'=>$league->id,'name'=>'Ipswich Town','short_name'=>'Ipswich']);
        $away=Team::create(['league_id'=>$league->id,'name'=>'Nottingham Forest','short_name'=>'Forest']);
        $match=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>now()->addDays(3)]);
        BetlensPrediction::create([
            'game_id'=>$match->id,'predicted_at'=>now(),'model_version'=>'poisson-v1',
            'home_score'=>1.35,'away_score'=>1.12,'home_win_probability'=>0.422,
            'draw_probability'=>0.238,'away_win_probability'=>0.340,
            'home_win_odds'=>2.3697,'draw_odds'=>4.2017,'away_win_odds'=>2.9412,
        ]);

        $this->get(route('matches.show',$match))
            ->assertOk()
            ->assertSee('Estimare statistică BetLens')
            ->assertSee('Probabilități rezultat (1/X/2)')
            ->assertSee('42,2%')
            ->assertSee('23,8%')
            ->assertSee('34,0%')
            ->assertSee('Goluri estimate:')
            ->assertDontSee('Nu există încă o analiză sau estimare disponibilă pentru acest meci.');
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
            ->assertSee('Am găsit potrivirea pentru bologna — inter milano')
            ->assertSee(route('matches.show',$match),false)
            ->assertSee(route('matches.show',$reverse),false)
            ->assertViewHas('matches',fn($matches)=>$matches->pluck('id')->all()===[$match->id,$reverse->id]);
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
            ->assertSee('Am găsit potrivirea pentru aguilas fc — ud ibiza')
            ->assertSee(route('matches.show',$match),false)
            ->assertViewHas('matches',fn($matches)=>$matches->pluck('id')->all()===[$match->id]);
        $this->get('/meciuri?'.http_build_query(['match_url'=>'https://winbet.ro.evil.test/in-play/aguilas-fc-vs-ud-ibiza-50008044281']))
            ->assertOk()->assertSee('Introdu un URL valid de meci Superbet sau Winbet')->assertSee('Niciun meci găsit');
    }

    public function test_winbet_utd_abbreviation_matches_united_and_reverse_fixture(): void
    {
        $league=League::create(['name'=>'Premier League','slug'=>'premier-league-url-test','code'=>'PL','active'=>true]);
        $newcastle=Team::create(['league_id'=>$league->id,'name'=>'Newcastle United FC','short_name'=>'Newcastle']);
        $villa=Team::create(['league_id'=>$league->id,'name'=>'Aston Villa','short_name'=>'Villa']);
        $match=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$newcastle->id,'away_team_id'=>$villa->id,'kickoff_at'=>now()->subHour()]);
        $reverse=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$villa->id,'away_team_id'=>$newcastle->id,'kickoff_at'=>now()->addMonths(4)]);

        $this->get('/meciuri?'.http_build_query(['match_url'=>'https://winbet.ro/in-play/newcastle-utd-vs-aston-villa-50007948549']))
            ->assertOk()
            ->assertSee(route('matches.show',$match),false)
            ->assertViewHas('matches',fn($matches)=>$matches->pluck('id')->all()===[$match->id,$reverse->id]);
    }

    public function test_url_search_ignores_team_fields_and_shows_partial_alternatives(): void
    {
        $league=League::create(['name'=>'Liga Test','slug'=>'liga-partial-url-test','code'=>'LT','active'=>true]);
        $requested=Team::create(['league_id'=>$league->id,'name'=>'Slavia Prag','short_name'=>'Slavia Prag']);
        $other=Team::create(['league_id'=>$league->id,'name'=>'Galatasaray','short_name'=>'Galatasaray']);
        $candidate=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$requested->id,'away_team_id'=>$other->id,'kickoff_at'=>now()->addHour()]);
        $url='https://superbet.ro/cote/fotbal/fenerbahce-vs-slavia-praga-14738984?mdt=o';

        $this->get('/meciuri?'.http_build_query(['team1'=>'Norrkoping','team2'=>'Orebro SK','match_url'=>$url]))
            ->assertOk()
            ->assertSee('Nu am găsit o potrivire exactă')
            ->assertSee('am recunoscut cel puțin una dintre echipe')
            ->assertSee(route('matches.show',$candidate),false)
            ->assertViewHas('urlExactMatch',false)
            ->assertViewHas('urlPartialMatch',true)
            ->assertViewHas('matches',fn($matches)=>$matches->pluck('id')->all()===[$candidate->id]);
    }
}

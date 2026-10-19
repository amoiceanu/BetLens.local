<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Market;
use App\Models\Odd;
use App\Models\Team;
use App\Models\User;
use App\Services\Mindmap\MatchLevelSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSimulatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_open_simulator(): void
    {
        $this->get(route('admin.simulator'))->assertRedirect(route('user.login'));
        $this->withSession(['user_id' => User::factory()->create()->id])->get(route('admin.simulator'))->assertRedirect();
        $this->withSession(['admin_user_id' => User::factory()->create(['is_admin' => true])->id])
            ->get(route('admin.simulator'))->assertOk()->assertSee('Simulator')->assertSee('L6');
    }

    public function test_levels_change_only_when_a_real_1x2_rule_exists_and_do_not_write(): void
    {
        [$match, $league, $home, $away] = $this->fixture();
        $market = Market::create(['key' => 'result', 'name' => 'Rezultat']);
        foreach (['1' => 2.0, 'X' => 3.0, '2' => 4.0] as $selection => $price) {
            Odd::create(['match_id' => $match->id, 'market_id' => $market->id, 'selection' => $selection,
                'price' => $price, 'provider' => 'test', 'captured_at' => $match->kickoff_at->copy()->subHour()]);
        }
        $simulator = app(MatchLevelSimulator::class);
        $before = DB::table('recommendations')->count();
        $all = $simulator->simulate($match, [1, 2, 3, 4, 5, 6]);
        $withoutContext = $simulator->simulate($match, [1, 2, 6]);
        $onlyLeague = $simulator->simulate($match, [1]);
        $onlyMarket = $simulator->simulate($match, [2]);
        $none = $simulator->simulate($match, []);

        $this->assertSame($all['final'], $withoutContext['final']);
        $this->assertNotSame($onlyLeague['final'], $onlyMarket['final']);
        $this->assertNull($none['final']);
        $this->assertSame('dependency', $simulator->simulate($match, [6])['steps'][5]['status']);
        $this->assertSame('applied', $all['steps'][5]['status']);
        $this->assertSame(12, $all['results_count']);
        $this->assertSame($before, DB::table('recommendations')->count());
    }

    public function test_future_results_do_not_leak_into_simulation(): void
    {
        [$match, $league, $home, $away] = $this->fixture();
        $future = FootballMatch::create(['league_id' => $league->id, 'home_team_id' => $home->id,
            'away_team_id' => $away->id, 'kickoff_at' => $match->kickoff_at->copy()->addDay()]);
        DB::table('match_results')->insert(['match_id' => $future->id, 'home_score' => 9, 'away_score' => 0,
            'created_at' => now(), 'updated_at' => now()]);
        $result = app(MatchLevelSimulator::class)->simulate($match, [1, 6]);
        $this->assertSame(12, $result['results_count']);
        $this->assertSame(8, $result['outcome_counts']['1']);
        $this->assertSame(4, $result['outcome_counts']['2']);
    }

    public function test_admin_page_shows_selected_match_and_respects_unchecked_levels(): void
    {
        [$match] = $this->fixture();
        $this->withSession(['admin_user_id' => User::factory()->create(['is_admin' => true])->id])
            ->get(route('admin.simulator', ['match_id' => $match->id, 'levels_submitted' => 1]))
            ->assertOk()->assertSee('Probabilități indisponibile')->assertSee('Dezactivat');
    }

    public function test_unavailable_levels_are_disabled_and_available_levels_reset_for_each_match(): void
    {
        [$first, $league, $home, $away] = $this->fixture();
        $second = FootballMatch::create(['league_id' => $league->id, 'home_team_id' => $home->id,
            'away_team_id' => $away->id, 'kickoff_at' => $first->kickoff_at->copy()->addDay()]);
        $market = Market::create(['key' => 'result', 'name' => 'Rezultat']);
        foreach (['1' => 2.0, 'X' => 3.0, '2' => 4.0] as $selection => $price) {
            Odd::create(['match_id' => $first->id, 'market_id' => $market->id, 'selection' => $selection,
                'price' => $price, 'provider' => 'test', 'captured_at' => $first->kickoff_at->copy()->subHour()]);
        }
        $this->withSession(['admin_user_id' => User::factory()->create(['is_admin' => true])->id]);
        $this->get(route('admin.simulator', ['match_id' => $first->id]))->assertOk()
            ->assertViewHas('enabled', [1, 2, 6])
            ->assertViewHas('availability', fn ($levels) => $levels[1] && $levels[2] && ! $levels[3] && ! $levels[4] && ! $levels[5] && $levels[6])
            ->assertSee('Nu sunt date pentru meciul selectat');
        $this->get(route('admin.simulator', ['match_id' => $second->id]))->assertOk()
            ->assertViewHas('enabled', [1, 6])
            ->assertViewHas('availability', fn ($levels) => ! $levels[2]);
        $this->get(route('admin.simulator', ['match_id' => $second->id, 'levels_submitted' => 1, 'levels' => [1, 2, 6]]))
            ->assertOk()->assertViewHas('enabled', [1, 6]);
    }

    private function fixture(): array
    {
        $league = League::create(['name' => 'Liga test', 'slug' => 'liga-test', 'code' => 'LT']);
        $home = Team::create(['league_id' => $league->id, 'name' => 'Acasă', 'short_name' => 'ACA']);
        $away = Team::create(['league_id' => $league->id, 'name' => 'Deplasare', 'short_name' => 'DEP']);
        $match = FootballMatch::create(['league_id' => $league->id, 'home_team_id' => $home->id,
            'away_team_id' => $away->id, 'kickoff_at' => now()->addDays(30)]);
        for ($i = 0; $i < 12; $i++) {
            $past = FootballMatch::create(['league_id' => $league->id, 'home_team_id' => $home->id,
                'away_team_id' => $away->id, 'kickoff_at' => $match->kickoff_at->copy()->subDays($i + 1)]);
            DB::table('match_results')->insert(['match_id' => $past->id,
                'home_score' => $i % 3 === 0 ? 0 : 2, 'away_score' => $i % 3 === 0 ? 1 : 0,
                'created_at' => now(), 'updated_at' => now()]);
        }
        return [$match, $league, $home, $away];
    }
}

<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_aliases_and_winbet_search_uses_them(): void
    {
        $league = League::create(['name' => 'Premier League', 'slug' => 'premier-league-alias-test', 'code' => 'PL', 'active' => true]);
        $home = Team::create(['league_id' => $league->id, 'name' => 'Newcastle United FC', 'short_name' => 'Newcastle']);
        $away = Team::create(['league_id' => $league->id, 'name' => 'Aston Villa', 'short_name' => 'Villa']);
        $match = FootballMatch::create(['league_id' => $league->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id, 'kickoff_at' => now()->subHour()]);
        $url = 'https://winbet.ro/in-play/the-magpies-vs-aston-villa-50007948549';

        $this->get('/meciuri?'.http_build_query(['match_url' => $url]))
            ->assertOk()->assertViewHas('matches', fn ($matches) => $matches->isEmpty());
        $this->get(route('admin.aliases.index'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.aliases.store'), ['team_id' => $home->id, 'provider' => 'winbet', 'alias' => 'The Magpies'])
            ->assertRedirect(route('admin.login'));

        $admin = User::factory()->create(['is_admin' => true]);
        $this->withSession(['admin_user_id' => $admin->id]);
        $this->post(route('admin.aliases.store'), ['team_id' => $home->id, 'provider' => 'winbet', 'alias' => 'The Magpies'])
            ->assertRedirect();
        $alias = TeamAlias::sole();
        $this->assertSame('themagpies', $alias->normalized_alias);
        $this->get(route('admin.aliases.index'))->assertOk()->assertSee('The Magpies');
        $this->get('/meciuri?'.http_build_query(['match_url' => $url]))
            ->assertOk()->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$match->id]);
        $this->get('/meciuri?'.http_build_query(['match_url' => 'https://superbet.ro/cote/fotbal/the-magpies-vs-aston-villa-14127714']))
            ->assertOk()->assertViewHas('matches', fn ($matches) => $matches->isEmpty());
        $this->get('/meciuri?team1=the+magpies&team2=aston+villa')
            ->assertOk()->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$match->id]);

        $this->patch(route('admin.aliases.update', $alias), ['team_id' => $home->id, 'provider' => 'winbet', 'alias' => 'NUFC'])
            ->assertRedirect();
        $this->get('/meciuri?'.http_build_query(['match_url' => $url]))
            ->assertOk()->assertViewHas('matches', fn ($matches) => $matches->isEmpty());
        $this->delete(route('admin.aliases.destroy', $alias))->assertRedirect();
        $this->assertDatabaseCount('team_aliases', 0);
    }

    public function test_aliases_cannot_claim_another_team_name_or_duplicate_a_league_provider_key(): void
    {
        $league = League::create(['name' => 'Liga Test', 'slug' => 'alias-conflict', 'code' => 'LT', 'active' => true]);
        $first = Team::create(['league_id' => $league->id, 'name' => 'Newcastle United FC', 'short_name' => 'Newcastle']);
        $second = Team::create(['league_id' => $league->id, 'name' => 'Aston Villa', 'short_name' => 'Villa']);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->withSession(['admin_user_id' => $admin->id]);

        $this->post(route('admin.aliases.store'), ['team_id' => $first->id, 'provider' => 'all', 'alias' => 'Aston Villa'])
            ->assertSessionHasErrors('alias');
        $this->post(route('admin.aliases.store'), ['team_id' => $first->id, 'provider' => 'winbet', 'alias' => 'The Magpies'])
            ->assertRedirect();
        $this->post(route('admin.aliases.store'), ['team_id' => $second->id, 'provider' => 'winbet', 'alias' => 'The-Magpies'])
            ->assertSessionHasErrors('alias');
        $this->assertDatabaseCount('team_aliases', 1);
    }
}

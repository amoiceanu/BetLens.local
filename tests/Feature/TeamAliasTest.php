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
            ->assertOk()->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$match->id]);
        $this->get(route('admin.aliases.index'))->assertRedirect(route('user.login'));
        $this->post(route('admin.aliases.store'), ['team_id' => $home->id, 'provider' => 'winbet', 'alias' => 'The Magpies'])
            ->assertRedirect(route('user.login'));

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
            ->assertOk()->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$match->id]);
        $this->get('/meciuri?team1=the+magpies&team2=aston+villa')
            ->assertOk()->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$match->id]);

        $this->patch(route('admin.aliases.update', $alias), ['team_id' => $home->id, 'provider' => 'winbet', 'alias' => 'NUFC'])
            ->assertRedirect();
        $this->get('/meciuri?'.http_build_query(['match_url' => $url]))
            ->assertOk()->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$match->id]);
        $this->delete(route('admin.aliases.destroy', $alias))->assertRedirect();
        $this->assertDatabaseCount('team_aliases', 0);
    }

    public function test_aliases_cannot_claim_another_team_name_or_duplicate_a_global_key(): void
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

    public function test_global_alias_resolver_ignores_league_and_provider_and_refuses_ambiguous_names(): void
    {
        $firstLeague = League::create(['name' => 'Liga A', 'slug' => 'alias-global-a', 'code' => 'LA', 'active' => true]);
        $secondLeague = League::create(['name' => 'Liga B', 'slug' => 'alias-global-b', 'code' => 'LB', 'active' => true]);
        $first = Team::create(['league_id' => $firstLeague->id, 'name' => 'Cologne FC', 'short_name' => 'Cologne']);
        $other = Team::create(['league_id' => $secondLeague->id, 'name' => 'A different club', 'short_name' => 'Different']);
        TeamAlias::create(['league_id' => $firstLeague->id, 'team_id' => $first->id, 'provider' => 'all', 'alias' => 'FC Koeln', 'normalized_alias' => 'fckoeln', 'status' => 'active']);

        $resolver = app(\App\Services\TeamAliasResolver::class);
        $this->assertSame($first->id, $resolver->resolve('FC Koeln')?->id);
        $this->assertSame($first->id, $resolver->resolve('FC Koeln')?->id, 'A global alias is not scoped to a league or provider.');

        TeamAlias::create(['league_id' => $secondLeague->id, 'team_id' => $other->id, 'provider' => 'all', 'alias' => 'FC Koeln', 'normalized_alias' => 'fckoeln', 'status' => 'review']);
        $resolver->forgetCache();
        $this->assertNull($resolver->resolve('FC Koeln'), 'Conflicting aliases must not select a team arbitrarily.');
    }

    public function test_alias_management_page_has_global_stats_and_searchable_team_picker(): void
    {
        $league = League::create(['name' => 'Liga UI', 'slug' => 'alias-ui', 'code' => 'UI', 'active' => true]);
        Team::create(['league_id' => $league->id, 'name' => 'FC Köln', 'short_name' => 'Köln']);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->withSession(['admin_user_id' => $admin->id]);

        $this->get(route('admin.aliases.index'))->assertOk()
            ->assertSee('Aliasuri globale echipe')
            ->assertSee('Potriviri automate')
            ->assertSee('metrică indisponibilă')
            ->assertDontSee('Operator</th>')
            ->assertDontSee('Liga</th>');
        $this->get(route('admin.aliases.teams', ['q' => 'Köln']))
            ->assertOk()->assertJsonFragment(['name' => 'FC Köln']);
        $this->get(route('admin.aliases.normalize', ['q' => 'FC Köln']))
            ->assertOk()->assertJson(['normalized' => 'fckoln']);
    }

    public function test_manual_alias_save_invalidates_global_resolver_cache_and_bulk_delete_keeps_team(): void
    {
        $league = League::create(['name' => 'Liga Cache', 'slug' => 'alias-cache', 'code' => 'CA', 'active' => true]);
        $team = Team::create(['league_id' => $league->id, 'name' => 'Canonical Team', 'short_name' => 'Canonical']);
        $resolver = app(\App\Services\TeamAliasResolver::class);
        $this->assertNull($resolver->resolve('Canonical Alternative'));
        $admin = User::factory()->create(['is_admin' => true]);
        $this->withSession(['admin_user_id' => $admin->id]);

        $this->post(route('admin.aliases.store'), ['team_id' => $team->id, 'alias' => 'Canonical Alternative'])
            ->assertRedirect();
        $this->assertSame($team->id, $resolver->resolve('Canonical Alternative')?->id);
        $alias = TeamAlias::sole();
        $this->delete(route('admin.aliases.bulk-destroy'), ['ids' => [$alias->id]])->assertRedirect();
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'Canonical Team']);
        $this->assertDatabaseCount('team_aliases', 0);
        $this->assertNull($resolver->resolve('Canonical Alternative'));
    }

    public function test_alias_resolver_returns_confident_suggestions_and_rejects_ambiguous_names(): void
    {
        $league=League::create(['name'=>'Liga Suggest','slug'=>'alias-suggest','code'=>'AS','active'=>true]);
        $team=Team::create(['league_id'=>$league->id,'name'=>'Aston Villa','short_name'=>'Villa']);
        $resolver=app(\App\Services\TeamAliasResolver::class);

        $this->assertNull($resolver->resolve('  '));
        $this->assertSame([], $resolver->suggestions(''));
        $suggestions=$resolver->suggestions('Aston Villa');
        $this->assertSame($team->id,$suggestions[0]['team']->id);
        $this->assertGreaterThanOrEqual(0.55,$suggestions[0]['score']);
        $this->assertSame([], $resolver->suggestions('no matching team'));

        Team::create(['league_id'=>$league->id,'name'=>'Aston Vilia','short_name'=>'Aston Vilia']);
        $resolver->forgetCache();
        $this->assertSame([], $resolver->suggestions('Aston Vila'));
    }
}

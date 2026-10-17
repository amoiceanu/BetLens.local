<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Team;
use App\Models\TeamAlias;
use Database\Seeders\KnownTeamAliasesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnownTeamAliasesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_aliases_are_seeded_only_for_existing_teams_without_overwriting_custom_entries(): void
    {
        $league = League::create(['name' => 'Premier League', 'slug' => 'premier-league-alias-seed', 'code' => 'PL', 'active' => true]);
        $newcastle = Team::create(['league_id' => $league->id, 'name' => 'Newcastle United FC', 'short_name' => 'Newcastle']);
        Team::create(['league_id' => $league->id, 'name' => 'Aston Villa', 'short_name' => 'Villa']);
        TeamAlias::create(['league_id' => $league->id, 'team_id' => $newcastle->id, 'provider' => 'all', 'alias' => 'Custom Newcastle Utd', 'normalized_alias' => 'newcastleunited']);

        $this->seed(KnownTeamAliasesSeeder::class);
        $this->assertDatabaseHas('team_aliases', ['team_id' => $newcastle->id, 'alias' => 'Custom Newcastle Utd']);
        $this->assertDatabaseHas('team_aliases', ['team_id' => $newcastle->id, 'alias' => 'Newcastle U.']);
        $this->assertDatabaseHas('team_aliases', ['alias' => 'Aston Villa FC']);
        $count = TeamAlias::count();
        $this->seed(KnownTeamAliasesSeeder::class);
        $this->assertSame($count, TeamAlias::count());
    }
}

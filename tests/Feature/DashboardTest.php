<?php
namespace Tests\Feature;
use App\Models\League;
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
}

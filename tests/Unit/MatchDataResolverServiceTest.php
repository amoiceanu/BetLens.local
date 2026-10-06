<?php
namespace Tests\Unit;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Team;
use App\Services\MatchDataResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class MatchDataResolverServiceTest extends TestCase
{
    use RefreshDatabase;
    public function test_it_matches_within_fifteen_minutes_without_overwriting_primary_data(): void
    {
        [$match]=$this->matchFixture();
        $result=app(MatchDataResolverService::class)->resolve('sportmonks',['external_id'=>'sm-1','league'=>'SuperLiga România','home_team'=>'FCSB','away_team'=>'Rapid București','kickoff_at'=>$match->kickoff_at->copy()->addMinutes(12)->toIso8601String(),'venue'=>['latitude'=>44.43,'longitude'=>26.10]]);
        $this->assertSame('resolved',$result['status']);$this->assertSame('api-football-1',$match->fresh()->external_id);$this->assertDatabaseHas('external_id_mappings',['provider'=>'sportmonks','external_id'=>'sm-1','internal_id'=>$match->id]);
    }
    public function test_it_marks_time_conflicts_and_disables_recommendations(): void
    {
        [$match]=$this->matchFixture();
        $result=app(MatchDataResolverService::class)->resolve('sportmonks',['external_id'=>'sm-2','league'=>'SuperLiga România','home_team'=>'FCSB','away_team'=>'Rapid București','kickoff_at'=>$match->kickoff_at->copy()->addMinutes(30)->toIso8601String()]);
        $this->assertSame('conflict',$result['status']);$this->assertSame('conflict',$match->fresh()->data_status);$this->assertDatabaseHas('data_sync_logs',['provider'=>'sportmonks','status'=>'conflict']);
    }
    private function matchFixture(): array
    {
        $league=League::create(['name'=>'SuperLiga România','slug'=>'superliga-test','country'=>'România','code'=>'SLT','active'=>true]);
        $home=Team::create(['league_id'=>$league->id,'name'=>'FCSB','short_name'=>'FCSB']);$away=Team::create(['league_id'=>$league->id,'name'=>'Rapid București','short_name'=>'Rapid']);
        return [FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>now()->addDay(),'status'=>'scheduled','external_id'=>'api-football-1'])];
    }
}

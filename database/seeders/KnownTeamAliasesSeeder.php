<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\TeamAlias;
use App\Services\TeamAliasMatcher;
use Illuminate\Database\Seeder;

class KnownTeamAliasesSeeder extends Seeder
{
    public function run(): void
    {
        $known = [
            'newcastle united fc' => ['Newcastle Utd', 'Newcastle United', 'Newcastle U.'],
            'manchester united fc' => ['Manchester Utd', 'Man Utd', 'Man United', 'Man United FC'],
            'manchester city' => ['Man City', 'Manchester City FC'],
            'tottenham hotspur fc' => ['Tottenham Hotspur', 'Spurs'],
            'nottingham forest' => ['Nottm Forest', 'Nottingham Forest FC'],
            'brighton & hove albion' => ['Brighton and Hove Albion', 'Brighton Hove Albion'],
            'fc arsenal' => ['Arsenal FC', 'Arsenal'],
            'fc liverpool' => ['Liverpool FC', 'Liverpool'],
            'chelsea fc' => ['Chelsea'],
            'crystal palace fc' => ['Crystal Palace'],
            'aston villa' => ['Aston Villa FC'],
            'everton fc' => ['Everton'],
            'fulham fc' => ['Fulham'],
            'brentford f.c.' => ['Brentford FC', 'Brentford'],
            'afc bournemouth' => ['Bournemouth AFC', 'Bournemouth'],
            'leeds united' => ['Leeds Utd'],
            'fc bayern münchen' => ['Bayern Munich', 'FC Bayern Munich', 'Bayern Muenchen'],
            'borussia mönchengladbach' => ['Borussia Mgladbach', 'Bor. Monchengladbach', 'Gladbach'],
            '1. fc köln' => ['FC Cologne', '1. FC Cologne', 'Cologne'],
            '1. fc union berlin' => ['Union Berlin', 'FC Union Berlin'],
            '1. fsv mainz 05' => ['Mainz 05', 'FSV Mainz'],
            'bayer 04 leverkusen' => ['Bayer Leverkusen', 'Bayer 04'],
            'borussia dortmund' => ['BVB', 'Dortmund'],
            'rb leipzig' => ['RasenBallsport Leipzig', 'Leipzig'],
            'hamburger sv' => ['Hamburg', 'HSV'],
            'sv werder bremen' => ['Werder Bremen'],
            'vfb stuttgart' => ['Stuttgart'],
            'sc freiburg' => ['Freiburg'],
            'real madrid' => ['Real Madrid CF'],
            'atlético madrid' => ['Atletico de Madrid', 'Atleti'],
            'atletico madrid' => ['Atletico de Madrid', 'Atleti'],
            'fc barcelona' => ['Barcelona FC', 'Barca', 'Barcelona'],
            'athletic club' => ['Athletic Bilbao', 'Athletic Club Bilbao'],
            'real sociedad' => ['Real Sociedad San Sebastian'],
            'fc villareal' => ['Villarreal', 'Villarreal CF'],
            'villarreal cf' => ['Villarreal', 'FC Villareal'],
            'fc sevilla' => ['Sevilla FC'],
            'fc valencia' => ['Valencia CF'],
            'celta vigo' => ['RC Celta', 'Celta de Vigo'],
            'espanyol barcelona' => ['RCD Espanyol', 'Espanyol'],
            'fc getafe' => ['Getafe CF'],
            'real betis' => ['Real Betis Balompie'],
            'inter mailand' => ['Inter Milano', 'Inter Milan', 'Internazionale'],
            'ssc napoli' => ['Napoli', 'Napoli SSC'],
            'as rom' => ['AS Roma', 'Roma'],
            'paris st. germain' => ['Paris Saint-Germain', 'Paris Saint Germain', 'PSG'],
            'sporting cp' => ['Sporting Lisbon', 'Sporting Clube de Portugal'],
            'fc brügge' => ['Club Brugge', 'Club Brugge KV'],
            'galatasaray istanbul' => ['Galatasaray SK', 'Galatasaray'],
            'fenerbahçe sk' => ['Fenerbahce Istanbul', 'Fenerbahce'],
            'feyenoord rotterdam' => ['Feyenoord'],
            'aek athen' => ['AEK Athens', 'AEK FC'],
            'slavia prag' => ['Slavia Prague', 'SK Slavia Praha'],
            'shakhtar donetsk' => ['Shakhtar'],
            'fk bodö/glimt' => ['Bodo Glimt', 'FK Bodo/Glimt'],
        ];

        $matcher = app(TeamAliasMatcher::class);
        $created = 0;
        foreach (Team::all() as $team) {
            foreach ($known[mb_strtolower(trim($team->name))] ?? [] as $alias) {
                $normalized = $matcher->normalize($alias);
                if ($normalized === $matcher->normalize($team->name)) continue;
                if (TeamAlias::where('league_id', $team->league_id)->where('provider', 'all')->where('normalized_alias', $normalized)->exists()) continue;

                $conflicts = Team::where('league_id', $team->league_id)->whereKeyNot($team->id)->get()
                    ->contains(fn (Team $other) => $matcher->normalize($other->name) === $normalized
                        || $matcher->normalize($other->short_name) === $normalized);
                if ($conflicts) continue;

                TeamAlias::create([
                    'league_id' => $team->league_id,
                    'team_id' => $team->id,
                    'provider' => 'all',
                    'alias' => $alias,
                    'normalized_alias' => $normalized,
                ]);
                $created++;
            }
        }

        $this->command?->info("Aliasuri noi: {$created}.");
    }
}

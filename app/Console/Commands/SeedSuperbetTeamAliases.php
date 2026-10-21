<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\TeamAlias;
use App\Models\League;
use App\Services\TeamAliasMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedSuperbetTeamAliases extends Command
{
    protected $signature = 'betlens:seed-superbet-team-aliases {--dry-run : Raportează aliasurile fără să le scrie în baza de date}';

    protected $description = 'Adaugă aliasuri Superbet pentru numele și denumirile românești ale echipelor cunoscute';

    private const ROMANIAN_ALIASES = [
        'premier-league' => [],
        'la-liga' => [
            'deportivocoruna' => ['Deportivo La Coruna'],
            'fcvillareal' => ['Villarreal'],
            'espanyolbarcelona' => ['Espanyol'],
            'atleticomadrid' => ['Atletico Madrid'],
        ],
        'bundesliga' => [
            'fcbayernmunchen' => ['Bayern Munchen', 'Bayern Munich'],
            'borussiamonchengladbach' => ['Borussia Monchengladbach', 'Gladbach'],
            'fckoln' => ['FC Koln', 'FC Cologne'],
            'svwerderbremen' => ['Werder Bremen'],
            'hamburgersv' => ['Hamburger SV', 'Hamburg'],
        ],
        'champions-league' => [
            'fcbrugge' => ['Club Brugge', 'Brugge'],
            'aekathen' => ['AEK Atena', 'AEK Athens'],
            'intermailand' => ['Inter Milano', 'Inter Milan'],
            'parisstgermain' => ['Paris Saint-Germain', 'PSG'],
            'galatasarayistanbul' => ['Galatasaray'],
            'fenerbahcesk' => ['Fenerbahce'],
            'asrom' => ['AS Roma'],
            'fkbodoglimt' => ['Bodo/Glimt'],
            'sabah' => ['Sabah Baku'],
            'slaviaprag' => ['Slavia Praga', 'Slavia Prague'],
        ],
    ];

    public function handle(TeamAliasMatcher $matcher): int
    {
        $host = (string) config('database.connections.mysql.host');
        if (in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $this->error('Baza țintă este locală; aliasurile nu au fost scrise.');

            return self::FAILURE;
        }

        $created = $existing = $conflicts = 0;
        $teams = Team::orderBy('league_id')->orderBy('id')->get();
        $leagues = League::whereIn('id', $teams->pluck('league_id')->unique())->get()->keyBy('id');

        DB::transaction(function () use ($teams, $leagues, $matcher, &$created, &$existing, &$conflicts) {
            foreach ($teams as $team) {
                $league = $leagues->get($team->league_id);
                if (! $league) {
                    $conflicts++;
                    continue;
                }

                $aliases = array_filter(array_unique(array_merge(
                    [$team->name, $team->short_name],
                    self::ROMANIAN_ALIASES[$league->slug][$matcher->normalize($team->name)] ?? [],
                )));

                foreach ($aliases as $alias) {
                    $normalized = $matcher->normalize($alias);
                    if ($normalized === '') continue;

                    $prior = TeamAlias::where('normalized_alias', $normalized)->first();
                    if ($prior) {
                        if ((int) $prior->team_id === (int) $team->id) $existing++;
                        else $conflicts++;
                        continue;
                    }

                    $teamConflict = $teams->where('id', '!=', $team->id)
                        ->contains(fn (Team $other) => $matcher->normalize($other->name) === $normalized
                            || $matcher->normalize($other->short_name) === $normalized);
                    if ($teamConflict) {
                        $conflicts++;
                        continue;
                    }

                    if (! $this->option('dry-run')) {
                        TeamAlias::create([
                            'league_id' => $team->league_id,
                            'team_id' => $team->id,
                            'provider' => 'superbet',
                            'alias' => trim($alias),
                            'normalized_alias' => $normalized,
                            'status' => 'active',
                        ]);
                    }
                    $created++;
                }
            }
        });

        if (! $this->option('dry-run') && $created > 0) app(\App\Services\TeamAliasResolver::class)->forgetCache();

        $this->info(sprintf('%s: %d aliasuri Superbet noi, %d deja existente, %d conflicte ignorate, pentru %d echipe.',
            $this->option('dry-run') ? 'Simulare' : 'Import', $created, $existing, $conflicts, $teams->count()));

        return self::SUCCESS;
    }
}

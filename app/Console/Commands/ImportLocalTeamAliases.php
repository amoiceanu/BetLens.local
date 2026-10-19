<?php

namespace App\Console\Commands;

use App\Models\League;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Services\TeamAliasMatcher;
use Illuminate\Console\Command;
use PDO;
use Throwable;

class ImportLocalTeamAliases extends Command
{
    protected $signature = 'betlens:import-local-aliases {--dry-run : Show the result without writing aliases}';

    protected $description = 'Copy team aliases from the local betlens database into the configured database, matching league and team names';

    public function handle(TeamAliasMatcher $matcher): int
    {
        $targetHost = (string) config('database.connections.mysql.host');
        if (in_array($targetHost, ['127.0.0.1', 'localhost'], true)) {
            $this->error('Baza țintă este locală; importul ar citi și scrie în aceeași bază.');
            return self::FAILURE;
        }

        try {
            $local = new PDO('mysql:host=127.0.0.1;port=3306;dbname=betlens;charset=utf8mb4', 'root', getenv('BETLENS_LOCAL_DB_PASSWORD') ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $rows = $local->query('SELECT l.slug AS league_slug, t.name AS team_name, a.provider, a.alias FROM team_aliases a JOIN teams t ON t.id = a.team_id JOIN leagues l ON l.id = a.league_id ORDER BY a.id')->fetchAll();
        } catch (Throwable $e) {
            $this->error('Nu am putut citi aliasurile din baza locală: '.$e->getMessage());
            return self::FAILURE;
        }

        $leagues = League::all()->keyBy('slug');
        $teams = Team::all()->groupBy('league_id');
        $created = $existing = $unmatched = $conflicts = 0;

        foreach ($rows as $row) {
            $league = $leagues->get($row['league_slug']);
            $leagueTeams = $league ? $teams->get($league->id, collect()) : collect();
            $teamName = $matcher->normalize($row['team_name']);
            $matches = $leagueTeams->filter(fn (Team $team) => $matcher->normalize($team->name) === $teamName);
            if ($matches->count() !== 1) { $unmatched++; continue; }

            $team = $matches->first();
            $provider = $row['provider'];
            $alias = trim($row['alias']);
            $normalized = $matcher->normalize($alias);
            if (! in_array($provider, ['all', 'superbet', 'winbet'], true) || $normalized === '') { $conflicts++; continue; }
            if ($normalized === $matcher->normalize($team->name)) { $existing++; continue; }

            $prior = TeamAlias::where('league_id', $league->id)->where('provider', $provider)
                ->where('normalized_alias', $normalized)->first();
            if ($prior) {
                if ($prior->team_id === $team->id) $existing++;
                else $conflicts++;
                continue;
            }
            if ($leagueTeams->contains(fn (Team $other) => $other->id !== $team->id
                && ($matcher->normalize($other->name) === $normalized || $matcher->normalize($other->short_name) === $normalized))) {
                $conflicts++;
                continue;
            }

            if (! $this->option('dry-run')) {
                TeamAlias::create(['league_id' => $league->id, 'team_id' => $team->id, 'provider' => $provider,
                    'alias' => $alias, 'normalized_alias' => $normalized]);
            }
            $created++;
        }

        $this->info(sprintf('%s: %d aliasuri noi, %d deja existente, %d echipe negăsite, %d conflicte.',
            $this->option('dry-run') ? 'Simulare' : 'Import', $created, $existing, $unmatched, $conflicts));
        return self::SUCCESS;
    }
}

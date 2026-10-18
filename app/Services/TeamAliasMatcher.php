<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TeamAliasMatcher
{
    public function normalize(string $name): string
    {
        $name = preg_replace('/\butd\b/i', 'united', Str::ascii($name));
        return preg_replace('/[^a-z0-9]+/', '', strtolower($name));
    }

    public function aliasesFor(string $name, ?string $provider): Collection
    {
        $normalized = $this->normalize($name);
        if ($normalized === '') return collect();

        return TeamAlias::where('normalized_alias', $normalized)
            ->whereIn('provider', $provider ? ['all', $provider] : ['all', 'superbet', 'winbet'])
            ->get();
    }

    public function matches(Team $team, string $name, Collection $aliases, ?string $provider): bool
    {
        $term = $this->normalize($name);
        if ($term === '') return true;

        $inLeague = $aliases->where('league_id', $team->league_id);
        $specific = $provider ? $inLeague->where('provider', $provider) : collect();
        $explicit = $provider === null ? $inLeague : ($specific->isNotEmpty() ? $specific : $inLeague->where('provider', 'all'));
        if ($explicit->isNotEmpty()) return $explicit->contains('team_id', $team->id);

        foreach ([$team->name, $team->short_name] as $candidate) {
            if ($candidate && str_contains($this->normalize($candidate), $term)) return true;
        }

        return $provider !== null && $term === 'intermilano'
            && in_array($this->normalize($team->name), ['intermailand', 'internazionalemilano', 'fcinternazionalemilano'], true);
    }
}

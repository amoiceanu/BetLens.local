<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
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

        $query = TeamAlias::where('normalized_alias', $normalized);
        if (Schema::hasColumn((new TeamAlias())->getTable(), 'status')) {
            $query->whereIn('status', ['active', 'review']);
        }
        $aliases = $query->get();
        if ($aliases->contains('status', 'review')) $aliases->push((object) ['team_id' => 0, 'status' => 'review']);

        return $aliases;
    }

    public function matches(Team $team, string $name, Collection $aliases, ?string $provider): bool
    {
        $term = $this->normalize($name);
        if ($term === '') return true;

        $teamIds = $aliases->pluck('team_id')->map(fn ($id) => (int) $id)->unique();
        if ($teamIds->isNotEmpty()) return $teamIds->count() === 1 && $teamIds->first() === (int) $team->id;

        foreach ([$team->name, $team->short_name] as $candidate) {
            if ($candidate && str_contains($this->normalize($candidate), $term)) return true;
        }

        return false;
    }

    public function matchesPartially(Team $team, string $name, Collection $aliases, ?string $provider): bool
    {
        if ($this->matches($team, $name, $aliases, $provider)) return true;

        $term = $this->normalize($name);
        if ($term === '') return false;

        // An explicit global alias is authoritative; never fuzzy-match it to a different team.
        if ($aliases->isNotEmpty()) return false;

        $words = static function (string $value): array {
            $ascii = strtolower(Str::ascii($value));
            return array_values(array_unique(array_filter(
                preg_split('/[^a-z0-9]+/', $ascii) ?: [],
                static fn (string $word): bool => strlen($word) >= 4 && ! in_array($word, ['united', 'club'], true),
            )));
        };
        $requestedWords = $words($name);
        if ($requestedWords === []) return false;

        foreach ([$team->name, $team->short_name] as $candidate) {
            if (! $candidate) continue;
            if (array_intersect($requestedWords, $words($candidate)) !== []) return true;
        }

        return false;
    }
}

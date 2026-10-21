<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamAlias;
use Illuminate\Support\Facades\Cache;

class TeamAliasResolver
{
    private const CACHE_KEY = 'betlens.team-aliases.global-index.v1';

    public function __construct(private readonly TeamAliasMatcher $matcher) {}

    public function resolve(string $name): ?Team
    {
        $normalized = $this->matcher->normalize($name);
        if ($normalized === '') return null;
        $ids = $this->index()[$normalized] ?? [];
        if (count($ids) !== 1) return null;

        return Team::find($ids[0]);
    }

    /** @return array<int, array{team: Team, score: float}> */
    public function suggestions(string $name, int $limit = 5): array
    {
        $normalized = $this->matcher->normalize($name);
        if ($normalized === '') return [];

        $teams = Team::query()->with('league')->get();
        $scores = [];
        foreach ($teams as $team) {
            $best = 0.0;
            foreach (array_filter([$team->name, $team->short_name]) as $candidate) {
                $candidate = $this->matcher->normalize((string) $candidate);
                similar_text($normalized, $candidate, $score);
                $best = max($best, $score / 100);
            }
            if ($best >= 0.55) $scores[] = ['team' => $team, 'score' => $best];
        }

        usort($scores, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scores, 0, max(1, $limit));
        if (count($top) > 1 && abs($top[0]['score'] - $top[1]['score']) < 0.03) return [];

        return $top;
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function index(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function (): array {
            $index = [];
            foreach (Team::query()->get(['id', 'name', 'short_name']) as $team) {
                foreach (array_filter([$team->name, $team->short_name]) as $label) {
                    $key = $this->matcher->normalize((string) $label);
                    if ($key !== '') $index[$key][] = (int) $team->id;
                }
            }
            foreach (TeamAlias::query()->get(['team_id', 'normalized_alias', 'status']) as $alias) {
                $index[$alias->normalized_alias][] = (int) $alias->team_id;
                if ($alias->status === 'review') $index[$alias->normalized_alias][] = 0;
            }

            foreach ($index as $key => $ids) $index[$key] = array_values(array_unique($ids));

            return $index;
        });
    }
}

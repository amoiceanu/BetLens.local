<?php

namespace App\Services\Coverage;

use App\Models\SourceDataDiscrepancy;

class DiscrepancyQuery
{
    public function query(array $filters)
    {
        $query = SourceDataDiscrepancy::query()->with(['sourceA:id,name', 'sourceB:id,name', 'game.homeTeam:id,name', 'game.awayTeam:id,name', 'game.league:id,name']);
        if (! empty($filters['league'])) {
            $query->whereHas('game', fn ($q) => $q->where('league_id', $filters['league']));
        }
        if (! empty($filters['season'])) {
            $query->whereHas('game', fn ($q) => $q->where('season_id', $filters['season']));
        }
        if (! empty($filters['source'])) {
            $query->where(fn ($q) => $q->where('source_a_id', $filters['source'])->orWhere('source_b_id', $filters['source']));
        }
        foreach (['field', 'status', 'severity', 'game_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['q'])) {
            $query->where(function ($q) use ($filters) {
                $term = '%'.addcslashes($filters['q'], '%_\\').'%';
                $q->whereHas('sourceA', fn ($sub) => $sub->where('name', 'like', $term))->orWhereHas('sourceB', fn ($sub) => $sub->where('name', 'like', $term))->orWhereHas('game.league', fn ($sub) => $sub->where('name', 'like', $term));
                foreach (app(FieldRegistry::class)->fields() as $field => $definition) {
                    if (mb_stripos($definition['label'], $filters['q']) !== false) {
                        $q->orWhere('field', $field);
                    }
                }
            });
        }

        return $query;
    }
}

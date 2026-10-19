<?php

namespace App\Services\Coverage;

use App\Models\FootballMatch;
use App\Models\SourceDataDiscrepancy;
use Carbon\Carbon;

class DiscrepancyDetector
{
    public function refresh(int $gameId): void
    {
        $game = FootballMatch::find($gameId);
        if (! $game) {
            return;
        }
        $observations = app(ObservationProjector::class)->latest()->with('source')->where('game_id', $gameId)->orderBy('data_source_id')->get();
        $keys = [];
        $fields = app(FieldRegistry::class)->fields();
        foreach ($observations as $i => $a) {
            foreach ($observations->slice($i + 1) as $b) {
                if ($a->data_source_id === $b->data_source_id) {
                    continue;
                }
                $profileA = app(FieldRegistry::class)->profile($a->source->slug, $fields);
                $profileB = app(FieldRegistry::class)->profile($b->source->slug, $fields);
                foreach (array_intersect_key($profileA, $profileB) as $field => $definition) {
                    if (! app(FieldRegistry::class)->eligible($definition, $a->match_status) || ! app(FieldRegistry::class)->eligible($definition, $b->match_status)) {
                        continue;
                    }
                    $va = $a->values[$field] ?? null;
                    $vb = $b->values[$field] ?? null;
                    if ($va === null && $vb === null) {
                        continue;
                    }
                    $severity = $va === null || $vb === null ? 'info' : null;
                    if (! $severity) {
                        if ($field === 'kickoff_at') {
                            $different = abs(Carbon::parse($va)->diffInMinutes(Carbon::parse($vb))) > config('coverage.kickoff_tolerance_minutes');
                        } elseif (in_array($field, ['home_team', 'away_team'], true)) {
                            $different = app(ObservationProjector::class)->identity($va, $game->league_id, $a->source->slug) !== app(ObservationProjector::class)->identity($vb, $game->league_id, $b->source->slug);
                        } else {
                            $different = abs((float) $va - (float) $vb) > config('coverage.numeric_tolerance');
                        }
                        if (! $different) {
                            continue;
                        }
                        $severity = in_array($field, ['home_team', 'away_team', 'home_score', 'away_score'], true) ? 'critical' : 'warning';
                    }
                    $key = hash('sha256', "$gameId:$a->data_source_id:$b->data_source_id:$field");
                    $keys[] = $key;
                    $existing = SourceDataDiscrepancy::where('identity_key', $key)->first();
                    $changed = ! $existing || json_encode([$existing->value_a, $existing->value_b]) !== json_encode([$va, $vb]);
                    $values = ['game_id' => $gameId, 'source_a_id' => $a->data_source_id, 'source_b_id' => $b->data_source_id, 'external_a_id' => $a->external_id, 'external_b_id' => $b->external_id, 'field' => $field, 'value_a' => $va, 'value_b' => $vb, 'severity' => $severity, 'last_detected_at' => now()];
                    if (! $existing) {
                        $values += ['detected_at' => now(), 'status' => 'new'];
                    } elseif ($existing->status === 'resolved' || $changed) {
                        $values += ['status' => 'new', 'resolved_at' => null, 'reviewed_by' => null, 'reviewed_at' => null];
                    }
                    SourceDataDiscrepancy::updateOrCreate(['identity_key' => $key], $values);
                }
            }
        }
        SourceDataDiscrepancy::where('game_id', $gameId)->whereNotIn('identity_key', $keys)->where('status', '!=', 'resolved')->update(['status' => 'resolved', 'resolved_at' => now()]);
    }
}

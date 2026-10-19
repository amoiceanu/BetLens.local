<?php

namespace App\Models\Concerns;

use App\Models\FootballMatch;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasGameComparisonValues
{
    public const COMPARISON_COLUMNS = [
        'home_score', 'away_score', 'home_score_ht', 'away_score_ht', 'home_score_2h', 'away_score_2h',
        'home_corners', 'away_corners', 'home_corners_ht', 'away_corners_ht', 'home_corners_2h', 'away_corners_2h',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'game_id');
    }
}

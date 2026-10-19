<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceDataDiscrepancy extends Model
{
    protected $guarded = [];

    protected $casts = ['value_a' => 'json', 'value_b' => 'json', 'detected_at' => 'datetime', 'last_detected_at' => 'datetime', 'resolved_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function game()
    {
        return $this->belongsTo(FootballMatch::class, 'game_id');
    }

    public function sourceA()
    {
        return $this->belongsTo(DataSource::class, 'source_a_id');
    }

    public function sourceB()
    {
        return $this->belongsTo(DataSource::class, 'source_b_id');
    }
}

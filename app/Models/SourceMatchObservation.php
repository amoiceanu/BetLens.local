<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceMatchObservation extends Model
{
    protected $guarded = [];

    protected $casts = ['values' => 'array', 'field_observed_at' => 'array', 'observed_at' => 'datetime'];

    public function source()
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function game()
    {
        return $this->belongsTo(FootballMatch::class, 'game_id');
    }
}

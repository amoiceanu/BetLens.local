<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceCoverageCell extends Model
{
    protected $guarded = [];

    protected $casts = ['available_counts' => 'array', 'eligible_counts' => 'array', 'last_observation_at' => 'datetime'];
}

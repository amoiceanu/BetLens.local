<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamStatistic extends Model
{
    protected $guarded = [];
    protected $casts = ['recent_form' => 'array', 'xg' => 'float', 'xga' => 'float'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Odd extends Model
{
    protected $guarded = [];
    protected $casts = ['price' => 'float', 'captured_at' => 'datetime'];

    public function match() { return $this->belongsTo(FootballMatch::class, 'match_id'); }
    public function market() { return $this->belongsTo(Market::class); }
}

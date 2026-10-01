<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderMatchMapping extends Model
{
    protected $guarded = [];
    protected $casts = ['kickoff_at' => 'datetime'];

    public function match() { return $this->belongsTo(FootballMatch::class, 'match_id'); }
}

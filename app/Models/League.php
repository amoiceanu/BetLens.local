<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class League extends Model { protected $guarded=[]; protected $casts=['active'=>'boolean']; public function teams(){return $this->hasMany(Team::class);} public function matches(){return $this->hasMany(FootballMatch::class);} }

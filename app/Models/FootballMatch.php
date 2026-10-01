<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FootballMatch extends Model { protected $table='matches'; protected $guarded=[]; protected $casts=['kickoff_at'=>'datetime']; public function league(){return $this->belongsTo(League::class);} public function homeTeam(){return $this->belongsTo(Team::class,'home_team_id');} public function awayTeam(){return $this->belongsTo(Team::class,'away_team_id');} public function recommendations(){return $this->hasMany(Recommendation::class,'match_id');} public function odds(){return $this->hasMany(Odd::class,'match_id');} public function providerMappings(){return $this->hasMany(ProviderMatchMapping::class,'match_id');} }

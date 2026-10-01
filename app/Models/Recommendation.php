<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Recommendation extends Model { protected $guarded=[]; protected $casts=['factors'=>'array','eligible'=>'boolean','model_probability'=>'float','implied_probability'=>'float','value'=>'float','odds'=>'float']; public function match(){return $this->belongsTo(FootballMatch::class,'match_id');} public function market(){return $this->belongsTo(Market::class);} }

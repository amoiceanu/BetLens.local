<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class GeneratedTicket extends Model { protected $guarded=[]; protected $casts=['total_odds'=>'float','combined_probability'=>'float','stake'=>'float']; public function selections(){return $this->hasMany(GeneratedTicketSelection::class);} public function league(){return $this->belongsTo(League::class);} }

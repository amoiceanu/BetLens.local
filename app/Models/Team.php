<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Team extends Model { protected $guarded=[]; public function league(){return $this->belongsTo(League::class);} }

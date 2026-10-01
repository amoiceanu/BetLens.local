<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class GeneratedTicketSelection extends Model { protected $guarded=[]; public function recommendation(){return $this->belongsTo(Recommendation::class);} }

<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Season extends Model { protected $guarded=[]; protected $casts=['starts_at'=>'date','ends_at'=>'date','current'=>'boolean']; }

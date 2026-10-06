<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ExternalIdMapping extends Model { protected $guarded=[]; protected $casts=['metadata'=>'array','last_verified_at'=>'datetime']; }

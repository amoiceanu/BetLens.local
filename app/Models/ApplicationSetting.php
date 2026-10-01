<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApplicationSetting extends Model { protected $guarded=[]; protected $casts=['value'=>'array']; }

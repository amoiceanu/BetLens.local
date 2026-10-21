<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApplicationSetting extends Model { protected $guarded=[]; protected $casts=['value'=>'array']; public function getConnectionName(){return config('database.default')==='mysql'&&config('database.connections.mysql.prefix')==='st_'?'mysql_auth':parent::getConnectionName();} }

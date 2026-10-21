<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Operator extends Model
{
    protected $guarded=[];
    protected $casts=['active'=>'boolean'];

    public function getConnectionName()
    {
        return config('database.default') === 'mysql' && config('database.connections.mysql.prefix') === 'st_'
            ? 'mysql_auth'
            : parent::getConnectionName();
    }
}

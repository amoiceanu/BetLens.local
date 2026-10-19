<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSourceLevel extends Model
{
    protected $guarded = [];

    public function source() { return $this->belongsTo(DataSource::class, 'data_source_id'); }
}

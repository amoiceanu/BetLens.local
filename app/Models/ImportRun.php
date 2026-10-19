<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRun extends Model
{
    protected $guarded = [];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime', 'metadata' => 'array'];

    public function source() { return $this->belongsTo(DataSource::class, 'data_source_id'); }
    public function league() { return $this->belongsTo(League::class); }
    public function season() { return $this->belongsTo(Season::class); }
    public function documents() { return $this->hasMany(ImportDocument::class); }
}

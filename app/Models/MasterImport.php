<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterImport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'items' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function currentRun()
    {
        return $this->belongsTo(ImportRun::class, 'current_import_run_id');
    }
}

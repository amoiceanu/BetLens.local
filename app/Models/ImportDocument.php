<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportDocument extends Model
{
    protected $guarded = [];

    public function run() { return $this->belongsTo(ImportRun::class, 'import_run_id'); }
}

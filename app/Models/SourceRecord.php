<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SourceRecord extends Model
{
    protected $guarded=[];
    protected $casts=['payload'=>'array','first_seen_at'=>'datetime','last_seen_at'=>'datetime'];
    public function source(){return $this->belongsTo(DataSource::class,'data_source_id');}
}

<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DataSource extends Model
{
    protected $guarded=[];
    protected $casts=['last_checked_at'=>'datetime','active'=>'boolean'];
    public function getStatusLabelAttribute(): string { return ['healthy'=>'Disponibilă','degraded'=>'Răspuns neașteptat','unavailable'=>'Indisponibilă','not_configured'=>'Neconfigurată','checking'=>'În verificare','never_checked'=>'Neverificată'][$this->status] ?? 'Necunoscută'; }
    public function records(){return $this->hasMany(SourceRecord::class);}
}

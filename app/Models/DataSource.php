<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DataSource extends Model
{
    protected $guarded=[];
    protected $casts=['last_checked_at'=>'datetime','active'=>'boolean','metadata'=>'array'];
    public function getStatusLabelAttribute(): string { return ['healthy'=>'Disponibilă','degraded'=>'Răspuns neașteptat','unavailable'=>'Indisponibilă','not_configured'=>'Neconfigurată','checking'=>'În verificare','never_checked'=>'Neverificată'][$this->status] ?? 'Necunoscută'; }
    public function getIsConfiguredAttribute(): bool { return !$this->credential_env || filled($this->credentialValue()); }
    public function credentialValue(): ?string { return match($this->credential_env) { 'FOOTBALL_DATA_API_KEY'=>config('services.football_data.key'), 'API_FOOTBALL_KEY'=>config('services.api_football.key'), 'ODDS_API_KEY'=>config('services.odds_api.key'), 'SPORTMONKS_API_TOKEN'=>config('services.sportmonks.token'), default=>null }; }
    public function records(){return $this->hasMany(SourceRecord::class);}
}

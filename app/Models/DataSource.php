<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DataSource extends Model
{
    protected $guarded=[];
    protected $casts=['last_checked_at'=>'datetime','last_manual_verification_at'=>'datetime','active'=>'boolean','import_enabled'=>'boolean','request_timeout'=>'integer','metadata'=>'array','verification_progress'=>'array','credential_secret'=>'encrypted'];
    protected $hidden=['credential_secret'];
    public function getStatusLabelAttribute(): string { return ['healthy'=>'Disponibilă','degraded'=>'Răspuns neașteptat','unavailable'=>'Indisponibilă','not_configured'=>'Neconfigurată','checking'=>'În verificare','never_checked'=>'Neverificată'][$this->status] ?? 'Necunoscută'; }
    public function getIsConfiguredAttribute(): bool { return !$this->credential_env || filled($this->credentialValue()); }
    public function getIsVerificationConfiguredAttribute(): bool { return $this->import_method === 'existing' && $this->is_configured && filled($this->verification_url); }
    public function getIsVerificationCoolingDownAttribute(): bool { return $this->last_manual_verification_at?->gt(now()->subMinutes(5)) ?? false; }
    public function getDataFormatAttribute(): string
    {
        return match ($this->import_method) {
            'manual_json' => 'JSON',
            'manual_csv' => 'CSV',
            'internal_model' => 'Calcul intern',
            'unsupported' => 'Nespecificat',
            default => match ($this->slug) {
                'football-data-uk' => 'CSV',
                'understat' => 'HTML',
                'api-football', 'football-data', 'openligadb', 'statsbomb-open-data',
                'sportmonks', 'open-meteo', 'the-odds-api', 'odds-api-io-superbet',
                'oddspapi-superbet' => 'JSON',
                default => 'Nespecificat',
            },
        };
    }
    public function credentialValue(): ?string { return $this->credential_secret ?: match($this->credential_env) { 'FOOTBALL_DATA_API_KEY'=>config('services.football_data.key'), 'API_FOOTBALL_KEY'=>config('services.api_football.key'), 'ODDS_API_KEY'=>config('services.odds_api.key'), 'ODDS_API_IO_KEY'=>config('services.odds_api_io.key'), 'ODDSPAPI_API_KEY'=>config('services.oddspapi.key'), 'SPORTMONKS_API_TOKEN'=>config('services.sportmonks.token'), default=>null }; }
    public function records(){return $this->hasMany(SourceRecord::class);}
    public function levels(){return $this->hasMany(DataSourceLevel::class);}
    public function importRuns(){return $this->hasMany(ImportRun::class);}
}

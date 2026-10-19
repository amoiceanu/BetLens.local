<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportedTicket extends Model
{
    protected $guarded = [];

    protected $casts = ['placed_at' => 'datetime', 'total_odds' => 'float', 'stake' => 'float', 'payout' => 'float'];

    public function selections()
    {
        return $this->hasMany(ImportedTicketSelection::class)->orderBy('position');
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {'won' => 'Câștigat', 'lost' => 'Pierdut', 'pending' => 'În așteptare', 'void' => 'Anulat', default => 'Necunoscut'};
    }

    public function getEffectiveFirstMatchAtAttribute()
    {
        return $this->selections->min('kickoff_at');
    }

    public function getEffectiveLastMatchAtAttribute()
    {
        return $this->selections->max('kickoff_at');
    }
}

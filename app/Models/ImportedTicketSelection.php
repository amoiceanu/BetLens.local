<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportedTicketSelection extends Model
{
    protected $guarded = [];

    protected $casts = ['kickoff_at' => 'datetime', 'odds' => 'float', 'details' => 'array'];

    public function ticket()
    {
        return $this->belongsTo(ImportedTicket::class, 'imported_ticket_id');
    }
}

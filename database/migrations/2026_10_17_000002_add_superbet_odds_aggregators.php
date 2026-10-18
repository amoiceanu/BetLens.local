<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        DB::table('data_sources')->upsert([
            [
                'name' => 'Odds-API.io (Superbet)',
                'slug' => 'odds-api-io-superbet',
                'category' => 'Cote Superbet, sursă secundară',
                'description' => 'Flux de cote Superbet prin Odds-API.io. Nu oferă acces la biletele personale.',
                'website_url' => 'https://odds-api.io/integrations/superbet',
                'verification_url' => 'https://api.odds-api.io/v3/events',
                'credential_env' => 'ODDS_API_IO_KEY',
                'status' => 'not_configured',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'OddsPapi (Superbet RO)',
                'slug' => 'oddspapi-superbet',
                'category' => 'Cote Superbet, sursă secundară',
                'description' => 'Flux de cote Superbet RO prin OddsPapi. Nu oferă acces la biletele personale.',
                'website_url' => 'https://oddspapi.io/sportsbooks/superbet-ro',
                'verification_url' => 'https://api.oddspapi.io/v4/tournaments',
                'credential_env' => 'ODDSPAPI_API_KEY',
                'status' => 'not_configured',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'category', 'description', 'website_url', 'verification_url', 'credential_env', 'active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('data_sources')->whereIn('slug', ['odds-api-io-superbet', 'oddspapi-superbet'])->delete();
    }
};

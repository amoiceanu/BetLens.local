<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DropLegacyUnprefixedTables extends Command
{
    protected $signature = 'betlens:drop-legacy-tables {--force : Șterge definitiv tabelele vechi fără prefix după verificarea celor st_*}';

    protected $description = 'Elimină tabelele BetLens fără prefix st_ după ce schema prefixed este instalată.';

    private const TABLES = [
        'data_sync_logs', 'leagues', 'teams', 'seasons', 'matches',
        'match_results', 'markets', 'odds', 'odds_snapshots', 'team_statistics', 'player_absences',
        'recommendations', 'generated_tickets', 'generated_ticket_selections', 'user_ticket_results',
        'data_sources', 'source_records', 'provider_match_mappings', 'external_id_mappings',
        'match_weather_snapshots', 'import_audits', 'team_aliases', 'imported_tickets',
        'imported_ticket_selections', 'data_source_levels', 'data_source_competitions', 'import_runs',
        'import_documents', 'source_field_mappings', 'game_actual_results', 'betlens_predictions',
        'external_platform_predictions', 'source_match_observations', 'source_coverage_cells',
        'source_data_discrepancies',
    ];

    public function handle(): int
    {
        $connection = DB::connection();
        $host = strtolower((string) config('database.connections.mysql.host'));
        $database = (string) $connection->getDatabaseName();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            || ! str_ends_with($host, '.ondigitalocean.com')
            || $database !== 'BetLens-Prod'
            || $connection->getTablePrefix() !== 'st_') {
            $this->error('Siguranță: comanda acceptă doar BetLens-Prod de pe DigitalOcean cu prefixul activ st_. Nu s-a șters nimic.');

            return self::FAILURE;
        }

        $existing = collect($connection->select(
            'SELECT TABLE_NAME AS tbl FROM information_schema.tables WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$database, 'BASE TABLE'],
        ))->pluck('tbl')->all();

        $legacy = array_values(array_filter(self::TABLES, fn (string $table) => in_array($table, $existing, true)));
        $missing = array_values(array_filter($legacy, fn (string $table) => ! in_array('st_'.$table, $existing, true)));
        if ($missing !== []) {
            $this->error('Schema st_ nu este completă. Lipsesc tabele prefixate pentru: '.implode(', ', $missing).'. Nu s-a șters nimic.');

            return self::FAILURE;
        }

        if ($legacy === []) {
            $this->info('Nu există tabele BetLens neprefixate de eliminat.');

            return self::SUCCESS;
        }

        $this->line('Tabele neprefixate care vor fi eliminate:');
        foreach ($legacy as $table) $this->line(' - '.$table);

        if (! $this->option('force')) {
            $this->warn('Doar previzualizare. După ce verifici schema st_, rulează din nou cu --force pentru ștergere definitivă.');

            return self::SUCCESS;
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($legacy as $table) {
                if (! preg_match('/^[a-z0-9_]+$/', $table)) throw new RuntimeException('Nume de tabel nevalid.');
                $connection->statement('DROP TABLE `'.$table.'`');
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->info('Au fost eliminate '.count($legacy).' tabele vechi fără prefix. Tabelele st_ au rămas intacte.');

        return self::SUCCESS;
    }
}

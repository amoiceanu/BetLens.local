<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $systemTables = ['password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    public function up(): void
    {
        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            || $connection->getTablePrefix() !== 'st_') {
            return;
        }

        $existing = $this->existingTables($connection);
        foreach ($this->systemTables as $table) {
            if (in_array('st_'.$table, $existing, true) && in_array($table, $existing, true)) {
                throw new RuntimeException("Both {$table} and st_{$table} exist; refusing to overwrite either table.");
            }
        }

        foreach ($this->systemTables as $table) {
            if (in_array('st_'.$table, $existing, true)) {
                $connection->statement('RENAME TABLE `st_'.$table.'` TO `'.$table.'`');
            }
        }

        // Prepare the normal Laravel migrations table for the post-migration config switch.
        if (in_array('st_migrations', $existing, true) && ! in_array('migrations', $existing, true)) {
            $connection->statement('CREATE TABLE `migrations` LIKE `st_migrations`');
            $connection->statement('INSERT INTO `migrations` SELECT * FROM `st_migrations`');
            $currentMigration = '2026_10_20_000004_move_framework_tables_out_of_import_prefix';
            $connection->statement(
                'INSERT INTO `migrations` (`migration`, `batch`) SELECT ?, COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`',
                [$currentMigration],
            );
        }
    }

    public function down(): void
    {
        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            || $connection->getTablePrefix() !== 'st_') {
            return;
        }

        $existing = $this->existingTables($connection);
        foreach (array_reverse($this->systemTables) as $table) {
            if (in_array($table, $existing, true) && ! in_array('st_'.$table, $existing, true)) {
                $connection->statement('RENAME TABLE `'.$table.'` TO `st_'.$table.'`');
            }
        }
    }

    private function existingTables($connection): array
    {
        return collect($connection->select(
            'SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE TABLE_SCHEMA = ?',
            [$connection->getDatabaseName()],
        ))->pluck('table_name')->all();
    }
};

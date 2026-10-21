<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $tables = ['application_settings', 'operators'];

    public function up(): void
    {
        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            || $connection->getTablePrefix() !== 'st_') {
            return;
        }

        $existing = $this->existingTables($connection);
        foreach ($this->tables as $table) {
            if (in_array('st_'.$table, $existing, true) && ! in_array($table, $existing, true)) {
                $connection->statement('RENAME TABLE `st_'.$table.'` TO `'.$table.'`');
            } elseif (in_array('st_'.$table, $existing, true) && in_array($table, $existing, true)) {
                throw new RuntimeException("Both {$table} and st_{$table} exist; refusing to overwrite either table.");
            }
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
        foreach ($this->tables as $table) {
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            || $connection->getTablePrefix() !== 'st_') {
            return;
        }

        $database = $connection->getDatabaseName();
        $tables = collect($connection->select(
            'SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE TABLE_SCHEMA = ?',
            [$database],
        ))->pluck('table_name')->all();

        if (in_array('st_users', $tables, true) && ! in_array('users', $tables, true)) {
            // MySQL updates existing foreign-key references when the referenced table is renamed.
            $connection->statement('RENAME TABLE `st_users` TO `users`');
        }
    }

    public function down(): void
    {
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            || $connection->getTablePrefix() !== 'st_') {
            return;
        }

        $database = $connection->getDatabaseName();
        $tables = collect($connection->select(
            'SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE TABLE_SCHEMA = ?',
            [$database],
        ))->pluck('table_name')->all();

        if (in_array('users', $tables, true) && ! in_array('st_users', $tables, true)) {
            $connection->statement('RENAME TABLE `users` TO `st_users`');
        }
    }
};

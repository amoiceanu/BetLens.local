<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseConnectionStatus
{
    public static function current(): array
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();
            $driver = $connection->getDriverName();
            $database = $driver === 'mysql' || $driver === 'mariadb'
                ? $connection->selectOne('SELECT DATABASE() AS name')?->name
                : $connection->getDatabaseName();
            $host = (string) $connection->getConfig('host');
            $isDigitalOcean = strcasecmp($host, 'expona-do-user-6467325-0.i.db.ondigitalocean.com') === 0
                && strcasecmp((string) $database, 'BetLens-Prod') === 0;

            return [
                'online' => $isDigitalOcean,
                'label' => $isDigitalOcean
                    ? 'DigitalOcean · BetLens-Prod'
                    : 'Altă bază · '.($database ?: 'necunoscută'),
            ];
        } catch (Throwable) {
            return ['online' => false, 'label' => 'Conexiune indisponibilă'];
        }
    }
}

<?php

namespace App\Database;

use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Schema\Blueprint;

class UnprefixedMigrationRepository extends DatabaseMigrationRepository
{
    private function usesSystemConnection(): bool
    {
        return config('database.default') === 'mysql'
            && config('database.connections.mysql.prefix') === 'st_'
            && str_ends_with(strtolower((string) config('database.connections.mysql.host')), '.ondigitalocean.com');
    }

    protected function table()
    {
        return $this->usesSystemConnection()
            ? $this->resolver->connection('mysql_auth')->table($this->table)->useWritePdo()
            : parent::table();
    }

    public function repositoryExists()
    {
        return $this->usesSystemConnection()
            ? $this->resolver->connection('mysql_auth')->getSchemaBuilder()->hasTable($this->table)
            : parent::repositoryExists();
    }

    public function createRepository()
    {
        if (! $this->usesSystemConnection()) {
            parent::createRepository();

            return;
        }

        $this->resolver->connection('mysql_auth')->getSchemaBuilder()->create($this->table, function (Blueprint $table) {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
    }

    public function deleteRepository()
    {
        if ($this->usesSystemConnection()) {
            $this->resolver->connection('mysql_auth')->getSchemaBuilder()->drop($this->table);

            return;
        }

        parent::deleteRepository();
    }

    public function setSource($name)
    {
        parent::setSource($this->usesSystemConnection() ? 'mysql_auth' : $name);
    }
}

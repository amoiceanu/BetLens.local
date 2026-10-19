<?php

namespace App\Services\Coverage;

use Illuminate\Support\Facades\Schema;

class FieldRegistry
{
    private ?array $supported = null;

    public function fields(): array
    {
        if ($this->supported !== null) {
            return $this->supported;
        }
        $columns = [];
        foreach (array_unique(array_column(config('coverage.fields'), 'storage')) as $table) {
            $columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        }

        return $this->supported = array_filter(config('coverage.fields'), fn ($field) => in_array($field['column'], $columns[$field['storage']] ?? [], true));
    }

    public function profile(string $provider, ?array $fields = null): array
    {
        return array_filter($fields ?? $this->fields(), fn ($field) => ($provider === 'open-meteo') === ($field['weather'] ?? false));
    }

    public function eligible(array $field, ?string $status): bool
    {
        return ! ($field['finished'] ?? false) || $status === 'finished';
    }

    public function ready(): bool
    {
        return Schema::hasTable('source_match_observations') && Schema::hasTable('source_coverage_cells') && Schema::hasTable('source_data_discrepancies') && Schema::hasColumn('import_audits', 'finished_at');
    }
}

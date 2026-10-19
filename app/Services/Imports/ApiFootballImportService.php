<?php

namespace App\Services\Imports;

use App\Models\DataSource;
use App\Models\SourceRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class ApiFootballImportService
{
    public function import(DataSource $source, array $items): array
    {
        if ($source->slug !== 'api-football') throw new RuntimeException('Sursa importului API-Football este invalidă.');
        $counts = ['received' => count($items), 'identified' => 0, 'competitions_saved' => 0,
            'seasons_saved' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];

        DB::transaction(function () use ($source, $items, &$counts) {
            foreach ($items as $item) {
                if (! is_array($item)) { $counts['failed']++; continue; }
                $country = $item['country'] ?? [];
                if (! is_array($country) || (strtoupper((string) ($country['code'] ?? '')) !== 'RO'
                    && Str::ascii(mb_strtolower((string) ($country['name'] ?? ''))) !== 'romania')) continue;
                $counts['identified']++;
                $validator = Validator::make($item, [
                    'country.name' => ['required', 'string', 'max:100'],
                    'country.code' => ['nullable', 'string', 'size:2'],
                    'league.id' => ['required', 'integer', 'min:1'],
                    'league.name' => ['required', 'string', 'max:255'],
                    'league.type' => ['required', 'string', 'max:80'],
                    'league.logo' => ['nullable', 'url', 'max:2048'],
                    'seasons' => ['required', 'array'],
                    'seasons.*.year' => ['required', 'integer', 'between:1900,2200'],
                    'seasons.*.start' => ['required', 'date_format:Y-m-d'],
                    'seasons.*.end' => ['required', 'date_format:Y-m-d'],
                    'seasons.*.current' => ['required', 'boolean'],
                    'seasons.*.coverage' => ['nullable', 'array'],
                ]);
                if ($validator->fails()) { $counts['failed']++; continue; }
                $league = $item['league'];
                $externalId = (string) $league['id'];
                $competition = $this->upsert($source, 'api_football_competition', 'competition:'.$externalId, [
                    'provider' => 'api-football', 'external_id' => $externalId,
                    'name' => $league['name'], 'type' => $league['type'],
                    'country_name' => $country['name'], 'country_code' => strtoupper($country['code'] ?? 'RO'),
                    'logo_url' => $league['logo'] ?? null,
                ], null, $counts);
                $counts['competitions_saved']++;

                foreach ($item['seasons'] as $season) {
                    $this->upsert($source, 'api_football_season', 'season:'.$externalId.':'.$season['year'], [
                        'provider' => 'api-football', 'competition_external_id' => $externalId,
                        'year' => (int) $season['year'], 'starts_at' => $season['start'],
                        'ends_at' => $season['end'], 'current' => (bool) $season['current'],
                        'coverage' => $season['coverage'] ?? [],
                    ], $competition->id, $counts);
                    $counts['seasons_saved']++;
                }
            }
            if ($counts['identified'] === 0) {
                throw new RuntimeException('Răspunsul nu conține competiții din România; importul a fost oprit.');
            }
        });

        return $counts;
    }

    private function upsert(DataSource $source, string $type, string $key, array $payload, ?int $parentId, array &$counts): SourceRecord
    {
        $checksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $record = SourceRecord::where('data_source_id', $source->id)->where('external_key', $key)->first();
        if (! $record) {
            $record = SourceRecord::create(['data_source_id' => $source->id, 'external_key' => $key,
                'record_type' => $type, 'parent_source_record_id' => $parentId,
                'payload' => $payload, 'checksum' => $checksum, 'first_seen_at' => now(), 'last_seen_at' => now()]);
            $counts['new']++;
        } elseif ($record->checksum !== $checksum || $record->parent_source_record_id !== $parentId) {
            $record->update(['record_type' => $type, 'parent_source_record_id' => $parentId,
                'payload' => $payload, 'checksum' => $checksum, 'last_seen_at' => now()]);
            $counts['updated']++;
        } else {
            $record->update(['last_seen_at' => now()]);
            $counts['unchanged']++;
        }
        return $record;
    }
}

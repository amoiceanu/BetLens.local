<?php

namespace App\Services\Imports;

use App\Models\DataSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class ApiFootballImportService
{
    private const WRITE_BATCH_SIZE = 200;

    public function import(DataSource $source, array $items, ?callable $checkpoint = null, int $cursor = 0, array $savedCounts = []): array
    {
        if ($source->slug !== 'api-football') throw new RuntimeException('Sursa importului API-Football este invalidă.');
        $counts = ['received' => count($items), 'identified' => 0, 'competitions_saved' => 0,
            'seasons_saved' => 0, 'new' => (int)($savedCounts['new']??0), 'updated' => (int)($savedCounts['updated']??0),
            'unchanged' => (int)($savedCounts['unchanged']??0), 'failed' => 0];

        $prepared = [];
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
            $competitionKey = 'competition:'.$externalId;
            $competitionPayload = [
                'provider' => 'api-football', 'external_id' => $externalId,
                'name' => $league['name'], 'type' => $league['type'],
                'country_name' => $country['name'], 'country_code' => strtoupper($country['code'] ?? 'RO'),
                'logo_url' => $league['logo'] ?? null,
            ];
            $prepared[$competitionKey] = ['type'=>'api_football_competition','payload'=>$competitionPayload,'parent_id'=>null];
            $counts['competitions_saved']++;

            foreach ($item['seasons'] as $season) {
                $seasonKey = 'season:'.$externalId.':'.$season['year'];
                $seasonPayload = [
                    'provider' => 'api-football', 'competition_external_id' => $externalId,
                    'year' => (int) $season['year'], 'starts_at' => $season['start'],
                    'ends_at' => $season['end'], 'current' => (bool) $season['current'],
                    'coverage' => $season['coverage'] ?? [],
                ];
                $prepared[$seasonKey] = ['type'=>'api_football_season','payload'=>$seasonPayload,'parent_key'=>$competitionKey];
                $counts['seasons_saved']++;
            }
        }
        if ($counts['identified'] === 0) throw new RuntimeException('Răspunsul nu conține competiții din România; importul a fost oprit.');
        $competitions = array_filter($prepared, fn ($record) => $record['type'] === 'api_football_competition');
        $seasons = array_filter($prepared, fn ($record) => $record['type'] === 'api_football_season');
        $ordered = array_merge($competitions, $seasons);
        $total = count($ordered);
        $cursor = min($total, max(0, $cursor));
        $written = $cursor;
        $competitionCount = count($competitions);
        if ($cursor < $competitionCount) {
            foreach (array_chunk(array_slice($competitions, $cursor, null, true), self::WRITE_BATCH_SIZE, true) as $batch) {
                DB::transaction(function () use ($source, $batch, &$counts): void { $this->writeBatch($source, $batch, $counts); });
                $written += count($batch);
                $checkpoint && $checkpoint($written, $total, $counts);
            }
        }
        $seasonCursor = max(0, $cursor - $competitionCount);
        foreach (array_chunk(array_slice($seasons, $seasonCursor, null, true), self::WRITE_BATCH_SIZE, true) as $batch) {
            DB::transaction(function () use ($source, $batch, &$counts): void { $this->writeBatch($source, $batch, $counts); });
            $written += count($batch);
            $checkpoint && $checkpoint($written, $total, $counts);
        }

        return $counts + ['write_cursor' => $written, 'write_total' => $total];
    }

    private function writeBatch(DataSource $source, array $batch, array &$counts): void
    {
                $keys = array_keys($batch);
                $existing = DB::table('source_records')->where('data_source_id', $source->id)->whereIn('external_key', $keys)->get(['id','external_key','checksum','parent_source_record_id'])->keyBy('external_key');
                $rows=[];$now=now();
                foreach($batch as $key=>$record){
                    $checksum=hash('sha256',json_encode($record['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                    $parentKey=$record['parent_key']??null;
                    $parentId=$record['parent_id']??($parentKey?DB::table('source_records')->where('data_source_id',$source->id)->where('external_key',$parentKey)->value('id'):null);
                    $old=$existing->get($key);
                    if(!$old)$counts['new']++;
                    elseif($old->checksum!==$checksum||(int)$old->parent_source_record_id!==(int)$parentId)$counts['updated']++;
                    else $counts['unchanged']++;
                    $rows[]=['data_source_id'=>$source->id,'external_key'=>$key,'record_type'=>$record['type'],'parent_source_record_id'=>$parentId,'payload'=>json_encode($record['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'checksum'=>$checksum,'first_seen_at'=>$now,'last_seen_at'=>$now,'created_at'=>$now,'updated_at'=>$now];
                }
                DB::table('source_records')->upsert($rows,['data_source_id','external_key'],['record_type','parent_source_record_id','payload','checksum','last_seen_at','updated_at']);
    }

}

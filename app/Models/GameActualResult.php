<?php

namespace App\Models;

use App\Models\Concerns\HasGameComparisonValues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class GameActualResult extends Model
{
    use HasGameComparisonValues;

    protected $guarded = [];

    protected function casts(): array
    {
        return array_fill_keys(self::COMPARISON_COLUMNS, 'integer') + ['result_updated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $result) {
            $rules = [
                'game_id' => ['required', 'integer', 'exists:matches,id'],
                'match_status' => ['sometimes', 'required', 'string', 'max:255'],
                'result_updated_at' => ['nullable', 'date'],
            ];
            foreach (self::COMPARISON_COLUMNS as $field) {
                $rules[$field] = ['nullable', 'integer', 'min:0', 'max:4294967295'];
            }
            Validator::make($result->getAttributes(), $rules)->validate();
        });
    }
}

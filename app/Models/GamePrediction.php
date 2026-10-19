<?php

namespace App\Models;

use App\Models\Concerns\HasGameComparisonValues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

abstract class GamePrediction extends Model
{
    use HasGameComparisonValues;

    public const PROBABILITY_COLUMNS = ['home_win_probability', 'draw_probability', 'away_win_probability'];

    public const ODDS_COLUMNS = ['home_win_odds', 'draw_odds', 'away_win_odds'];

    protected $guarded = [];

    protected function casts(): array
    {
        return array_fill_keys(self::COMPARISON_COLUMNS, 'decimal:2')
            + array_fill_keys(self::PROBABILITY_COLUMNS, 'decimal:6')
            + array_fill_keys(self::ODDS_COLUMNS, 'decimal:4')
            + ['predicted_at' => 'datetime'];
    }

    public static function rules(): array
    {
        $rules = ['game_id' => ['required', 'integer', 'exists:matches,id'], 'predicted_at' => ['required', 'date']];
        foreach (self::COMPARISON_COLUMNS as $field) {
            $rules[$field] = ['nullable', 'numeric', 'between:0,99999.99'];
        }
        foreach (self::PROBABILITY_COLUMNS as $field) {
            $rules[$field] = ['nullable', 'numeric', 'between:0,1'];
        }
        foreach (self::ODDS_COLUMNS as $field) {
            $rules[$field] = ['nullable', 'numeric', 'between:0,999999.9999'];
        }

        return $rules;
    }

    protected static function booted(): void
    {
        static::saving(function (self $prediction) {
            Validator::make($prediction->getAttributes(), static::rules())->validate();
            if ($prediction->exists && $prediction->isDirty()) {
                $finished = FootballMatch::whereKey($prediction->getRawOriginal('game_id'))
                    ->where(fn ($query) => $query->where('status', 'finished')->orWhereHas('actualResult', fn ($result) => $result->where('match_status', 'finished')))
                    ->exists();
                if ($finished) {
                    throw ValidationException::withMessages(['prediction' => 'Predicțiile existente nu pot fi modificate după încheierea meciului. Salvează o înregistrare separată pentru istoricul preluat.']);
                }
            }
        });
    }
}

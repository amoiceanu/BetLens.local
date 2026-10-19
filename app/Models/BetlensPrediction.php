<?php

namespace App\Models;

class BetlensPrediction extends GamePrediction
{
    public static function rules(): array
    {
        return parent::rules() + ['model_version' => ['nullable', 'string', 'max:255']];
    }
}

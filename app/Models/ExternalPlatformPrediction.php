<?php

namespace App\Models;

class ExternalPlatformPrediction extends GamePrediction
{
    public static function rules(): array
    {
        return parent::rules() + [
            'platform_name' => ['required', 'string', 'max:255'],
            'source_prediction_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}

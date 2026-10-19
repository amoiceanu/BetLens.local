<?php

namespace App\Services\Coverage;

use Illuminate\Support\Arr;

class SafeImportMessage
{
    public function clean(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }
        foreach (Arr::dot(config('services', [])) as $key => $value) {
            if (is_string($value) && $value !== '' && preg_match('/(key|token|password|secret)/i', $key)) {
                $message = str_replace($value, '[secret eliminat]', $message);
            }
        }
        $message = preg_replace('~https?://[^\s<>"\']+~i', '[URL eliminat]', $message);
        $message = preg_replace('/\b(authorization|api[_-]?key|api[_-]?token|access[_-]?token|token|password|secret)["\']?\s*[=:]\s*["\']?(?:(?:Bearer|Basic)\s+)?[^\s,;"\']+/i', '$1=[secret eliminat]', $message);
        $message = preg_replace('/\b(?:Bearer|Basic)\s+\S+/i', '[autentificare eliminată]', $message);

        return mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $message), 0, 1000);
    }
}

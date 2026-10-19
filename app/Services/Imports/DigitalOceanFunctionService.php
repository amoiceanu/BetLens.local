<?php

namespace App\Services\Imports;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DigitalOceanFunctionService
{
    public function configured(string $function): bool
    {
        $config = config("services.digitalocean_functions.{$function}", []);
        return filled($config['url'] ?? null) && filled($config['token'] ?? null)
            && str_starts_with((string) $config['url'], 'https://');
    }

    public function fetch(string $function): array
    {
        if (! $this->configured($function)) throw new RuntimeException('Funcția DigitalOcean nu are URL HTTPS și token configurate.');
        $config = config("services.digitalocean_functions.{$function}");
        $timeout = max(5, min(120, (int) ($config['timeout'] ?? 30)));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::acceptJson()->withHeaders(['X-Require-Whisk-Auth' => $config['token']])
                    ->connectTimeout(min($timeout, 10))->timeout($timeout)->post($config['url'], []);
            } catch (ConnectionException $e) {
                if ($attempt < 3) { usleep(250000 * (2 ** ($attempt - 1))); continue; }
                Log::warning('DigitalOcean Function connection failed', ['function' => $function, 'attempts' => $attempt]);
                throw new RuntimeException('Funcția DigitalOcean nu a răspuns în intervalul configurat.', 0, $e);
            }

            if (in_array($response->status(), [429, 502, 503, 504], true) && $attempt < 3) {
                usleep(250000 * (2 ** ($attempt - 1)));
                continue;
            }
            if (! $response->successful()) {
                Log::warning('DigitalOcean Function HTTP failure', ['function' => $function, 'status' => $response->status()]);
                throw new RuntimeException('Funcția DigitalOcean a răspuns cu HTTP '.$response->status().'.');
            }

            try {
                $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
                for ($depth = 0; $depth < 5; $depth++) {
                    if (! is_array($payload)) break;
                    if (isset($payload['statusCode'])) {
                        if ((int) $payload['statusCode'] < 200 || (int) $payload['statusCode'] >= 300) {
                            throw new RuntimeException('Funcția DigitalOcean a raportat HTTP '.(int) $payload['statusCode'].'.');
                        }
                        unset($payload['statusCode']);
                    }
                    if (isset($payload['body'])) {
                        $payload = is_string($payload['body']) ? json_decode($payload['body'], true, 512, JSON_THROW_ON_ERROR) : $payload['body'];
                        continue;
                    }
                    $next = null;
                    foreach (['response', 'competitions', 'records', 'data', 'results'] as $key) {
                        if (isset($payload[$key]) && is_array($payload[$key])) { $next = $payload[$key]; break; }
                    }
                    if ($next === null) break;
                    $payload = $next;
                }
            } catch (\JsonException $e) {
                throw new RuntimeException('Funcția DigitalOcean a returnat JSON invalid.', 0, $e);
            }
            if (! is_array($payload) || ! array_is_list($payload)) {
                $keys = is_array($payload) ? implode(', ', array_slice(array_map('strval', array_keys($payload)), 0, 12)) : get_debug_type($payload);
                throw new RuntimeException('Răspunsul funcției nu conține lista de competiții (câmpuri: '.$keys.').');
            }
            return $payload;
        }

        throw new RuntimeException('Funcția DigitalOcean nu a putut fi apelată.');
    }
}

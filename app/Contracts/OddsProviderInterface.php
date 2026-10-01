<?php
namespace App\Contracts;
interface OddsProviderInterface { public function oddsFor(string $externalMatchId): array; }

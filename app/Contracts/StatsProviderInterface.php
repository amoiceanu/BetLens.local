<?php
namespace App\Contracts;
interface StatsProviderInterface { public function teamStats(string $team): array; }

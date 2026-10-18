<?php

namespace App\Contracts;

interface FootballDataProviderInterface
{
    public function verifyConnection(): array;
    public function upcomingMatches(): array;
    public function fixtures(array $filters=[]): array;
    public function statistics(array $filters=[]): array;
    public function absences(array $filters=[]): array;
    public function standings(array $filters=[]): array;
}

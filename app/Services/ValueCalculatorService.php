<?php
namespace App\Services;
class ValueCalculatorService
{
    public function impliedProbability(float $odds): float { return $odds > 0 ? round(1 / $odds, 4) : 0; }
    public function calculate(float $modelProbability, float $odds): float { return round($modelProbability - $this->impliedProbability($odds), 4); }
}

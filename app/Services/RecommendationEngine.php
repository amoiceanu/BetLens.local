<?php
namespace App\Services;
class RecommendationEngine
{
    public function __construct(private ValueCalculatorService $valueCalculator) {}
    public function evaluate(array $signals, float $odds): array
    {
        $weights=['recent_form'=>.28,'goals'=>.24,'home_away'=>.18,'opponent_strength'=>.12,'xg'=>.10,'availability'=>.05,'fatigue'=>.02,'h2h'=>.01];
        $probability=0.45;
        foreach($weights as $key=>$weight) $probability += (($signals[$key] ?? .5)-.5)*$weight;
        $probability=max(.05,min(.95,$probability));
        $value=$this->valueCalculator->calculate($probability,$odds);
        $score=(int) round(($probability*.68 + max(0,min(.2,$value))/.2*.32)*100);
        return ['probability'=>round($probability,4),'implied'=>$this->valueCalculator->impliedProbability($odds),'value'=>$value,'score'=>$score,'confidence'=>$score>=72?'Ridicat':($score>=60?'Mediu':'Scăzut')];
    }
}

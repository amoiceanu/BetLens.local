<?php
namespace App\Services;
use App\Models\ApplicationSetting;
use App\Models\Recommendation;
use Illuminate\Support\Collection;
class TicketBuilderService
{
    public const LIMITS=['conservator'=>[2,3],'echilibrat'=>[5,8],'agresiv'=>[10,15]];
    public function build(Collection $recommendations,string $profile): Collection
    {
        [$min,$max]=self::LIMITS[$profile] ?? self::LIMITS['echilibrat'];
        $thresholds=$this->thresholds($profile);
        $unique=$recommendations->filter(fn($r)=>$r->eligible
            && (float)$r->model_probability >= $thresholds['min_probability']
            && ((string)($r->model_version??'')==='poisson-fair-v1' || (float)$r->value >= $thresholds['min_value'])
            && (int)$r->score >= $thresholds['min_confidence'])
            ->sortByDesc(fn($r)=>$r->score+($r->value*100))->unique('match_id')->take($max)->values();
        return $unique->count() >= $min ? $unique : collect();
    }
    public function totals(Collection $items): array
    {
        return ['odds'=>round($items->reduce(fn($v,$r)=>$v*$r->odds,1),2),'probability'=>round($items->reduce(fn($v,$r)=>$v*$r->model_probability,1)*100,2)];
    }
    private function thresholds(string $profile): array
    {
        $defaults=['conservator'=>['min_probability'=>.68,'min_value'=>.02,'min_confidence'=>70],'echilibrat'=>['min_probability'=>.62,'min_value'=>.015,'min_confidence'=>62],'agresiv'=>['min_probability'=>.56,'min_value'=>.01,'min_confidence'=>55]];
        try{$stored=ApplicationSetting::where('key','risk.'.$profile)->value('value');if(is_string($stored))$stored=json_decode($stored,true);return is_array($stored)?$stored+($defaults[$profile]??$defaults['echilibrat']):($defaults[$profile]??$defaults['echilibrat']);}
        catch(\Throwable){return $defaults[$profile]??$defaults['echilibrat'];}
    }
}

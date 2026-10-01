<?php
namespace Tests\Unit;
use App\Services\RecommendationEngine;
use App\Services\ValueCalculatorService;
use PHPUnit\Framework\TestCase;
class RecommendationEngineTest extends TestCase { public function test_it_returns_an_explainable_score():void{$r=(new RecommendationEngine(new ValueCalculatorService))->evaluate(['recent_form'=>.8,'goals'=>.75,'home_away'=>.7],1.8);$this->assertArrayHasKey('probability',$r);$this->assertContains($r['confidence'],['Scăzut','Mediu','Ridicat']);$this->assertGreaterThan(0,$r['score']);} }

<?php
namespace Tests\Unit;
use App\Services\ValueCalculatorService;
use PHPUnit\Framework\TestCase;
class ValueCalculatorServiceTest extends TestCase { public function test_it_calculates_implied_probability_and_value():void{$s=new ValueCalculatorService; $this->assertSame(.5,$s->impliedProbability(2));$this->assertSame(.1,$s->calculate(.6,2));} }

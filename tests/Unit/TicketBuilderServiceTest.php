<?php
namespace Tests\Unit;
use App\Services\TicketBuilderService;
use PHPUnit\Framework\TestCase;
class TicketBuilderServiceTest extends TestCase { public function test_it_never_duplicates_a_match():void{$items=collect([(object)['match_id'=>1,'eligible'=>true,'score'=>90,'value'=>.1,'odds'=>1.5,'model_probability'=>.7],(object)['match_id'=>1,'eligible'=>true,'score'=>80,'value'=>.08,'odds'=>1.6,'model_probability'=>.65],(object)['match_id'=>2,'eligible'=>true,'score'=>75,'value'=>.05,'odds'=>1.4,'model_probability'=>.72]]);$result=(new TicketBuilderService)->build($items,'conservator');$this->assertCount(2,$result);$this->assertCount(2,$result->unique('match_id'));} }

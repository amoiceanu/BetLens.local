<?php

namespace Tests\Feature;

use App\Models\Operator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_operators_are_visible(): void
    {
        $this->get(route('operators.index'))->assertOk()->assertSee('Winbet')->assertSee('Superbet');
    }

    public function test_an_operator_can_be_added_edited_and_deleted(): void
    {
        $this->post(route('operators.store'),['name'=>'Operator Test','website_url'=>'https://example.com','active'=>1])->assertRedirect();
        $operator=Operator::where('name','Operator Test')->firstOrFail();
        $this->patch(route('operators.update',$operator),['name'=>'Operator Editat','website_url'=>'https://example.org','active'=>0])->assertRedirect();
        $this->assertDatabaseHas('operators',['id'=>$operator->id,'name'=>'Operator Editat','active'=>false]);
        $this->delete(route('operators.destroy',$operator))->assertRedirect();
        $this->assertDatabaseMissing('operators',['id'=>$operator->id]);
    }
}

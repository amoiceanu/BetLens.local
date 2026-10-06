<?php

namespace App\Http\Controllers;

use App\Models\Operator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OperatorController extends Controller
{
    public function index()
    {
        return view('operators.index',['operators'=>Operator::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data=$this->validated($request);
        $data['slug']=$this->uniqueSlug($data['name']);
        Operator::create($data);

        return back()->with('success','Operatorul a fost adăugat.');
    }

    public function update(Request $request,Operator $operator)
    {
        $data=$this->validated($request,$operator);
        $data['slug']=$this->uniqueSlug($data['name'],$operator);
        $operator->update($data);

        return back()->with('success','Operatorul a fost actualizat.');
    }

    public function destroy(Operator $operator)
    {
        $operator->delete();

        return back()->with('success','Operatorul a fost șters.');
    }

    private function validated(Request $request,?Operator $operator=null): array
    {
        return $request->validate([
            'name'=>['required','string','max:100',Rule::unique('operators','name')->ignore($operator?->id)],
            'website_url'=>'nullable|url:http,https|max:255',
            'active'=>'required|boolean',
        ],[
            'name.required'=>'Introdu numele operatorului.',
            'name.unique'=>'Există deja un operator cu acest nume.',
            'website_url.url'=>'Adresa site-ului trebuie să fie un URL HTTP sau HTTPS valid.',
        ]);
    }

    private function uniqueSlug(string $name,?Operator $operator=null): string
    {
        $base=Str::slug($name)?:'operator';
        $slug=$base;
        $suffix=2;
        while(Operator::where('slug',$slug)->when($operator,fn($query)=>$query->whereKeyNot($operator->id))->exists()) $slug=$base.'-'.$suffix++;
        return $slug;
    }
}

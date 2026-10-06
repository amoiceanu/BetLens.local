<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('generated_tickets',function(Blueprint $table){$table->foreignId('operator_id')->nullable()->after('status')->constrained('operators')->nullOnDelete();});$winbetId=DB::table('operators')->where('slug','winbet')->value('id');DB::table('generated_tickets')->where('status','placed_winbet')->update(['status'=>'placed','operator_id'=>$winbetId]); }
    public function down(): void { $winbetId=DB::table('operators')->where('slug','winbet')->value('id');if($winbetId)DB::table('generated_tickets')->where('status','placed')->where('operator_id',$winbetId)->update(['status'=>'placed_winbet']);Schema::table('generated_tickets',fn(Blueprint $table)=>$table->dropConstrainedForeignId('operator_id')); }
};

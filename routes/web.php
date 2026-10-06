<?php

use App\Http\Controllers\BetLensController;
use App\Http\Controllers\OperatorController;
use Illuminate\Support\Facades\Route;

Route::get('/',[BetLensController::class,'dashboard'])->name('dashboard');
Route::post('/genereaza',[BetLensController::class,'generate'])->name('generate');
Route::get('/meciuri',[BetLensController::class,'matches'])->name('matches');
Route::get('/meciuri/{match}',[BetLensController::class,'match'])->name('matches.show');
Route::get('/bilete',[BetLensController::class,'tickets'])->name('tickets');
Route::get('/bilete/{ticket}',[BetLensController::class,'ticket'])->name('tickets.show');
Route::patch('/bilete/{ticket}',[BetLensController::class,'updateTicket'])->name('tickets.update');
Route::delete('/bilete/{ticket}',[BetLensController::class,'destroyTicket'])->name('tickets.destroy');
Route::delete('/selectii/{selection}',[BetLensController::class,'removeSelection'])->name('selections.destroy');
Route::post('/selectii/{selection}/replace',[BetLensController::class,'replaceSelection'])->name('selections.replace');
Route::get('/performanta',[BetLensController::class,'performance'])->name('performance');
Route::get('/operatori',[OperatorController::class,'index'])->name('operators.index');
Route::post('/operatori',[OperatorController::class,'store'])->name('operators.store');
Route::patch('/operatori/{operator}',[OperatorController::class,'update'])->name('operators.update');
Route::delete('/operatori/{operator}',[OperatorController::class,'destroy'])->name('operators.destroy');
Route::get('/surse-date',[BetLensController::class,'dataSources'])->name('data-sources');
Route::post('/surse-date/{source}/verifica',[BetLensController::class,'verifyDataSource'])->middleware('throttle:10,1')->name('data-sources.verify');
Route::get('/admin/login',[BetLensController::class,'adminLogin'])->name('admin.login');
Route::post('/admin/login',[BetLensController::class,'adminAuthenticate'])->middleware('throttle:admin-login')->name('admin.authenticate');
Route::middleware('admin.auth')->group(function(){
    Route::get('/admin',[BetLensController::class,'admin'])->name('admin');
    Route::post('/admin/logout',[BetLensController::class,'adminLogout'])->name('admin.logout');
    Route::post('/admin/sync',[BetLensController::class,'sync'])->name('admin.sync');
    Route::patch('/admin/ligi/{league}/toggle',[BetLensController::class,'toggleLeague'])->name('admin.leagues.toggle');
    Route::patch('/admin/piete/{market}/toggle',[BetLensController::class,'toggleMarket'])->name('admin.markets.toggle');
    Route::put('/admin/setari',[BetLensController::class,'settings'])->name('admin.settings');
});

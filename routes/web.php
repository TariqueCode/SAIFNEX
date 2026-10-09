<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceNetworkController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', [SessionController::class, 'create'])->name('login');
Route::post('/login', [SessionController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('login.store');
Route::post('/logout', [SessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/control-center', function () {
    return view('control-center');
})->name('control-center');

Route::middleware('auth')->group(function () {
    Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');
    Route::post('/workspace/networks', [WorkspaceNetworkController::class, 'store'])
        ->name('workspace.networks.store');
});

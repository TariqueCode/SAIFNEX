<?php

use App\Http\Controllers\Api\V1\PolicyController;
use App\Http\Controllers\Api\V1\PolicyRuleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('auth')->group(function () {
        Route::get('/networks/{network}/policies', [PolicyController::class, 'index']);
        Route::post('/networks/{network}/policies', [PolicyController::class, 'store']);

        Route::get('/policies/{policy}', [PolicyController::class, 'show']);
        Route::patch('/policies/{policy}', [PolicyController::class, 'update']);

        Route::post('/policies/{policy}/rules', [PolicyRuleController::class, 'store']);
        Route::patch('/policies/{policy}/rules/{rule}', [PolicyRuleController::class, 'update']);
        Route::delete('/policies/{policy}/rules/{rule}', [PolicyRuleController::class, 'destroy']);
    });
});

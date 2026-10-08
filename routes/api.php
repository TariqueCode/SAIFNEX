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
        Route::post('/policies/{policy}/publish', [PolicyController::class, 'publish']);
        Route::post('/policies/{policy}/rollback', [PolicyController::class, 'rollback']);

        Route::post('/policies/{policy}/rules', [PolicyRuleController::class, 'store']);
        Route::patch('/policies/{policy}/rules/{rule}', [PolicyRuleController::class, 'update']);
        Route::delete('/policies/{policy}/rules/{rule}', [PolicyRuleController::class, 'destroy']);

        Route::get('/networks/{network}/presets', [\App\Http\Controllers\Api\V1\PresetController::class, 'index']);
        Route::post('/networks/{network}/policies/from-preset', [\App\Http\Controllers\Api\V1\PresetController::class, 'store']);
        Route::post('/networks/{network}/policies/{policy}/set-default', [\App\Http\Controllers\Api\V1\PolicyAssignmentController::class, 'setDefault']);
        Route::post('/devices/{device}/policies/{policy}/assign', [\App\Http\Controllers\Api\V1\PolicyAssignmentController::class, 'assignDevice']);
        Route::post('/networks/{network}/configuration/compile', [\App\Http\Controllers\Api\V1\ConfigurationController::class, 'compile']);

        Route::post('/networks/{network}/devices/enrollments', [\App\Http\Controllers\Api\V1\DeviceEnrollmentController::class, 'create']);
    });

    // Device-side enrollment is authenticated by a short-lived one-time token.
    Route::post('/devices/enroll', [\App\Http\Controllers\Api\V1\DeviceEnrollmentController::class, 'consume']);
});

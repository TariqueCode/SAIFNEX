<?php

use App\Http\Controllers\Api\V1\PolicyController;
use App\Http\Controllers\Api\V1\PolicyRuleController;
use App\Http\Controllers\Api\V1\ConfigurationLifecycleController;
use App\Http\Controllers\Api\V1\NetworkNodeController;
use App\Http\Controllers\Api\V1\ConfigurationDeploymentController;
use App\Http\Controllers\Api\V1\Internal\NodeRuntimeController;
use App\Http\Middleware\AuthenticateNetworkNode;
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
        Route::post('/configurations/{configuration}/validate', [ConfigurationLifecycleController::class, 'validate']);
        Route::post('/configurations/{configuration}/stage', [ConfigurationLifecycleController::class, 'stage']);
        Route::post('/configurations/{configuration}/publish', [ConfigurationLifecycleController::class, 'publish']);
        Route::get('/networks/{network}/nodes', [NetworkNodeController::class, 'index']);
        Route::post('/networks/{network}/nodes', [NetworkNodeController::class, 'store']);
        Route::get('/nodes/{node}', [NetworkNodeController::class, 'show']);
        Route::post('/configurations/{configuration}/nodes/{node}/deploy', [ConfigurationDeploymentController::class, 'store']);
        Route::get('/deployments/{deployment}', [ConfigurationDeploymentController::class, 'show']);
        Route::post('/networks/{network}/devices/enrollments', [\App\Http\Controllers\Api\V1\DeviceEnrollmentController::class, 'create']);
    });

    Route::post('/devices/enroll', [\App\Http\Controllers\Api\V1\DeviceEnrollmentController::class, 'consume']);

    Route::prefix('internal/v1/nodes/{node}')
        ->middleware(AuthenticateNetworkNode::class)
        ->group(function () {
            Route::post('/heartbeat', [NodeRuntimeController::class, 'heartbeat']);
            Route::get('/configuration', [NodeRuntimeController::class, 'currentConfiguration']);
            Route::post('/deployments/{deployment}/ack', [NodeRuntimeController::class, 'acknowledge']);
        });
});

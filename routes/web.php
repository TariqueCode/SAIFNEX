<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceNetworkController;
use App\Http\Controllers\WorkspaceNetworkDetailController;
use App\Http\Controllers\WorkspacePolicyRuleController;
use App\Http\Controllers\WorkspaceNodeController;
use App\Http\Controllers\WorkspaceConfigurationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
Route::get('/login', [SessionController::class, 'create'])->name('login');
Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/control-center', fn () => view('control-center'))->name('control-center');

Route::middleware('auth')->group(function () {
    Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');
    Route::post('/workspace/networks', [WorkspaceNetworkController::class, 'store'])->name('workspace.networks.store');
    Route::get('/workspace/networks/{networkId}', [WorkspaceNetworkDetailController::class, 'show'])->whereNumber('networkId')->name('workspace.networks.show');
    Route::post('/workspace/networks/{networkId}/policies', [WorkspaceNetworkDetailController::class, 'storePolicy'])->whereNumber('networkId')->name('workspace.networks.policies.store');
    Route::get('/workspace/networks/{networkId}/nodes', [WorkspaceNodeController::class, 'index'])->whereNumber('networkId')->name('workspace.networks.nodes.index');
    Route::post('/workspace/networks/{networkId}/nodes', [WorkspaceNodeController::class, 'store'])->whereNumber('networkId')->name('workspace.networks.nodes.store');
    Route::get('/workspace/networks/{networkId}/configurations', [WorkspaceConfigurationController::class, 'index'])->whereNumber('networkId')->name('workspace.networks.configurations.index');
    Route::post('/workspace/networks/{networkId}/configurations/compile', [WorkspaceConfigurationController::class, 'compile'])->whereNumber('networkId')->name('workspace.networks.configurations.compile');
    Route::post('/workspace/networks/{networkId}/configurations/{configurationId}/validate', [WorkspaceConfigurationController::class, 'validateVersion'])->whereNumber(['networkId', 'configurationId'])->name('workspace.networks.configurations.validate');
    Route::post('/workspace/networks/{networkId}/configurations/{configurationId}/stage', [WorkspaceConfigurationController::class, 'stage'])->whereNumber(['networkId', 'configurationId'])->name('workspace.networks.configurations.stage');
    Route::post('/workspace/networks/{networkId}/configurations/{configurationId}/publish', [WorkspaceConfigurationController::class, 'publish'])->whereNumber(['networkId', 'configurationId'])->name('workspace.networks.configurations.publish');
    Route::get('/workspace/networks/{networkId}/policies/{profileId}', [WorkspacePolicyRuleController::class, 'show'])->whereNumber(['networkId', 'profileId'])->name('workspace.networks.policies.show');
    Route::post('/workspace/networks/{networkId}/policies/{profileId}/rules', [WorkspacePolicyRuleController::class, 'store'])->whereNumber(['networkId', 'profileId'])->name('workspace.networks.policies.rules.store');
    Route::patch('/workspace/networks/{networkId}/policies/{profileId}/rules/{ruleId}', [WorkspacePolicyRuleController::class, 'update'])->whereNumber(['networkId', 'profileId', 'ruleId'])->name('workspace.networks.policies.rules.update');
    Route::delete('/workspace/networks/{networkId}/policies/{profileId}/rules/{ruleId}', [WorkspacePolicyRuleController::class, 'destroy'])->whereNumber(['networkId', 'profileId', 'ruleId'])->name('workspace.networks.policies.rules.destroy');
});

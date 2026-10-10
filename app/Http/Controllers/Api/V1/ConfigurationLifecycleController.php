<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationVersion;
use App\Services\Access\NetworkAccess;
use App\Services\Configuration\ConfigurationLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigurationLifecycleController extends Controller
{
    public function validate(Request $request, ConfigurationVersion $configuration, ConfigurationLifecycleService $lifecycle): JsonResponse
    {
        app(NetworkAccess::class)->require($request->user(), $configuration->network_id, 'configuration.manage');
        return response()->json(['success' => true, 'data' => $lifecycle->validate($configuration)]);
    }

    public function stage(Request $request, ConfigurationVersion $configuration, ConfigurationLifecycleService $lifecycle): JsonResponse
    {
        app(NetworkAccess::class)->require($request->user(), $configuration->network_id, 'configuration.manage');
        return response()->json(['success' => true, 'data' => $lifecycle->stage($configuration)]);
    }

    public function publish(Request $request, ConfigurationVersion $configuration, ConfigurationLifecycleService $lifecycle): JsonResponse
    {
        app(NetworkAccess::class)->require($request->user(), $configuration->network_id, 'configuration.publish');
        return response()->json(['success' => true, 'data' => $lifecycle->publish($configuration)]);
    }

}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Services\Access\NetworkAccess;
use App\Services\Configuration\ConfigurationCompiler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    public function compile(Request $request, Network $network, ConfigurationCompiler $compiler): JsonResponse
    {
        app(NetworkAccess::class)->require($request->user(), $network->id, 'configuration.manage');

        $configuration = $compiler->compile($network);

        return response()->json([
            'success' => true,
            'data' => $configuration,
        ], 201);
    }

}

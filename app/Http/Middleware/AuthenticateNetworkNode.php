<?php

namespace App\Http\Middleware;

use App\Models\NetworkNode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateNetworkNode
{
    public function handle(Request $request, Closure $next): Response
    {
        $node = $request->route('node');

        if (!$node instanceof NetworkNode || !$node->credential_hash) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NODE_CREDENTIAL_INVALID', 'message' => 'Node credentials are invalid.'],
            ], 401);
        }

        $token = $request->bearerToken();

        if (!$token || !hash_equals($node->credential_hash, hash('sha256', $token))) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NODE_CREDENTIAL_INVALID', 'message' => 'Node credentials are invalid.'],
            ], 401);
        }

        if (in_array($node->status, ['REVOKED', 'MAINTENANCE'], true)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NODE_NOT_ACTIVE', 'message' => 'This node is not allowed to communicate.'],
            ], 403);
        }

        $request->attributes->set('authenticated_network_node', $node);

        return $next($request);
    }
}

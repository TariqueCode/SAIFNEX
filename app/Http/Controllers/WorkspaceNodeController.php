<?php

namespace App\Http\Controllers;

use App\Services\Access\NetworkAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkspaceNodeController extends Controller
{
    public function index(Request $request, int $networkId): View
    {
        $network = app(NetworkAccess::class)->require($request->user(), $networkId, 'device.read');
        $network->load(['nodes' => fn ($query) => $query->latest('id')]);

        return view('workspace-nodes', compact('network'));
    }

    public function store(Request $request, int $networkId): RedirectResponse
    {
        $network = app(NetworkAccess::class)->require($request->user(), $networkId, 'device.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['EDGE', 'DNS', 'GATEWAY', 'ROUTER', 'OTHER'])],
            'region' => ['nullable', 'string', 'max:80'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:50'],
        ]);

        $token = bin2hex(random_bytes(32));

        $node = $network->nodes()->create([
            ...$data,
            'status' => 'PENDING',
        ]);

        $node->forceFill([
            'credential_hash' => hash('sha256', $token),
            'credential_rotated_at' => now(),
        ])->save();

        return redirect()
            ->route('workspace.networks.nodes.index', $network->id)
            ->with('new_node_token', $token)
            ->with('new_node_name', $node->name)
            ->with('status', 'Node registered. Copy its credential now; it will not be shown again.');
    }
}

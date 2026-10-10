<?php

namespace App\\Http\\Controllers;

use App\\Models\\PolicyProfile;
use App\\Services\\Access\\NetworkAccess;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Str;
use Illuminate\\View\\View;

class WorkspaceNetworkDetailController extends Controller
{
    public function show(Request $request, int $networkId): View
    {
        $network = app(NetworkAccess::class)->require($request->user(), $networkId, 'network.read');

        $network->load([
            'nodes' => fn ($query) => $query->latest(),
            'devices' => fn ($query) => $query->latest(),
            'policyProfiles' => fn ($query) => $query->withCount('rules')->latest(),
        ]);

        return view('workspace-network', compact('network'));
    }

    public function storePolicy(Request $request, int $networkId): RedirectResponse
    {
        $network = app(NetworkAccess::class)->require($request->user(), $networkId, 'policy.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $baseKey = Str::slug($validated['name']) ?: 'policy-profile';
        $key = $baseKey;
        $suffix = 2;

        while (PolicyProfile::query()->where('network_id', $network->id)->where('key', $key)->exists()) {
            $key = $baseKey.'-'.$suffix++;
        }

        $network->policyProfiles()->create([
            'name' => $validated['name'],
            'key' => $key,
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
            'description' => $validated['description'] ?? null,
            'is_default' => false,
            'is_template' => false,
        ]);

        return redirect()
            ->route('workspace.networks.show', $network->id)
            ->with('status', 'Draft policy profile created. Add rules and validate it before publishing.');
    }
}

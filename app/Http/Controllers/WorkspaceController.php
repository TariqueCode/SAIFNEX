<?php

namespace App\Http\Controllers;

use App\Models\Network;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $networks = Network::query()
            ->where('owner_id', $request->user()->id)
            ->withCount(['nodes', 'devices', 'policyProfiles'])
            ->latest()
            ->get();

        return view('workspace', [
            'networks' => $networks,
            'networkCount' => $networks->count(),
            'nodeCount' => $networks->sum('nodes_count'),
            'deviceCount' => $networks->sum('devices_count'),
            'policyCount' => $networks->sum('policy_profiles_count'),
        ]);
    }
}

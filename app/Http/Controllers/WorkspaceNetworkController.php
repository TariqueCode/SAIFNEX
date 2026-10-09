<?php

namespace App\Http\Controllers;

use App\Models\Network;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceNetworkController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['HOME', 'OFFICE', 'SCHOOL', 'ENTERPRISE', 'OTHER'])],
            'timezone' => ['required', 'timezone'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $request->user()->ownedNetworks()->create([
            ...$validated,
            'status' => 'ACTIVE',
        ]);

        return redirect()
            ->route('workspace')
            ->with('status', 'Your network workspace has been created.');
    }
}

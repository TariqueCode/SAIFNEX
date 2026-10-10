<?php

namespace App\Http\Controllers;

use App\Models\Network;
use App\Models\PolicyProfile;
use App\Services\Access\NetworkAccess;
use App\Services\Policy\PolicyRuleTimeNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkspacePolicyRuleController extends Controller
{
    public function show(Request $request, int $networkId, int $profileId): View
    {
        $network = app(NetworkAccess::class)->require($request->user(), $networkId, 'policy.read');
        $profile = $network->policyProfiles()
            ->with(['rules' => fn ($query) => $query->orderBy('priority')->orderBy('id')])
            ->findOrFail($profileId);

        return view('workspace-policy', compact('network', 'profile'));
    }

    public function store(Request $request, int $networkId, int $profileId): RedirectResponse
    {
        [$network, $profile] = $this->accessibleProfile($request, $networkId, $profileId, 'policy.manage');
        $this->ensureDraft($profile);

        $data = $request->validate([
            'target_type' => ['required', Rule::in(['DOMAIN', 'DOMAIN_SUFFIX', 'IP', 'CIDR', 'KEYWORD'])],
            'target' => ['required', 'string', 'max:255'],
            'action' => ['required', Rule::in(['ALLOW', 'BLOCK', 'WARN', 'MONITOR', 'DIRECT', 'ROUTE', 'CACHE'])],
            'priority' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'enabled' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $profile->rules()->create([
            ...app(PolicyRuleTimeNormalizer::class)->normalize($data, $network->timezone),
            'enabled' => $request->boolean('enabled', true),
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('workspace.networks.policies.show', [$network->id, $profile->id])
            ->with('status', 'Rule added to the draft policy. Validate it before publishing.');
    }

    public function update(Request $request, int $networkId, int $profileId, int $ruleId): RedirectResponse
    {
        [$network, $profile] = $this->accessibleProfile($request, $networkId, $profileId, 'policy.manage');
        $this->ensureDraft($profile);
        $rule = $profile->rules()->findOrFail($ruleId);

        $data = $request->validate([
            'target_type' => ['required', Rule::in(['DOMAIN', 'DOMAIN_SUFFIX', 'IP', 'CIDR', 'KEYWORD'])],
            'target' => ['required', 'string', 'max:255'],
            'action' => ['required', Rule::in(['ALLOW', 'BLOCK', 'WARN', 'MONITOR', 'DIRECT', 'ROUTE', 'CACHE'])],
            'priority' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'enabled' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $rule->update([
            ...app(PolicyRuleTimeNormalizer::class)->normalize($data, $network->timezone),
            'enabled' => $request->boolean('enabled'),
        ]);

        return redirect()->route('workspace.networks.policies.show', [$network->id, $profile->id])
            ->with('status', 'Draft rule updated.');
    }

    public function destroy(Request $request, int $networkId, int $profileId, int $ruleId): RedirectResponse
    {
        [$network, $profile] = $this->accessibleProfile($request, $networkId, $profileId, 'policy.manage');
        $this->ensureDraft($profile);
        $profile->rules()->findOrFail($ruleId)->delete();

        return redirect()->route('workspace.networks.policies.show', [$network->id, $profile->id])
            ->with('status', 'Rule removed from the draft policy.');
    }

    /** @return array{0: Network, 1: PolicyProfile} */
    private function accessibleProfile(Request $request, int $networkId, int $profileId, string $permission): array
    {
        $network = app(NetworkAccess::class)->require($request->user(), $networkId, $permission);

        return [$network, $network->policyProfiles()->findOrFail($profileId)];
    }

    private function ensureDraft(PolicyProfile $profile): void
    {
        abort_if($profile->status !== 'DRAFT', 409, 'Only draft policy profiles can be edited. Create a new draft before changing an active profile.');
    }
}

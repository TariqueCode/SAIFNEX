<?php

namespace App\Http\Controllers;

use App\Models\ConfigurationDeployment;
use App\Models\ConfigurationVersion;
use App\Models\NetworkNode;
use App\Services\Access\NetworkAccess;
use App\Services\Configuration\ConfigurationDeploymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class WorkspaceDeploymentController extends Controller
{
    public function index(Request $request, int $networkId): View
    {
        $network = app(NetworkAccess::class)->require($request->user(), $networkId, 'deployment.read');
        $nodes = $network->nodes()->orderBy('name')->get();
        $configurations = ConfigurationVersion::query()
            ->where('network_id', $network->id)
            ->where('status', 'PUBLISHED')
            ->latest('version')
            ->get();
        $deployments = ConfigurationDeployment::query()
            ->where('network_id', $network->id)
            ->with(['configuration', 'node'])
            ->latest('id')
            ->limit(100)
            ->get();

        return view('workspace-deployments', compact('network', 'nodes', 'configurations', 'deployments'));
    }

    public function store(Request $request, int $networkId, ConfigurationDeploymentService $service): RedirectResponse
    {
        $network = app(NetworkAccess::class)->require($request->user(), $networkId, 'deployment.manage');
        $data = $request->validate([
            'configuration_id' => ['required', 'integer'],
            'node_id' => ['required', 'integer'],
        ]);

        $configuration = ConfigurationVersion::query()
            ->where('network_id', $network->id)
            ->whereKey($data['configuration_id'])
            ->firstOrFail();
        $node = NetworkNode::query()
            ->where('network_id', $network->id)
            ->whereKey($data['node_id'])
            ->firstOrFail();

        try {
            $deployment = $service->create($configuration, $node);
        } catch (RuntimeException $exception) {
            Log::notice('SAIFNEX deployment request rejected.', [
                'network_id' => $network->id,
                'configuration_id' => $configuration->id,
                'node_id' => $node->id,
                'user_id' => $request->user()->id,
                'reason' => $exception->getMessage(),
            ]);

            return redirect()->route('workspace.networks.deployments.index', $network->id)
                ->withErrors(['deployment' => 'Deployment request was rejected. Confirm the configuration is published and signed, and the selected node is eligible.']);
        } catch (Throwable $exception) {
            Log::error('SAIFNEX deployment request failed.', [
                'network_id' => $network->id,
                'configuration_id' => $configuration->id,
                'node_id' => $node->id,
                'user_id' => $request->user()->id,
                'exception' => $exception::class,
            ]);

            return redirect()->route('workspace.networks.deployments.index', $network->id)
                ->withErrors(['deployment' => 'The deployment request failed unexpectedly. Check application logs before retrying.']);
        }

        return redirect()->route('workspace.networks.deployments.index', $network->id)
            ->with('status', "Deployment request #{$deployment->id} is {$deployment->status}. The node must acknowledge activation before it is considered deployed.");
    }
}

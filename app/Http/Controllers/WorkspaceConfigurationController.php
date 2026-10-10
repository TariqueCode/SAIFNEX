<?php

namespace App\\Http\\Controllers;

use App\\Models\\ConfigurationVersion;
use App\\Services\\Access\\NetworkAccess;
use App\\Services\\Configuration\\ConfigurationCompiler;
use App\\Services\\Configuration\\ConfigurationLifecycleService;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\View\\View;
use RuntimeException;
use Throwable;

class WorkspaceConfigurationController extends Controller
{
    public function index(Request $request, int $networkId): View
    {
        $network = app(NetworkAccess::class)->require($request->user(), $networkId, 'configuration.read');
        $configurations = ConfigurationVersion::query()
            ->where('network_id', $network->id)
            ->latest('version')
            ->get();

        return view('workspace-configurations', compact('network', 'configurations'));
    }

    public function compile(Request $request, int $networkId, ConfigurationCompiler $compiler): RedirectResponse
    {
        $network = app(NetworkAccess::class)->require($request->user(), $networkId, 'configuration.manage');

        try {
            $configuration = $compiler->compile($network);
        } catch (Throwable $exception) {
            Log::warning('SAIFNEX configuration compilation failed.', [
                'network_id' => $network->id,
                'user_id' => $request->user()->id,
                'exception' => $exception::class,
            ]);

            return redirect()->route('workspace.networks.configurations.index', $network->id)
                ->withErrors(['configuration' => 'Configuration could not be generated. Review the network and active policy setup, then try again.']);
        }

        return redirect()->route('workspace.networks.configurations.index', $network->id)
            ->with('status', "Configuration v{$configuration->version} generated. Validate it before staging.");
    }

    public function validateVersion(Request $request, int $networkId, int $configurationId, ConfigurationLifecycleService $lifecycle): RedirectResponse
    {
        return $this->runLifecycle($request, $networkId, $configurationId, $lifecycle, 'validate');
    }

    public function stage(Request $request, int $networkId, int $configurationId, ConfigurationLifecycleService $lifecycle): RedirectResponse
    {
        return $this->runLifecycle($request, $networkId, $configurationId, $lifecycle, 'stage');
    }

    public function publish(Request $request, int $networkId, int $configurationId, ConfigurationLifecycleService $lifecycle): RedirectResponse
    {
        return $this->runLifecycle($request, $networkId, $configurationId, $lifecycle, 'publish');
    }

    private function runLifecycle(Request $request, int $networkId, int $configurationId, ConfigurationLifecycleService $lifecycle, string $operation): RedirectResponse
    {
        $permission = $operation === 'publish' ? 'configuration.publish' : 'configuration.manage';
        $network = app(NetworkAccess::class)->require($request->user(), $networkId, $permission);
        $configuration = ConfigurationVersion::query()
            ->where('network_id', $network->id)
            ->findOrFail($configurationId);

        try {
            $result = $lifecycle->{$operation}($configuration);
        } catch (RuntimeException $exception) {
            Log::notice('SAIFNEX configuration lifecycle transition rejected.', [
                'network_id' => $network->id,
                'configuration_id' => $configuration->id,
                'operation' => $operation,
                'user_id' => $request->user()->id,
                'reason' => $exception->getMessage(),
            ]);

            $message = $operation === 'publish'
                ? 'Publishing was not completed. Verify the signing configuration and PHP Sodium support; the version has not been published unless its status confirms PUBLISHED.'
                : 'This lifecycle step was rejected. Check the configuration status and validation result before retrying.';

            return redirect()->route('workspace.networks.configurations.index', $network->id)
                ->withErrors(['configuration' => $message]);
        } catch (Throwable $exception) {
            Log::error('SAIFNEX configuration lifecycle operation failed.', [
                'network_id' => $network->id,
                'configuration_id' => $configuration->id,
                'operation' => $operation,
                'user_id' => $request->user()->id,
                'exception' => $exception::class,
            ]);

            return redirect()->route('workspace.networks.configurations.index', $network->id)
                ->withErrors(['configuration' => 'The operation failed unexpectedly. Check application logs before retrying.']);
        }

        return redirect()->route('workspace.networks.configurations.index', $network->id)
            ->with('status', "Configuration v{$result->version} status: {$result->status}.");
    }
}

<?php

namespace App\Actions\Kubernetes;

use App\Models\Application;
use App\Models\KubernetesCluster;
use App\Models\Server;
use App\Services\KubernetesClientService;
use App\Services\OpenShiftRouteGenerator;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * SyncOpenShiftRoute
 *
 * Creates or updates OpenShift Route and Service for applications deployed
 * to Docker Host VMs (Path A). This enables apps to be exposed via OpenShift
 * Routes with automatic TLS using the cluster's wildcard certificate.
 *
 * Usage:
 *   SyncOpenShiftRoute::run($application, $server);
 *
 * This action should be called after an application is successfully deployed
 * to a Docker Host VM that runs on OpenShift/OKD with KubeVirt.
 */
class SyncOpenShiftRoute
{
    use AsAction;

    /**
     * Sync the OpenShift Route for an application.
     *
     * @param  Application  $application  The application that was deployed
     * @param  Server  $server  The Docker Host VM server
     * @param  KubernetesCluster|null  $cluster  Optional: The Kubernetes cluster (auto-detected if null)
     * @return array{success: bool, message: string, manifests?: array}
     */
    public function handle(
        Application $application,
        Server $server,
        ?KubernetesCluster $cluster = null
    ): array {
        // Check if the application has a FQDN configured
        if (empty($application->fqdn)) {
            return [
                'success' => true,
                'message' => 'No FQDN configured, skipping Route creation.',
            ];
        }

        // Create the route generator
        $generator = OpenShiftRouteGenerator::forApplication($application, $server);

        if ($generator === null) {
            return [
                'success' => false,
                'message' => 'Server is not a KubeVirt VM, cannot create OpenShift Route.',
            ];
        }

        // Find the Kubernetes cluster
        if ($cluster === null) {
            $cluster = $this->findClusterForServer($server);
        }

        if ($cluster === null) {
            return [
                'success' => false,
                'message' => 'No Kubernetes cluster found for this server.',
            ];
        }

        // Check if this cluster supports OpenShift Routes
        $kubeClient = new KubernetesClientService($cluster);

        try {
            // Generate the manifests
            $manifests = $generator->generateAll();
            $yaml = $generator->toYaml();

            // Apply the manifests
            $kubeClient->applyManifest($yaml);

            return [
                'success' => true,
                'message' => 'OpenShift Route and Service created/updated successfully.',
                'manifests' => $manifests,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to create OpenShift Route: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Delete the OpenShift Route and Service for an application.
     *
     * @param  Application  $application  The application
     * @param  Server  $server  The Docker Host VM server
     * @param  KubernetesCluster|null  $cluster  Optional: The Kubernetes cluster
     * @return array{success: bool, message: string}
     */
    public function delete(
        Application $application,
        Server $server,
        ?KubernetesCluster $cluster = null
    ): array {
        $generator = OpenShiftRouteGenerator::forApplication($application, $server);

        if ($generator === null) {
            return [
                'success' => true,
                'message' => 'Not a KubeVirt VM, nothing to delete.',
            ];
        }

        if ($cluster === null) {
            $cluster = $this->findClusterForServer($server);
        }

        if ($cluster === null) {
            return [
                'success' => false,
                'message' => 'No Kubernetes cluster found.',
            ];
        }

        $kubeClient = new KubernetesClientService($cluster);

        // Get the resource name and namespace from the generator
        $manifests = $generator->generateAll();
        $serviceName = $manifests['Service']['metadata']['name'] ?? null;
        $namespace = $manifests['Service']['metadata']['namespace'] ?? 'coolify-vms';

        try {
            // Delete Service
            if ($serviceName) {
                try {
                    $kubeClient->deleteResource('service', $serviceName, $namespace);
                } catch (\Throwable $e) {
                    // Ignore if not found
                }
            }

            // Delete Route(s)
            $routes = $manifests['Route'] ?? null;
            if ($routes) {
                // Handle single route or array of routes
                $routeList = isset($routes['metadata']) ? [$routes] : $routes;
                foreach ($routeList as $route) {
                    $routeName = $route['metadata']['name'] ?? null;
                    if ($routeName) {
                        try {
                            $kubeClient->deleteResource('route', $routeName, $namespace);
                        } catch (\Throwable $e) {
                            // Ignore if not found
                        }
                    }
                }
            }

            return [
                'success' => true,
                'message' => 'OpenShift Route and Service deleted successfully.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to delete OpenShift Route: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Find the Kubernetes cluster associated with a server.
     *
     * For KubeVirt VMs, the cluster is the one running the VM.
     */
    private function findClusterForServer(Server $server): ?KubernetesCluster
    {
        // Option 1: Check if server has cluster reference in settings
        $clusterId = $server->settings->kubernetes_cluster_id ?? null;
        if ($clusterId) {
            return KubernetesCluster::find($clusterId);
        }

        // Option 2: Check if there's a cluster that manages the VM namespace
        $vmNamespace = $server->settings->vm_namespace ?? 'coolify-vms';

        // For now, return the first cluster that has the VM namespace
        // In production, you'd want more sophisticated matching
        $clusters = KubernetesCluster::all();
        foreach ($clusters as $cluster) {
            try {
                $kubeClient = new KubernetesClientService($cluster);
                if ($kubeClient->namespaceExists($vmNamespace)) {
                    return $cluster;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }
}

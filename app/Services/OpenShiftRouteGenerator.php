<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Server;
use Symfony\Component\Yaml\Yaml;

/**
 * OpenShiftRouteGenerator
 *
 * Generates OpenShift Route and Service manifests for applications deployed
 * to Docker Host VMs (Path A). This allows apps running in Docker containers
 * inside KubeVirt VMs to be exposed via OpenShift Routes with the cluster's
 * wildcard certificate.
 *
 * Traffic Flow:
 * User → OpenShift Route (TLS termination) → Service → VM Pod → Docker Container
 */
class OpenShiftRouteGenerator
{
    private Application $application;

    private Server $server;

    private string $vmName;

    private string $vmNamespace;

    /**
     * Create a new OpenShiftRouteGenerator instance.
     *
     * @param  Application  $application  The application being deployed
     * @param  Server  $server  The Docker Host VM server
     * @param  string  $vmName  The KubeVirt VM name (e.g., "docker-host-00")
     * @param  string  $vmNamespace  The namespace where the VM runs (e.g., "coolify-vms")
     */
    public function __construct(
        Application $application,
        Server $server,
        string $vmName,
        string $vmNamespace = 'coolify-vms'
    ) {
        $this->application = $application;
        $this->server = $server;
        $this->vmName = $vmName;
        $this->vmNamespace = $vmNamespace;
    }

    /**
     * Generate all manifests (Service + Route) for the application.
     *
     * @return array<string, array> Map of manifest type to manifest data
     */
    public function generateAll(): array
    {
        $manifests = [];

        $manifests['Service'] = $this->generateService();

        $route = $this->generateRoute();
        if ($route !== null) {
            $manifests['Route'] = $route;
        }

        return $manifests;
    }

    /**
     * Generate a Kubernetes Service that targets the VM's virt-launcher pod.
     *
     * The service uses the kubevirt.io/domain label to select the correct VM.
     *
     * @return array The Service manifest
     */
    public function generateService(): array
    {
        $name = $this->getResourceName();
        $ports = $this->buildServicePorts();
        $labels = $this->generateLabels();

        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $name,
                'namespace' => $this->vmNamespace,
                'labels' => $labels,
                'annotations' => [
                    'coolify.io/application-uuid' => $this->application->uuid,
                    'coolify.io/server-uuid' => $this->server->uuid,
                    'coolify.io/generated-at' => now()->toIso8601String(),
                ],
            ],
            'spec' => [
                'type' => 'ClusterIP',
                'selector' => [
                    // This selects the virt-launcher pod for the specific VM
                    'kubevirt.io/domain' => $this->vmName,
                ],
                'ports' => $ports,
            ],
        ];
    }

    /**
     * Generate an OpenShift Route for the application.
     *
     * The route provides TLS termination using the cluster's wildcard certificate.
     * Returns null if the application has no FQDN configured.
     *
     * @return array|null The Route manifest or null
     */
    public function generateRoute(): ?array
    {
        $fqdn = $this->application->fqdn;

        if (empty($fqdn)) {
            return null;
        }

        $hosts = $this->parseHosts($fqdn);

        if (empty($hosts)) {
            return null;
        }

        $routes = [];
        $name = $this->getResourceName();
        $labels = $this->generateLabels();
        $primaryPort = $this->getPrimaryPort();

        foreach ($hosts as $index => $host) {
            $routeName = $index === 0 ? $name : $name.'-'.($index + 1);

            $route = [
                'apiVersion' => 'route.openshift.io/v1',
                'kind' => 'Route',
                'metadata' => [
                    'name' => $routeName,
                    'namespace' => $this->vmNamespace,
                    'labels' => $labels,
                    'annotations' => [
                        'coolify.io/application-uuid' => $this->application->uuid,
                        'coolify.io/server-uuid' => $this->server->uuid,
                        'coolify.io/generated-at' => now()->toIso8601String(),
                    ],
                ],
                'spec' => [
                    'host' => $host['hostname'],
                    'to' => [
                        'kind' => 'Service',
                        'name' => $name,
                        'weight' => 100,
                    ],
                    'port' => [
                        'targetPort' => $primaryPort,
                    ],
                ],
            ];

            // Add TLS configuration for HTTPS hosts
            if ($host['https']) {
                $route['spec']['tls'] = [
                    // Edge termination: TLS terminates at the router
                    // The router uses the wildcard cert for *.apps.cluster.example.com
                    'termination' => 'edge',
                    // Redirect HTTP to HTTPS
                    'insecureEdgeTerminationPolicy' => 'Redirect',
                ];
            }

            $routes[] = $route;
        }

        // Return single route or array of routes
        return count($routes) === 1 ? $routes[0] : $routes;
    }

    /**
     * Generate a combined YAML string of all manifests.
     *
     * @return string YAML representation of all manifests
     */
    public function toYaml(): string
    {
        $manifests = $this->generateAll();
        $yamlParts = [];

        foreach ($manifests as $type => $manifest) {
            if ($type === 'Route' && isset($manifest[0])) {
                // Multiple routes
                foreach ($manifest as $route) {
                    $yamlParts[] = Yaml::dump($route, 10, 2);
                }
            } else {
                $yamlParts[] = Yaml::dump($manifest, 10, 2);
            }
        }

        return implode("---\n", $yamlParts);
    }

    /**
     * Get the resource name for Kubernetes objects.
     *
     * @return string The sanitized resource name
     */
    private function getResourceName(): string
    {
        $name = $this->application->name ?? $this->application->uuid;

        // Sanitize for Kubernetes naming requirements
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        if (strlen($name) > 63) {
            $name = substr($name, 0, 63);
            $name = rtrim($name, '-');
        }

        if (empty($name)) {
            $name = 'app-'.substr($this->application->uuid, 0, 8);
        }

        return $name;
    }

    /**
     * Generate standard Kubernetes labels.
     *
     * @return array<string, string> The labels
     */
    private function generateLabels(): array
    {
        return [
            'app.kubernetes.io/name' => $this->getResourceName(),
            'app.kubernetes.io/instance' => $this->application->uuid,
            'app.kubernetes.io/managed-by' => 'coolify',
            'coolify.io/resource-type' => 'application',
            'coolify.io/resource-uuid' => $this->application->uuid,
            'coolify.io/deployment-path' => 'docker-host-vm',
            'coolify.io/vm-name' => $this->vmName,
        ];
    }

    /**
     * Build service ports from the application configuration.
     *
     * @return array The service ports
     */
    private function buildServicePorts(): array
    {
        $ports = [];
        $exposedPorts = $this->getExposedPorts();

        foreach ($exposedPorts as $port) {
            $ports[] = [
                'name' => 'port-'.$port,
                'port' => (int) $port,
                'targetPort' => (int) $port,
                'protocol' => 'TCP',
            ];
        }

        // Ensure at least one port
        if (empty($ports)) {
            $ports[] = [
                'name' => 'http',
                'port' => 80,
                'targetPort' => 80,
                'protocol' => 'TCP',
            ];
        }

        return $ports;
    }

    /**
     * Get exposed ports from the application.
     *
     * @return array<int> The exposed ports
     */
    private function getExposedPorts(): array
    {
        $portsExposes = $this->application->ports_exposes ?? '';

        if (empty($portsExposes)) {
            return [];
        }

        return array_map('intval', array_filter(explode(',', $portsExposes)));
    }

    /**
     * Get the primary port for the application.
     *
     * @return int The primary port
     */
    private function getPrimaryPort(): int
    {
        $exposedPorts = $this->getExposedPorts();

        return ! empty($exposedPorts) ? (int) $exposedPorts[0] : 80;
    }

    /**
     * Parse hosts from FQDN string.
     *
     * @param  string  $fqdn  The FQDN string (comma-separated URLs)
     * @return array<array{hostname: string, https: bool}> The parsed hosts
     */
    private function parseHosts(string $fqdn): array
    {
        $hosts = [];
        $urls = array_filter(array_map('trim', explode(',', $fqdn)));

        foreach ($urls as $url) {
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $url = 'https://'.$url;
            }

            $parsed = parse_url($url);
            if (isset($parsed['host'])) {
                $hosts[] = [
                    'hostname' => $parsed['host'],
                    'https' => ($parsed['scheme'] ?? 'https') === 'https',
                ];
            }
        }

        return $hosts;
    }

    /**
     * Static factory method for creating routes from deployment context.
     *
     * @param  Application  $application  The application
     * @param  Server  $server  The server (must be a KubeVirt VM)
     * @return static|null Returns null if the server is not a KubeVirt VM
     */
    public static function forApplication(Application $application, Server $server): ?static
    {
        // Check if this server is a KubeVirt VM by looking for metadata
        $vmName = $server->settings->vm_name ?? null;
        $vmNamespace = $server->settings->vm_namespace ?? 'coolify-vms';

        if (empty($vmName)) {
            // Try to extract from server hostname pattern: docker-host-XX-ssh.namespace.svc...
            $hostname = $server->ip;
            if (preg_match('/^(docker-host-\d+)-ssh\.([^.]+)\./', $hostname, $matches)) {
                $vmName = $matches[1];
                $vmNamespace = $matches[2];
            } else {
                // Not a KubeVirt VM
                return null;
            }
        }

        return new static($application, $server, $vmName, $vmNamespace);
    }
}

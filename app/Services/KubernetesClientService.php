<?php

namespace App\Services;

use App\Models\KubernetesCluster;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Yaml\Yaml;

/**
 * KubernetesClientService
 *
 * Service for communicating with the Kubernetes API using native HTTP calls.
 * Provides methods for managing namespaces, deployments, pods, services, and ingresses.
 */
class KubernetesClientService
{
    private KubernetesCluster $cluster;

    private ?Client $httpClient = null;

    private array $clientOptions = [];

    /**
     * Create a new KubernetesClientService instance.
     */
    public function __construct(KubernetesCluster $cluster)
    {
        $this->cluster = $cluster;
    }

    /**
     * Get the HTTP client and options for API requests.
     *
     * @throws \Exception If the client cannot be initialized
     */
    private function getHttpClient(): Client
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }

        $this->initializeClient();

        return $this->httpClient;
    }

    /**
     * Initialize the HTTP client from the cluster's kubeconfig.
     *
     * @throws \Exception If the kubeconfig is invalid or cannot be parsed
     */
    private function initializeClient(): void
    {
        $kubeconfig = $this->cluster->kubeconfig;

        if (empty($kubeconfig)) {
            throw new \Exception('Kubeconfig is empty or not configured for this cluster.');
        }

        // Parse the kubeconfig YAML
        $config = Yaml::parse($kubeconfig);

        if (! is_array($config)) {
            throw new \Exception('Invalid kubeconfig format: unable to parse YAML.');
        }

        // Determine which context to use
        $contextName = $this->cluster->context_name ?? ($config['current-context'] ?? null);

        if (empty($contextName)) {
            throw new \Exception('No context specified and no current-context found in kubeconfig.');
        }

        // Find the context configuration
        $context = $this->findContextByName($config, $contextName);
        if ($context === null) {
            throw new \Exception("Context '{$contextName}' not found in kubeconfig.");
        }

        // Find the cluster configuration
        $clusterConfig = $this->findClusterByName($config, $context['context']['cluster'] ?? '');
        if ($clusterConfig === null) {
            throw new \Exception("Cluster configuration not found for context '{$contextName}'.");
        }

        // Find the user configuration
        $userConfig = $this->findUserByName($config, $context['context']['user'] ?? '');
        if ($userConfig === null) {
            throw new \Exception("User configuration not found for context '{$contextName}'.");
        }

        // Build the client options
        $this->clientOptions = $this->buildClientOptions($clusterConfig, $userConfig);
        $this->httpClient = new Client([
            'base_uri' => $this->clientOptions['base_uri'],
            'timeout' => 30,
        ]);
    }

    /**
     * Find a context by name in the kubeconfig.
     */
    private function findContextByName(array $config, string $name): ?array
    {
        foreach ($config['contexts'] ?? [] as $context) {
            if (($context['name'] ?? '') === $name) {
                return $context;
            }
        }

        return null;
    }

    /**
     * Find a cluster by name in the kubeconfig.
     */
    private function findClusterByName(array $config, string $name): ?array
    {
        foreach ($config['clusters'] ?? [] as $cluster) {
            if (($cluster['name'] ?? '') === $name) {
                return $cluster;
            }
        }

        return null;
    }

    /**
     * Find a user by name in the kubeconfig.
     */
    private function findUserByName(array $config, string $name): ?array
    {
        foreach ($config['users'] ?? [] as $user) {
            if (($user['name'] ?? '') === $name) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Build client options from cluster and user configurations.
     *
     * @throws \Exception If required configuration is missing
     */
    private function buildClientOptions(array $clusterConfig, array $userConfig): array
    {
        $cluster = $clusterConfig['cluster'] ?? [];
        $user = $userConfig['user'] ?? [];

        $server = $cluster['server'] ?? null;
        if (empty($server)) {
            throw new \Exception('Server URL not found in cluster configuration.');
        }

        $options = [
            'base_uri' => rtrim($server, '/'),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        // Handle CA certificate
        if (! empty($cluster['certificate-authority-data'])) {
            $caCert = base64_decode($cluster['certificate-authority-data']);
            $caCertPath = $this->writeTempFile($caCert, 'ca-cert');
            $options['verify'] = $caCertPath;
        } elseif (! empty($cluster['certificate-authority'])) {
            $options['verify'] = $cluster['certificate-authority'];
        }

        // Handle insecure skip TLS verify
        if (! empty($cluster['insecure-skip-tls-verify'])) {
            $options['verify'] = false;
        }

        // Handle client certificate authentication
        if (! empty($user['client-certificate-data'])) {
            $clientCert = base64_decode($user['client-certificate-data']);
            $clientCertPath = $this->writeTempFile($clientCert, 'client-cert');
            $options['cert'] = $clientCertPath;
        } elseif (! empty($user['client-certificate'])) {
            $options['cert'] = $user['client-certificate'];
        }

        if (! empty($user['client-key-data'])) {
            $clientKey = base64_decode($user['client-key-data']);
            $clientKeyPath = $this->writeTempFile($clientKey, 'client-key');
            $options['ssl_key'] = $clientKeyPath;
        } elseif (! empty($user['client-key'])) {
            $options['ssl_key'] = $user['client-key'];
        }

        // Handle token authentication
        if (! empty($user['token'])) {
            $options['headers']['Authorization'] = 'Bearer '.$user['token'];
        }

        // Handle username/password authentication
        if (! empty($user['username']) && ! empty($user['password'])) {
            $options['auth'] = [$user['username'], $user['password']];
        }

        return $options;
    }

    /**
     * Write content to a temporary file and return the path.
     */
    private function writeTempFile(string $content, string $prefix): string
    {
        $tempDir = sys_get_temp_dir();
        $filename = $prefix.'-'.$this->cluster->uuid.'-'.md5($content);
        $path = $tempDir.DIRECTORY_SEPARATOR.$filename;

        // Only write if file doesn't exist or content changed
        if (! file_exists($path) || file_get_contents($path) !== $content) {
            file_put_contents($path, $content);
            chmod($path, 0600);
        }

        return $path;
    }

    /**
     * Make an API request to the Kubernetes cluster.
     *
     * @throws \Exception If the request fails
     */
    private function makeApiRequest(string $method, string $path, ?array $body = null): ?array
    {
        $client = $this->getHttpClient();
        $options = [
            'headers' => $this->clientOptions['headers'] ?? [],
        ];

        // Add verify option
        if (isset($this->clientOptions['verify'])) {
            $options['verify'] = $this->clientOptions['verify'];
        }

        // Add cert and ssl_key options
        if (isset($this->clientOptions['cert'])) {
            $options['cert'] = $this->clientOptions['cert'];
        }
        if (isset($this->clientOptions['ssl_key'])) {
            $options['ssl_key'] = $this->clientOptions['ssl_key'];
        }

        // Add auth if present
        if (isset($this->clientOptions['auth'])) {
            $options['auth'] = $this->clientOptions['auth'];
        }

        // Add body for POST/PUT/PATCH requests
        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $options['json'] = $body;
        }

        try {
            $response = $client->request($method, $path, $options);
            $responseBody = $response->getBody()->getContents();

            if (empty($responseBody)) {
                return null;
            }

            return json_decode($responseBody, true);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $body = $e->getResponse()->getBody()->getContents();
            $message = json_decode($body, true)['message'] ?? $body;
            throw new \Exception("Kubernetes API request failed ({$statusCode}): {$message}", $statusCode, $e);
        } catch (\Throwable $e) {
            throw new \Exception('Kubernetes API request failed: '.$e->getMessage(), 0, $e);
        }
    }

    // =========================================================================
    // Namespace Operations
    // =========================================================================

    /**
     * Create a new namespace in the cluster.
     *
     * @throws \Exception If creation fails
     */
    public function createNamespace(string $name): void
    {
        try {
            $namespace = [
                'apiVersion' => 'v1',
                'kind' => 'Namespace',
                'metadata' => [
                    'name' => $name,
                ],
            ];

            $this->makeApiRequest('POST', '/api/v1/namespaces', $namespace);
        } catch (\Throwable $e) {
            throw new \Exception("Failed to create namespace '{$name}': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if a namespace exists in the cluster.
     *
     * @return bool True if the namespace exists
     *
     * @throws \Exception If connection fails
     */
    public function namespaceExists(string $name): bool
    {
        try {
            $this->makeApiRequest('GET', "/api/v1/namespaces/{$name}");

            return true;
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return false;
            }
            throw new \Exception("Failed to check namespace existence for '{$name}': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all namespaces from the cluster.
     *
     * @return array<string> List of namespace names
     *
     * @throws \Exception If connection fails
     */
    public function getNamespaces(): array
    {
        try {
            $response = $this->makeApiRequest('GET', '/api/v1/namespaces');
            $result = [];

            foreach ($response['items'] ?? [] as $namespace) {
                $result[] = $namespace['metadata']['name'];
            }

            return $result;
        } catch (\Throwable $e) {
            throw new \Exception('Failed to get namespaces: '.$e->getMessage(), 0, $e);
        }
    }

    // =========================================================================
    // Deployment Operations
    // =========================================================================

    /**
     * Apply a Kubernetes manifest (YAML) to the cluster.
     *
     * Supports multiple documents separated by '---'.
     *
     * @throws \Exception If application fails
     */
    public function applyManifest(string $yaml): void
    {
        try {
            // Split YAML into multiple documents
            $documents = preg_split('/^---\s*$/m', $yaml);

            foreach ($documents as $document) {
                $document = trim($document);
                if (empty($document)) {
                    continue;
                }

                $manifest = Yaml::parse($document);
                if (! is_array($manifest) || empty($manifest['kind'])) {
                    continue;
                }

                $this->applyResource($manifest);
            }
        } catch (\Throwable $e) {
            throw new \Exception('Failed to apply manifest: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Apply a single resource to the cluster.
     *
     * @throws \Exception If application fails
     */
    private function applyResource(array $manifest): void
    {
        $kind = $manifest['kind'];
        $name = $manifest['metadata']['name'] ?? null;
        $namespace = $manifest['metadata']['namespace'] ?? 'default';

        if (empty($name)) {
            throw new \Exception("Resource of kind '{$kind}' is missing metadata.name");
        }

        // Map resource kinds to API paths
        $resourceMap = [
            'Namespace' => ['api' => '/api/v1', 'resource' => 'namespaces', 'namespaced' => false],
            'ConfigMap' => ['api' => '/api/v1', 'resource' => 'configmaps', 'namespaced' => true],
            'Secret' => ['api' => '/api/v1', 'resource' => 'secrets', 'namespaced' => true],
            'Service' => ['api' => '/api/v1', 'resource' => 'services', 'namespaced' => true],
            'PersistentVolumeClaim' => ['api' => '/api/v1', 'resource' => 'persistentvolumeclaims', 'namespaced' => true],
            'Deployment' => ['api' => '/apis/apps/v1', 'resource' => 'deployments', 'namespaced' => true],
            'Ingress' => ['api' => '/apis/networking.k8s.io/v1', 'resource' => 'ingresses', 'namespaced' => true],
            'HorizontalPodAutoscaler' => ['api' => '/apis/autoscaling/v2', 'resource' => 'horizontalpodautoscalers', 'namespaced' => true],
            'Route' => ['api' => '/apis/route.openshift.io/v1', 'resource' => 'routes', 'namespaced' => true],
        ];

        if (! isset($resourceMap[$kind])) {
            throw new \Exception("Unsupported resource kind: {$kind}");
        }

        $resourceInfo = $resourceMap[$kind];
        $basePath = $resourceInfo['namespaced']
            ? "{$resourceInfo['api']}/namespaces/{$namespace}/{$resourceInfo['resource']}"
            : "{$resourceInfo['api']}/{$resourceInfo['resource']}";

        // Check if resource exists
        $exists = $this->resourceExists($basePath, $name);

        if ($exists) {
            // Get existing resource for resourceVersion
            $existing = $this->makeApiRequest('GET', "{$basePath}/{$name}");
            $manifest['metadata']['resourceVersion'] = $existing['metadata']['resourceVersion'] ?? null;
            $this->makeApiRequest('PUT', "{$basePath}/{$name}", $manifest);
        } else {
            $this->makeApiRequest('POST', $basePath, $manifest);
        }
    }

    /**
     * Check if a resource exists.
     */
    private function resourceExists(string $basePath, string $name): bool
    {
        try {
            $this->makeApiRequest('GET', "{$basePath}/{$name}");

            return true;
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Delete a resource from the cluster.
     *
     * @throws \Exception If deletion fails
     */
    public function deleteResource(string $kind, string $name, string $namespace): void
    {
        try {
            // Map resource kinds to API paths
            $resourceMap = [
                'namespace' => ['api' => '/api/v1', 'resource' => 'namespaces', 'namespaced' => false],
                'configmap' => ['api' => '/api/v1', 'resource' => 'configmaps', 'namespaced' => true],
                'secret' => ['api' => '/api/v1', 'resource' => 'secrets', 'namespaced' => true],
                'service' => ['api' => '/api/v1', 'resource' => 'services', 'namespaced' => true],
                'persistentvolumeclaim' => ['api' => '/api/v1', 'resource' => 'persistentvolumeclaims', 'namespaced' => true],
                'pvc' => ['api' => '/api/v1', 'resource' => 'persistentvolumeclaims', 'namespaced' => true],
                'pod' => ['api' => '/api/v1', 'resource' => 'pods', 'namespaced' => true],
                'deployment' => ['api' => '/apis/apps/v1', 'resource' => 'deployments', 'namespaced' => true],
                'ingress' => ['api' => '/apis/networking.k8s.io/v1', 'resource' => 'ingresses', 'namespaced' => true],
                'horizontalpodautoscaler' => ['api' => '/apis/autoscaling/v2', 'resource' => 'horizontalpodautoscalers', 'namespaced' => true],
                'hpa' => ['api' => '/apis/autoscaling/v2', 'resource' => 'horizontalpodautoscalers', 'namespaced' => true],
                'route' => ['api' => '/apis/route.openshift.io/v1', 'resource' => 'routes', 'namespaced' => true],
            ];

            $kindLower = strtolower($kind);
            if (! isset($resourceMap[$kindLower])) {
                throw new \Exception("Unsupported resource kind for deletion: {$kind}");
            }

            $resourceInfo = $resourceMap[$kindLower];
            $path = $resourceInfo['namespaced']
                ? "{$resourceInfo['api']}/namespaces/{$namespace}/{$resourceInfo['resource']}/{$name}"
                : "{$resourceInfo['api']}/{$resourceInfo['resource']}/{$name}";

            $deleteOptions = [
                'apiVersion' => 'v1',
                'kind' => 'DeleteOptions',
                'propagationPolicy' => 'Foreground',
            ];

            $this->makeApiRequest('DELETE', $path, $deleteOptions);
        } catch (\Throwable $e) {
            // Ignore 404 errors (resource already deleted)
            if (str_contains($e->getMessage(), '404')) {
                return;
            }
            throw new \Exception("Failed to delete {$kind} '{$name}' in namespace '{$namespace}': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get a deployment by name and namespace.
     *
     * @return array|null The deployment data or null if not found
     *
     * @throws \Exception If connection fails
     */
    public function getDeployment(string $name, string $namespace): ?array
    {
        try {
            return $this->makeApiRequest('GET', "/apis/apps/v1/namespaces/{$namespace}/deployments/{$name}");
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw new \Exception("Failed to get deployment '{$name}' in namespace '{$namespace}': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the status of a deployment.
     *
     * @return array The deployment status with keys: ready, available, unavailable, replicas, updatedReplicas
     *
     * @throws \Exception If connection fails or deployment not found
     */
    public function getDeploymentStatus(string $name, string $namespace): array
    {
        try {
            $deployment = $this->getDeployment($name, $namespace);

            if ($deployment === null) {
                throw new \Exception("Deployment '{$name}' not found in namespace '{$namespace}'");
            }

            $status = $deployment['status'] ?? [];

            return [
                'replicas' => $status['replicas'] ?? 0,
                'readyReplicas' => $status['readyReplicas'] ?? 0,
                'availableReplicas' => $status['availableReplicas'] ?? 0,
                'unavailableReplicas' => $status['unavailableReplicas'] ?? 0,
                'updatedReplicas' => $status['updatedReplicas'] ?? 0,
                'conditions' => $status['conditions'] ?? [],
                'observedGeneration' => $status['observedGeneration'] ?? 0,
            ];
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                throw $e;
            }
            throw new \Exception("Failed to get deployment status for '{$name}' in namespace '{$namespace}': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Scale a deployment to a specific number of replicas.
     *
     * @throws \Exception If scaling fails
     */
    public function scaleDeployment(string $name, string $namespace, int $replicas): void
    {
        try {
            // Get the current deployment
            $deployment = $this->getDeployment($name, $namespace);

            if ($deployment === null) {
                throw new \Exception("Deployment '{$name}' not found in namespace '{$namespace}'");
            }

            // Update the replicas count
            $deployment['spec']['replicas'] = $replicas;

            // Apply the updated deployment
            $this->makeApiRequest('PUT', "/apis/apps/v1/namespaces/{$namespace}/deployments/{$name}", $deployment);
        } catch (\Throwable $e) {
            throw new \Exception("Failed to scale deployment '{$name}' in namespace '{$namespace}' to {$replicas} replicas: ".$e->getMessage(), 0, $e);
        }
    }

    // =========================================================================
    // Pod Operations
    // =========================================================================

    /**
     * Get pods matching a label selector.
     *
     * @param  array<string, string>  $labelSelector  Key-value pairs for label selection
     * @return array<array> List of pod data
     *
     * @throws \Exception If connection fails
     */
    public function getPods(string $namespace, array $labelSelector = []): array
    {
        try {
            $path = "/api/v1/namespaces/{$namespace}/pods";

            if (! empty($labelSelector)) {
                $labels = [];
                foreach ($labelSelector as $key => $value) {
                    $labels[] = "{$key}={$value}";
                }
                $path .= '?labelSelector='.urlencode(implode(',', $labels));
            }

            $response = $this->makeApiRequest('GET', $path);

            return $response['items'] ?? [];
        } catch (\Throwable $e) {
            throw new \Exception("Failed to get pods in namespace '{$namespace}': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get logs from a pod.
     *
     * @param  string  $name  The pod name
     * @param  string  $namespace  The namespace
     * @param  string|null  $container  The container name (optional, required if pod has multiple containers)
     * @return string The pod logs
     *
     * @throws \Exception If connection fails
     */
    public function getPodLogs(string $name, string $namespace, ?string $container = null): string
    {
        try {
            $path = "/api/v1/namespaces/{$namespace}/pods/{$name}/log";

            if ($container !== null) {
                $path .= "?container={$container}";
            }

            $client = $this->getHttpClient();
            $options = [
                'headers' => $this->clientOptions['headers'] ?? [],
            ];

            if (isset($this->clientOptions['verify'])) {
                $options['verify'] = $this->clientOptions['verify'];
            }
            if (isset($this->clientOptions['cert'])) {
                $options['cert'] = $this->clientOptions['cert'];
            }
            if (isset($this->clientOptions['ssl_key'])) {
                $options['ssl_key'] = $this->clientOptions['ssl_key'];
            }

            $response = $client->request('GET', $path, $options);

            return $response->getBody()->getContents();
        } catch (\Throwable $e) {
            throw new \Exception("Failed to get logs for pod '{$name}' in namespace '{$namespace}': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Stream logs from a pod.
     *
     * @param  string  $name  The pod name
     * @param  string  $namespace  The namespace
     * @param  callable  $callback  Callback function that receives log lines
     *
     * @throws \Exception If connection fails
     */
    public function streamPodLogs(string $name, string $namespace, callable $callback): void
    {
        try {
            // Get logs with tailLines
            $path = "/api/v1/namespaces/{$namespace}/pods/{$name}/log?tailLines=100";

            $client = $this->getHttpClient();
            $options = [
                'headers' => $this->clientOptions['headers'] ?? [],
            ];

            if (isset($this->clientOptions['verify'])) {
                $options['verify'] = $this->clientOptions['verify'];
            }
            if (isset($this->clientOptions['cert'])) {
                $options['cert'] = $this->clientOptions['cert'];
            }
            if (isset($this->clientOptions['ssl_key'])) {
                $options['ssl_key'] = $this->clientOptions['ssl_key'];
            }

            $response = $client->request('GET', $path, $options);
            $logs = $response->getBody()->getContents();

            // Split logs into lines and call the callback for each
            $lines = explode("\n", $logs);
            foreach ($lines as $line) {
                if (! empty($line)) {
                    $callback($line);
                }
            }
        } catch (\Throwable $e) {
            throw new \Exception("Failed to stream logs for pod '{$name}' in namespace '{$namespace}': ".$e->getMessage(), 0, $e);
        }
    }

    // =========================================================================
    // Service/Ingress Operations
    // =========================================================================

    /**
     * Get a service by name and namespace.
     *
     * @return array|null The service data or null if not found
     *
     * @throws \Exception If connection fails
     */
    public function getService(string $name, string $namespace): ?array
    {
        try {
            return $this->makeApiRequest('GET', "/api/v1/namespaces/{$namespace}/services/{$name}");
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw new \Exception("Failed to get service '{$name}' in namespace '{$namespace}': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get an ingress by name and namespace.
     *
     * @return array|null The ingress data or null if not found
     *
     * @throws \Exception If connection fails
     */
    public function getIngress(string $name, string $namespace): ?array
    {
        try {
            return $this->makeApiRequest('GET', "/apis/networking.k8s.io/v1/namespaces/{$namespace}/ingresses/{$name}");
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw new \Exception("Failed to get ingress '{$name}' in namespace '{$namespace}': ".$e->getMessage(), 0, $e);
        }
    }

    // =========================================================================
    // Events
    // =========================================================================

    /**
     * Get events from a namespace.
     *
     * @param  string  $namespace  The namespace to get events from
     * @param  string|null  $fieldSelector  Optional field selector (e.g., 'involvedObject.name=my-pod')
     * @return array<array> List of events
     *
     * @throws \Exception If connection fails
     */
    public function getEvents(string $namespace, ?string $fieldSelector = null): array
    {
        try {
            $path = "/api/v1/namespaces/{$namespace}/events";

            if ($fieldSelector !== null) {
                $path .= '?fieldSelector='.urlencode($fieldSelector);
            }

            $response = $this->makeApiRequest('GET', $path);

            return $response['items'] ?? [];
        } catch (\Throwable $e) {
            throw new \Exception("Failed to get events in namespace '{$namespace}': ".$e->getMessage(), 0, $e);
        }
    }

    // =========================================================================
    // Health Checks
    // =========================================================================

    /**
     * Check if the Kubernetes API is healthy and reachable.
     *
     * @return bool True if the API is healthy
     */
    public function checkApiHealth(): bool
    {
        try {
            // Try to list namespaces as a health check
            // This verifies both connectivity and basic authentication
            $this->getNamespaces();

            return true;
        } catch (\Throwable $e) {
            // Log the error for debugging
            \Log::warning('Kubernetes API health check failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Check RBAC permissions for the configured service account.
     *
     * @return array<string, bool> Map of permission to granted status
     */
    public function checkRbacPermissions(): array
    {
        $permissions = [];
        $namespace = $this->cluster->default_namespace ?? 'default';

        // Define the resources and verbs to check
        $resourceChecks = [
            'pods' => ['get', 'list', 'create', 'update', 'delete'],
            'deployments' => ['get', 'list', 'create', 'update', 'delete'],
            'services' => ['get', 'list', 'create', 'update', 'delete'],
            'ingresses' => ['get', 'list', 'create', 'update', 'delete'],
            'configmaps' => ['get', 'list', 'create', 'update', 'delete'],
            'secrets' => ['get', 'list', 'create', 'update', 'delete'],
            'persistentvolumeclaims' => ['get', 'list', 'create', 'update', 'delete'],
            'namespaces' => ['get', 'list', 'create'],
        ];

        foreach ($resourceChecks as $resource => $verbs) {
            foreach ($verbs as $verb) {
                $key = "{$resource}.{$verb}";
                $permissions[$key] = $this->checkPermission($resource, $verb, $namespace);
            }
        }

        return $permissions;
    }

    /**
     * Check a specific RBAC permission using SelfSubjectAccessReview.
     */
    private function checkPermission(string $resource, string $verb, string $namespace): bool
    {
        try {
            // Map resource names to API groups
            $apiGroups = [
                'pods' => '',
                'services' => '',
                'configmaps' => '',
                'secrets' => '',
                'persistentvolumeclaims' => '',
                'namespaces' => '',
                'deployments' => 'apps',
                'ingresses' => 'networking.k8s.io',
            ];

            $apiGroup = $apiGroups[$resource] ?? '';

            // Create a SelfSubjectAccessReview request
            $review = [
                'apiVersion' => 'authorization.k8s.io/v1',
                'kind' => 'SelfSubjectAccessReview',
                'spec' => [
                    'resourceAttributes' => [
                        'namespace' => $namespace,
                        'verb' => $verb,
                        'resource' => $resource,
                        'group' => $apiGroup,
                    ],
                ],
            ];

            $response = $this->makeApiRequest('POST', '/apis/authorization.k8s.io/v1/selfsubjectaccessreviews', $review);

            return $response['status']['allowed'] ?? false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the cluster associated with this service.
     */
    public function getCluster(): KubernetesCluster
    {
        return $this->cluster;
    }

    // =========================================================================
    // OpenShift Route Operations
    // =========================================================================

    /**
     * Check if an OpenShift Route exists.
     */
    public function openShiftRouteExists(string $name, string $namespace): bool
    {
        try {
            $route = $this->getOpenShiftRoute($name, $namespace);

            return $route !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get an OpenShift Route by name and namespace.
     *
     * @return array|null The route data or null if not found
     *
     * @throws \Exception If connection fails
     */
    public function getOpenShiftRoute(string $name, string $namespace): ?array
    {
        try {
            return $this->makeApiRequest('GET', "/apis/route.openshift.io/v1/namespaces/{$namespace}/routes/{$name}");
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw new \Exception("Failed to get OpenShift Route '{$name}' in namespace '{$namespace}': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * List all OpenShift Routes in a namespace.
     *
     * @return array<array> List of route data
     *
     * @throws \Exception If connection fails
     */
    public function listOpenShiftRoutes(string $namespace, array $labelSelector = []): array
    {
        $path = "/apis/route.openshift.io/v1/namespaces/{$namespace}/routes";

        if (! empty($labelSelector)) {
            $labels = [];
            foreach ($labelSelector as $key => $value) {
                $labels[] = "{$key}={$value}";
            }
            $path .= '?labelSelector='.urlencode(implode(',', $labels));
        }

        $response = $this->makeApiRequest('GET', $path);

        return $response['items'] ?? [];
    }

    /**
     * Check if the cluster supports OpenShift Routes.
     *
     * @return bool True if the cluster supports OpenShift Routes
     */
    public function supportsOpenShiftRoutes(): bool
    {
        try {
            // Check if the route.openshift.io API group is available
            $this->makeApiRequest('GET', '/apis/route.openshift.io/v1');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

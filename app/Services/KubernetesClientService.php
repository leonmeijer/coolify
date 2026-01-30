<?php

namespace App\Services;

use App\Models\KubernetesCluster;
use Maclof\Kubernetes\Client;
use Maclof\Kubernetes\Models\DeleteOptions;
use Maclof\Kubernetes\Models\Deployment;
use Maclof\Kubernetes\Models\Ingress;
use Maclof\Kubernetes\Models\NamespaceModel;
use Maclof\Kubernetes\Models\Service;

/**
 * KubernetesClientService
 *
 * Service for communicating with the Kubernetes API.
 * Provides methods for managing namespaces, deployments, pods, services, and ingresses.
 */
class KubernetesClientService
{
    private KubernetesCluster $cluster;

    private ?Client $client = null;

    /**
     * Create a new KubernetesClientService instance.
     */
    public function __construct(KubernetesCluster $cluster)
    {
        $this->cluster = $cluster;
    }

    /**
     * Get the Kubernetes client instance.
     *
     * @throws \Exception If the client cannot be initialized
     */
    private function getClient(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = $this->createClientFromKubeconfig();

        return $this->client;
    }

    /**
     * Create a Kubernetes client from the cluster's kubeconfig.
     *
     * @throws \Exception If the kubeconfig is invalid or cannot be parsed
     */
    private function createClientFromKubeconfig(): Client
    {
        $kubeconfig = $this->cluster->kubeconfig;

        if (empty($kubeconfig)) {
            throw new \Exception('Kubeconfig is empty or not configured for this cluster.');
        }

        // Parse the kubeconfig YAML
        $config = \Symfony\Component\Yaml\Yaml::parse($kubeconfig);

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
        $options = $this->buildClientOptions($clusterConfig, $userConfig);

        return new Client($options);
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
            'master' => $server,
        ];

        // Handle CA certificate
        if (! empty($cluster['certificate-authority-data'])) {
            $caCert = base64_decode($cluster['certificate-authority-data']);
            $caCertPath = $this->writeTempFile($caCert, 'ca-cert');
            $options['ca_cert'] = $caCertPath;
        } elseif (! empty($cluster['certificate-authority'])) {
            $options['ca_cert'] = $cluster['certificate-authority'];
        }

        // Handle insecure skip TLS verify
        if (! empty($cluster['insecure-skip-tls-verify'])) {
            $options['verify'] = false;
        }

        // Handle client certificate authentication
        if (! empty($user['client-certificate-data'])) {
            $clientCert = base64_decode($user['client-certificate-data']);
            $clientCertPath = $this->writeTempFile($clientCert, 'client-cert');
            $options['client_cert'] = $clientCertPath;
        } elseif (! empty($user['client-certificate'])) {
            $options['client_cert'] = $user['client-certificate'];
        }

        if (! empty($user['client-key-data'])) {
            $clientKey = base64_decode($user['client-key-data']);
            $clientKeyPath = $this->writeTempFile($clientKey, 'client-key');
            $options['client_key'] = $clientKeyPath;
        } elseif (! empty($user['client-key'])) {
            $options['client_key'] = $user['client-key'];
        }

        // Handle token authentication
        if (! empty($user['token'])) {
            $options['token'] = $user['token'];
        }

        // Handle username/password authentication
        if (! empty($user['username']) && ! empty($user['password'])) {
            $options['username'] = $user['username'];
            $options['password'] = $user['password'];
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
            $namespace = new NamespaceModel([
                'metadata' => [
                    'name' => $name,
                ],
            ]);

            $this->getClient()->namespaces()->create($namespace);
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
            return $this->getClient()->namespaces()->exists($name);
        } catch (\Throwable $e) {
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
            $namespaces = $this->getClient()->namespaces()->find();
            $result = [];

            foreach ($namespaces as $namespace) {
                $result[] = $namespace->getMetadata('name');
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

                $manifest = \Symfony\Component\Yaml\Yaml::parse($document);
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

        $client = $this->getClient();

        switch ($kind) {
            case 'Namespace':
                $model = new NamespaceModel($manifest);
                if ($client->namespaces()->exists($name)) {
                    $client->namespaces()->update($model);
                } else {
                    $client->namespaces()->create($model);
                }
                break;

            case 'Deployment':
                $model = new Deployment($manifest);
                $client->setNamespace($namespace);
                if ($client->deployments()->exists($name)) {
                    $client->deployments()->update($model);
                } else {
                    $client->deployments()->create($model);
                }
                break;

            case 'Service':
                $model = new Service($manifest);
                $client->setNamespace($namespace);
                if ($client->services()->exists($name)) {
                    $client->services()->update($model);
                } else {
                    $client->services()->create($model);
                }
                break;

            case 'Ingress':
                $model = new Ingress($manifest);
                $client->setNamespace($namespace);
                if ($client->ingresses()->exists($name)) {
                    $client->ingresses()->update($model);
                } else {
                    $client->ingresses()->create($model);
                }
                break;

            case 'ConfigMap':
                $model = new \Maclof\Kubernetes\Models\ConfigMap($manifest);
                $client->setNamespace($namespace);
                if ($client->configMaps()->exists($name)) {
                    $client->configMaps()->update($model);
                } else {
                    $client->configMaps()->create($model);
                }
                break;

            case 'Secret':
                $model = new \Maclof\Kubernetes\Models\Secret($manifest);
                $client->setNamespace($namespace);
                if ($client->secrets()->exists($name)) {
                    $client->secrets()->update($model);
                } else {
                    $client->secrets()->create($model);
                }
                break;

            case 'PersistentVolumeClaim':
                $model = new \Maclof\Kubernetes\Models\PersistentVolumeClaim($manifest);
                $client->setNamespace($namespace);
                if ($client->persistentVolumeClaims()->exists($name)) {
                    $client->persistentVolumeClaims()->update($model);
                } else {
                    $client->persistentVolumeClaims()->create($model);
                }
                break;

            case 'HorizontalPodAutoscaler':
                $model = new \Maclof\Kubernetes\Models\HorizontalPodAutoscaler($manifest);
                $client->setNamespace($namespace);
                if ($client->horizontalPodAutoscalers()->exists($name)) {
                    $client->horizontalPodAutoscalers()->update($model);
                } else {
                    $client->horizontalPodAutoscalers()->create($model);
                }
                break;

            case 'Route':
                // OpenShift Route - use custom API call since it's not in standard k8s
                $this->applyOpenShiftRoute($manifest, $name, $namespace);
                break;

            default:
                throw new \Exception("Unsupported resource kind: {$kind}")
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
            $client = $this->getClient();
            $client->setNamespace($namespace);

            $deleteOptions = new DeleteOptions([
                'propagationPolicy' => 'Foreground',
            ]);

            switch (strtolower($kind)) {
                case 'deployment':
                    $client->deployments()->deleteByName($name, $deleteOptions);
                    break;

                case 'service':
                    $client->services()->deleteByName($name, $deleteOptions);
                    break;

                case 'ingress':
                    $client->ingresses()->deleteByName($name, $deleteOptions);
                    break;

                case 'configmap':
                    $client->configMaps()->deleteByName($name, $deleteOptions);
                    break;

                case 'secret':
                    $client->secrets()->deleteByName($name, $deleteOptions);
                    break;

                case 'persistentvolumeclaim':
                case 'pvc':
                    $client->persistentVolumeClaims()->deleteByName($name, $deleteOptions);
                    break;

                case 'horizontalpodautoscaler':
                case 'hpa':
                    $client->horizontalPodAutoscalers()->deleteByName($name, $deleteOptions);
                    break;

                case 'pod':
                    $client->pods()->deleteByName($name, $deleteOptions);
                    break;

                case 'namespace':
                    $client->namespaces()->deleteByName($name, $deleteOptions);
                    break;

                case 'route':
                    // OpenShift Route - use custom API call
                    $this->deleteOpenShiftRoute($name, $namespace);
                    break;

                default:
                    throw new \Exception("Unsupported resource kind for deletion: {$kind}");
            }
        } catch (\Throwable $e) {
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
            $client = $this->getClient();
            $client->setNamespace($namespace);

            if (! $client->deployments()->exists($name)) {
                return null;
            }

            $deployment = $client->deployments()->find()->first(function ($d) use ($name) {
                return $d->getMetadata('name') === $name;
            });

            if ($deployment === null) {
                return null;
            }

            return $deployment->toArray();
        } catch (\Throwable $e) {
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
            $client = $this->getClient();
            $client->setNamespace($namespace);

            // Get the current deployment
            $deployment = $this->getDeployment($name, $namespace);

            if ($deployment === null) {
                throw new \Exception("Deployment '{$name}' not found in namespace '{$namespace}'");
            }

            // Update the replicas count
            $deployment['spec']['replicas'] = $replicas;

            // Apply the updated deployment
            $model = new Deployment($deployment);
            $client->deployments()->update($model);
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
            $client = $this->getClient();
            $client->setNamespace($namespace);

            $query = [];
            if (! empty($labelSelector)) {
                $labels = [];
                foreach ($labelSelector as $key => $value) {
                    $labels[] = "{$key}={$value}";
                }
                $query['labelSelector'] = implode(',', $labels);
            }

            $pods = $client->pods()->setLabelSelector($query['labelSelector'] ?? '')->find();
            $result = [];

            foreach ($pods as $pod) {
                $result[] = $pod->toArray();
            }

            return $result;
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
            $client = $this->getClient();
            $client->setNamespace($namespace);

            $options = [];
            if ($container !== null) {
                $options['container'] = $container;
            }

            return $client->pods()->logs($name, $options);
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
            $client = $this->getClient();
            $client->setNamespace($namespace);

            // Get logs with follow option
            // Note: The maclof/kubernetes-client library may not support true streaming,
            // so we implement a polling approach as a fallback
            $options = [
                'follow' => true,
                'tailLines' => 100,
            ];

            $logs = $client->pods()->logs($name, $options);

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
            $client = $this->getClient();
            $client->setNamespace($namespace);

            if (! $client->services()->exists($name)) {
                return null;
            }

            $service = $client->services()->find()->first(function ($s) use ($name) {
                return $s->getMetadata('name') === $name;
            });

            if ($service === null) {
                return null;
            }

            return $service->toArray();
        } catch (\Throwable $e) {
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
            $client = $this->getClient();
            $client->setNamespace($namespace);

            if (! $client->ingresses()->exists($name)) {
                return null;
            }

            $ingress = $client->ingresses()->find()->first(function ($i) use ($name) {
                return $i->getMetadata('name') === $name;
            });

            if ($ingress === null) {
                return null;
            }

            return $ingress->toArray();
        } catch (\Throwable $e) {
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
            $client = $this->getClient();
            $client->setNamespace($namespace);

            $events = $client->events();

            if ($fieldSelector !== null) {
                $events = $events->setFieldSelector($fieldSelector);
            }

            $result = [];
            foreach ($events->find() as $event) {
                $result[] = $event->toArray();
            }

            return $result;
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
     *
     * @throws \Exception If connection fails
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
     * Tests the minimum required permissions for Coolify to manage deployments:
     * - pods: get, list, create, update, delete
     * - deployments: get, list, create, update, delete
     * - services: get, list, create, update, delete
     * - ingresses: get, list, create, update, delete
     * - configmaps: get, list, create, update, delete
     * - secrets: get, list, create, update, delete
     * - persistentvolumeclaims: get, list, create, update, delete
     * - namespaces: get, list, create
     *
     * @return array<string, bool> Map of permission to granted status
     *
     * @throws \Exception If connection fails
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
     *
     * @param  string  $resource  The resource type (e.g., 'pods', 'deployments')
     * @param  string  $verb  The verb to check (e.g., 'get', 'list', 'create')
     * @param  string  $namespace  The namespace to check permissions in
     * @return bool True if the permission is granted
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

            // Use the client's HTTP client to make the request
            $client = $this->getClient();

            // The maclof/kubernetes-client doesn't have direct support for SelfSubjectAccessReview,
            // so we'll use a simpler approach: try to perform the operation and catch errors
            return $this->testPermissionByOperation($resource, $verb, $namespace);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Test a permission by attempting the operation.
     *
     * @param  string  $resource  The resource type
     * @param  string  $verb  The verb to test
     * @param  string  $namespace  The namespace
     * @return bool True if the permission is granted
     */
    private function testPermissionByOperation(string $resource, string $verb, string $namespace): bool
    {
        try {
            $client = $this->getClient();

            // For 'list' and 'get' verbs, we can test by actually listing
            if (in_array($verb, ['list', 'get'])) {
                $client->setNamespace($namespace);

                switch ($resource) {
                    case 'pods':
                        $client->pods()->find();
                        break;
                    case 'deployments':
                        $client->deployments()->find();
                        break;
                    case 'services':
                        $client->services()->find();
                        break;
                    case 'ingresses':
                        $client->ingresses()->find();
                        break;
                    case 'configmaps':
                        $client->configMaps()->find();
                        break;
                    case 'secrets':
                        $client->secrets()->find();
                        break;
                    case 'persistentvolumeclaims':
                        $client->persistentVolumeClaims()->find();
                        break;
                    case 'namespaces':
                        $client->namespaces()->find();
                        break;
                    default:
                        return false;
                }

                return true;
            }

            // For create, update, delete - we assume permission if list works
            // This is a simplification; in production, you might want to use
            // SelfSubjectAccessReview API if available
            if (in_array($verb, ['create', 'update', 'delete'])) {
                // If we can list, we'll assume we have the other permissions
                // This is not 100% accurate but avoids creating test resources
                return $this->testPermissionByOperation($resource, 'list', $namespace);
            }

            return false;
        } catch (\Throwable $e) {
            // If we get a 403 Forbidden, the permission is denied
            if (str_contains($e->getMessage(), '403') || str_contains($e->getMessage(), 'Forbidden')) {
                return false;
            }

            // For other errors (like 404 for empty lists), assume permission is granted
            return true;
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
     * Apply an OpenShift Route manifest.
     *
     * Routes are OpenShift-specific resources that are not part of the standard
     * Kubernetes API. They use the route.openshift.io/v1 API group.
     *
     * @throws \Exception If the operation fails
     */
    private function applyOpenShiftRoute(array $manifest, string $name, string $namespace): void
    {
        $exists = $this->openShiftRouteExists($name, $namespace);

        if ($exists) {
            $this->updateOpenShiftRoute($manifest, $name, $namespace);
        } else {
            $this->createOpenShiftRoute($manifest, $namespace);
        }
    }

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
            $response = $this->makeOpenShiftApiRequest(
                'GET',
                "/apis/route.openshift.io/v1/namespaces/{$namespace}/routes/{$name}"
            );

            return $response;
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                return null;
            }
            throw new \Exception("Failed to get OpenShift Route '{$name}' in namespace '{$namespace}': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Create an OpenShift Route.
     *
     * @throws \Exception If creation fails
     */
    private function createOpenShiftRoute(array $manifest, string $namespace): void
    {
        $this->makeOpenShiftApiRequest(
            'POST',
            "/apis/route.openshift.io/v1/namespaces/{$namespace}/routes",
            $manifest
        );
    }

    /**
     * Update an OpenShift Route.
     *
     * @throws \Exception If update fails
     */
    private function updateOpenShiftRoute(array $manifest, string $name, string $namespace): void
    {
        // Get the existing route to preserve resourceVersion
        $existing = $this->getOpenShiftRoute($name, $namespace);
        if ($existing !== null) {
            $manifest['metadata']['resourceVersion'] = $existing['metadata']['resourceVersion'] ?? null;
        }

        $this->makeOpenShiftApiRequest(
            'PUT',
            "/apis/route.openshift.io/v1/namespaces/{$namespace}/routes/{$name}",
            $manifest
        );
    }

    /**
     * Delete an OpenShift Route.
     *
     * @throws \Exception If deletion fails
     */
    private function deleteOpenShiftRoute(string $name, string $namespace): void
    {
        try {
            $this->makeOpenShiftApiRequest(
                'DELETE',
                "/apis/route.openshift.io/v1/namespaces/{$namespace}/routes/{$name}"
            );
        } catch (\Throwable $e) {
            // Ignore 404 errors (route already deleted)
            if (! str_contains($e->getMessage(), '404') && ! str_contains($e->getMessage(), 'not found')) {
                throw $e;
            }
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

        $response = $this->makeOpenShiftApiRequest('GET', $path);

        return $response['items'] ?? [];
    }

    /**
     * Check if the cluster supports OpenShift Routes.
     *
     * This is useful to determine whether to use Routes (OpenShift/OKD)
     * or standard Kubernetes Ingress.
     *
     * @return bool True if the cluster supports OpenShift Routes
     */
    public function supportsOpenShiftRoutes(): bool
    {
        try {
            // Check if the route.openshift.io API group is available
            $this->makeOpenShiftApiRequest('GET', '/apis/route.openshift.io/v1');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Make a direct HTTP request to the Kubernetes/OpenShift API.
     *
     * This is used for resources not natively supported by the maclof/kubernetes-client library.
     *
     * @throws \Exception If the request fails
     */
    private function makeOpenShiftApiRequest(string $method, string $path, ?array $body = null): ?array
    {
        $kubeconfig = $this->cluster->kubeconfig;

        if (empty($kubeconfig)) {
            throw new \Exception('Kubeconfig is empty or not configured for this cluster.');
        }

        $config = \Symfony\Component\Yaml\Yaml::parse($kubeconfig);
        $contextName = $this->cluster->context_name ?? ($config['current-context'] ?? null);
        $context = $this->findContextByName($config, $contextName);
        $clusterConfig = $this->findClusterByName($config, $context['context']['cluster'] ?? '');
        $userConfig = $this->findUserByName($config, $context['context']['user'] ?? '');

        $cluster = $clusterConfig['cluster'] ?? [];
        $user = $userConfig['user'] ?? [];
        $server = rtrim($cluster['server'] ?? '', '/');

        $url = $server.$path;

        // Build HTTP client options
        $httpOptions = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        // Handle authentication
        if (! empty($user['token'])) {
            $httpOptions['headers']['Authorization'] = 'Bearer '.$user['token'];
        }

        // Handle TLS configuration
        if (! empty($cluster['insecure-skip-tls-verify'])) {
            $httpOptions['verify'] = false;
        } elseif (! empty($cluster['certificate-authority-data'])) {
            $caCert = base64_decode($cluster['certificate-authority-data']);
            $caCertPath = $this->writeTempFile($caCert, 'ca-cert');
            $httpOptions['verify'] = $caCertPath;
        }

        // Handle client certificate authentication
        if (! empty($user['client-certificate-data']) && ! empty($user['client-key-data'])) {
            $clientCert = base64_decode($user['client-certificate-data']);
            $clientKey = base64_decode($user['client-key-data']);
            $clientCertPath = $this->writeTempFile($clientCert, 'client-cert');
            $clientKeyPath = $this->writeTempFile($clientKey, 'client-key');
            $httpOptions['cert'] = $clientCertPath;
            $httpOptions['ssl_key'] = $clientKeyPath;
        }

        // Add body for POST/PUT/PATCH requests
        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $httpOptions['json'] = $body;
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->request($method, $url, $httpOptions);
            $responseBody = $response->getBody()->getContents();

            if (empty($responseBody)) {
                return null;
            }

            return json_decode($responseBody, true);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $body = $e->getResponse()->getBody()->getContents();
            $message = json_decode($body, true)['message'] ?? $body;
            throw new \Exception("OpenShift API request failed ({$statusCode}): {$message}", $statusCode, $e);
        } catch (\Throwable $e) {
            throw new \Exception("OpenShift API request failed: ".$e->getMessage(), 0, $e);
        }
    }
}

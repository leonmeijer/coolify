<?php

namespace App\Services;

use App\Models\Application;
use App\Models\KubernetesDestination;
use App\Models\KubernetesDeploymentSettings;
use App\Models\Service;
use Symfony\Component\Yaml\Yaml;

/**
 * KubernetesManifestGenerator
 *
 * Service for generating Kubernetes manifests from Coolify application/service configurations.
 * Converts Coolify resources to Kubernetes Deployments, Services, Ingress, ConfigMaps, Secrets, PVCs, and HPAs.
 *
 * @see Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6
 */
class KubernetesManifestGenerator
{
    private Application|Service $resource;

    private KubernetesDestination $destination;

    private ?KubernetesDeploymentSettings $deploymentSettings = null;

    /**
     * Generated manifests cache.
     *
     * @var array<string, array>
     */
    private array $manifests = [];

    /**
     * Create a new KubernetesManifestGenerator instance.
     *
     * @param  Application|Service  $resource  The Coolify resource to generate manifests for
     * @param  KubernetesDestination  $destination  The Kubernetes destination configuration
     */
    public function __construct(Application|Service $resource, KubernetesDestination $destination)
    {
        $this->resource = $resource;
        $this->destination = $destination;

        // Load deployment settings if available (only for Applications)
        if ($resource instanceof Application) {
            $this->deploymentSettings = KubernetesDeploymentSettings::where('application_id', $resource->id)->first();
        }
    }

    // =========================================================================
    // Main Generation Methods
    // =========================================================================

    /**
     * Generate all relevant Kubernetes manifests for the resource.
     *
     * @return array<string, array> Map of manifest type to manifest data
     */
    public function generateAll(): array
    {
        $this->manifests = [];

        // Always generate Deployment and Service
        $this->manifests['Deployment'] = $this->generateDeployment();
        $this->manifests['Service'] = $this->generateService();

        // Conditionally generate other resources
        $ingress = $this->generateIngress();
        if ($ingress !== null) {
            $this->manifests['Ingress'] = $ingress;
        }

        $configMap = $this->generateConfigMap();
        if ($configMap !== null) {
            $this->manifests['ConfigMap'] = $configMap;
        }

        $secret = $this->generateSecret();
        if ($secret !== null) {
            $this->manifests['Secret'] = $secret;
        }

        $pvcs = $this->generatePersistentVolumeClaim();
        if ($pvcs !== null) {
            $this->manifests['PersistentVolumeClaim'] = $pvcs;
        }

        $hpa = $this->generateHorizontalPodAutoscaler();
        if ($hpa !== null) {
            $this->manifests['HorizontalPodAutoscaler'] = $hpa;
        }

        return $this->manifests;
    }

    /**
     * Generate a Kubernetes Deployment manifest.
     *
     * @return array The Deployment manifest
     *
     * @see Requirements 3.1
     */
    public function generateDeployment(): array
    {
        $name = $this->getResourceName();
        $namespace = $this->destination->namespace;
        $labels = $this->generateLabels();
        $annotations = $this->generateAnnotations();

        $replicas = $this->getReplicas();
        $containerSpec = $this->buildContainerSpec();

        $deployment = [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'replicas' => $replicas,
                'selector' => [
                    'matchLabels' => [
                        'app.kubernetes.io/name' => $name,
                        'app.kubernetes.io/instance' => $this->resource->uuid,
                    ],
                ],
                'template' => [
                    'metadata' => [
                        'labels' => $labels,
                        'annotations' => $annotations,
                    ],
                    'spec' => [
                        'containers' => [$containerSpec],
                    ],
                ],
            ],
        ];

        // Add volumes if there are persistent storages
        $volumes = $this->buildVolumes();
        if (! empty($volumes)) {
            $deployment['spec']['template']['spec']['volumes'] = $volumes;
        }

        // Add image pull secrets if configured
        $imagePullSecrets = $this->getImagePullSecrets();
        if (! empty($imagePullSecrets)) {
            $deployment['spec']['template']['spec']['imagePullSecrets'] = $imagePullSecrets;
        }

        return $deployment;
    }

    /**
     * Generate a Kubernetes Service manifest.
     *
     * @return array The Service manifest
     *
     * @see Requirements 3.2
     */
    public function generateService(): array
    {
        $name = $this->getResourceName();
        $namespace = $this->destination->namespace;
        $labels = $this->generateLabels();
        $ports = $this->buildServicePorts();

        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'type' => 'ClusterIP',
                'selector' => [
                    'app.kubernetes.io/name' => $name,
                    'app.kubernetes.io/instance' => $this->resource->uuid,
                ],
                'ports' => $ports,
            ],
        ];
    }

    /**
     * Generate a Kubernetes Ingress manifest.
     *
     * Returns null if the resource has no FQDN configured.
     *
     * @return array|null The Ingress manifest or null
     *
     * @see Requirements 3.3
     */
    public function generateIngress(): ?array
    {
        $fqdn = $this->getFqdn();
        if (empty($fqdn)) {
            return null;
        }

        $name = $this->getResourceName();
        $namespace = $this->destination->namespace;
        $labels = $this->generateLabels();
        $hosts = $this->parseHosts($fqdn);

        if (empty($hosts)) {
            return null;
        }

        $rules = [];
        $tls = [];

        foreach ($hosts as $host) {
            $rules[] = [
                'host' => $host['hostname'],
                'http' => [
                    'paths' => [
                        [
                            'path' => '/',
                            'pathType' => 'Prefix',
                            'backend' => [
                                'service' => [
                                    'name' => $name,
                                    'port' => [
                                        'number' => $this->getPrimaryPort(),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            // Add TLS configuration for HTTPS hosts
            if ($host['https']) {
                $tls[] = [
                    'hosts' => [$host['hostname']],
                    'secretName' => $name . '-tls-' . str_replace('.', '-', $host['hostname']),
                ];
            }
        }

        $ingress = [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
                'annotations' => $this->buildIngressAnnotations(),
            ],
            'spec' => [
                'rules' => $rules,
            ],
        ];

        // Add ingress class if configured
        if (! empty($this->destination->ingress_class)) {
            $ingress['spec']['ingressClassName'] = $this->destination->ingress_class;
        }

        // Add TLS configuration if any HTTPS hosts
        if (! empty($tls)) {
            $ingress['spec']['tls'] = $tls;
        }

        return $ingress;
    }

    /**
     * Generate a Kubernetes ConfigMap manifest for non-sensitive environment variables.
     *
     * Returns null if there are no non-sensitive environment variables.
     *
     * @return array|null The ConfigMap manifest or null
     *
     * @see Requirements 3.4
     */
    public function generateConfigMap(): ?array
    {
        $envVars = $this->getNonSensitiveEnvVars();

        if (empty($envVars)) {
            return null;
        }

        $name = $this->getResourceName() . '-config';
        $namespace = $this->destination->namespace;
        $labels = $this->generateLabels();

        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'data' => $envVars,
        ];
    }

    /**
     * Generate a Kubernetes Secret manifest for sensitive environment variables.
     *
     * Returns null if there are no sensitive environment variables.
     *
     * @return array|null The Secret manifest or null
     *
     * @see Requirements 3.4
     */
    public function generateSecret(): ?array
    {
        $envVars = $this->getSensitiveEnvVars();

        if (empty($envVars)) {
            return null;
        }

        $name = $this->getResourceName() . '-secret';
        $namespace = $this->destination->namespace;
        $labels = $this->generateLabels();

        // Base64 encode all values for Kubernetes secrets
        $encodedData = [];
        foreach ($envVars as $key => $value) {
            $encodedData[$key] = base64_encode($value);
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'type' => 'Opaque',
            'data' => $encodedData,
        ];
    }

    /**
     * Generate Kubernetes PersistentVolumeClaim manifests.
     *
     * Returns null if there are no persistent volumes configured.
     *
     * @return array|null Array of PVC manifests or null
     *
     * @see Requirements 3.5
     */
    public function generatePersistentVolumeClaim(): ?array
    {
        $persistentStorages = $this->getPersistentStorages();

        if ($persistentStorages->isEmpty()) {
            return null;
        }

        $pvcs = [];
        $labels = $this->generateLabels();
        $namespace = $this->destination->namespace;

        foreach ($persistentStorages as $storage) {
            $pvcName = $this->generatePvcName($storage);

            $pvc = [
                'apiVersion' => 'v1',
                'kind' => 'PersistentVolumeClaim',
                'metadata' => [
                    'name' => $pvcName,
                    'namespace' => $namespace,
                    'labels' => $labels,
                ],
                'spec' => [
                    'accessModes' => ['ReadWriteOnce'],
                    'resources' => [
                        'requests' => [
                            'storage' => '1Gi', // Default size, can be customized
                        ],
                    ],
                ],
            ];

            // Add storage class if configured
            if (! empty($this->destination->storage_class)) {
                $pvc['spec']['storageClassName'] = $this->destination->storage_class;
            }

            $pvcs[] = $pvc;
        }

        return $pvcs;
    }

    /**
     * Generate a Kubernetes HorizontalPodAutoscaler manifest.
     *
     * Returns null if autoscaling is not enabled.
     *
     * @return array|null The HPA manifest or null
     *
     * @see Requirements 5.2, 5.3
     */
    public function generateHorizontalPodAutoscaler(): ?array
    {
        if ($this->deploymentSettings === null || ! $this->deploymentSettings->autoscaling_enabled) {
            return null;
        }

        $name = $this->getResourceName();
        $namespace = $this->destination->namespace;
        $labels = $this->generateLabels();

        $hpa = [
            'apiVersion' => 'autoscaling/v2',
            'kind' => 'HorizontalPodAutoscaler',
            'metadata' => [
                'name' => $name . '-hpa',
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'scaleTargetRef' => [
                    'apiVersion' => 'apps/v1',
                    'kind' => 'Deployment',
                    'name' => $name,
                ],
                'minReplicas' => $this->deploymentSettings->min_replicas ?? 1,
                'maxReplicas' => $this->deploymentSettings->max_replicas ?? 10,
                'metrics' => [],
            ],
        ];

        // Add CPU metric if target is set
        if ($this->deploymentSettings->target_cpu_utilization) {
            $hpa['spec']['metrics'][] = [
                'type' => 'Resource',
                'resource' => [
                    'name' => 'cpu',
                    'target' => [
                        'type' => 'Utilization',
                        'averageUtilization' => $this->deploymentSettings->target_cpu_utilization,
                    ],
                ],
            ];
        }

        // Add memory metric if target is set
        if ($this->deploymentSettings->target_memory_utilization) {
            $hpa['spec']['metrics'][] = [
                'type' => 'Resource',
                'resource' => [
                    'name' => 'memory',
                    'target' => [
                        'type' => 'Utilization',
                        'averageUtilization' => $this->deploymentSettings->target_memory_utilization,
                    ],
                ],
            ];
        }

        // If no metrics configured, use default CPU target
        if (empty($hpa['spec']['metrics'])) {
            $hpa['spec']['metrics'][] = [
                'type' => 'Resource',
                'resource' => [
                    'name' => 'cpu',
                    'target' => [
                        'type' => 'Utilization',
                        'averageUtilization' => 80,
                    ],
                ],
            ];
        }

        return $hpa;
    }

    // =========================================================================
    // Export Methods
    // =========================================================================

    /**
     * Export all generated manifests as YAML.
     *
     * @return string YAML representation of all manifests
     *
     * @see Requirements 3.6
     */
    public function toYaml(): string
    {
        if (empty($this->manifests)) {
            $this->generateAll();
        }

        $yamlParts = [];

        foreach ($this->manifests as $type => $manifest) {
            // Handle arrays of manifests (like PVCs)
            if ($type === 'PersistentVolumeClaim' && is_array($manifest) && isset($manifest[0])) {
                foreach ($manifest as $pvc) {
                    $yamlParts[] = Yaml::dump($pvc, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
                }
            } else {
                $yamlParts[] = Yaml::dump($manifest, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            }
        }

        return implode("---\n", $yamlParts);
    }

    /**
     * Export all generated manifests as an array.
     *
     * @return array<string, array> Map of manifest type to manifest data
     */
    public function toArray(): array
    {
        if (empty($this->manifests)) {
            $this->generateAll();
        }

        return $this->manifests;
    }

    // =========================================================================
    // Helper Methods - Container Spec
    // =========================================================================

    /**
     * Build the container specification for the Deployment.
     *
     * @return array The container spec
     */
    private function buildContainerSpec(): array
    {
        $name = $this->getResourceName();
        $image = $this->getContainerImage();
        $ports = $this->buildContainerPorts();

        $container = [
            'name' => $name,
            'image' => $image,
            'ports' => $ports,
        ];

        // Add resource limits
        $resources = $this->buildResourceLimits();
        if (! empty($resources)) {
            $container['resources'] = $resources;
        }

        // Add environment variables from ConfigMap and Secret
        $envFrom = $this->buildEnvFrom();
        if (! empty($envFrom)) {
            $container['envFrom'] = $envFrom;
        }

        // Add volume mounts
        $volumeMounts = $this->buildVolumeMounts();
        if (! empty($volumeMounts)) {
            $container['volumeMounts'] = $volumeMounts;
        }

        // Add probes
        $probes = $this->buildProbes();
        if (isset($probes['livenessProbe'])) {
            $container['livenessProbe'] = $probes['livenessProbe'];
        }
        if (isset($probes['readinessProbe'])) {
            $container['readinessProbe'] = $probes['readinessProbe'];
        }

        return $container;
    }

    /**
     * Build resource limits and requests for the container.
     *
     * @return array The resource limits/requests
     */
    private function buildResourceLimits(): array
    {
        $resources = [];

        // Get limits from deployment settings or destination defaults
        $cpuLimit = $this->deploymentSettings?->cpu_limit ?? $this->destination->default_cpu_limit;
        $memoryLimit = $this->deploymentSettings?->memory_limit ?? $this->destination->default_memory_limit;
        $cpuRequest = $this->deploymentSettings?->cpu_request ?? $this->destination->default_cpu_request;
        $memoryRequest = $this->deploymentSettings?->memory_request ?? $this->destination->default_memory_request;

        if ($cpuLimit || $memoryLimit) {
            $resources['limits'] = [];
            if ($cpuLimit) {
                $resources['limits']['cpu'] = $cpuLimit;
            }
            if ($memoryLimit) {
                $resources['limits']['memory'] = $memoryLimit;
            }
        }

        if ($cpuRequest || $memoryRequest) {
            $resources['requests'] = [];
            if ($cpuRequest) {
                $resources['requests']['cpu'] = $cpuRequest;
            }
            if ($memoryRequest) {
                $resources['requests']['memory'] = $memoryRequest;
            }
        }

        return $resources;
    }

    /**
     * Build liveness and readiness probes based on health check configuration.
     *
     * @return array The probes configuration
     *
     * @see Requirements 4.5
     */
    private function buildProbes(): array
    {
        $probes = [];

        // Only Applications have health check configuration
        if (! ($this->resource instanceof Application)) {
            return $probes;
        }

        $healthCheckEnabled = $this->resource->health_check_enabled ?? false;

        if (! $healthCheckEnabled) {
            return $probes;
        }

        $livenessProbe = $this->buildLivenessProbe();
        if ($livenessProbe !== null) {
            $probes['livenessProbe'] = $livenessProbe;
        }

        $readinessProbe = $this->buildReadinessProbe();
        if ($readinessProbe !== null) {
            $probes['readinessProbe'] = $readinessProbe;
        }

        return $probes;
    }

    /**
     * Build a Kubernetes liveness probe from Coolify health check configuration.
     *
     * Converts Coolify Application health check settings to a Kubernetes liveness probe spec.
     * The liveness probe determines if the container is running. If it fails, Kubernetes
     * will restart the container.
     *
     * @return array|null The liveness probe configuration or null if health checks are disabled
     *
     * @see Requirements 4.5
     */
    public function buildLivenessProbe(): ?array
    {
        // Only Applications have health check configuration
        if (! ($this->resource instanceof Application)) {
            return null;
        }

        $healthCheckEnabled = $this->resource->health_check_enabled ?? false;

        if (! $healthCheckEnabled) {
            return null;
        }

        return $this->buildHttpProbe();
    }

    /**
     * Build a Kubernetes readiness probe from Coolify health check configuration.
     *
     * Converts Coolify Application health check settings to a Kubernetes readiness probe spec.
     * The readiness probe determines if the container is ready to receive traffic. If it fails,
     * the pod will be removed from service endpoints.
     *
     * @return array|null The readiness probe configuration or null if health checks are disabled
     *
     * @see Requirements 4.5
     */
    public function buildReadinessProbe(): ?array
    {
        // Only Applications have health check configuration
        if (! ($this->resource instanceof Application)) {
            return null;
        }

        $healthCheckEnabled = $this->resource->health_check_enabled ?? false;

        if (! $healthCheckEnabled) {
            return null;
        }

        return $this->buildHttpProbe();
    }

    /**
     * Build an HTTP probe configuration from Coolify health check settings.
     *
     * This method converts Coolify's health check configuration to a Kubernetes probe spec:
     * - health_check_path → httpGet.path
     * - health_check_port → httpGet.port
     * - health_check_scheme → httpGet.scheme (HTTP or HTTPS)
     * - health_check_interval → periodSeconds
     * - health_check_timeout → timeoutSeconds
     * - health_check_retries → failureThreshold
     * - health_check_start_period → initialDelaySeconds
     *
     * @return array The HTTP probe configuration
     *
     * @see Requirements 4.5
     */
    private function buildHttpProbe(): array
    {
        $path = $this->resource->health_check_path ?? '/';
        $port = $this->resource->health_check_port ?? $this->getPrimaryPort();
        $interval = $this->resource->health_check_interval ?? 30;
        $timeout = $this->resource->health_check_timeout ?? 10;
        $retries = $this->resource->health_check_retries ?? 3;
        $startPeriod = $this->resource->health_check_start_period ?? 0;
        $scheme = strtoupper($this->resource->health_check_scheme ?? 'HTTP');

        // Build HTTP probe spec
        $httpProbe = [
            'httpGet' => [
                'path' => $path,
                'port' => (int) $port,
                'scheme' => $scheme,
            ],
            'periodSeconds' => (int) $interval,
            'timeoutSeconds' => (int) $timeout,
            'failureThreshold' => (int) $retries,
        ];

        // Only add initialDelaySeconds if start period is greater than 0
        if ($startPeriod > 0) {
            $httpProbe['initialDelaySeconds'] = (int) $startPeriod;
        }

        return $httpProbe;
    }

    /**
     * Build volume mounts for the container.
     *
     * @return array The volume mounts
     */
    private function buildVolumeMounts(): array
    {
        $persistentStorages = $this->getPersistentStorages();
        $volumeMounts = [];

        foreach ($persistentStorages as $storage) {
            $volumeMounts[] = [
                'name' => $this->generatePvcName($storage),
                'mountPath' => $storage->mount_path,
            ];
        }

        return $volumeMounts;
    }

    /**
     * Build volumes for the pod spec.
     *
     * @return array The volumes
     */
    private function buildVolumes(): array
    {
        $persistentStorages = $this->getPersistentStorages();
        $volumes = [];

        foreach ($persistentStorages as $storage) {
            $pvcName = $this->generatePvcName($storage);
            $volumes[] = [
                'name' => $pvcName,
                'persistentVolumeClaim' => [
                    'claimName' => $pvcName,
                ],
            ];
        }

        return $volumes;
    }

    // =========================================================================
    // Helper Methods - Labels and Annotations
    // =========================================================================

    /**
     * Generate standard Kubernetes labels for the resource.
     *
     * @return array<string, string> The labels
     */
    private function generateLabels(): array
    {
        $name = $this->getResourceName();

        return [
            'app.kubernetes.io/name' => $name,
            'app.kubernetes.io/instance' => $this->resource->uuid,
            'app.kubernetes.io/managed-by' => 'coolify',
            'coolify.io/resource-type' => $this->resource instanceof Application ? 'application' : 'service',
            'coolify.io/resource-uuid' => $this->resource->uuid,
        ];
    }

    /**
     * Generate annotations for the resource.
     *
     * @return array<string, string> The annotations
     */
    private function generateAnnotations(): array
    {
        return [
            'coolify.io/generated-at' => now()->toIso8601String(),
        ];
    }

    /**
     * Build Ingress-specific annotations.
     *
     * @return array<string, string> The ingress annotations
     */
    private function buildIngressAnnotations(): array
    {
        $annotations = [
            'coolify.io/generated-at' => now()->toIso8601String(),
        ];

        // Add nginx ingress annotations if using nginx
        if (str_contains($this->destination->ingress_class ?? '', 'nginx')) {
            $annotations['nginx.ingress.kubernetes.io/proxy-body-size'] = '0';
            $annotations['nginx.ingress.kubernetes.io/proxy-read-timeout'] = '3600';
            $annotations['nginx.ingress.kubernetes.io/proxy-send-timeout'] = '3600';
        }

        return $annotations;
    }

    // =========================================================================
    // Helper Methods - Ports
    // =========================================================================

    /**
     * Build container ports from the resource configuration.
     *
     * @return array The container ports
     */
    private function buildContainerPorts(): array
    {
        $ports = [];
        $exposedPorts = $this->getExposedPorts();

        foreach ($exposedPorts as $port) {
            $ports[] = [
                'containerPort' => (int) $port,
                'protocol' => 'TCP',
            ];
        }

        // Ensure at least one port
        if (empty($ports)) {
            $ports[] = [
                'containerPort' => 80,
                'protocol' => 'TCP',
            ];
        }

        return $ports;
    }

    /**
     * Build service ports from the resource configuration.
     *
     * @return array The service ports
     */
    private function buildServicePorts(): array
    {
        $ports = [];
        $exposedPorts = $this->getExposedPorts();

        foreach ($exposedPorts as $index => $port) {
            $ports[] = [
                'name' => 'port-' . $port,
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
     * Get the primary port for the resource.
     *
     * @return int The primary port
     */
    private function getPrimaryPort(): int
    {
        $exposedPorts = $this->getExposedPorts();

        return ! empty($exposedPorts) ? (int) $exposedPorts[0] : 80;
    }

    /**
     * Get exposed ports from the resource.
     *
     * @return array<int> The exposed ports
     */
    private function getExposedPorts(): array
    {
        if ($this->resource instanceof Application) {
            $portsExposes = $this->resource->ports_exposes ?? '';

            if (empty($portsExposes)) {
                return [];
            }

            return array_map('intval', array_filter(explode(',', $portsExposes)));
        }

        // For Service resources, default to port 80
        return [80];
    }

    // =========================================================================
    // Helper Methods - Environment Variables
    // =========================================================================

    /**
     * Build envFrom references for ConfigMap and Secret.
     *
     * @return array The envFrom configuration
     */
    private function buildEnvFrom(): array
    {
        $envFrom = [];
        $name = $this->getResourceName();

        // Add ConfigMap reference if we have non-sensitive env vars
        if (! empty($this->getNonSensitiveEnvVars())) {
            $envFrom[] = [
                'configMapRef' => [
                    'name' => $name . '-config',
                ],
            ];
        }

        // Add Secret reference if we have sensitive env vars
        if (! empty($this->getSensitiveEnvVars())) {
            $envFrom[] = [
                'secretRef' => [
                    'name' => $name . '-secret',
                ],
            ];
        }

        return $envFrom;
    }

    /**
     * Get non-sensitive environment variables (for ConfigMap).
     *
     * @return array<string, string> The environment variables
     */
    private function getNonSensitiveEnvVars(): array
    {
        $envVars = $this->getAllEnvVars();
        $nonSensitive = [];

        foreach ($envVars as $key => $value) {
            // Consider variables with certain patterns as sensitive
            if (! $this->isSensitiveKey($key)) {
                $nonSensitive[$key] = (string) $value;
            }
        }

        return $nonSensitive;
    }

    /**
     * Get sensitive environment variables (for Secret).
     *
     * @return array<string, string> The environment variables
     */
    private function getSensitiveEnvVars(): array
    {
        $envVars = $this->getAllEnvVars();
        $sensitive = [];

        foreach ($envVars as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $sensitive[$key] = (string) $value;
            }
        }

        return $sensitive;
    }

    /**
     * Get all environment variables from the resource.
     *
     * @return array<string, string> The environment variables
     */
    private function getAllEnvVars(): array
    {
        $envVars = [];

        if ($this->resource instanceof Application) {
            $variables = $this->resource->runtime_environment_variables ?? collect();

            foreach ($variables as $var) {
                // Get the real value (decrypted)
                $value = $var->value ?? '';
                $envVars[$var->key] = $value;
            }
        } elseif ($this->resource instanceof Service) {
            $variables = $this->resource->environment_variables ?? collect();

            foreach ($variables as $var) {
                $value = $var->value ?? '';
                $envVars[$var->key] = $value;
            }
        }

        return $envVars;
    }

    /**
     * Check if an environment variable key is considered sensitive.
     *
     * @param  string  $key  The environment variable key
     * @return bool True if the key is sensitive
     */
    private function isSensitiveKey(string $key): bool
    {
        $sensitivePatterns = [
            'PASSWORD',
            'SECRET',
            'TOKEN',
            'KEY',
            'CREDENTIAL',
            'AUTH',
            'PRIVATE',
            'API_KEY',
            'APIKEY',
            'ACCESS_KEY',
            'DB_PASS',
            'DATABASE_PASSWORD',
        ];

        $upperKey = strtoupper($key);

        foreach ($sensitivePatterns as $pattern) {
            if (str_contains($upperKey, $pattern)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // Helper Methods - Resource Properties
    // =========================================================================

    /**
     * Get the resource name for Kubernetes objects.
     *
     * @return string The sanitized resource name
     */
    private function getResourceName(): string
    {
        $name = $this->resource->name ?? $this->resource->uuid;

        // Sanitize for Kubernetes naming requirements
        // Must be lowercase, alphanumeric, hyphens allowed, max 63 chars
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        // Ensure max length of 63 characters
        if (strlen($name) > 63) {
            $name = substr($name, 0, 63);
            $name = rtrim($name, '-');
        }

        // Ensure name is not empty
        if (empty($name)) {
            $name = 'app-' . substr($this->resource->uuid, 0, 8);
        }

        return $name;
    }

    /**
     * Get the container image for the deployment.
     *
     * @return string The container image
     */
    private function getContainerImage(): string
    {
        if ($this->resource instanceof Application) {
            // Check for docker registry image
            $registryImage = $this->resource->docker_registry_image_name ?? null;
            $registryTag = $this->resource->docker_registry_image_tag ?? 'latest';

            if (! empty($registryImage)) {
                return $registryImage . ':' . $registryTag;
            }

            // Fallback to a generated image name based on UUID
            return 'coolify/' . $this->resource->uuid . ':latest';
        }

        // For Service resources
        return 'coolify/' . $this->resource->uuid . ':latest';
    }

    /**
     * Get the FQDN (fully qualified domain name) for the resource.
     *
     * @return string|null The FQDN or null
     */
    private function getFqdn(): ?string
    {
        if ($this->resource instanceof Application) {
            return $this->resource->fqdn;
        }

        return null;
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
            // Parse the URL to extract hostname and scheme
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $url = 'https://' . $url;
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
     * Get the number of replicas for the deployment.
     *
     * @return int The replica count
     */
    private function getReplicas(): int
    {
        // First check deployment settings
        if ($this->deploymentSettings?->replicas) {
            return $this->deploymentSettings->replicas;
        }

        // Fall back to destination defaults
        return $this->destination->default_replicas ?? 1;
    }

    /**
     * Get persistent storages for the resource.
     *
     * @return \Illuminate\Support\Collection The persistent storages
     */
    private function getPersistentStorages()
    {
        if ($this->resource instanceof Application) {
            return $this->resource->persistentStorages ?? collect();
        }

        return collect();
    }

    /**
     * Generate a PVC name from a persistent storage.
     *
     * @param  mixed  $storage  The persistent storage
     * @return string The PVC name
     */
    private function generatePvcName($storage): string
    {
        $baseName = $this->getResourceName();
        $mountPath = $storage->mount_path ?? '/data';

        // Create a unique name based on mount path
        $pathSlug = strtolower(preg_replace('/[^a-z0-9]/', '-', $mountPath));
        $pathSlug = preg_replace('/-+/', '-', $pathSlug);
        $pathSlug = trim($pathSlug, '-');

        $pvcName = $baseName . '-pvc-' . $pathSlug;

        // Ensure max length
        if (strlen($pvcName) > 63) {
            $pvcName = substr($pvcName, 0, 55) . '-' . substr(md5($mountPath), 0, 7);
        }

        return $pvcName;
    }

    /**
     * Get image pull secrets if configured.
     *
     * @return array The image pull secrets
     */
    private function getImagePullSecrets(): array
    {
        // Check if the application has a private registry configured
        if ($this->resource instanceof Application) {
            $registryImage = $this->resource->docker_registry_image_name ?? null;

            // If using a private registry, we need pull secrets
            if (! empty($registryImage) && ! $this->isPublicRegistry($registryImage)) {
                return [
                    ['name' => $this->getResourceName() . '-registry-secret'],
                ];
            }
        }

        return [];
    }

    /**
     * Check if an image is from a public registry.
     *
     * @param  string  $image  The image name
     * @return bool True if public registry
     */
    private function isPublicRegistry(string $image): bool
    {
        $publicRegistries = [
            'docker.io',
            'registry.hub.docker.com',
            'ghcr.io',
            'gcr.io',
            'quay.io',
        ];

        foreach ($publicRegistries as $registry) {
            if (str_starts_with($image, $registry)) {
                return true;
            }
        }

        // Images without a registry prefix are from Docker Hub (public)
        if (! str_contains($image, '/') || ! str_contains(explode('/', $image)[0], '.')) {
            return true;
        }

        return false;
    }

    // =========================================================================
    // Docker Compose Conversion Methods
    // =========================================================================

    /**
     * Conversion warnings collected during Docker Compose parsing.
     *
     * @var array<string>
     */
    private array $conversionWarnings = [];

    /**
     * Convert a Docker Compose YAML string to Kubernetes manifests.
     *
     * This method parses a Docker Compose file and generates equivalent Kubernetes
     * resources including Deployments, Services, PVCs, ConfigMaps, and Secrets.
     *
     * @param  string  $dockerComposeYaml  The Docker Compose YAML content
     * @param  string  $namespace  The Kubernetes namespace for all generated resources
     * @param  array<string, string>  $options  Additional options for conversion:
     *                                          - 'storage_class': StorageClass for PVCs
     *                                          - 'ingress_class': IngressClass for Ingress resources
     *                                          - 'default_replicas': Default replica count (default: 1)
     * @return array{manifests: array<string, array>, warnings: array<string>}
     *
     * @throws \InvalidArgumentException If the YAML is invalid or missing required sections
     *
     * @see Requirements 7.1, 7.2, 7.3, 7.4, 7.5
     */
    public static function fromDockerCompose(string $dockerComposeYaml, string $namespace = 'default', array $options = []): array
    {
        $converter = new DockerComposeToKubernetesConverter($namespace, $options);

        return $converter->convert($dockerComposeYaml);
    }

    /**
     * Get the warnings from the last conversion.
     *
     * @return array<string> Array of warning messages
     */
    public function getConversionWarnings(): array
    {
        return $this->conversionWarnings;
    }
}

/**
 * DockerComposeToKubernetesConverter
 *
 * Internal class for converting Docker Compose files to Kubernetes manifests.
 * Handles parsing of Docker Compose YAML and generation of equivalent K8s resources.
 *
 * @internal
 */
class DockerComposeToKubernetesConverter
{
    private string $namespace;

    private array $options;

    private array $warnings = [];

    private array $manifests = [];

    /**
     * Sensitive environment variable patterns.
     */
    private const SENSITIVE_PATTERNS = [
        'PASSWORD',
        'SECRET',
        'TOKEN',
        'KEY',
        'CREDENTIAL',
        'AUTH',
        'PRIVATE',
        'API_KEY',
        'APIKEY',
        'ACCESS_KEY',
        'DB_PASS',
        'DATABASE_PASSWORD',
    ];

    /**
     * Unsupported Docker Compose features that will generate warnings.
     */
    private const UNSUPPORTED_FEATURES = [
        'network_mode' => 'host',
        'privileged' => true,
        'cap_add' => null,
        'cap_drop' => null,
    ];

    /**
     * Kompose-specific label prefixes.
     * @see https://kompose.io/user-guide/
     */
    private const KOMPOSE_LABEL_PREFIX = 'kompose.';

    public function __construct(string $namespace = 'default', array $options = [])
    {
        $this->namespace = $namespace;
        $this->options = array_merge([
            'storage_class' => null,
            'ingress_class' => null,
            'default_replicas' => 1,
        ], $options);
    }

    /**
     * Convert Docker Compose YAML to Kubernetes manifests.
     *
     * @param  string  $dockerComposeYaml  The Docker Compose YAML content
     * @return array{manifests: array<string, array>, warnings: array<string>}
     *
     * @throws \InvalidArgumentException If the YAML is invalid
     */
    public function convert(string $dockerComposeYaml): array
    {
        $this->warnings = [];
        $this->manifests = [];

        // Parse the Docker Compose YAML
        try {
            $compose = Yaml::parse($dockerComposeYaml);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid Docker Compose YAML: ' . $e->getMessage(), 0, $e);
        }

        if (! is_array($compose)) {
            throw new \InvalidArgumentException('Docker Compose file must be a valid YAML document');
        }

        if (! isset($compose['services']) || ! is_array($compose['services'])) {
            throw new \InvalidArgumentException('Docker Compose file must contain a "services" section');
        }

        // Process top-level volumes first (for named volume references)
        $namedVolumes = $this->extractNamedVolumes($compose);

        // Process each service
        foreach ($compose['services'] as $serviceName => $serviceConfig) {
            $this->convertService($serviceName, $serviceConfig, $namedVolumes);
        }

        return [
            'manifests' => $this->manifests,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Extract named volumes from the top-level volumes section.
     *
     * @param  array  $compose  The parsed Docker Compose configuration
     * @return array<string, array> Map of volume name to volume configuration
     */
    private function extractNamedVolumes(array $compose): array
    {
        $namedVolumes = [];

        if (isset($compose['volumes']) && is_array($compose['volumes'])) {
            foreach ($compose['volumes'] as $volumeName => $volumeConfig) {
                $namedVolumes[$volumeName] = $volumeConfig ?? [];
            }
        }

        return $namedVolumes;
    }

    /**
     * Convert a single Docker Compose service to Kubernetes resources.
     *
     * @param  string  $serviceName  The service name
     * @param  array  $serviceConfig  The service configuration
     * @param  array  $namedVolumes  Named volumes from the compose file
     */
    private function convertService(string $serviceName, array $serviceConfig, array $namedVolumes): void
    {
        // Sanitize service name for Kubernetes
        $k8sName = $this->sanitizeK8sName($serviceName);

        // Extract Kompose-specific labels for enhanced configuration
        $komposeLabels = $this->extractKomposeLabels($serviceConfig);

        // Check for unsupported features
        $this->checkUnsupportedFeatures($serviceName, $serviceConfig);

        // Check for build directive (requires pre-built image)
        if (isset($serviceConfig['build'])) {
            $this->addWarning("Service '{$serviceName}': 'build' directive found. Kubernetes requires pre-built container images. Please build and push the image to a registry first.");
        }

        // Get container image
        $image = $serviceConfig['image'] ?? null;
        if (! $image && isset($serviceConfig['build'])) {
            $image = $k8sName . ':latest';
            $this->addWarning("Service '{$serviceName}': No 'image' specified. Using placeholder '{$image}'. You must build and push this image before deploying.");
        } elseif (! $image) {
            $this->addWarning("Service '{$serviceName}': No 'image' or 'build' specified. Skipping service.");

            return;
        }

        // Extract environment variables
        $envResult = $this->extractEnvironmentVariables($serviceName, $serviceConfig);

        // Extract ports
        $ports = $this->extractPorts($serviceConfig);

        // Extract volumes and create PVCs (with Kompose volume size support)
        $volumeResult = $this->extractVolumes($serviceName, $k8sName, $serviceConfig, $namedVolumes, $komposeLabels);

        // Get replicas from deploy section
        $replicas = $this->extractReplicas($serviceConfig);

        // Get resource limits/requests
        $resources = $this->extractResources($serviceConfig);

        // Get restart policy
        $restartPolicy = $this->extractRestartPolicy($serviceConfig);

        // Get command and entrypoint
        $command = $this->extractCommand($serviceConfig);
        $args = $this->extractArgs($serviceConfig);

        // Get health check configuration
        $probes = $this->extractHealthCheck($serviceConfig);

        // Get image pull secret from Kompose labels
        $imagePullSecret = $this->getImagePullSecretFromKompose($komposeLabels);

        // Generate ConfigMap if there are non-sensitive env vars
        if (! empty($envResult['nonSensitive'])) {
            $this->manifests['ConfigMap'][] = $this->generateConfigMapManifest($k8sName, $envResult['nonSensitive']);
        }

        // Generate Secret if there are sensitive env vars
        if (! empty($envResult['sensitive'])) {
            $this->manifests['Secret'][] = $this->generateSecretManifest($k8sName, $envResult['sensitive']);
        }

        // Generate PVCs for named volumes
        foreach ($volumeResult['pvcs'] as $pvc) {
            $this->manifests['PersistentVolumeClaim'][] = $pvc;
        }

        // Generate Deployment
        $this->manifests['Deployment'][] = $this->generateDeploymentManifest(
            $k8sName,
            $image,
            $ports,
            $replicas,
            $resources,
            $restartPolicy,
            $command,
            $args,
            $probes,
            $volumeResult['mounts'],
            $volumeResult['volumes'],
            ! empty($envResult['nonSensitive']),
            ! empty($envResult['sensitive']),
            $imagePullSecret
        );

        // Generate Service if there are ports (with Kompose service type support)
        if (! empty($ports)) {
            $this->manifests['Service'][] = $this->generateServiceManifest($k8sName, $ports, $komposeLabels);
        }

        // Generate Ingress if kompose.service.expose is set
        $ingress = $this->generateIngressFromKompose($k8sName, $ports, $komposeLabels);
        if ($ingress !== null) {
            $this->manifests['Ingress'][] = $ingress;
        }

        // Generate HPA if kompose.hpa.* labels are set
        $hpa = $this->generateHpaFromKompose($k8sName, $komposeLabels);
        if ($hpa !== null) {
            $this->manifests['HorizontalPodAutoscaler'][] = $hpa;
        }
    }

    /**
     * Check for unsupported Docker Compose features.
     */
    private function checkUnsupportedFeatures(string $serviceName, array $config): void
    {
        // Check network_mode: host
        if (isset($config['network_mode']) && $config['network_mode'] === 'host') {
            $this->addWarning("Service '{$serviceName}': 'network_mode: host' is not supported in Kubernetes. Use hostNetwork: true in pod spec manually if needed.");
        }

        // Check privileged
        if (isset($config['privileged']) && $config['privileged'] === true) {
            $this->addWarning("Service '{$serviceName}': 'privileged: true' requires special Kubernetes security context configuration and may not be allowed by cluster policies.");
        }

        // Check cap_add/cap_drop
        if (isset($config['cap_add'])) {
            $this->addWarning("Service '{$serviceName}': 'cap_add' requires Kubernetes securityContext.capabilities configuration.");
        }
        if (isset($config['cap_drop'])) {
            $this->addWarning("Service '{$serviceName}': 'cap_drop' requires Kubernetes securityContext.capabilities configuration.");
        }

        // Check depends_on
        if (isset($config['depends_on'])) {
            $this->addWarning("Service '{$serviceName}': 'depends_on' is not directly supported in Kubernetes. Use init containers or readiness probes for dependency management.");
        }

        // Check links (deprecated even in Docker)
        if (isset($config['links'])) {
            $this->addWarning("Service '{$serviceName}': 'links' is deprecated. Kubernetes Services provide automatic DNS-based service discovery.");
        }
    }

    /**
     * Extract environment variables and split into sensitive/non-sensitive.
     *
     * @return array{sensitive: array<string, string>, nonSensitive: array<string, string>}
     */
    private function extractEnvironmentVariables(string $serviceName, array $config): array
    {
        $sensitive = [];
        $nonSensitive = [];

        if (! isset($config['environment'])) {
            return ['sensitive' => $sensitive, 'nonSensitive' => $nonSensitive];
        }

        $environment = $config['environment'];

        // Handle both list and map formats
        if (is_array($environment)) {
            foreach ($environment as $key => $value) {
                if (is_int($key)) {
                    // List format: "KEY=value" or just "KEY"
                    if (is_string($value)) {
                        $parts = explode('=', $value, 2);
                        $envKey = $parts[0];
                        $envValue = $parts[1] ?? '';
                    } else {
                        continue;
                    }
                } else {
                    // Map format: KEY: value
                    $envKey = $key;
                    $envValue = (string) ($value ?? '');
                }

                // Classify as sensitive or non-sensitive
                if ($this->isSensitiveKey($envKey)) {
                    $sensitive[$envKey] = $envValue;
                } else {
                    $nonSensitive[$envKey] = $envValue;
                }
            }
        }

        return ['sensitive' => $sensitive, 'nonSensitive' => $nonSensitive];
    }

    /**
     * Check if an environment variable key is considered sensitive.
     */
    private function isSensitiveKey(string $key): bool
    {
        $upperKey = strtoupper($key);

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (str_contains($upperKey, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract ports from Docker Compose service configuration.
     *
     * @return array<array{containerPort: int, hostPort: int|null, protocol: string}>
     */
    private function extractPorts(array $config): array
    {
        $ports = [];

        if (! isset($config['ports'])) {
            return $ports;
        }

        foreach ($config['ports'] as $port) {
            if (is_string($port)) {
                $parsed = $this->parsePortMapping($port);
                if ($parsed) {
                    $ports[] = $parsed;
                }
            } elseif (is_array($port)) {
                // Long syntax
                $containerPort = $port['target'] ?? null;
                $hostPort = $port['published'] ?? null;
                $protocol = strtoupper($port['protocol'] ?? 'TCP');

                if ($containerPort) {
                    $ports[] = [
                        'containerPort' => (int) $containerPort,
                        'hostPort' => $hostPort ? (int) $hostPort : null,
                        'protocol' => $protocol,
                    ];
                }
            }
        }

        return $ports;
    }

    /**
     * Parse a port mapping string like "8080:80" or "80" or "8080:80/udp".
     *
     * @return array{containerPort: int, hostPort: int|null, protocol: string}|null
     */
    private function parsePortMapping(string $port): ?array
    {
        // Extract protocol if present
        $protocol = 'TCP';
        if (str_contains($port, '/')) {
            [$port, $proto] = explode('/', $port, 2);
            $protocol = strtoupper($proto);
        }

        // Handle IP binding format: "127.0.0.1:8080:80"
        $parts = explode(':', $port);
        if (count($parts) === 3) {
            // IP:hostPort:containerPort
            $hostPort = (int) $parts[1];
            $containerPort = (int) $parts[2];
        } elseif (count($parts) === 2) {
            // hostPort:containerPort
            $hostPort = (int) $parts[0];
            $containerPort = (int) $parts[1];
        } else {
            // Just containerPort
            $containerPort = (int) $parts[0];
            $hostPort = null;
        }

        if ($containerPort <= 0 || $containerPort > 65535) {
            return null;
        }

        return [
            'containerPort' => $containerPort,
            'hostPort' => $hostPort,
            'protocol' => $protocol,
        ];
    }

    /**
     * Extract volumes and generate PVCs for named volumes.
     *
     * @param  string  $serviceName  The service name
     * @param  string  $k8sName  The Kubernetes-sanitized name
     * @param  array  $config  The service configuration
     * @param  array  $namedVolumes  Named volumes from the compose file
     * @param  array  $komposeLabels  Kompose-specific labels for volume configuration
     * @return array{pvcs: array, mounts: array, volumes: array}
     */
    private function extractVolumes(string $serviceName, string $k8sName, array $config, array $namedVolumes, array $komposeLabels = []): array
    {
        $pvcs = [];
        $mounts = [];
        $volumes = [];

        if (! isset($config['volumes'])) {
            return ['pvcs' => $pvcs, 'mounts' => $mounts, 'volumes' => $volumes];
        }

        // Get volume size and storage class from Kompose labels
        $volumeSize = $this->getVolumeSizeFromKompose($komposeLabels);
        $storageClass = $this->getStorageClassFromKompose($komposeLabels);

        foreach ($config['volumes'] as $index => $volume) {
            $volumeResult = $this->parseVolumeMapping($serviceName, $k8sName, $volume, $namedVolumes, $index, $volumeSize, $storageClass);

            if ($volumeResult) {
                if (isset($volumeResult['pvc'])) {
                    $pvcs[] = $volumeResult['pvc'];
                }
                if (isset($volumeResult['mount'])) {
                    $mounts[] = $volumeResult['mount'];
                }
                if (isset($volumeResult['volume'])) {
                    $volumes[] = $volumeResult['volume'];
                }
            }
        }

        return ['pvcs' => $pvcs, 'mounts' => $mounts, 'volumes' => $volumes];
    }

    /**
     * Parse a single volume mapping.
     *
     * @param  string  $serviceName  The service name
     * @param  string  $k8sName  The Kubernetes-sanitized name
     * @param  mixed  $volume  The volume specification
     * @param  array  $namedVolumes  Named volumes from the compose file
     * @param  int  $index  The volume index
     * @param  string  $volumeSize  The volume size from Kompose labels
     * @param  string|null  $storageClass  The storage class from Kompose labels
     */
    private function parseVolumeMapping(string $serviceName, string $k8sName, mixed $volume, array $namedVolumes, int $index, string $volumeSize = '1Gi', ?string $storageClass = null): ?array
    {
        $source = null;
        $target = null;
        $readOnly = false;

        if (is_string($volume)) {
            // String format: "source:target" or "source:target:ro"
            $parts = explode(':', $volume);

            if (count($parts) >= 2) {
                $source = $parts[0];
                $target = $parts[1];
                if (count($parts) >= 3 && in_array($parts[2], ['ro', 'readonly'])) {
                    $readOnly = true;
                }
            } else {
                // Just a path - treat as both source and target
                $target = $parts[0];
                $source = $parts[0];
            }
        } elseif (is_array($volume)) {
            // Long syntax
            $source = $volume['source'] ?? null;
            $target = $volume['target'] ?? null;
            $readOnly = ($volume['read_only'] ?? false) === true;
        }

        if (! $target) {
            return null;
        }

        // Determine volume type
        $volumeName = $this->sanitizeK8sName($k8sName . '-vol-' . $index);

        // Check if it's a named volume
        if ($source && isset($namedVolumes[$source])) {
            // Named volume - create a PVC
            $pvcName = $this->sanitizeK8sName($source);

            return [
                'pvc' => $this->generatePvcManifest($pvcName, $volumeSize, $storageClass),
                'mount' => [
                    'name' => $pvcName,
                    'mountPath' => $target,
                    'readOnly' => $readOnly,
                ],
                'volume' => [
                    'name' => $pvcName,
                    'persistentVolumeClaim' => [
                        'claimName' => $pvcName,
                    ],
                ],
            ];
        }

        // Check if it's a bind mount (starts with / or ./)
        if ($source && (str_starts_with($source, '/') || str_starts_with($source, './'))) {
            $this->addWarning("Service '{$serviceName}': Bind mount '{$source}:{$target}' is not directly supported in Kubernetes. Consider using a PersistentVolume, ConfigMap, or Secret instead.");

            // Still create a PVC as a placeholder
            return [
                'pvc' => $this->generatePvcManifest($volumeName, $volumeSize, $storageClass),
                'mount' => [
                    'name' => $volumeName,
                    'mountPath' => $target,
                    'readOnly' => $readOnly,
                ],
                'volume' => [
                    'name' => $volumeName,
                    'persistentVolumeClaim' => [
                        'claimName' => $volumeName,
                    ],
                ],
            ];
        }

        // Anonymous volume - create a PVC
        if (! $source || $source === $target) {
            return [
                'pvc' => $this->generatePvcManifest($volumeName, $volumeSize, $storageClass),
                'mount' => [
                    'name' => $volumeName,
                    'mountPath' => $target,
                    'readOnly' => $readOnly,
                ],
                'volume' => [
                    'name' => $volumeName,
                    'persistentVolumeClaim' => [
                        'claimName' => $volumeName,
                    ],
                ],
            ];
        }

        // Treat as named volume that wasn't declared
        $pvcName = $this->sanitizeK8sName($source);

        return [
            'pvc' => $this->generatePvcManifest($pvcName, $volumeSize, $storageClass),
            'mount' => [
                'name' => $pvcName,
                'mountPath' => $target,
                'readOnly' => $readOnly,
            ],
            'volume' => [
                'name' => $pvcName,
                'persistentVolumeClaim' => [
                    'claimName' => $pvcName,
                ],
            ],
        ];
    }

    /**
     * Extract replica count from deploy section.
     */
    private function extractReplicas(array $config): int
    {
        if (isset($config['deploy']['replicas'])) {
            return (int) $config['deploy']['replicas'];
        }

        return $this->options['default_replicas'];
    }

    /**
     * Extract resource limits and requests from deploy section.
     */
    private function extractResources(array $config): array
    {
        $resources = [];

        if (! isset($config['deploy']['resources'])) {
            return $resources;
        }

        $deployResources = $config['deploy']['resources'];

        // Limits
        if (isset($deployResources['limits'])) {
            $resources['limits'] = [];
            if (isset($deployResources['limits']['cpus'])) {
                $resources['limits']['cpu'] = $this->convertCpuValue($deployResources['limits']['cpus']);
            }
            if (isset($deployResources['limits']['memory'])) {
                $resources['limits']['memory'] = $this->convertMemoryValue($deployResources['limits']['memory']);
            }
        }

        // Reservations (become requests in K8s)
        if (isset($deployResources['reservations'])) {
            $resources['requests'] = [];
            if (isset($deployResources['reservations']['cpus'])) {
                $resources['requests']['cpu'] = $this->convertCpuValue($deployResources['reservations']['cpus']);
            }
            if (isset($deployResources['reservations']['memory'])) {
                $resources['requests']['memory'] = $this->convertMemoryValue($deployResources['reservations']['memory']);
            }
        }

        return $resources;
    }

    /**
     * Convert Docker CPU value to Kubernetes format.
     */
    private function convertCpuValue(string|float $value): string
    {
        // Docker uses fractional CPUs (e.g., "0.5" for half a CPU)
        // Kubernetes uses millicores (e.g., "500m" for half a CPU)
        $numValue = (float) $value;

        if ($numValue < 1) {
            return (int) ($numValue * 1000) . 'm';
        }

        return (string) $numValue;
    }

    /**
     * Convert Docker memory value to Kubernetes format.
     */
    private function convertMemoryValue(string $value): string
    {
        // Docker supports: b, k, m, g (case insensitive)
        // Kubernetes supports: Ki, Mi, Gi, Ti, Pi, Ei
        $value = trim($value);

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([bkmgt]?)$/i', $value, $matches)) {
            $num = (float) $matches[1];
            $unit = strtolower($matches[2] ?? '');

            switch ($unit) {
                case '':
                case 'b':
                    return (int) $num . '';
                case 'k':
                    return (int) $num . 'Ki';
                case 'm':
                    return (int) $num . 'Mi';
                case 'g':
                    return (int) $num . 'Gi';
                case 't':
                    return (int) $num . 'Ti';
            }
        }

        // Already in Kubernetes format or unknown
        return $value;
    }

    /**
     * Extract restart policy from Docker Compose configuration.
     */
    private function extractRestartPolicy(array $config): string
    {
        $restart = $config['restart'] ?? 'no';

        // Docker Compose: no, always, on-failure, unless-stopped
        // Kubernetes: Always, OnFailure, Never
        switch ($restart) {
            case 'always':
            case 'unless-stopped':
                return 'Always';
            case 'on-failure':
                return 'OnFailure';
            case 'no':
            default:
                return 'Always'; // Default for Deployments
        }
    }

    /**
     * Extract command (entrypoint in Docker).
     */
    private function extractCommand(array $config): ?array
    {
        if (! isset($config['entrypoint'])) {
            return null;
        }

        $entrypoint = $config['entrypoint'];

        if (is_string($entrypoint)) {
            // Shell format - need to wrap in shell
            return ['/bin/sh', '-c', $entrypoint];
        }

        return (array) $entrypoint;
    }

    /**
     * Extract args (command in Docker).
     */
    private function extractArgs(array $config): ?array
    {
        if (! isset($config['command'])) {
            return null;
        }

        $command = $config['command'];

        if (is_string($command)) {
            // Shell format
            return ['/bin/sh', '-c', $command];
        }

        return (array) $command;
    }

    /**
     * Extract health check and convert to Kubernetes probes.
     *
     * @return array{livenessProbe: array|null, readinessProbe: array|null}
     */
    private function extractHealthCheck(array $config): array
    {
        $probes = [
            'livenessProbe' => null,
            'readinessProbe' => null,
        ];

        if (! isset($config['healthcheck'])) {
            return $probes;
        }

        $healthcheck = $config['healthcheck'];

        // Check if healthcheck is disabled
        if (isset($healthcheck['disable']) && $healthcheck['disable'] === true) {
            return $probes;
        }

        if (! isset($healthcheck['test'])) {
            return $probes;
        }

        $test = $healthcheck['test'];

        // Parse the test command
        if (is_array($test)) {
            if ($test[0] === 'NONE') {
                return $probes;
            }
            if ($test[0] === 'CMD' || $test[0] === 'CMD-SHELL') {
                array_shift($test);
            }
            $command = $test;
        } else {
            $command = ['sh', '-c', $test];
        }

        // Convert timing values
        $interval = $this->parseDuration($healthcheck['interval'] ?? '30s');
        $timeout = $this->parseDuration($healthcheck['timeout'] ?? '30s');
        $retries = (int) ($healthcheck['retries'] ?? 3);
        $startPeriod = $this->parseDuration($healthcheck['start_period'] ?? '0s');

        $probe = [
            'exec' => [
                'command' => $command,
            ],
            'periodSeconds' => $interval,
            'timeoutSeconds' => $timeout,
            'failureThreshold' => $retries,
        ];

        if ($startPeriod > 0) {
            $probe['initialDelaySeconds'] = $startPeriod;
        }

        // Use the same probe for both liveness and readiness
        $probes['livenessProbe'] = $probe;
        $probes['readinessProbe'] = $probe;

        return $probes;
    }

    /**
     * Parse a Docker Compose duration string to seconds.
     */
    private function parseDuration(string $duration): int
    {
        $duration = trim($duration);

        if (preg_match('/^(\d+)(s|m|h)?$/i', $duration, $matches)) {
            $value = (int) $matches[1];
            $unit = strtolower($matches[2] ?? 's');

            switch ($unit) {
                case 'h':
                    return $value * 3600;
                case 'm':
                    return $value * 60;
                case 's':
                default:
                    return $value;
            }
        }

        return 30; // Default
    }

    /**
     * Sanitize a name for Kubernetes resource naming requirements.
     */
    private function sanitizeK8sName(string $name): string
    {
        // Must be lowercase, alphanumeric, hyphens allowed, max 63 chars
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        // Ensure max length of 63 characters
        if (strlen($name) > 63) {
            $name = substr($name, 0, 63);
            $name = rtrim($name, '-');
        }

        // Ensure name is not empty
        if (empty($name)) {
            $name = 'resource-' . substr(md5((string) microtime(true)), 0, 8);
        }

        return $name;
    }

    /**
     * Add a warning message.
     */
    private function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Generate standard Kubernetes labels.
     */
    private function generateLabels(string $name): array
    {
        return [
            'app.kubernetes.io/name' => $name,
            'app.kubernetes.io/managed-by' => 'coolify',
            'coolify.io/source' => 'docker-compose',
        ];
    }

    /**
     * Generate a ConfigMap manifest.
     */
    private function generateConfigMapManifest(string $name, array $data): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => $name . '-config',
                'namespace' => $this->namespace,
                'labels' => $this->generateLabels($name),
            ],
            'data' => $data,
        ];
    }

    /**
     * Generate a Secret manifest.
     */
    private function generateSecretManifest(string $name, array $data): array
    {
        // Base64 encode all values
        $encodedData = [];
        foreach ($data as $key => $value) {
            $encodedData[$key] = base64_encode($value);
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => $name . '-secret',
                'namespace' => $this->namespace,
                'labels' => $this->generateLabels($name),
            ],
            'type' => 'Opaque',
            'data' => $encodedData,
        ];
    }

    /**
     * Generate a PersistentVolumeClaim manifest.
     *
     * @param  string  $name  The PVC name
     * @param  string  $size  The storage size (e.g., '1Gi', '10Gi')
     * @param  string|null  $storageClass  Optional storage class name
     */
    private function generatePvcManifest(string $name, string $size = '1Gi', ?string $storageClass = null): array
    {
        $pvc = [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => [
                'name' => $name,
                'namespace' => $this->namespace,
                'labels' => $this->generateLabels($name),
            ],
            'spec' => [
                'accessModes' => ['ReadWriteOnce'],
                'resources' => [
                    'requests' => [
                        'storage' => $size,
                    ],
                ],
            ],
        ];

        // Use provided storage class, fall back to options, or omit if neither is set
        $effectiveStorageClass = $storageClass ?? $this->options['storage_class'] ?? null;
        if ($effectiveStorageClass) {
            $pvc['spec']['storageClassName'] = $effectiveStorageClass;
        }

        return $pvc;
    }

    /**
     * Generate a Deployment manifest.
     *
     * @param  string  $name  The deployment name
     * @param  string  $image  The container image
     * @param  array  $ports  The container ports
     * @param  int  $replicas  The number of replicas
     * @param  array  $resources  Resource limits and requests
     * @param  string  $restartPolicy  The restart policy
     * @param  array|null  $command  Optional container command
     * @param  array|null  $args  Optional container arguments
     * @param  array  $probes  Liveness and readiness probes
     * @param  array  $volumeMounts  Volume mounts for the container
     * @param  array  $volumes  Pod volumes
     * @param  bool  $hasConfigMap  Whether the deployment has a ConfigMap
     * @param  bool  $hasSecret  Whether the deployment has a Secret
     * @param  string|null  $imagePullSecret  Optional image pull secret name (from kompose.image-pull-secret)
     */
    private function generateDeploymentManifest(
        string $name,
        string $image,
        array $ports,
        int $replicas,
        array $resources,
        string $restartPolicy,
        ?array $command,
        ?array $args,
        array $probes,
        array $volumeMounts,
        array $volumes,
        bool $hasConfigMap,
        bool $hasSecret,
        ?string $imagePullSecret = null
    ): array {
        $labels = $this->generateLabels($name);

        $container = [
            'name' => $name,
            'image' => $image,
        ];

        // Add ports
        if (! empty($ports)) {
            $container['ports'] = array_map(function ($port) {
                return [
                    'containerPort' => $port['containerPort'],
                    'protocol' => $port['protocol'],
                ];
            }, $ports);
        }

        // Add resources
        if (! empty($resources)) {
            $container['resources'] = $resources;
        }

        // Add command and args
        if ($command) {
            $container['command'] = $command;
        }
        if ($args) {
            $container['args'] = $args;
        }

        // Add probes
        if ($probes['livenessProbe']) {
            $container['livenessProbe'] = $probes['livenessProbe'];
        }
        if ($probes['readinessProbe']) {
            $container['readinessProbe'] = $probes['readinessProbe'];
        }

        // Add volume mounts
        if (! empty($volumeMounts)) {
            $container['volumeMounts'] = $volumeMounts;
        }

        // Add envFrom for ConfigMap and Secret
        $envFrom = [];
        if ($hasConfigMap) {
            $envFrom[] = [
                'configMapRef' => [
                    'name' => $name . '-config',
                ],
            ];
        }
        if ($hasSecret) {
            $envFrom[] = [
                'secretRef' => [
                    'name' => $name . '-secret',
                ],
            ];
        }
        if (! empty($envFrom)) {
            $container['envFrom'] = $envFrom;
        }

        $deployment = [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $name,
                'namespace' => $this->namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'replicas' => $replicas,
                'selector' => [
                    'matchLabels' => [
                        'app.kubernetes.io/name' => $name,
                    ],
                ],
                'template' => [
                    'metadata' => [
                        'labels' => $labels,
                    ],
                    'spec' => [
                        'containers' => [$container],
                    ],
                ],
            ],
        ];

        // Add volumes
        if (! empty($volumes)) {
            // Deduplicate volumes by name
            $uniqueVolumes = [];
            foreach ($volumes as $vol) {
                $uniqueVolumes[$vol['name']] = $vol;
            }
            $deployment['spec']['template']['spec']['volumes'] = array_values($uniqueVolumes);
        }

        // Add imagePullSecrets if specified (from kompose.image-pull-secret label)
        if ($imagePullSecret) {
            $deployment['spec']['template']['spec']['imagePullSecrets'] = [
                ['name' => $imagePullSecret],
            ];
        }

        return $deployment;
    }

    /**
     * Generate a Service manifest.
     *
     * @param  string  $name  The service name
     * @param  array  $ports  The ports to expose
     * @param  array  $komposeLabels  Kompose-specific labels for configuration
     */
    private function generateServiceManifest(string $name, array $ports, array $komposeLabels = []): array
    {
        $servicePorts = [];
        foreach ($ports as $port) {
            $servicePort = [
                'name' => 'port-' . $port['containerPort'],
                'port' => $port['containerPort'],
                'targetPort' => $port['containerPort'],
                'protocol' => $port['protocol'],
            ];

            // Add nodePort if kompose.service.nodeport.port is set
            if (isset($komposeLabels['service.nodeport.port'])) {
                $servicePort['nodePort'] = (int) $komposeLabels['service.nodeport.port'];
            }

            $servicePorts[] = $servicePort;
        }

        // Determine service type from Kompose labels
        $serviceType = 'ClusterIP';
        if (isset($komposeLabels['service.type'])) {
            $type = strtolower($komposeLabels['service.type']);
            switch ($type) {
                case 'nodeport':
                    $serviceType = 'NodePort';
                    break;
                case 'loadbalancer':
                    $serviceType = 'LoadBalancer';
                    break;
                case 'headless':
                    $serviceType = 'ClusterIP';
                    // For headless services, clusterIP should be None
                    break;
                default:
                    $serviceType = 'ClusterIP';
            }
        }

        $service = [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $name,
                'namespace' => $this->namespace,
                'labels' => $this->generateLabels($name),
            ],
            'spec' => [
                'type' => $serviceType,
                'selector' => [
                    'app.kubernetes.io/name' => $name,
                ],
                'ports' => $servicePorts,
            ],
        ];

        // Handle headless service
        if (isset($komposeLabels['service.type']) && strtolower($komposeLabels['service.type']) === 'headless') {
            $service['spec']['clusterIP'] = 'None';
        }

        // Handle external traffic policy
        if (isset($komposeLabels['service.external-traffic-policy'])) {
            $service['spec']['externalTrafficPolicy'] = ucfirst(strtolower($komposeLabels['service.external-traffic-policy']));
        }

        return $service;
    }

    /**
     * Extract Kompose-specific labels from Docker Compose service labels.
     *
     * Kompose uses labels prefixed with "kompose." to configure Kubernetes
     * resource generation. This method extracts those labels and returns
     * them in a normalized format.
     *
     * @see https://kompose.io/user-guide/
     *
     * @param  array  $config  The Docker Compose service configuration
     * @return array<string, string> Map of Kompose label suffix to value
     */
    private function extractKomposeLabels(array $config): array
    {
        $komposeLabels = [];

        if (! isset($config['labels'])) {
            return $komposeLabels;
        }

        $labels = $config['labels'];

        // Handle both list and map formats
        if (is_array($labels)) {
            foreach ($labels as $key => $value) {
                if (is_int($key)) {
                    // List format: "kompose.service.type=nodeport"
                    if (is_string($value) && str_starts_with($value, self::KOMPOSE_LABEL_PREFIX)) {
                        $parts = explode('=', $value, 2);
                        if (count($parts) === 2) {
                            $labelKey = substr($parts[0], strlen(self::KOMPOSE_LABEL_PREFIX));
                            $komposeLabels[$labelKey] = $parts[1];
                        }
                    }
                } else {
                    // Map format: kompose.service.type: nodeport
                    if (str_starts_with($key, self::KOMPOSE_LABEL_PREFIX)) {
                        $labelKey = substr($key, strlen(self::KOMPOSE_LABEL_PREFIX));
                        $komposeLabels[$labelKey] = (string) $value;
                    }
                }
            }
        }

        return $komposeLabels;
    }

    /**
     * Generate an Ingress manifest from Kompose labels.
     *
     * When kompose.service.expose is set, this generates an Ingress resource.
     *
     * @param  string  $name  The service name
     * @param  array  $ports  The ports to expose
     * @param  array  $komposeLabels  Kompose-specific labels
     * @return array|null The Ingress manifest or null if not applicable
     */
    private function generateIngressFromKompose(string $name, array $ports, array $komposeLabels): ?array
    {
        if (! isset($komposeLabels['service.expose'])) {
            return null;
        }

        $expose = $komposeLabels['service.expose'];
        $host = ($expose === 'true' || $expose === '1') ? $name . '.local' : $expose;

        // Get the primary port
        $port = ! empty($ports) ? $ports[0]['containerPort'] : 80;

        $ingress = [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'name' => $name,
                'namespace' => $this->namespace,
                'labels' => $this->generateLabels($name),
                'annotations' => [],
            ],
            'spec' => [
                'rules' => [
                    [
                        'host' => $host,
                        'http' => [
                            'paths' => [
                                [
                                    'path' => '/',
                                    'pathType' => 'Prefix',
                                    'backend' => [
                                        'service' => [
                                            'name' => $name,
                                            'port' => [
                                                'number' => $port,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Add ingress class if specified
        if (isset($komposeLabels['service.expose.ingress-class-name'])) {
            $ingress['spec']['ingressClassName'] = $komposeLabels['service.expose.ingress-class-name'];
        } elseif ($this->options['ingress_class']) {
            $ingress['spec']['ingressClassName'] = $this->options['ingress_class'];
        }

        // Add TLS if secret is specified
        if (isset($komposeLabels['service.expose.tls-secret'])) {
            $ingress['spec']['tls'] = [
                [
                    'hosts' => [$host],
                    'secretName' => $komposeLabels['service.expose.tls-secret'],
                ],
            ];
        }

        return $ingress;
    }

    /**
     * Generate a HorizontalPodAutoscaler manifest from Kompose labels.
     *
     * When kompose.hpa.* labels are set, this generates an HPA resource.
     *
     * @param  string  $name  The deployment name
     * @param  array  $komposeLabels  Kompose-specific labels
     * @return array|null The HPA manifest or null if not applicable
     */
    private function generateHpaFromKompose(string $name, array $komposeLabels): ?array
    {
        // Check if any HPA labels are present
        $hasCpu = isset($komposeLabels['hpa.cpu']);
        $hasMemory = isset($komposeLabels['hpa.memory']);
        $hasMin = isset($komposeLabels['hpa.replicas.min']);
        $hasMax = isset($komposeLabels['hpa.replicas.max']);

        if (! $hasCpu && ! $hasMemory && ! $hasMin && ! $hasMax) {
            return null;
        }

        $hpa = [
            'apiVersion' => 'autoscaling/v2',
            'kind' => 'HorizontalPodAutoscaler',
            'metadata' => [
                'name' => $name . '-hpa',
                'namespace' => $this->namespace,
                'labels' => $this->generateLabels($name),
            ],
            'spec' => [
                'scaleTargetRef' => [
                    'apiVersion' => 'apps/v1',
                    'kind' => 'Deployment',
                    'name' => $name,
                ],
                'minReplicas' => (int) ($komposeLabels['hpa.replicas.min'] ?? 1),
                'maxReplicas' => (int) ($komposeLabels['hpa.replicas.max'] ?? 10),
                'metrics' => [],
            ],
        ];

        // Add CPU metric
        if ($hasCpu) {
            $hpa['spec']['metrics'][] = [
                'type' => 'Resource',
                'resource' => [
                    'name' => 'cpu',
                    'target' => [
                        'type' => 'Utilization',
                        'averageUtilization' => (int) $komposeLabels['hpa.cpu'],
                    ],
                ],
            ];
        }

        // Add memory metric
        if ($hasMemory) {
            $memoryValue = $komposeLabels['hpa.memory'];
            // Check if it's a percentage or absolute value
            if (is_numeric($memoryValue)) {
                $hpa['spec']['metrics'][] = [
                    'type' => 'Resource',
                    'resource' => [
                        'name' => 'memory',
                        'target' => [
                            'type' => 'Utilization',
                            'averageUtilization' => (int) $memoryValue,
                        ],
                    ],
                ];
            } else {
                // Absolute value like "512Mi"
                $hpa['spec']['metrics'][] = [
                    'type' => 'Resource',
                    'resource' => [
                        'name' => 'memory',
                        'target' => [
                            'type' => 'AverageValue',
                            'averageValue' => $memoryValue,
                        ],
                    ],
                ];
            }
        }

        // Default to CPU 80% if no metrics specified but min/max are
        if (empty($hpa['spec']['metrics'])) {
            $hpa['spec']['metrics'][] = [
                'type' => 'Resource',
                'resource' => [
                    'name' => 'cpu',
                    'target' => [
                        'type' => 'Utilization',
                        'averageUtilization' => 80,
                    ],
                ],
            ];
        }

        return $hpa;
    }

    /**
     * Get volume size from Kompose labels.
     *
     * @param  array  $komposeLabels  Kompose-specific labels
     * @return string The volume size (default: 1Gi)
     */
    private function getVolumeSizeFromKompose(array $komposeLabels): string
    {
        return $komposeLabels['volume.size'] ?? '1Gi';
    }

    /**
     * Get storage class from Kompose labels.
     *
     * @param  array  $komposeLabels  Kompose-specific labels
     * @return string|null The storage class or null
     */
    private function getStorageClassFromKompose(array $komposeLabels): ?string
    {
        return $komposeLabels['volume.storage-class-name'] ?? $this->options['storage_class'] ?? null;
    }

    /**
     * Get image pull secret from Kompose labels.
     *
     * @param  array  $komposeLabels  Kompose-specific labels
     * @return string|null The image pull secret name or null
     */
    private function getImagePullSecretFromKompose(array $komposeLabels): ?string
    {
        return $komposeLabels['image-pull-secret'] ?? null;
    }
}

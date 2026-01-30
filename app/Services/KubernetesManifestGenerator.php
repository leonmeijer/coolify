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
}

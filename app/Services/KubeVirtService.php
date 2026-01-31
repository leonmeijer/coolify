<?php

namespace App\Services;

use App\Exceptions\DeploymentException;
use App\Models\KubernetesCluster;

/**
 * KubeVirtService
 *
 * Service for managing KubeVirt VirtualMachines through the Kubernetes API.
 * Provides methods for creating, starting, stopping, and deleting VMs.
 *
 * @see https://kubevirt.io/api-reference/
 */
class KubeVirtService
{
    private KubernetesCluster $cluster;

    private KubernetesClientService $kubeClient;

    /**
     * KubeVirt API paths.
     */
    private const KUBEVIRT_API_VERSION = 'kubevirt.io/v1';

    private const VM_API_PATH = '/apis/kubevirt.io/v1';

    private const CDI_API_PATH = '/apis/cdi.kubevirt.io/v1beta1';

    /**
     * Create a new KubeVirtService instance.
     *
     * @param  KubernetesCluster  $cluster  The Kubernetes cluster with KubeVirt installed
     */
    public function __construct(KubernetesCluster $cluster)
    {
        $this->cluster = $cluster;
        $this->kubeClient = new KubernetesClientService($cluster);
    }

    /**
     * Create a KubeVirtService from a raw kubeconfig string.
     *
     * @param  string  $kubeconfig  The kubeconfig YAML content
     * @param  string|null  $contextName  Optional context name to use
     * @return static
     *
     * @throws DeploymentException If the kubeconfig is invalid
     */
    public static function fromKubeconfig(string $kubeconfig, ?string $contextName = null): static
    {
        // Create a temporary cluster model with the kubeconfig
        $cluster = new KubernetesCluster([
            'uuid' => 'kubevirt-'.md5($kubeconfig),
            'name' => 'KubeVirt Cluster',
            'kubeconfig' => $kubeconfig,
            'context_name' => $contextName,
            'default_namespace' => 'default',
        ]);

        return new static($cluster);
    }

    // =========================================================================
    // Namespace Operations
    // =========================================================================

    /**
     * Get all namespaces from the cluster.
     *
     * @return array<string> List of namespace names
     *
     * @throws DeploymentException If the request fails
     */
    public function getNamespaces(): array
    {
        try {
            return $this->kubeClient->getNamespaces();
        } catch (\Throwable $e) {
            throw new DeploymentException('Failed to get namespaces: '.$e->getMessage(), 0, $e);
        }
    }

    // =========================================================================
    // VirtualMachine Operations
    // =========================================================================

    /**
     * Get all VirtualMachines in a namespace.
     *
     * @param  string  $namespace  The namespace to list VMs from
     * @return array<array> List of VirtualMachine resources
     *
     * @throws DeploymentException If the request fails
     */
    public function getVirtualMachines(string $namespace): array
    {
        try {
            $response = $this->makeKubeVirtRequest(
                'GET',
                self::VM_API_PATH."/namespaces/{$namespace}/virtualmachines"
            );

            return $response['items'] ?? [];
        } catch (\Throwable $e) {
            throw new DeploymentException(
                "Failed to list VirtualMachines in namespace '{$namespace}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get a specific VirtualMachine.
     *
     * @param  string  $namespace  The namespace
     * @param  string  $name  The VM name
     * @return array|null The VirtualMachine resource or null if not found
     *
     * @throws DeploymentException If the request fails
     */
    public function getVirtualMachine(string $namespace, string $name): ?array
    {
        try {
            return $this->makeKubeVirtRequest(
                'GET',
                self::VM_API_PATH."/namespaces/{$namespace}/virtualmachines/{$name}"
            );
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw new DeploymentException(
                "Failed to get VirtualMachine '{$name}' in namespace '{$namespace}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get a VirtualMachineInstance (running VM) with details like IP address.
     *
     * @param  string  $namespace  The namespace
     * @param  string  $name  The VM name
     * @return array|null The VMI data with status, IP, and node information
     *
     * @throws DeploymentException If the request fails
     */
    public function getVirtualMachineInstance(string $namespace, string $name): ?array
    {
        try {
            $vmi = $this->makeKubeVirtRequest(
                'GET',
                self::VM_API_PATH."/namespaces/{$namespace}/virtualmachineinstances/{$name}"
            );

            if ($vmi === null) {
                return null;
            }

            // Extract useful information
            $status = $vmi['status'] ?? [];
            $phase = $status['phase'] ?? 'Unknown';

            // Get IP addresses from interfaces
            $interfaces = $status['interfaces'] ?? [];
            $ipAddresses = [];
            foreach ($interfaces as $interface) {
                if (isset($interface['ipAddress'])) {
                    $ipAddresses[] = $interface['ipAddress'];
                }
                if (isset($interface['ipAddresses'])) {
                    $ipAddresses = array_merge($ipAddresses, $interface['ipAddresses']);
                }
            }

            return [
                'name' => $vmi['metadata']['name'] ?? $name,
                'namespace' => $vmi['metadata']['namespace'] ?? $namespace,
                'phase' => $phase,
                'ready' => $phase === 'Running',
                'ipAddress' => $ipAddresses[0] ?? null,
                'ipAddresses' => array_unique($ipAddresses),
                'nodeName' => $status['nodeName'] ?? null,
                'guestOSInfo' => $status['guestOSInfo'] ?? null,
                'conditions' => $status['conditions'] ?? [],
                'raw' => $vmi,
            ];
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw new DeploymentException(
                "Failed to get VirtualMachineInstance '{$name}' in namespace '{$namespace}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Create a new VirtualMachine.
     *
     * @param  array  $params  VM parameters:
     *                         - name: string (required) - VM name
     *                         - namespace: string (required) - Target namespace
     *                         - cpu: int (default: 1) - Number of CPU cores
     *                         - memory: string (default: '1Gi') - Memory amount (e.g., '2Gi', '512Mi')
     *                         - diskSize: string (optional) - Disk size for DataVolume (e.g., '10Gi')
     *                         - image: string (required) - Container disk image or DataVolume source URL
     *                         - imageType: string (default: 'containerDisk') - 'containerDisk' or 'dataVolume'
     *                         - cloudInit: string (optional) - Cloud-init userData
     *                         - sshPublicKey: string (optional) - SSH public key to inject
     *                         - storageClassName: string (optional) - Storage class for DataVolume
     *                         - labels: array (optional) - Additional labels
     *                         - annotations: array (optional) - Additional annotations
     *                         - running: bool (default: true) - Start VM immediately
     * @return array The created VirtualMachine resource
     *
     * @throws DeploymentException If creation fails
     */
    public function createVirtualMachine(array $params): array
    {
        $this->validateCreateParams($params);

        $name = $params['name'];
        $namespace = $params['namespace'];
        $cpu = $params['cpu'] ?? 1;
        $memory = $params['memory'] ?? '1Gi';
        $image = $params['image'];
        $imageType = $params['imageType'] ?? 'containerDisk';
        $cloudInit = $params['cloudInit'] ?? null;
        $sshPublicKey = $params['sshPublicKey'] ?? null;
        $diskSize = $params['diskSize'] ?? '10Gi';
        $storageClassName = $params['storageClassName'] ?? null;
        $labels = $params['labels'] ?? [];
        $annotations = $params['annotations'] ?? [];
        $running = $params['running'] ?? true;

        // Build cloud-init userData if SSH key is provided but no cloudInit
        if ($sshPublicKey && ! $cloudInit) {
            $cloudInit = $this->generateCloudInit($sshPublicKey);
        }

        // Build the VM manifest
        $manifest = $this->buildVirtualMachineManifest(
            name: $name,
            namespace: $namespace,
            cpu: $cpu,
            memory: $memory,
            image: $image,
            imageType: $imageType,
            diskSize: $diskSize,
            storageClassName: $storageClassName,
            cloudInit: $cloudInit,
            labels: $labels,
            annotations: $annotations,
            running: $running
        );

        try {
            // Create DataVolume first if using dataVolume type
            if ($imageType === 'dataVolume') {
                $this->createDataVolume(
                    name: $name,
                    namespace: $namespace,
                    size: $diskSize,
                    sourceUrl: $image,
                    storageClassName: $storageClassName
                );
            }

            // Create the VirtualMachine
            $result = $this->makeKubeVirtRequest(
                'POST',
                self::VM_API_PATH."/namespaces/{$namespace}/virtualmachines",
                $manifest
            );

            return $result;
        } catch (\Throwable $e) {
            throw new DeploymentException(
                "Failed to create VirtualMachine '{$name}' in namespace '{$namespace}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Delete a VirtualMachine.
     *
     * @param  string  $namespace  The namespace
     * @param  string  $name  The VM name
     * @param  bool  $deleteDataVolumes  Also delete associated DataVolumes
     *
     * @throws DeploymentException If deletion fails
     */
    public function deleteVirtualMachine(string $namespace, string $name, bool $deleteDataVolumes = true): void
    {
        try {
            // Delete the VirtualMachine
            $this->makeKubeVirtRequest(
                'DELETE',
                self::VM_API_PATH."/namespaces/{$namespace}/virtualmachines/{$name}"
            );

            // Optionally delete associated DataVolume
            if ($deleteDataVolumes) {
                try {
                    $this->deleteDataVolume($namespace, $name);
                } catch (\Throwable $e) {
                    // Ignore if DataVolume doesn't exist
                    if (! str_contains($e->getMessage(), '404')) {
                        throw $e;
                    }
                }
            }
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return; // Already deleted
            }
            throw new DeploymentException(
                "Failed to delete VirtualMachine '{$name}' in namespace '{$namespace}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Start a stopped VirtualMachine.
     *
     * @param  string  $namespace  The namespace
     * @param  string  $name  The VM name
     *
     * @throws DeploymentException If start fails
     */
    public function startVirtualMachine(string $namespace, string $name): void
    {
        try {
            $this->patchVirtualMachineRunning($namespace, $name, true);
        } catch (\Throwable $e) {
            throw new DeploymentException(
                "Failed to start VirtualMachine '{$name}' in namespace '{$namespace}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Stop a running VirtualMachine.
     *
     * @param  string  $namespace  The namespace
     * @param  string  $name  The VM name
     *
     * @throws DeploymentException If stop fails
     */
    public function stopVirtualMachine(string $namespace, string $name): void
    {
        try {
            $this->patchVirtualMachineRunning($namespace, $name, false);
        } catch (\Throwable $e) {
            throw new DeploymentException(
                "Failed to stop VirtualMachine '{$name}' in namespace '{$namespace}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Restart a VirtualMachine.
     *
     * @param  string  $namespace  The namespace
     * @param  string  $name  The VM name
     *
     * @throws DeploymentException If restart fails
     */
    public function restartVirtualMachine(string $namespace, string $name): void
    {
        try {
            // Delete the VMI to trigger a restart (VM will recreate it)
            $this->makeKubeVirtRequest(
                'DELETE',
                self::VM_API_PATH."/namespaces/{$namespace}/virtualmachineinstances/{$name}"
            );
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                // VMI doesn't exist, just start the VM
                $this->startVirtualMachine($namespace, $name);

                return;
            }
            throw new DeploymentException(
                "Failed to restart VirtualMachine '{$name}' in namespace '{$namespace}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Wait for a VirtualMachine to be ready (Running and has IP).
     *
     * @param  string  $namespace  The namespace
     * @param  string  $name  The VM name
     * @param  int  $timeout  Timeout in seconds (default: 300)
     * @param  int  $pollInterval  Poll interval in seconds (default: 5)
     * @return array The VMI data when ready
     *
     * @throws DeploymentException If timeout or VM fails
     */
    public function waitForVmReady(string $namespace, string $name, int $timeout = 300, int $pollInterval = 5): array
    {
        $startTime = time();

        while ((time() - $startTime) < $timeout) {
            $vmi = $this->getVirtualMachineInstance($namespace, $name);

            if ($vmi !== null) {
                // Check if VM is running and has an IP
                if ($vmi['phase'] === 'Running' && ! empty($vmi['ipAddress'])) {
                    return $vmi;
                }

                // Check for failure conditions
                if (in_array($vmi['phase'], ['Failed', 'Unknown'])) {
                    $conditions = $vmi['conditions'] ?? [];
                    $failureReason = $this->extractFailureReason($conditions);
                    throw new DeploymentException(
                        "VirtualMachine '{$name}' failed to start: {$failureReason}"
                    );
                }
            }

            sleep($pollInterval);
        }

        throw new DeploymentException(
            "Timeout waiting for VirtualMachine '{$name}' to be ready after {$timeout} seconds"
        );
    }

    // =========================================================================
    // DataVolume Operations
    // =========================================================================

    /**
     * Create a DataVolume for persistent VM storage.
     *
     * @param  string  $name  The DataVolume name
     * @param  string  $namespace  The namespace
     * @param  string  $size  The disk size (e.g., '10Gi')
     * @param  string  $sourceUrl  The source URL for the disk image
     * @param  string|null  $storageClassName  The storage class to use
     * @return array The created DataVolume resource
     *
     * @throws DeploymentException If creation fails
     */
    public function createDataVolume(
        string $name,
        string $namespace,
        string $size,
        string $sourceUrl,
        ?string $storageClassName = null
    ): array {
        $manifest = [
            'apiVersion' => 'cdi.kubevirt.io/v1beta1',
            'kind' => 'DataVolume',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
            ],
            'spec' => [
                'source' => [
                    'http' => [
                        'url' => $sourceUrl,
                    ],
                ],
                'pvc' => [
                    'accessModes' => ['ReadWriteOnce'],
                    'resources' => [
                        'requests' => [
                            'storage' => $size,
                        ],
                    ],
                ],
            ],
        ];

        if ($storageClassName) {
            $manifest['spec']['pvc']['storageClassName'] = $storageClassName;
        }

        try {
            return $this->makeKubeVirtRequest(
                'POST',
                self::CDI_API_PATH."/namespaces/{$namespace}/datavolumes",
                $manifest
            );
        } catch (\Throwable $e) {
            throw new DeploymentException(
                "Failed to create DataVolume '{$name}' in namespace '{$namespace}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Delete a DataVolume.
     *
     * @param  string  $namespace  The namespace
     * @param  string  $name  The DataVolume name
     *
     * @throws DeploymentException If deletion fails
     */
    public function deleteDataVolume(string $namespace, string $name): void
    {
        try {
            $this->makeKubeVirtRequest(
                'DELETE',
                self::CDI_API_PATH."/namespaces/{$namespace}/datavolumes/{$name}"
            );
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return;
            }
            throw new DeploymentException(
                "Failed to delete DataVolume '{$name}' in namespace '{$namespace}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get DataVolume status.
     *
     * @param  string  $namespace  The namespace
     * @param  string  $name  The DataVolume name
     * @return array|null The DataVolume with status or null if not found
     *
     * @throws DeploymentException If the request fails
     */
    public function getDataVolume(string $namespace, string $name): ?array
    {
        try {
            return $this->makeKubeVirtRequest(
                'GET',
                self::CDI_API_PATH."/namespaces/{$namespace}/datavolumes/{$name}"
            );
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw new DeploymentException(
                "Failed to get DataVolume '{$name}' in namespace '{$namespace}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    // =========================================================================
    // Health Checks
    // =========================================================================

    /**
     * Check if KubeVirt is installed and available on the cluster.
     *
     * @return bool True if KubeVirt API is available
     */
    public function isKubeVirtAvailable(): bool
    {
        try {
            $this->makeKubeVirtRequest('GET', '/apis/kubevirt.io/v1');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if CDI (Containerized Data Importer) is available.
     *
     * @return bool True if CDI API is available
     */
    public function isCdiAvailable(): bool
    {
        try {
            $this->makeKubeVirtRequest('GET', '/apis/cdi.kubevirt.io/v1beta1');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the cluster this service is connected to.
     */
    public function getCluster(): KubernetesCluster
    {
        return $this->cluster;
    }

    // =========================================================================
    // Private Helper Methods
    // =========================================================================

    /**
     * Make an API request using the KubernetesClientService.
     *
     * @param  string  $method  HTTP method
     * @param  string  $path  API path
     * @param  array|null  $body  Request body
     * @return array|null Response data
     *
     * @throws \Exception If the request fails
     */
    private function makeKubeVirtRequest(string $method, string $path, ?array $body = null): ?array
    {
        // Use reflection to access the private makeApiRequest method
        $reflection = new \ReflectionClass($this->kubeClient);
        $method_ref = $reflection->getMethod('makeApiRequest');
        $method_ref->setAccessible(true);

        return $method_ref->invoke($this->kubeClient, $method, $path, $body);
    }

    /**
     * Validate create parameters.
     *
     * @param  array  $params  The parameters to validate
     *
     * @throws DeploymentException If validation fails
     */
    private function validateCreateParams(array $params): void
    {
        $required = ['name', 'namespace', 'image'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                throw new DeploymentException("Missing required parameter: {$field}");
            }
        }

        // Validate name format (DNS-1123 subdomain)
        if (! preg_match('/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $params['name'])) {
            throw new DeploymentException(
                'Invalid VM name: must be lowercase alphanumeric with hyphens, '.
                'starting and ending with alphanumeric character'
            );
        }

        // Validate namespace format
        if (! preg_match('/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $params['namespace'])) {
            throw new DeploymentException(
                'Invalid namespace: must be lowercase alphanumeric with hyphens, '.
                'starting and ending with alphanumeric character'
            );
        }

        // Validate memory format
        if (isset($params['memory']) && ! preg_match('/^\d+[KMGTP]?i?$/', $params['memory'])) {
            throw new DeploymentException(
                'Invalid memory format: must be like "512Mi", "1Gi", "2G"'
            );
        }

        // Validate CPU
        if (isset($params['cpu']) && (! is_int($params['cpu']) || $params['cpu'] < 1)) {
            throw new DeploymentException('CPU cores must be a positive integer');
        }

        // Validate imageType
        if (isset($params['imageType']) && ! in_array($params['imageType'], ['containerDisk', 'dataVolume'])) {
            throw new DeploymentException(
                'Invalid imageType: must be "containerDisk" or "dataVolume"'
            );
        }
    }

    /**
     * Build a VirtualMachine manifest.
     *
     * @return array The VM manifest
     */
    private function buildVirtualMachineManifest(
        string $name,
        string $namespace,
        int $cpu,
        string $memory,
        string $image,
        string $imageType,
        string $diskSize,
        ?string $storageClassName,
        ?string $cloudInit,
        array $labels,
        array $annotations,
        bool $running
    ): array {
        // Base labels
        $baseLabels = array_merge([
            'kubevirt.io/vm' => $name,
            'app.kubernetes.io/name' => $name,
            'app.kubernetes.io/managed-by' => 'coolify',
        ], $labels);

        // Build disks array
        $disks = [
            [
                'name' => 'rootdisk',
                'disk' => [
                    'bus' => 'virtio',
                ],
            ],
        ];

        // Build volumes array
        $volumes = [];

        if ($imageType === 'containerDisk') {
            $volumes[] = [
                'name' => 'rootdisk',
                'containerDisk' => [
                    'image' => $image,
                ],
            ];
        } else {
            // DataVolume reference
            $volumes[] = [
                'name' => 'rootdisk',
                'dataVolume' => [
                    'name' => $name,
                ],
            ];
        }

        // Add cloud-init disk if provided
        if ($cloudInit) {
            $disks[] = [
                'name' => 'cloudinitdisk',
                'disk' => [
                    'bus' => 'virtio',
                ],
            ];

            $volumes[] = [
                'name' => 'cloudinitdisk',
                'cloudInitNoCloud' => [
                    'userData' => $cloudInit,
                ],
            ];
        }

        $manifest = [
            'apiVersion' => self::KUBEVIRT_API_VERSION,
            'kind' => 'VirtualMachine',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $baseLabels,
            ],
            'spec' => [
                'running' => $running,
                'template' => [
                    'metadata' => [
                        'labels' => $baseLabels,
                    ],
                    'spec' => [
                        'domain' => [
                            'cpu' => [
                                'cores' => $cpu,
                            ],
                            'memory' => [
                                'guest' => $memory,
                            ],
                            'devices' => [
                                'disks' => $disks,
                                'interfaces' => [
                                    [
                                        'name' => 'default',
                                        'masquerade' => (object) [],
                                    ],
                                ],
                            ],
                        ],
                        'networks' => [
                            [
                                'name' => 'default',
                                'pod' => (object) [],
                            ],
                        ],
                        'volumes' => $volumes,
                    ],
                ],
            ],
        ];

        // Add annotations if provided
        if (! empty($annotations)) {
            $manifest['metadata']['annotations'] = $annotations;
            $manifest['spec']['template']['metadata']['annotations'] = $annotations;
        }

        return $manifest;
    }

    /**
     * Generate cloud-init userData with SSH key.
     *
     * @param  string  $sshPublicKey  The SSH public key
     * @param  string  $user  The user to add the key to
     * @return string Cloud-init YAML
     */
    private function generateCloudInit(string $sshPublicKey, string $user = 'root'): string
    {
        $cloudConfig = [
            '#cloud-config',
            'users:',
            "  - name: {$user}",
            '    ssh_authorized_keys:',
            "      - {$sshPublicKey}",
            '    sudo: ALL=(ALL) NOPASSWD:ALL',
            '    shell: /bin/bash',
            'ssh_pwauth: false',
            'disable_root: false',
            'chpasswd:',
            '  expire: false',
        ];

        return implode("\n", $cloudConfig);
    }

    /**
     * Patch the VM's running state.
     *
     * @param  string  $namespace  The namespace
     * @param  string  $name  The VM name
     * @param  bool  $running  The desired running state
     *
     * @throws \Exception If the request fails
     */
    private function patchVirtualMachineRunning(string $namespace, string $name, bool $running): void
    {
        // Get current VM to get resourceVersion
        $vm = $this->getVirtualMachine($namespace, $name);

        if ($vm === null) {
            throw new DeploymentException("VirtualMachine '{$name}' not found in namespace '{$namespace}'");
        }

        // Update the running state
        $vm['spec']['running'] = $running;

        // Use PUT to update (merge patch doesn't work well with strategic merge in K8s)
        $this->makeKubeVirtRequest(
            'PUT',
            self::VM_API_PATH."/namespaces/{$namespace}/virtualmachines/{$name}",
            $vm
        );
    }

    /**
     * Extract failure reason from VMI conditions.
     *
     * @param  array  $conditions  The conditions array
     * @return string The failure reason
     */
    private function extractFailureReason(array $conditions): string
    {
        foreach ($conditions as $condition) {
            if (isset($condition['status']) && $condition['status'] === 'False') {
                $reason = $condition['reason'] ?? 'Unknown';
                $message = $condition['message'] ?? '';

                return $message ? "{$reason}: {$message}" : $reason;
            }
        }

        return 'Unknown failure';
    }
}

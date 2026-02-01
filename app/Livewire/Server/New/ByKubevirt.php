<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyTypes;
use App\Models\CloudInitScript;
use App\Models\KubernetesCluster;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidCloudInitYaml;
use App\Rules\ValidHostname;
use App\Services\KubeVirtService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ByKubevirt extends Component
{
    use AuthorizesRequests;

    // Step tracking
    public int $current_step = 1;

    // Locked data
    #[Locked]
    public Collection $available_clusters;

    #[Locked]
    public $private_keys;

    #[Locked]
    public $limit_reached;

    // Step 1: Cluster selection
    public ?int $selected_cluster_id = null;

    // Step 2: VM configuration
    public array $namespaces = [];

    public ?string $selected_namespace = null;

    public string $vm_name = '';

    public int $cpu_cores = 2;

    public string $memory = '4Gi';

    public string $disk_size = '20Gi';

    public string $container_image = 'quay.io/containerdisks/fedora:latest';

    public ?int $private_key_id = null;

    public bool $loading_data = false;

    public ?string $cloud_init_script = null;

    public bool $save_cloud_init_script = false;

    public ?string $cloud_init_script_name = null;

    public ?int $selected_cloud_init_script_id = null;

    #[Locked]
    public Collection $saved_cloud_init_scripts;

    public bool $from_onboarding = false;

    public function mount()
    {
        $this->authorize('viewAny', KubernetesCluster::class);
        $this->loadClusters();
        $this->loadSavedCloudInitScripts();
        $this->vm_name = generate_random_name();
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();
        $this->limit_reached = Team::serverLimitReached();

        if ($this->private_keys->count() > 0) {
            $this->private_key_id = $this->private_keys->first()->id;
        }

        // Set default cloud-init script for Docker installation
        $this->cloud_init_script = $this->getDefaultCloudInitScript();
    }

    public function loadSavedCloudInitScripts()
    {
        $this->saved_cloud_init_scripts = CloudInitScript::ownedByCurrentTeam()->get();
    }

    public function getListeners()
    {
        return [
            'clusterAdded' => 'handleClusterAdded',
            'privateKeyCreated' => 'handlePrivateKeyCreated',
            'modalClosed' => 'resetSelection',
        ];
    }

    public function resetSelection()
    {
        $this->selected_cluster_id = null;
        $this->current_step = 1;
        $this->cloud_init_script = $this->getDefaultCloudInitScript();
        $this->save_cloud_init_script = false;
        $this->cloud_init_script_name = null;
        $this->selected_cloud_init_script_id = null;
    }

    public function loadClusters()
    {
        $this->available_clusters = KubernetesCluster::ownedByCurrentTeam()
            ->where('is_reachable', true)
            ->get();
    }

    public function handleClusterAdded($clusterId)
    {
        // Refresh cluster list
        $this->loadClusters();

        // Auto-select the new cluster
        $this->selected_cluster_id = $clusterId;

        // Automatically proceed to next step
        $this->nextStep();
    }

    public function handlePrivateKeyCreated($keyId)
    {
        // Refresh private keys list
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();

        // Auto-select the new key
        $this->private_key_id = $keyId;

        // Clear validation errors for private_key_id
        $this->resetErrorBag('private_key_id');

        // Update cloud-init script with new key
        $this->cloud_init_script = $this->getDefaultCloudInitScript();
    }

    protected function rules(): array
    {
        $rules = [
            'selected_cluster_id' => 'required|integer|exists:kubernetes_clusters,id',
        ];

        if ($this->current_step === 2) {
            $rules = array_merge($rules, [
                'vm_name' => ['required', 'string', 'max:253', new ValidHostname],
                'selected_namespace' => 'required|string|max:63',
                'cpu_cores' => 'required|integer|min:1|max:64',
                'memory' => ['required', 'string', 'regex:/^\d+[GMK]i$/'],
                'disk_size' => ['required', 'string', 'regex:/^\d+[GMK]i$/'],
                'container_image' => 'required|string|max:500',
                'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
                'cloud_init_script' => ['nullable', 'string', new ValidCloudInitYaml],
                'save_cloud_init_script' => 'boolean',
                'cloud_init_script_name' => 'nullable|string|max:255',
                'selected_cloud_init_script_id' => 'nullable|integer|exists:cloud_init_scripts,id',
            ]);
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'selected_cluster_id.required' => 'Please select a Kubernetes cluster.',
            'selected_cluster_id.exists' => 'Selected cluster not found.',
            'vm_name.required' => 'VM name is required.',
            'selected_namespace.required' => 'Please select a namespace.',
            'cpu_cores.min' => 'CPU cores must be at least 1.',
            'memory.regex' => 'Memory must be in Kubernetes format (e.g., 4Gi, 512Mi).',
            'disk_size.regex' => 'Disk size must be in Kubernetes format (e.g., 20Gi, 100Gi).',
            'container_image.required' => 'Container image is required.',
            'private_key_id.required' => 'Please select a private key.',
        ];
    }

    public function selectCluster(int $clusterId)
    {
        $this->selected_cluster_id = $clusterId;
    }

    private function getSelectedCluster(): ?KubernetesCluster
    {
        if ($this->selected_cluster_id) {
            return $this->available_clusters->firstWhere('id', $this->selected_cluster_id);
        }

        return null;
    }

    public function nextStep()
    {
        // Validate step 1 - just need a cluster selected
        $this->validate([
            'selected_cluster_id' => 'required|integer|exists:kubernetes_clusters,id',
        ]);

        try {
            $cluster = $this->getSelectedCluster();

            if (! $cluster) {
                return $this->dispatch('error', 'Please select a valid Kubernetes cluster.');
            }

            // Check if KubeVirt is available on the cluster
            $kubeVirtService = new KubeVirtService($cluster);
            if (! $kubeVirtService->isKubeVirtAvailable()) {
                return $this->dispatch('error', 'KubeVirt is not installed or available on this cluster. Please install KubeVirt first.');
            }

            // Load namespaces from cluster
            $this->loadClusterData($cluster);

            // Move to step 2
            $this->current_step = 2;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function previousStep()
    {
        $this->current_step = 1;
    }

    private function loadClusterData(KubernetesCluster $cluster)
    {
        $this->loading_data = true;

        try {
            $kubeVirtService = new KubeVirtService($cluster);
            $this->namespaces = $kubeVirtService->getNamespaces();

            // Set default namespace
            if (in_array($cluster->default_namespace, $this->namespaces)) {
                $this->selected_namespace = $cluster->default_namespace;
            } elseif (in_array('default', $this->namespaces)) {
                $this->selected_namespace = 'default';
            } elseif (count($this->namespaces) > 0) {
                $this->selected_namespace = $this->namespaces[0];
            }

            $this->loading_data = false;
        } catch (\Throwable $e) {
            $this->loading_data = false;
            throw $e;
        }
    }

    public function updatedSelectedCloudInitScriptId($value)
    {
        if ($value) {
            $script = CloudInitScript::ownedByCurrentTeam()->findOrFail($value);
            $this->cloud_init_script = $script->script;
            $this->cloud_init_script_name = $script->name;
        }
    }

    public function updatedPrivateKeyId($value)
    {
        // Update the cloud-init script with the new SSH key if using default
        if (! $this->selected_cloud_init_script_id) {
            $this->cloud_init_script = $this->getDefaultCloudInitScript();
        }
    }

    public function clearCloudInitScript()
    {
        $this->selected_cloud_init_script_id = null;
        $this->cloud_init_script = $this->getDefaultCloudInitScript();
        $this->cloud_init_script_name = '';
        $this->save_cloud_init_script = false;
    }

    public function resetCloudInitToDefault()
    {
        $this->cloud_init_script = $this->getDefaultCloudInitScript();
        $this->selected_cloud_init_script_id = null;
        $this->cloud_init_script_name = '';
        $this->save_cloud_init_script = false;
    }

    private function getDefaultCloudInitScript(): string
    {
        $publicKey = '{public_key}';

        if ($this->private_key_id) {
            $privateKey = PrivateKey::find($this->private_key_id);
            if ($privateKey) {
                $publicKey = $privateKey->getPublicKey();
            }
        }

        return <<<YAML
#cloud-config
users:
  - name: root
    ssh_authorized_keys:
      - {$publicKey}
packages:
  - openssh-server
  - curl
runcmd:
  # Enable and start SSH
  - systemctl enable sshd
  - systemctl start sshd
  # Install Docker CE with compose plugin (official method)
  - curl -fsSL https://get.docker.com | sh
  - systemctl enable docker
  - systemctl start docker
YAML;
    }

    public function submit()
    {
        $this->validate();

        try {
            $this->authorize('create', Server::class);

            if (Team::serverLimitReached()) {
                return $this->dispatch('error', 'You have reached the server limit for your subscription.');
            }

            // Save cloud-init script if requested
            if ($this->save_cloud_init_script && ! empty($this->cloud_init_script) && ! empty($this->cloud_init_script_name)) {
                $this->authorize('create', CloudInitScript::class);

                CloudInitScript::create([
                    'team_id' => currentTeam()->id,
                    'name' => $this->cloud_init_script_name,
                    'script' => $this->cloud_init_script,
                ]);
            }

            $cluster = $this->getSelectedCluster();

            if (! $cluster) {
                return $this->dispatch('error', 'Selected cluster not found.');
            }

            // Get the private key and extract public key
            $privateKey = PrivateKey::ownedByCurrentTeam()->findOrFail($this->private_key_id);
            $publicKey = $privateKey->getPublicKey();

            // Prepare cloud-init script with actual public key
            $cloudInitScript = $this->cloud_init_script;
            if (str_contains($cloudInitScript, '{public_key}')) {
                $cloudInitScript = str_replace('{public_key}', $publicKey, $cloudInitScript);
            }

            // Normalize VM name to lowercase for Kubernetes compatibility
            $normalizedVmName = strtolower(trim($this->vm_name));

            // Create VM via KubeVirt
            $kubeVirtService = new KubeVirtService($cluster);

            $vmParams = [
                'name' => $normalizedVmName,
                'namespace' => $this->selected_namespace,
                'cpu' => $this->cpu_cores,
                'memory' => $this->memory,
                'image' => $this->container_image,
                'imageType' => 'containerDisk',
                'cloudInit' => $cloudInitScript,
                'running' => true,
                'labels' => [
                    'coolify.io/managed' => 'true',
                    'coolify.io/team-id' => (string) currentTeam()->id,
                ],
            ];

            $kubeVirtService->createVirtualMachine($vmParams);

            // Notify user that VM is being created
            $this->dispatch('info', 'VM created. Waiting for it to start and obtain an IP address...');

            // Wait for VM to become ready and get IP address
            $vmi = $kubeVirtService->waitForVmReady(
                $this->selected_namespace,
                $normalizedVmName,
                timeout: 300,
                pollInterval: 5
            );

            $ipAddress = $vmi['ipAddress'] ?? null;

            if (! $ipAddress) {
                throw new \Exception('VM started but no IP address was assigned. Please check the cluster network configuration.');
            }

            // Create server in Coolify database
            $server = Server::create([
                'name' => $this->vm_name,
                'ip' => $ipAddress,
                'user' => 'root',
                'port' => 22,
                'team_id' => currentTeam()->id,
                'private_key_id' => $this->private_key_id,
            ]);

            // Set up proxy configuration
            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);

            // Store KubeVirt metadata in proxy schemaless attributes
            $server->proxy->set('kubevirt', [
                'enabled' => true,
                'cluster_id' => $cluster->id,
                'namespace' => $this->selected_namespace,
                'vm_name' => $normalizedVmName,
            ]);
            $server->save();

            if ($this->from_onboarding) {
                // Complete the boarding when server is successfully created via KubeVirt
                currentTeam()->update([
                    'show_boarding' => false,
                ]);
                refreshSession();

                return redirectRoute($this, 'server.show', [$server->uuid]);
            }

            return redirectRoute($this, 'server.show', [$server->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.new.by-kubevirt');
    }
}

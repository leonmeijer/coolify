<?php

namespace App\Livewire\Kubernetes;

use App\Models\KubernetesCluster;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Form extends Component
{
    use AuthorizesRequests;

    public ?KubernetesCluster $cluster = null;

    public bool $isEditMode = false;

    #[Validate(['required', 'string', 'min:2', 'max:255'])]
    public string $name = '';

    #[Validate(['nullable', 'string', 'max:1000'])]
    public ?string $description = null;

    #[Validate(['required', 'string', 'in:kubernetes,k3s,okd,openshift'])]
    public string $clusterType = 'kubernetes';

    #[Validate(['required', 'string', 'min:10'])]
    public string $kubeconfig = '';

    #[Validate(['nullable', 'string'])]
    public ?string $contextName = null;

    public array $availableContexts = [];

    public array $availableNamespaces = [];

    public bool $connectionTested = false;

    public bool $connectionSuccessful = false;

    public ?string $connectionError = null;

    public bool $isTesting = false;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'clusterType' => ['required', 'string', 'in:kubernetes,k3s,okd,openshift'],
            'kubeconfig' => ['required', 'string', 'min:10'],
            'contextName' => ['nullable', 'string'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'The cluster name is required.',
            'name.min' => 'The cluster name must be at least 2 characters.',
            'kubeconfig.required' => 'The kubeconfig is required.',
            'kubeconfig.min' => 'The kubeconfig appears to be invalid or too short.',
            'clusterType.in' => 'Please select a valid cluster type.',
        ];
    }

    public function mount(?KubernetesCluster $cluster = null)
    {
        if ($cluster && $cluster->exists) {
            $this->authorize('view', $cluster);
            $this->cluster = $cluster;
            $this->isEditMode = true;
            $this->loadClusterData();
        }
    }

    private function loadClusterData(): void
    {
        if (! $this->cluster) {
            return;
        }

        $this->name = $this->cluster->name;
        $this->description = $this->cluster->description;
        $this->clusterType = $this->cluster->cluster_type;
        $this->kubeconfig = $this->cluster->kubeconfig ?? '';
        $this->contextName = $this->cluster->context_name;

        if (! empty($this->kubeconfig)) {
            $this->parseKubeconfig();
        }
    }

    public function updatedKubeconfig(): void
    {
        $this->connectionTested = false;
        $this->connectionSuccessful = false;
        $this->connectionError = null;
        $this->availableNamespaces = [];

        if (! empty($this->kubeconfig)) {
            $this->parseKubeconfig();
        }
    }

    private function parseKubeconfig(): void
    {
        try {
            $config = \Symfony\Component\Yaml\Yaml::parse($this->kubeconfig);

            if (! is_array($config)) {
                $this->availableContexts = [];

                return;
            }

            $this->availableContexts = [];

            foreach ($config['contexts'] ?? [] as $context) {
                if (isset($context['name'])) {
                    $this->availableContexts[] = $context['name'];
                }
            }

            // Set default context if not already set
            if (empty($this->contextName) && isset($config['current-context'])) {
                $this->contextName = $config['current-context'];
            }
        } catch (\Throwable $e) {
            $this->availableContexts = [];
        }
    }

    public function testConnection(): void
    {
        $this->validate([
            'kubeconfig' => ['required', 'string', 'min:10'],
        ]);

        $this->isTesting = true;
        $this->connectionTested = false;
        $this->connectionSuccessful = false;
        $this->connectionError = null;
        $this->availableNamespaces = [];

        try {
            // Create a temporary cluster model to test the connection
            $tempCluster = new KubernetesCluster([
                'uuid' => (string) Str::uuid(),
                'kubeconfig' => $this->kubeconfig,
                'context_name' => $this->contextName,
                'cluster_type' => $this->clusterType,
            ]);

            $client = $tempCluster->getClient();
            $isHealthy = $client->checkApiHealth();

            if ($isHealthy) {
                $this->connectionSuccessful = true;
                $this->connectionTested = true;

                // Get available namespaces
                try {
                    $namespaces = $client->getNamespaces();
                    $this->availableNamespaces = $namespaces;
                } catch (\Throwable $e) {
                    // Namespaces retrieval failed, but connection is still successful
                    $this->availableNamespaces = [];
                }

                $this->dispatch('success', 'Connection successful! Cluster is reachable.');
            } else {
                $this->connectionSuccessful = false;
                $this->connectionTested = true;
                $this->connectionError = 'Cluster is not healthy or unreachable.';
                $this->dispatch('error', 'Connection failed: Cluster is not healthy.');
            }
        } catch (\Throwable $e) {
            $this->connectionSuccessful = false;
            $this->connectionTested = true;
            $this->connectionError = $e->getMessage();
            $this->dispatch('error', 'Connection failed: '.$e->getMessage());
        } finally {
            $this->isTesting = false;
        }
    }

    public function save(): void
    {
        $this->validate();

        try {
            if ($this->isEditMode && $this->cluster) {
                $this->authorize('update', $this->cluster);

                $this->cluster->update([
                    'name' => $this->name,
                    'description' => $this->description,
                    'cluster_type' => $this->clusterType,
                    'kubeconfig' => $this->kubeconfig,
                    'context_name' => $this->contextName,
                ]);

                // Test connection and update reachability
                $this->cluster->testConnection();

                $this->dispatch('success', 'Kubernetes cluster updated successfully.');
                $this->dispatch('clusterUpdated');
            } else {
                $this->authorize('create', KubernetesCluster::class);

                $cluster = KubernetesCluster::create([
                    'uuid' => (string) Str::uuid(),
                    'name' => $this->name,
                    'description' => $this->description,
                    'team_id' => currentTeam()->id,
                    'cluster_type' => $this->clusterType,
                    'kubeconfig' => $this->kubeconfig,
                    'context_name' => $this->contextName,
                    'default_namespace' => 'default',
                ]);

                // Test connection and update reachability
                $cluster->testConnection();

                $this->dispatch('success', 'Kubernetes cluster created successfully.');
                $this->dispatch('clusterCreated', ['uuid' => $cluster->uuid]);

                // Redirect to the cluster page
                $this->redirect(route('kubernetes.show', ['uuid' => $cluster->uuid]), navigate: true);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.kubernetes.form');
    }
}

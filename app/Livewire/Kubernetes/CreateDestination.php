<?php

namespace App\Livewire\Kubernetes;

use App\Models\KubernetesCluster;
use App\Models\KubernetesDestination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CreateDestination extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?KubernetesCluster $cluster = null;

    public array $availableNamespaces = [];

    #[Validate(['required', 'string', 'min:2', 'max:255'])]
    public string $name = '';

    #[Validate(['required', 'string', 'min:1', 'max:63'])]
    public string $namespace = '';

    #[Validate(['nullable', 'string', 'max:255'])]
    public ?string $ingressClass = null;

    #[Validate(['nullable', 'string', 'max:255'])]
    public ?string $storageClass = null;

    #[Validate(['required', 'string', 'regex:/^\d+m?$/'])]
    public string $defaultCpuLimit = '500m';

    #[Validate(['required', 'string', 'regex:/^\d+(Mi|Gi)?$/'])]
    public string $defaultMemoryLimit = '512Mi';

    #[Validate(['required', 'string', 'regex:/^\d+m?$/'])]
    public string $defaultCpuRequest = '100m';

    #[Validate(['required', 'string', 'regex:/^\d+(Mi|Gi)?$/'])]
    public string $defaultMemoryRequest = '128Mi';

    #[Validate(['required', 'integer', 'min:1', 'max:100'])]
    public int $defaultReplicas = 1;

    public function mount(string $uuid)
    {
        try {
            $this->cluster = KubernetesCluster::whereUuid($uuid)->firstOrFail();
            $this->authorize('manageDestinations', $this->cluster);

            // Try to load available namespaces
            $this->loadNamespaces();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadNamespaces(): void
    {
        try {
            $namespaces = $this->cluster->getNamespaces();
            $this->availableNamespaces = $namespaces->toArray();
        } catch (\Throwable $e) {
            $this->availableNamespaces = [];
            $this->dispatch('warning', 'Could not load namespaces from cluster. You can still enter a namespace manually.');
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'namespace' => ['required', 'string', 'min:1', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
            'ingressClass' => ['nullable', 'string', 'max:255'],
            'storageClass' => ['nullable', 'string', 'max:255'],
            'defaultCpuLimit' => ['required', 'string', 'regex:/^\d+m?$/'],
            'defaultMemoryLimit' => ['required', 'string', 'regex:/^\d+(Mi|Gi)?$/'],
            'defaultCpuRequest' => ['required', 'string', 'regex:/^\d+m?$/'],
            'defaultMemoryRequest' => ['required', 'string', 'regex:/^\d+(Mi|Gi)?$/'],
            'defaultReplicas' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'The destination name is required.',
            'namespace.required' => 'The namespace is required.',
            'namespace.regex' => 'The namespace must be a valid Kubernetes namespace name (lowercase letters, numbers, and hyphens only).',
            'defaultCpuLimit.regex' => 'Invalid CPU limit format. Use format like "500m" or "1".',
            'defaultMemoryLimit.regex' => 'Invalid memory limit format. Use format like "512Mi" or "1Gi".',
            'defaultCpuRequest.regex' => 'Invalid CPU request format. Use format like "100m" or "1".',
            'defaultMemoryRequest.regex' => 'Invalid memory request format. Use format like "128Mi" or "1Gi".',
        ];
    }

    public function save(): void
    {
        $this->validate();

        try {
            $this->authorize('manageDestinations', $this->cluster);

            // Check if a destination with this namespace already exists for this cluster
            $existingDestination = KubernetesDestination::where('kubernetes_cluster_id', $this->cluster->id)
                ->where('namespace', $this->namespace)
                ->first();

            if ($existingDestination) {
                $this->dispatch('error', 'A destination with this namespace already exists for this cluster.');

                return;
            }

            $destination = KubernetesDestination::create([
                'uuid' => (string) Str::uuid(),
                'name' => $this->name,
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => $this->namespace,
                'ingress_class' => $this->ingressClass,
                'storage_class' => $this->storageClass,
                'default_cpu_limit' => $this->defaultCpuLimit,
                'default_memory_limit' => $this->defaultMemoryLimit,
                'default_cpu_request' => $this->defaultCpuRequest,
                'default_memory_request' => $this->defaultMemoryRequest,
                'default_replicas' => $this->defaultReplicas,
            ]);

            $this->dispatch('success', 'Destination created successfully.');
            $this->redirect(route('kubernetes.destinations', ['uuid' => $this->cluster->uuid]), navigate: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.kubernetes.create-destination');
    }
}

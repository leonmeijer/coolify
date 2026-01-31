<?php

namespace App\Livewire\Kubernetes;

use App\Models\KubernetesCluster;
use App\Models\KubernetesDestination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Destination extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?KubernetesCluster $cluster = null;

    #[Locked]
    public ?KubernetesDestination $destination = null;

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

    public function mount(string $uuid, string $destination_uuid)
    {
        try {
            $this->cluster = KubernetesCluster::whereUuid($uuid)->firstOrFail();
            $this->destination = KubernetesDestination::whereUuid($destination_uuid)
                ->where('kubernetes_cluster_id', $this->cluster->id)
                ->firstOrFail();

            $this->authorize('view', $this->destination);
            $this->syncFromModel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function syncFromModel(): void
    {
        $this->name = $this->destination->name;
        $this->namespace = $this->destination->namespace;
        $this->ingressClass = $this->destination->ingress_class;
        $this->storageClass = $this->destination->storage_class;
        $this->defaultCpuLimit = $this->destination->default_cpu_limit;
        $this->defaultMemoryLimit = $this->destination->default_memory_limit;
        $this->defaultCpuRequest = $this->destination->default_cpu_request;
        $this->defaultMemoryRequest = $this->destination->default_memory_request;
        $this->defaultReplicas = $this->destination->default_replicas;
    }

    private function syncToModel(): void
    {
        $this->destination->name = $this->name;
        $this->destination->ingress_class = $this->ingressClass;
        $this->destination->storage_class = $this->storageClass;
        $this->destination->default_cpu_limit = $this->defaultCpuLimit;
        $this->destination->default_memory_limit = $this->defaultMemoryLimit;
        $this->destination->default_cpu_request = $this->defaultCpuRequest;
        $this->destination->default_memory_request = $this->defaultMemoryRequest;
        $this->destination->default_replicas = $this->defaultReplicas;
        $this->destination->save();
    }

    public function save(): void
    {
        $this->validate();

        try {
            $this->authorize('update', $this->destination);
            $this->syncToModel();
            $this->dispatch('success', 'Destination updated successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete(): void
    {
        try {
            $this->authorize('delete', $this->destination);

            if (! $this->destination->canDelete()) {
                $this->dispatch('error', $this->destination->getDeleteBlockedReason());

                return;
            }

            $this->destination->delete();
            $this->dispatch('success', 'Destination deleted successfully.');
            $this->redirect(route('kubernetes.destinations', ['uuid' => $this->cluster->uuid]), navigate: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.kubernetes.destination');
    }
}

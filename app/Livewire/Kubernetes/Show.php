<?php

namespace App\Livewire\Kubernetes;

use App\Models\KubernetesCluster;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?KubernetesCluster $cluster = null;

    public function mount(string $uuid)
    {
        try {
            $this->cluster = KubernetesCluster::whereUuid($uuid)->firstOrFail();
            $this->authorize('view', $this->cluster);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    #[On('clusterUpdated')]
    public function refreshCluster(): void
    {
        $this->cluster->refresh();
    }

    public function testConnection(): void
    {
        try {
            $this->authorize('testConnection', $this->cluster);
            $result = $this->cluster->testConnection();

            if ($result) {
                $this->dispatch('success', 'Connection successful! Cluster is reachable.');
            } else {
                $this->dispatch('error', 'Connection failed: Cluster is not reachable.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete(): void
    {
        try {
            $this->authorize('delete', $this->cluster);

            if (! $this->cluster->canDelete()) {
                $this->dispatch('error', $this->cluster->getDeleteBlockedReason());

                return;
            }

            $this->cluster->delete();

            $this->redirect(route('kubernetes.index'), navigate: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.kubernetes.show');
    }
}

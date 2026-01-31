<?php

namespace App\Livewire\Kubernetes;

use App\Models\KubernetesCluster;
use App\Models\KubernetesDestination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Destinations extends Component
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

    public function deleteDestination(int $destinationId): void
    {
        try {
            $destination = KubernetesDestination::findOrFail($destinationId);
            $this->authorize('delete', $destination);

            if (! $destination->canDelete()) {
                $this->dispatch('error', $destination->getDeleteBlockedReason());

                return;
            }

            $destination->delete();
            $this->dispatch('success', 'Destination deleted successfully.');
            $this->cluster->refresh();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.kubernetes.destinations');
    }
}

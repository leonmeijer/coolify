<?php

namespace App\Livewire\Server;

use App\Models\CloudProviderToken;
use App\Models\KubernetesCluster;
use App\Models\PrivateKey;
use App\Models\Team;
use Livewire\Component;

class Create extends Component
{
    public $private_keys = [];

    public bool $limit_reached = false;

    public bool $has_hetzner_tokens = false;

    public bool $has_kubernetes_clusters = false;

    public function mount()
    {
        $this->private_keys = PrivateKey::ownedByCurrentTeamCached();
        if (! isCloud()) {
            $this->limit_reached = false;

            return;
        }
        $this->limit_reached = Team::serverLimitReached();

        // Check if user has Hetzner tokens
        $this->has_hetzner_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'hetzner')
            ->exists();

        // Check if user has Kubernetes clusters (for KubeVirt)
        $this->has_kubernetes_clusters = KubernetesCluster::ownedByCurrentTeam()->exists();
    }

    public function render()
    {
        return view('livewire.server.create');
    }
}

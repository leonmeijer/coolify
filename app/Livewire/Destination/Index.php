<?php

namespace App\Livewire\Destination;

use App\Models\KubernetesCluster;
use App\Models\Server;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Index extends Component
{
    #[Locked]
    public $servers;

    #[Locked]
    public $kubernetesClusters;

    public function mount()
    {
        $this->servers = Server::isUsable()->get();
        $this->kubernetesClusters = KubernetesCluster::ownedByCurrentTeamCached();
    }

    public function render()
    {
        return view('livewire.destination.index');
    }
}

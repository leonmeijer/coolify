<?php

namespace App\Livewire\Kubernetes;

use App\Models\KubernetesCluster;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class Index extends Component
{
    public ?Collection $clusters = null;

    public function mount()
    {
        $this->clusters = KubernetesCluster::ownedByCurrentTeamCached();
    }

    public function render()
    {
        return view('livewire.kubernetes.index');
    }
}

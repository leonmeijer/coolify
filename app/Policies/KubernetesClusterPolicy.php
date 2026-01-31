<?php

namespace App\Policies;

use App\Models\KubernetesCluster;
use App\Models\User;

class KubernetesClusterPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return $user->teams->contains('id', $kubernetesCluster->team_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return $user->teams->contains('id', $kubernetesCluster->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return $user->teams->contains('id', $kubernetesCluster->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return false;
    }

    /**
     * Determine whether the user can test the connection to the cluster.
     */
    public function testConnection(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return $user->teams->contains('id', $kubernetesCluster->team_id);
    }

    /**
     * Determine whether the user can manage destinations for this cluster.
     */
    public function manageDestinations(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return $user->teams->contains('id', $kubernetesCluster->team_id);
    }
}

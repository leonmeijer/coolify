<?php

namespace App\Policies;

use App\Models\KubernetesDestination;
use App\Models\User;

class KubernetesDestinationPolicy
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
    public function view(User $user, KubernetesDestination $kubernetesDestination): bool
    {
        return $user->teams->contains('id', $kubernetesDestination->cluster->team_id);
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
    public function update(User $user, KubernetesDestination $kubernetesDestination): bool
    {
        return $user->teams->contains('id', $kubernetesDestination->cluster->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, KubernetesDestination $kubernetesDestination): bool
    {
        return $user->teams->contains('id', $kubernetesDestination->cluster->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, KubernetesDestination $kubernetesDestination): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, KubernetesDestination $kubernetesDestination): bool
    {
        return false;
    }
}

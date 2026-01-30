<?php

namespace App\Models;

use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * KubernetesCluster Model
 *
 * Represents a Kubernetes cluster configuration that stores connection details,
 * authentication credentials, and cluster-specific settings.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property int $team_id
 * @property string $cluster_type
 * @property string $api_server_url
 * @property string $kubeconfig
 * @property string|null $context_name
 * @property string $default_namespace
 * @property bool $is_reachable
 * @property \Carbon\Carbon|null $last_checked_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
class KubernetesCluster extends BaseModel
{
    use HasFactory, HasSafeStringAttribute, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'description',
        'team_id',
        'cluster_type',
        'api_server_url',
        'kubeconfig',
        'context_name',
        'default_namespace',
        'is_reachable',
        'last_checked_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'kubeconfig' => 'encrypted',
        'is_reachable' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        'kubeconfig',
    ];

    /**
     * Valid cluster types.
     */
    public const CLUSTER_TYPES = [
        'kubernetes',
        'k3s',
        'okd',
        'openshift',
    ];

    /**
     * Get query builder for Kubernetes clusters owned by current team.
     *
     * @param  array<string>  $select
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $teamId = currentTeam()->id;
        $selectArray = collect($select)->concat(['id']);

        return static::whereTeamId($teamId)
            ->select($selectArray->all())
            ->orderBy('name');
    }

    /**
     * Get all Kubernetes clusters owned by current team (cached for request duration).
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return static::ownedByCurrentTeam()->get();
        });
    }

    /**
     * Get the team that owns this cluster.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the destinations (namespaces) for this cluster.
     */
    public function destinations(): HasMany
    {
        return $this->hasMany(KubernetesDestination::class);
    }

    /**
     * Test the connection to the Kubernetes cluster.
     *
     * Validates the cluster is reachable by performing an API health check.
     * Updates the is_reachable and last_checked_at fields.
     *
     * @return bool True if connection is successful, false otherwise
     */
    public function testConnection(): bool
    {
        try {
            $client = $this->getClient();
            $isHealthy = $client->checkApiHealth();

            $this->is_reachable = $isHealthy;
            $this->last_checked_at = now();
            $this->save();

            return $isHealthy;
        } catch (\Throwable $e) {
            $this->is_reachable = false;
            $this->last_checked_at = now();
            $this->save();

            return false;
        }
    }

    /**
     * Get the available namespaces from the Kubernetes cluster.
     *
     * @return Collection Collection of namespace names
     *
     * @throws \Exception If the cluster is not reachable or connection fails
     */
    public function getNamespaces(): Collection
    {
        $client = $this->getClient();
        $namespaces = $client->getNamespaces();

        return collect($namespaces);
    }

    /**
     * Get a Kubernetes client service instance for this cluster.
     *
     * @return \App\Services\KubernetesClientService
     */
    public function getClient()
    {
        return new \App\Services\KubernetesClientService($this);
    }

    /**
     * Check if the cluster can be deleted.
     *
     * A cluster cannot be deleted if it has destinations with active deployments.
     *
     * @return bool True if the cluster can be safely deleted
     */
    public function canDelete(): bool
    {
        // Check if any destinations have active resources
        foreach ($this->destinations as $destination) {
            if (! $destination->canDelete()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the reason why the cluster cannot be deleted.
     *
     * @return string|null The reason, or null if it can be deleted
     */
    public function getDeleteBlockedReason(): ?string
    {
        if ($this->canDelete()) {
            return null;
        }

        $destinationsWithResources = $this->destinations->filter(function ($destination) {
            return ! $destination->canDelete();
        });

        $count = $destinationsWithResources->count();

        return "Cannot delete cluster: {$count} destination(s) have active resources attached.";
    }

    /**
     * Check if this is a standard Kubernetes cluster.
     */
    public function isKubernetes(): bool
    {
        return $this->cluster_type === 'kubernetes';
    }

    /**
     * Check if this is a k3s cluster.
     */
    public function isK3s(): bool
    {
        return $this->cluster_type === 'k3s';
    }

    /**
     * Check if this is an OKD cluster.
     */
    public function isOkd(): bool
    {
        return $this->cluster_type === 'okd';
    }

    /**
     * Check if this is an OpenShift cluster.
     */
    public function isOpenShift(): bool
    {
        return $this->cluster_type === 'openshift';
    }

    /**
     * Check if this cluster supports OpenShift-specific features (Routes, etc.).
     */
    public function supportsOpenShiftFeatures(): bool
    {
        return $this->isOkd() || $this->isOpenShift();
    }
}

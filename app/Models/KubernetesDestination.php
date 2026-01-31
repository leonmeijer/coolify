<?php

namespace App\Models;

use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * KubernetesDestination Model
 *
 * Represents a deployment destination within a Kubernetes cluster (namespace).
 * Follows the same pattern as StandaloneDocker and SwarmDocker destinations.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int $kubernetes_cluster_id
 * @property string $namespace
 * @property string $default_cpu_limit
 * @property string $default_memory_limit
 * @property string $default_cpu_request
 * @property string $default_memory_request
 * @property int $default_replicas
 * @property string|null $ingress_class
 * @property string|null $storage_class
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class KubernetesDestination extends BaseModel
{
    use HasFactory, HasSafeStringAttribute;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'kubernetes_cluster_id',
        'namespace',
        'default_cpu_limit',
        'default_memory_limit',
        'default_cpu_request',
        'default_memory_request',
        'default_replicas',
        'ingress_class',
        'storage_class',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'default_replicas' => 'integer',
    ];

    /**
     * Get the cluster that owns this destination.
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(KubernetesCluster::class, 'kubernetes_cluster_id');
    }

    /**
     * Get the applications deployed to this destination.
     */
    public function applications(): MorphMany
    {
        return $this->morphMany(Application::class, 'destination');
    }

    /**
     * Get the PostgreSQL databases deployed to this destination.
     */
    public function postgresqls(): MorphMany
    {
        return $this->morphMany(StandalonePostgresql::class, 'destination');
    }

    /**
     * Get the Redis instances deployed to this destination.
     */
    public function redis(): MorphMany
    {
        return $this->morphMany(StandaloneRedis::class, 'destination');
    }

    /**
     * Get the MongoDB databases deployed to this destination.
     */
    public function mongodbs(): MorphMany
    {
        return $this->morphMany(StandaloneMongodb::class, 'destination');
    }

    /**
     * Get the MySQL databases deployed to this destination.
     */
    public function mysqls(): MorphMany
    {
        return $this->morphMany(StandaloneMysql::class, 'destination');
    }

    /**
     * Get the MariaDB databases deployed to this destination.
     */
    public function mariadbs(): MorphMany
    {
        return $this->morphMany(StandaloneMariadb::class, 'destination');
    }

    /**
     * Get the KeyDB instances deployed to this destination.
     */
    public function keydbs(): MorphMany
    {
        return $this->morphMany(StandaloneKeydb::class, 'destination');
    }

    /**
     * Get the Dragonfly instances deployed to this destination.
     */
    public function dragonflies(): MorphMany
    {
        return $this->morphMany(StandaloneDragonfly::class, 'destination');
    }

    /**
     * Get the ClickHouse databases deployed to this destination.
     */
    public function clickhouses(): MorphMany
    {
        return $this->morphMany(StandaloneClickhouse::class, 'destination');
    }

    /**
     * Get the services deployed to this destination.
     */
    public function services(): MorphMany
    {
        return $this->morphMany(Service::class, 'destination');
    }

    /**
     * Get all databases deployed to this destination.
     *
     * @return \Illuminate\Support\Collection
     */
    public function databases()
    {
        return $this->postgresqls
            ->concat($this->redis)
            ->concat($this->mongodbs)
            ->concat($this->mysqls)
            ->concat($this->mariadbs)
            ->concat($this->keydbs)
            ->concat($this->dragonflies)
            ->concat($this->clickhouses);
    }

    /**
     * Check if this destination has any resources attached.
     *
     * @return bool True if applications or databases are attached
     */
    public function attachedTo(): bool
    {
        return $this->applications?->count() > 0 || $this->databases()->count() > 0 || $this->services?->count() > 0;
    }

    /**
     * Check if this destination can be deleted.
     *
     * A destination cannot be deleted if it has resources attached.
     *
     * @return bool True if the destination can be safely deleted
     */
    public function canDelete(): bool
    {
        return $this->applications->isEmpty()
            && $this->services->isEmpty()
            && $this->databases()->isEmpty();
    }

    /**
     * Get the reason why the destination cannot be deleted.
     *
     * @return string|null The reason, or null if it can be deleted
     */
    public function getDeleteBlockedReason(): ?string
    {
        if ($this->canDelete()) {
            return null;
        }

        $resources = [];

        if ($this->applications->isNotEmpty()) {
            $resources[] = $this->applications->count() . ' application(s)';
        }

        if ($this->services->isNotEmpty()) {
            $resources[] = $this->services->count() . ' service(s)';
        }

        $databaseCount = $this->databases()->count();
        if ($databaseCount > 0) {
            $resources[] = $databaseCount . ' database(s)';
        }

        return 'Cannot delete destination: ' . implode(', ', $resources) . ' attached.';
    }

    /**
     * Get the server via the cluster for compatibility with existing code.
     *
     * Note: Kubernetes destinations don't have a direct server relationship,
     * but this method provides compatibility with code that expects a server.
     *
     * @return Server|null
     */
    public function server()
    {
        // Kubernetes destinations don't have a direct server relationship
        // This is provided for compatibility with existing destination patterns
        return null;
    }

    /**
     * Get the team that owns this destination via the cluster.
     *
     * @return \App\Models\Team|null
     */
    public function team()
    {
        return $this->cluster?->team;
    }

    /**
     * Get query builder for Kubernetes destinations owned by current team.
     *
     * @param  array<string>  $select
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $teamId = currentTeam()->id;
        $selectArray = collect($select)->concat(['id']);

        return static::whereHas('cluster', function ($query) use ($teamId) {
            $query->where('team_id', $teamId);
        })
            ->select($selectArray->all())
            ->orderBy('name');
    }

    /**
     * Get all Kubernetes destinations owned by current team (cached for request duration).
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return static::ownedByCurrentTeam()->get();
        });
    }
}

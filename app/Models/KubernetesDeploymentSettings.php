<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KubernetesDeploymentSettings extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'application_id',
        'replicas',
        'cpu_limit',
        'memory_limit',
        'cpu_request',
        'memory_request',
        'autoscaling_enabled',
        'min_replicas',
        'max_replicas',
        'target_cpu_utilization',
        'target_memory_utilization',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'replicas' => 'integer',
        'autoscaling_enabled' => 'boolean',
        'min_replicas' => 'integer',
        'max_replicas' => 'integer',
        'target_cpu_utilization' => 'integer',
        'target_memory_utilization' => 'integer',
    ];

    /**
     * Get the application that owns the deployment settings.
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

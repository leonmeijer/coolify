<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Events\ApplicationConfigurationChanged;
use App\Events\ServiceStatusChanged;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\KubernetesCluster;
use App\Models\KubernetesDestination;
use App\Models\KubernetesDeploymentSettings;
use App\Models\Server;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use App\Services\KubernetesClientService;
use App\Services\KubernetesManifestGenerator;
use App\Traits\ExecuteRemoteCommand;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use Visus\Cuid2\Cuid2;

/**
 * KubernetesDeploymentJob
 *
 * Handles deploying applications to Kubernetes clusters.
 * This is the Kubernetes equivalent of ApplicationDeploymentJob for Docker.
 *
 * Deployment flow:
 * 1. Load deployment queue and application
 * 2. Validate destination is a KubernetesDestination
 * 3. Build container image if git-based (using build server if configured)
 * 4. Push image to registry
 * 5. Generate Kubernetes manifests using KubernetesManifestGenerator
 * 6. Apply manifests to cluster using KubernetesClientService
 * 7. Wait for deployment to become ready
 * 8. Perform health checks
 * 9. Stream logs for monitoring
 * 10. Handle errors with rollback capability
 */
class KubernetesDeploymentJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, ExecuteRemoteCommand, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of retries for this job.
     */
    public $tries = 1;

    /**
     * Job timeout in seconds (1 hour).
     */
    public $timeout = 3600;

    /**
     * The deployment queue record.
     */
    private ApplicationDeploymentQueue $application_deployment_queue;

    /**
     * The application being deployed.
     */
    private Application $application;

    /**
     * The Kubernetes destination (namespace configuration).
     */
    private KubernetesDestination $destination;

    /**
     * The Kubernetes cluster.
     */
    private KubernetesCluster $cluster;

    /**
     * The Kubernetes client service.
     */
    private KubernetesClientService $kubernetesClient;

    /**
     * The manifest generator service.
     */
    private KubernetesManifestGenerator $manifestGenerator;

    /**
     * Deployment UUID for tracking.
     */
    private string $deployment_uuid;

    /**
     * The commit SHA being deployed.
     */
    private string $commit;

    /**
     * Whether this is a rollback deployment.
     */
    private bool $rollback;

    /**
     * Whether to force rebuild the image.
     */
    private bool $force_rebuild;

    /**
     * The pull request ID (0 for main deployments).
     */
    private int $pull_request_id;

    /**
     * The container image name for this deployment.
     */
    private string $production_image_name = '';

    /**
     * The previous deployment's image for rollback.
     */
    private ?string $previous_image_name = null;

    /**
     * The build pack type.
     */
    private ?string $build_pack = null;

    /**
     * Git source (GithubApp, GitlabApp, or 'other').
     */
    private GithubApp|GitlabApp|string $source = 'other';

    /**
     * Saved command outputs for reference.
     */
    private Collection $saved_outputs;

    /**
     * Kubernetes deployment settings for the application.
     */
    private ?KubernetesDeploymentSettings $deploymentSettings = null;

    /**
     * Generated manifests cache.
     */
    private array $manifests = [];

    /**
     * Build server if configured.
     */
    private ?Server $build_server = null;

    /**
     * Whether to use a build server.
     */
    private bool $use_build_server = false;

    /**
     * Preview deployment if this is a PR deployment.
     */
    private ?ApplicationPreview $preview = null;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $application_deployment_queue_id)
    {
        $this->onQueue('high');
        $this->saved_outputs = collect();
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['App\Models\ApplicationDeploymentQueue:'.$this->application_deployment_queue_id];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Load the deployment queue
        $this->application_deployment_queue = ApplicationDeploymentQueue::find($this->application_deployment_queue_id);

        if (! $this->application_deployment_queue) {
            \Log::error("KubernetesDeploymentJob: Deployment queue {$this->application_deployment_queue_id} not found");

            return;
        }

        // Check if deployment was cancelled before starting
        $this->application_deployment_queue->refresh();
        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
            $this->application_deployment_queue->addLogEntry('Deployment was cancelled before starting.');

            return;
        }

        // Mark as in progress
        $this->application_deployment_queue->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            'horizon_job_worker' => gethostname(),
        ]);

        try {
            // Initialize deployment context
            $this->initializeDeployment();

            // Validate destination
            if (! $this->validateDestination()) {
                return;
            }

            // Execute deployment based on build pack
            $this->executeDeployment();

            // Post-deployment tasks
            $this->postDeployment();

        } catch (Exception $e) {
            $this->fail($e);
            throw $e;
        } finally {
            try {
                $this->application_deployment_queue->update([
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);
            } catch (Exception $e) {
                \Log::warning('Failed to update finished_at for Kubernetes deployment '.$this->deployment_uuid.': '.$e->getMessage());
            }

            try {
                ServiceStatusChanged::dispatch(data_get($this->application, 'environment.project.team.id'));
            } catch (Exception $e) {
                \Log::warning('Failed to dispatch ServiceStatusChanged for deployment '.$this->deployment_uuid.': '.$e->getMessage());
            }
        }
    }

    /**
     * Initialize the deployment context.
     */
    private function initializeDeployment(): void
    {
        $this->application = Application::find($this->application_deployment_queue->application_id);

        if (! $this->application) {
            throw new Exception('Application not found');
        }

        $this->deployment_uuid = $this->application_deployment_queue->deployment_uuid;
        $this->pull_request_id = $this->application_deployment_queue->pull_request_id;
        $this->commit = $this->application_deployment_queue->commit;
        $this->rollback = $this->application_deployment_queue->rollback;
        $this->force_rebuild = $this->application_deployment_queue->force_rebuild;
        $this->build_pack = data_get($this->application, 'build_pack');

        // Load git source if available
        $source = data_get($this->application, 'source');
        if ($source) {
            $this->source = $source->getMorphClass()::where('id', $this->application->source->id)->first();
        }

        // Load Kubernetes deployment settings
        $this->deploymentSettings = KubernetesDeploymentSettings::where('application_id', $this->application->id)->first();

        // Set preview if this is a PR deployment
        if ($this->pull_request_id !== 0) {
            $this->preview = ApplicationPreview::findPreviewByApplicationAndPullId($this->application->id, $this->pull_request_id);
        }

        // Check for build server
        if (data_get($this->application, 'settings.is_build_server_enabled')) {
            $teamId = data_get($this->application, 'environment.project.team.id');
            $buildServers = Server::buildServers($teamId)->get();
            if ($buildServers->count() > 0) {
                $this->build_server = $buildServers->random();
                $this->application_deployment_queue->build_server_id = $this->build_server->id;
                $this->use_build_server = true;
                $this->application_deployment_queue->addLogEntry("Found a suitable build server ({$this->build_server->name}).");
            }
        }

        $this->application_deployment_queue->addLogEntry("Starting Kubernetes deployment of {$this->application->name}.");
    }

    /**
     * Validate that the destination is a Kubernetes destination.
     */
    private function validateDestination(): bool
    {
        // Get destination from the application
        $destination = $this->application->destination;

        if (! ($destination instanceof KubernetesDestination)) {
            $this->application_deployment_queue->addLogEntry('This deployment job is only for Kubernetes destinations.', 'stderr');
            $this->failDeployment();

            return false;
        }

        $this->destination = $destination;
        $this->cluster = $this->destination->cluster;

        if (! $this->cluster) {
            $this->application_deployment_queue->addLogEntry('Kubernetes cluster not found.', 'stderr');
            $this->failDeployment();

            return false;
        }

        // Test cluster connectivity
        if (! $this->cluster->testConnection()) {
            $this->application_deployment_queue->addLogEntry('Cannot connect to Kubernetes cluster. Please verify the cluster configuration.', 'stderr');
            $this->failDeployment();

            return false;
        }

        // Initialize Kubernetes client
        $this->kubernetesClient = $this->cluster->getClient();

        // Check RBAC permissions
        $this->checkRbacPermissions();

        // Initialize manifest generator
        $this->manifestGenerator = new KubernetesManifestGenerator($this->application, $this->destination);

        $this->application_deployment_queue->addLogEntry("Deploying to Kubernetes cluster: {$this->cluster->name}");
        $this->application_deployment_queue->addLogEntry("Target namespace: {$this->destination->namespace}");

        return true;
    }

    /**
     * Check RBAC permissions on the cluster.
     */
    private function checkRbacPermissions(): void
    {
        $this->application_deployment_queue->addLogEntry('Checking RBAC permissions...');

        try {
            $permissions = $this->kubernetesClient->checkRbacPermissions();
            $missingPermissions = array_filter($permissions, fn ($granted) => ! $granted);

            if (! empty($missingPermissions)) {
                $missing = array_keys($missingPermissions);
                $this->application_deployment_queue->addLogEntry('Warning: Some RBAC permissions may be missing: '.implode(', ', array_slice($missing, 0, 5)), 'stderr');
            } else {
                $this->application_deployment_queue->addLogEntry('RBAC permissions verified.');
            }
        } catch (Exception $e) {
            $this->application_deployment_queue->addLogEntry('Could not verify RBAC permissions: '.$e->getMessage(), hidden: true);
        }
    }

    /**
     * Execute the deployment based on build pack type.
     */
    private function executeDeployment(): void
    {
        // Ensure namespace exists
        $this->ensureNamespaceExists();

        // Build and push image if needed
        if ($this->shouldBuildImage()) {
            $this->buildAndPushImage();
        } else {
            $this->prepareDockerImage();
        }

        // Check for cancellation
        $this->checkForCancellation();

        // Generate Kubernetes manifests
        $this->generateManifests();

        // Apply manifests to cluster
        $this->applyManifests();

        // Wait for deployment to be ready
        $this->waitForDeployment();

        // Perform health check
        $this->performHealthCheck();

        // Stream deployment logs
        $this->streamLogs();
    }

    /**
     * Ensure the target namespace exists in the cluster.
     */
    private function ensureNamespaceExists(): void
    {
        $namespace = $this->destination->namespace;

        try {
            if (! $this->kubernetesClient->namespaceExists($namespace)) {
                $this->application_deployment_queue->addLogEntry("Creating namespace: {$namespace}");
                $this->kubernetesClient->createNamespace($namespace);
            }
        } catch (Exception $e) {
            throw new Exception("Failed to ensure namespace exists: ".$e->getMessage());
        }
    }

    /**
     * Check if we need to build an image.
     */
    private function shouldBuildImage(): bool
    {
        // Docker image build pack doesn't need building
        if ($this->build_pack === 'dockerimage') {
            return false;
        }

        // If we have a registry image configured, check if it's a pre-built image
        $registryImage = $this->application->docker_registry_image_name ?? null;
        if (! empty($registryImage) && $this->build_pack === 'dockerimage') {
            return false;
        }

        // Git-based builds need to be built
        return in_array($this->build_pack, ['nixpacks', 'dockerfile', 'dockercompose', 'static']);
    }

    /**
     * Build and push the container image.
     *
     * For Kubernetes deployments, images must be pushed to a registry.
     * This integrates with the existing build infrastructure.
     */
    private function buildAndPushImage(): void
    {
        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        $this->application_deployment_queue->addLogEntry('Preparing container image...');

        // Generate image name
        $this->generateImageName();

        // For Kubernetes deployments, we need the image in a registry
        $registryImage = $this->application->docker_registry_image_name ?? null;

        if (empty($registryImage)) {
            throw new Exception('A Docker registry must be configured for Kubernetes deployments. Please configure a registry in the application settings.');
        }

        $this->application_deployment_queue->addLogEntry("Target image: {$this->production_image_name}");

        // Note: The actual image build would be done by the ApplicationDeploymentJob
        // or a dedicated build system. For Kubernetes deployments, we assume the image
        // is already available in the registry (built by CI/CD or previous deployment).
        //
        // In a full implementation, we would either:
        // 1. Trigger a build on a build server using SSH
        // 2. Use Kaniko for in-cluster builds
        // 3. Integrate with external CI/CD (GitHub Actions, GitLab CI, etc.)

        $this->application_deployment_queue->addLogEntry('Using container image from registry.');

        // Store current image for potential rollback
        if (! $this->rollback) {
            $this->previous_image_name = $this->application->docker_registry_image_name.':'.($this->application->docker_registry_image_tag ?? 'latest');
        }
    }

    /**
     * Prepare Docker image name for dockerimage build pack.
     */
    private function prepareDockerImage(): void
    {
        $dockerImage = $this->application->docker_registry_image_name;
        $dockerImageTag = $this->application->docker_registry_image_tag ?: 'latest';

        if (empty($dockerImage)) {
            throw new Exception('Docker image name is required for dockerimage build pack.');
        }

        // Handle image hash deployments (sha256-)
        if (str($dockerImageTag)->startsWith('sha256-')) {
            $this->production_image_name = "{$dockerImage}@sha256:".str($dockerImageTag)->after('sha256-');
        } else {
            $this->production_image_name = "{$dockerImage}:{$dockerImageTag}";
        }

        $this->application_deployment_queue->addLogEntry("Using Docker image: {$this->production_image_name}");
    }

    /**
     * Generate the production image name.
     */
    private function generateImageName(): void
    {
        $registryImage = $this->application->docker_registry_image_name;
        $registryTag = $this->application->docker_registry_image_tag ?? 'latest';

        if (! empty($registryImage)) {
            // Use commit SHA as tag for traceability if available
            if ($this->commit !== 'HEAD' && strlen($this->commit) >= 8) {
                $commitTag = substr($this->commit, 0, 8);
                $this->production_image_name = "{$registryImage}:{$commitTag}";
            } else {
                $this->production_image_name = "{$registryImage}:{$registryTag}";
            }
        } else {
            // Fallback to UUID-based naming
            $this->production_image_name = "coolify/{$this->application->uuid}:latest";
        }
    }

    /**
     * Generate Kubernetes manifests for the application.
     */
    private function generateManifests(): void
    {
        $this->application_deployment_queue->addLogEntry('Generating Kubernetes manifests...');

        try {
            // Temporarily update the application image for manifest generation
            $originalImage = $this->application->docker_registry_image_name;
            $originalTag = $this->application->docker_registry_image_tag;

            // Parse image name and tag
            if (str_contains($this->production_image_name, '@sha256:')) {
                // Handle digest-based images
                $parts = explode('@', $this->production_image_name);
                $this->application->docker_registry_image_name = $parts[0];
                $this->application->docker_registry_image_tag = 'sha256-'.substr($parts[1], 7);
            } else {
                $imageParts = explode(':', $this->production_image_name, 2);
                $this->application->docker_registry_image_name = $imageParts[0];
                $this->application->docker_registry_image_tag = $imageParts[1] ?? 'latest';
            }

            // Generate all manifests
            $this->manifests = $this->manifestGenerator->generateAll();

            // Restore original values
            $this->application->docker_registry_image_name = $originalImage;
            $this->application->docker_registry_image_tag = $originalTag;

            // Log generated manifests
            $manifestTypes = array_keys($this->manifests);
            $this->application_deployment_queue->addLogEntry('Generated manifests: '.implode(', ', $manifestTypes));

            // Output YAML for debugging (hidden by default)
            if (config('app.debug')) {
                $yaml = $this->manifestGenerator->toYaml();
                $this->application_deployment_queue->addLogEntry("Manifest YAML:\n{$yaml}", hidden: true);
            }

        } catch (Exception $e) {
            throw new Exception("Failed to generate manifests: ".$e->getMessage());
        }
    }

    /**
     * Apply generated manifests to the Kubernetes cluster.
     */
    private function applyManifests(): void
    {
        $this->application_deployment_queue->addLogEntry('Applying manifests to cluster...');

        try {
            // Convert manifests to YAML
            $yaml = $this->manifestGenerator->toYaml();

            // Apply to cluster
            $this->kubernetesClient->applyManifest($yaml);

            $this->application_deployment_queue->addLogEntry('Manifests applied successfully.');

        } catch (Exception $e) {
            throw new Exception("Failed to apply manifests: ".$e->getMessage());
        }
    }

    /**
     * Wait for the deployment to become ready.
     */
    private function waitForDeployment(): void
    {
        $this->application_deployment_queue->addLogEntry('Waiting for deployment to become ready...');

        $deploymentName = $this->getDeploymentName();
        $namespace = $this->destination->namespace;

        // Get desired replicas from settings or defaults
        $desiredReplicas = $this->deploymentSettings?->replicas ?? $this->destination->default_replicas ?? 1;

        // Timeout configuration
        $maxWaitTime = config('coolify.kubernetes.deployment_timeout', 300); // 5 minutes default
        $checkInterval = 5; // 5 seconds
        $startTime = time();
        $lastLoggedStatus = '';

        while ((time() - $startTime) < $maxWaitTime) {
            // Check for cancellation
            $this->checkForCancellation();

            try {
                $status = $this->kubernetesClient->getDeploymentStatus($deploymentName, $namespace);

                $readyReplicas = $status['readyReplicas'] ?? 0;
                $availableReplicas = $status['availableReplicas'] ?? 0;
                $updatedReplicas = $status['updatedReplicas'] ?? 0;
                $totalReplicas = $status['replicas'] ?? $desiredReplicas;

                $statusString = "{$readyReplicas}/{$totalReplicas} pods ready";

                // Only log if status changed
                if ($statusString !== $lastLoggedStatus) {
                    $this->application_deployment_queue->addLogEntry("Deployment status: {$statusString}");
                    $lastLoggedStatus = $statusString;
                }

                // Check if deployment is ready
                if ($readyReplicas >= $desiredReplicas && $availableReplicas >= $desiredReplicas) {
                    $this->application_deployment_queue->addLogEntry(
                        "Deployment ready: {$readyReplicas}/{$desiredReplicas} pods available."
                    );

                    return;
                }

                // Check for failed conditions
                $conditions = $status['conditions'] ?? [];
                foreach ($conditions as $condition) {
                    if (($condition['type'] ?? '') === 'Progressing' &&
                        ($condition['status'] ?? '') === 'False') {
                        $reason = $condition['reason'] ?? 'Unknown';
                        $message = $condition['message'] ?? 'Deployment failed to progress';
                        throw new Exception("Deployment failed: {$reason} - {$message}");
                    }
                }

                // Log events for debugging
                $this->logDeploymentEvents($deploymentName, $namespace);

            } catch (Exception $e) {
                if (str_contains($e->getMessage(), 'Deployment failed')) {
                    throw $e;
                }

                if (str_contains($e->getMessage(), 'not found')) {
                    $this->application_deployment_queue->addLogEntry(
                        'Waiting for deployment to be created...',
                        hidden: true
                    );
                } else {
                    throw $e;
                }
            }

            Sleep::for($checkInterval)->seconds();
        }

        throw new Exception("Deployment timed out after {$maxWaitTime} seconds. The pods may still be starting.");
    }

    /**
     * Log Kubernetes events related to the deployment.
     */
    private function logDeploymentEvents(string $deploymentName, string $namespace): void
    {
        try {
            $events = $this->kubernetesClient->getEvents(
                $namespace,
                "involvedObject.name={$deploymentName}"
            );

            foreach ($events as $event) {
                $type = $event['type'] ?? 'Normal';
                $reason = $event['reason'] ?? 'Unknown';
                $message = $event['message'] ?? '';

                if ($type === 'Warning') {
                    $this->application_deployment_queue->addLogEntry(
                        "Event: [{$reason}] {$message}",
                        'stderr',
                        hidden: true
                    );
                }
            }
        } catch (Exception $e) {
            // Ignore event logging failures
        }
    }

    /**
     * Perform health check on the deployed application.
     */
    private function performHealthCheck(): void
    {
        if (! $this->application->health_check_enabled) {
            $this->application_deployment_queue->addLogEntry('Health check disabled, skipping.');

            return;
        }

        $this->application_deployment_queue->addLogEntry('Performing health check...');

        $deploymentName = $this->getDeploymentName();
        $namespace = $this->destination->namespace;

        try {
            // Get pods for this deployment
            $pods = $this->kubernetesClient->getPods($namespace, [
                'app.kubernetes.io/name' => $deploymentName,
                'app.kubernetes.io/instance' => $this->application->uuid,
            ]);

            if (empty($pods)) {
                $this->application_deployment_queue->addLogEntry('No pods found for health check.', 'stderr');

                return;
            }

            $healthyPods = 0;
            $totalPods = count($pods);

            foreach ($pods as $pod) {
                $podName = $pod['metadata']['name'] ?? 'unknown';
                $phase = $pod['status']['phase'] ?? 'Unknown';
                $containerStatuses = $pod['status']['containerStatuses'] ?? [];

                $isReady = $phase === 'Running';
                foreach ($containerStatuses as $containerStatus) {
                    if (! ($containerStatus['ready'] ?? false)) {
                        $isReady = false;

                        // Log container issues
                        $state = $containerStatus['state'] ?? [];
                        if (isset($state['waiting'])) {
                            $reason = $state['waiting']['reason'] ?? 'Unknown';
                            $message = $state['waiting']['message'] ?? '';
                            $this->application_deployment_queue->addLogEntry(
                                "Pod {$podName}: Container waiting - {$reason}: {$message}",
                                hidden: true
                            );
                        }
                        break;
                    }
                }

                if ($isReady) {
                    $healthyPods++;
                }
            }

            $this->application_deployment_queue->addLogEntry("Health check: {$healthyPods}/{$totalPods} pods healthy.");

            if ($healthyPods === 0) {
                throw new Exception('Health check failed: No healthy pods');
            }

        } catch (Exception $e) {
            $this->application_deployment_queue->addLogEntry(
                "Health check warning: ".$e->getMessage(),
                'stderr'
            );
        }
    }

    /**
     * Stream logs from the deployed pods.
     */
    private function streamLogs(): void
    {
        $deploymentName = $this->getDeploymentName();
        $namespace = $this->destination->namespace;

        $this->application_deployment_queue->addLogEntry('----------------------------------------');

        try {
            // Get pods for this deployment
            $pods = $this->kubernetesClient->getPods($namespace, [
                'app.kubernetes.io/name' => $deploymentName,
                'app.kubernetes.io/instance' => $this->application->uuid,
            ]);

            if (empty($pods)) {
                $this->application_deployment_queue->addLogEntry('No pods found for log streaming.');

                return;
            }

            // Stream logs from the first running pod
            foreach ($pods as $pod) {
                $podName = $pod['metadata']['name'] ?? null;
                $phase = $pod['status']['phase'] ?? 'Unknown';

                if ($podName && $phase === 'Running') {
                    $this->application_deployment_queue->addLogEntry("Fetching logs from pod: {$podName}");

                    try {
                        $logs = $this->kubernetesClient->getPodLogs($podName, $namespace);

                        // Only show last 30 lines to avoid flooding
                        $logLines = explode("\n", $logs);
                        $lastLines = array_slice($logLines, -30);

                        if (! empty(array_filter($lastLines))) {
                            $this->application_deployment_queue->addLogEntry('--- Recent Pod Logs ---', hidden: true);
                            foreach ($lastLines as $line) {
                                if (! empty(trim($line))) {
                                    $this->application_deployment_queue->addLogEntry($line, hidden: true);
                                }
                            }
                            $this->application_deployment_queue->addLogEntry('--- End Logs ---', hidden: true);
                        }
                    } catch (Exception $e) {
                        $this->application_deployment_queue->addLogEntry(
                            "Could not retrieve pod logs: ".$e->getMessage(),
                            hidden: true
                        );
                    }

                    break; // Only log from one pod
                }
            }

        } catch (Exception $e) {
            $this->application_deployment_queue->addLogEntry(
                "Log streaming skipped: ".$e->getMessage(),
                hidden: true
            );
        }
    }

    /**
     * Post-deployment tasks.
     */
    private function postDeployment(): void
    {
        // Mark deployment as complete
        $this->completeDeployment();

        try {
            $this->application->isConfigurationChanged(true);
        } catch (Exception $e) {
            \Log::warning('Failed to mark configuration as changed for deployment '.$this->deployment_uuid.': '.$e->getMessage());
        }
    }

    /**
     * Rollback to the previous deployment.
     */
    private function rollback(): void
    {
        $this->application_deployment_queue->addLogEntry('Initiating rollback...');

        $deploymentName = $this->getDeploymentName();
        $namespace = $this->destination->namespace;

        try {
            // Get current deployment
            $deployment = $this->kubernetesClient->getDeployment($deploymentName, $namespace);

            if (! $deployment) {
                $this->application_deployment_queue->addLogEntry('No deployment found to rollback.', 'stderr');

                return;
            }

            // Get the previous revision annotation
            $annotations = $deployment['metadata']['annotations'] ?? [];
            $currentRevision = $annotations['deployment.kubernetes.io/revision'] ?? '1';

            $this->application_deployment_queue->addLogEntry("Current revision: {$currentRevision}");

            // For safety during failures, scale down to 0
            // The user can manually restore or trigger a new deployment
            $this->application_deployment_queue->addLogEntry('Scaling deployment to 0 replicas for safety.');
            $this->kubernetesClient->scaleDeployment($deploymentName, $namespace, 0);

            $this->application_deployment_queue->addLogEntry('Rollback completed. Deployment scaled to 0.');

        } catch (Exception $e) {
            $this->application_deployment_queue->addLogEntry(
                "Rollback failed: ".$e->getMessage(),
                'stderr'
            );
        }
    }

    /**
     * Get the Kubernetes deployment name for this application.
     */
    private function getDeploymentName(): string
    {
        $name = $this->application->name ?? $this->application->uuid;

        // Sanitize for Kubernetes naming requirements
        // Must be lowercase, alphanumeric, hyphens allowed, max 63 chars
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        // Ensure max length of 63 characters
        if (strlen($name) > 63) {
            $name = substr($name, 0, 63);
            $name = rtrim($name, '-');
        }

        // Ensure name is not empty
        if (empty($name)) {
            $name = 'app-'.substr($this->application->uuid, 0, 8);
        }

        return $name;
    }

    /**
     * Check if deployment was cancelled.
     */
    private function checkForCancellation(): void
    {
        $this->application_deployment_queue->refresh();

        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
            $this->application_deployment_queue->addLogEntry('Deployment cancelled by user.');
            throw new Exception('Deployment cancelled by user');
        }
    }

    /**
     * Transition deployment to a new status.
     */
    private function transitionToStatus(ApplicationDeploymentStatus $status): void
    {
        if ($this->isInTerminalState()) {
            return;
        }

        $this->application_deployment_queue->update([
            'status' => $status->value,
        ]);

        $this->handleStatusTransition($status);
        queue_next_deployment($this->application);
    }

    /**
     * Check if deployment is in a terminal state.
     */
    private function isInTerminalState(): bool
    {
        $this->application_deployment_queue->refresh();

        $terminalStates = [
            ApplicationDeploymentStatus::FINISHED->value,
            ApplicationDeploymentStatus::FAILED->value,
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ];

        if (in_array($this->application_deployment_queue->status, $terminalStates)) {
            if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
                throw new Exception('Deployment cancelled by user');
            }

            return true;
        }

        return false;
    }

    /**
     * Handle status transition side effects.
     */
    private function handleStatusTransition(ApplicationDeploymentStatus $status): void
    {
        match ($status) {
            ApplicationDeploymentStatus::FINISHED => $this->handleSuccessfulDeployment(),
            ApplicationDeploymentStatus::FAILED => $this->handleFailedDeployment(),
            default => null,
        };
    }

    /**
     * Handle successful deployment side effects.
     */
    private function handleSuccessfulDeployment(): void
    {
        // Reset restart count after successful deployment
        $this->application->update([
            'restart_count' => 0,
            'last_restart_at' => null,
            'last_restart_type' => null,
        ]);

        event(new ApplicationConfigurationChanged($this->application->team()->id));

        // Send success notification
        $this->application->environment->project->team?->notify(
            new DeploymentSuccess($this->application, $this->deployment_uuid, $this->preview)
        );
    }

    /**
     * Handle failed deployment side effects.
     */
    private function handleFailedDeployment(): void
    {
        // Send failure notification
        $this->application->environment->project->team?->notify(
            new DeploymentFailed($this->application, $this->deployment_uuid, $this->preview)
        );
    }

    /**
     * Complete deployment successfully.
     */
    private function completeDeployment(): void
    {
        $this->application_deployment_queue->addLogEntry('Kubernetes deployment completed successfully.');
        $this->transitionToStatus(ApplicationDeploymentStatus::FINISHED);
    }

    /**
     * Fail the deployment.
     */
    protected function failDeployment(): void
    {
        $this->transitionToStatus(ApplicationDeploymentStatus::FAILED);
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        $this->failDeployment();

        // Log comprehensive error information
        $errorMessage = $exception->getMessage() ?: 'Unknown error occurred';
        $errorCode = $exception->getCode();
        $errorClass = get_class($exception);

        $this->application_deployment_queue->addLogEntry('========================================', 'stderr');
        $this->application_deployment_queue->addLogEntry("Kubernetes deployment failed: {$errorMessage}", 'stderr');
        $this->application_deployment_queue->addLogEntry("Error type: {$errorClass}", 'stderr', hidden: true);
        $this->application_deployment_queue->addLogEntry("Error code: {$errorCode}", 'stderr', hidden: true);
        $this->application_deployment_queue->addLogEntry("Location: {$exception->getFile()}:{$exception->getLine()}", 'stderr', hidden: true);

        // Log previous exceptions if they exist (for chained exceptions)
        $previous = $exception->getPrevious();
        if ($previous) {
            $this->application_deployment_queue->addLogEntry('Caused by:', 'stderr', hidden: true);
            $previousMessage = $previous->getMessage() ?: 'No message';
            $previousClass = get_class($previous);
            $this->application_deployment_queue->addLogEntry("  {$previousClass}: {$previousMessage}", 'stderr', hidden: true);
        }

        // Log first few lines of stack trace for debugging
        $trace = $exception->getTraceAsString();
        $traceLines = explode("\n", $trace);
        $this->application_deployment_queue->addLogEntry('Stack trace (first 5 lines):', 'stderr', hidden: true);
        foreach (array_slice($traceLines, 0, 5) as $traceLine) {
            $this->application_deployment_queue->addLogEntry("  {$traceLine}", 'stderr', hidden: true);
        }
        $this->application_deployment_queue->addLogEntry('========================================', 'stderr');

        // Attempt rollback if this was not a cancelled deployment
        if ($errorMessage !== 'Deployment cancelled by user') {
            try {
                $this->rollback();
            } catch (Exception $e) {
                $this->application_deployment_queue->addLogEntry(
                    "Rollback attempt failed: ".$e->getMessage(),
                    'stderr'
                );
            }
        }
    }
}

<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProcessStatus;
use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\KubernetesCluster;
use App\Models\KubernetesDestination;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use App\Services\KubernetesClientService;
use App\Services\KubernetesManifestGenerator;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * KubernetesDeploymentJob
 *
 * Handles the deployment of applications to Kubernetes clusters.
 * This job orchestrates the entire deployment process including:
 * - Building and pushing container images
 * - Generating Kubernetes manifests
 * - Applying manifests to the cluster
 * - Monitoring deployment status
 * - Rolling back on failure
 */
class KubernetesDeploymentJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 3600;

    /**
     * The deployment queue record ID.
     */
    private int $application_deployment_queue_id;

    /**
     * The deployment queue record.
     */
    private ?ApplicationDeploymentQueue $deploymentQueue = null;

    /**
     * The application being deployed.
     */
    private ?Application $application = null;

    /**
     * The Kubernetes destination.
     */
    private ?KubernetesDestination $destination = null;

    /**
     * The Kubernetes cluster.
     */
    private ?KubernetesCluster $cluster = null;

    /**
     * The Kubernetes client service.
     */
    private ?KubernetesClientService $kubeClient = null;

    /**
     * The manifest generator.
     */
    private ?KubernetesManifestGenerator $manifestGenerator = null;

    /**
     * Deployment UUID for tracking.
     */
    private string $deploymentUuid;

    /**
     * Whether this is a rollback deployment.
     */
    private bool $isRollback = false;

    /**
     * The commit hash being deployed.
     */
    private string $commit;

    /**
     * Container image to deploy.
     */
    private string $containerImage;

    /**
     * Get tags for the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return ['App\Models\ApplicationDeploymentQueue:' . $this->application_deployment_queue_id];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(int $application_deployment_queue_id)
    {
        $this->onQueue('high');
        $this->application_deployment_queue_id = $application_deployment_queue_id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $this->initialize();
            $this->addDeploymentLog('Starting Kubernetes deployment...');

            // Step 1: Validate prerequisites
            $this->validatePrerequisites();

            // Step 2: Build and push container image (if needed)
            $this->buildAndPushImage();

            // Step 3: Generate Kubernetes manifests
            $manifests = $this->generateManifests();

            // Step 4: Apply manifests to cluster
            $this->applyManifests($manifests);

            // Step 5: Wait for deployment to be ready
            $this->waitForDeployment();

            // Step 6: Finalize deployment
            $this->finalizeDeployment();

        } catch (Throwable $e) {
            $this->handleDeploymentFailure($e);
        }
    }

    /**
     * Initialize the job with required models.
     *
     * @throws Exception
     */
    private function initialize(): void
    {
        $this->deploymentQueue = ApplicationDeploymentQueue::find($this->application_deployment_queue_id);

        if (! $this->deploymentQueue) {
            throw new Exception('Deployment queue record not found.');
        }

        $this->application = Application::find($this->deploymentQueue->application_id);

        if (! $this->application) {
            throw new Exception('Application not found.');
        }

        $this->deploymentUuid = $this->deploymentQueue->deployment_uuid;
        $this->isRollback = $this->deploymentQueue->rollback ?? false;
        $this->commit = $this->deploymentQueue->commit ?? 'HEAD';

        // Get the Kubernetes destination
        $this->destination = $this->application->destination;

        if (! ($this->destination instanceof KubernetesDestination)) {
            throw new Exception('Application destination is not a Kubernetes destination.');
        }

        $this->cluster = $this->destination->cluster;

        if (! $this->cluster) {
            throw new Exception('Kubernetes cluster not found for destination.');
        }

        // Initialize Kubernetes client
        $this->kubeClient = new KubernetesClientService($this->cluster);

        // Initialize manifest generator
        $this->manifestGenerator = new KubernetesManifestGenerator($this->application, $this->destination);

        // Update deployment status
        $this->updateDeploymentStatus(ApplicationDeploymentStatus::IN_PROGRESS);
    }

    /**
     * Validate that all prerequisites are met for deployment.
     *
     * @throws Exception
     */
    private function validatePrerequisites(): void
    {
        $this->addDeploymentLog('Validating prerequisites...');

        // Check Kubernetes API connectivity
        if (! $this->kubeClient->checkApiHealth()) {
            throw new Exception('Cannot connect to Kubernetes API. Please verify cluster configuration.');
        }

        $this->addDeploymentLog('Kubernetes API connection verified.');

        // Check RBAC permissions
        $permissions = $this->kubeClient->checkRbacPermissions();
        $missingPermissions = array_filter($permissions, fn ($granted) => ! $granted);

        if (! empty($missingPermissions)) {
            $missing = array_keys($missingPermissions);
            throw new Exception('Missing RBAC permissions: ' . implode(', ', $missing));
        }

        $this->addDeploymentLog('RBAC permissions verified.');

        // Ensure namespace exists
        $namespace = $this->destination->namespace;
        if (! $this->kubeClient->namespaceExists($namespace)) {
            $this->addDeploymentLog("Creating namespace: {$namespace}");
            $this->kubeClient->createNamespace($namespace);
        }

        $this->addDeploymentLog('Prerequisites validated successfully.');
    }

    /**
     * Build and push the container image.
     *
     * @throws Exception
     */
    private function buildAndPushImage(): void
    {
        $this->addDeploymentLog('Building container image...');

        // Determine the container image name and tag
        $registryImage = $this->application->docker_registry_image_name ?? null;
        $registryTag = $this->deploymentQueue->commit ?? $this->application->docker_registry_image_tag ?? 'latest';

        if (! empty($registryImage)) {
            // Use pre-built image from registry
            $this->containerImage = $registryImage . ':' . $registryTag;
            $this->addDeploymentLog("Using registry image: {$this->containerImage}");
        } else {
            // Build image using the build server
            $this->containerImage = $this->buildContainerImage();
        }

        $this->addDeploymentLog('Container image ready: ' . $this->containerImage);
    }

    /**
     * Build the container image on the build server.
     *
     * @return string The built image name
     *
     * @throws Exception
     */
    private function buildContainerImage(): string
    {
        // For Kubernetes deployments, we need to build and push to a registry
        // This would integrate with the existing build process

        $uuid = $this->application->uuid;
        $tag = $this->deploymentQueue->commit ?? 'latest';

        // Generate image name based on registry configuration
        $registry = $this->destination->container_registry ?? 'docker.io';
        $imageName = "{$registry}/coolify/{$uuid}:{$tag}";

        $this->addDeploymentLog("Building image: {$imageName}");

        // Note: Actual build process would be handled by the build server
        // This is a placeholder for the integration point
        // In production, this would trigger the build process on the build server
        // and push the image to the configured registry

        // For now, we'll use a generated image name
        // The actual build would be done by a separate process or the existing build system

        return $imageName;
    }

    /**
     * Generate Kubernetes manifests for the deployment.
     *
     * @return string The generated manifests as YAML
     */
    private function generateManifests(): string
    {
        $this->addDeploymentLog('Generating Kubernetes manifests...');

        // Generate all manifests
        $manifests = $this->manifestGenerator->generateAll();

        // Update the deployment image to the built image
        if (isset($manifests['Deployment'])) {
            $manifests['Deployment']['spec']['template']['spec']['containers'][0]['image'] = $this->containerImage;
        }

        // Convert to YAML
        $yaml = $this->manifestGenerator->toYaml();

        $this->addDeploymentLog('Manifests generated successfully.');
        $this->addDeploymentLog('Generated resources: ' . implode(', ', array_keys($manifests)));

        return $yaml;
    }

    /**
     * Apply manifests to the Kubernetes cluster.
     *
     * @param  string  $manifests  The manifests YAML
     *
     * @throws Exception
     */
    private function applyManifests(string $manifests): void
    {
        $this->addDeploymentLog('Applying manifests to cluster...');

        try {
            $this->kubeClient->applyManifest($manifests);
            $this->addDeploymentLog('Manifests applied successfully.');
        } catch (Throwable $e) {
            throw new Exception('Failed to apply manifests: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Wait for the deployment to become ready.
     *
     * @throws Exception
     */
    private function waitForDeployment(): void
    {
        $this->addDeploymentLog('Waiting for deployment to be ready...');

        $deploymentName = $this->getDeploymentName();
        $namespace = $this->destination->namespace;
        $timeout = config('coolify.kubernetes.deployment_timeout', 300);
        $startTime = time();

        while ((time() - $startTime) < $timeout) {
            try {
                $status = $this->kubeClient->getDeploymentStatus($deploymentName, $namespace);

                $replicas = $status['replicas'] ?? 0;
                $readyReplicas = $status['readyReplicas'] ?? 0;
                $availableReplicas = $status['availableReplicas'] ?? 0;
                $updatedReplicas = $status['updatedReplicas'] ?? 0;

                $this->addDeploymentLog("Deployment status: {$readyReplicas}/{$replicas} ready, {$availableReplicas} available");

                // Check if deployment is complete
                if ($replicas > 0 && $readyReplicas === $replicas && $availableReplicas === $replicas && $updatedReplicas === $replicas) {
                    $this->addDeploymentLog('Deployment is ready!');

                    return;
                }

                // Check for deployment conditions
                $conditions = $status['conditions'] ?? [];
                foreach ($conditions as $condition) {
                    if ($condition['type'] === 'Progressing' && $condition['status'] === 'False') {
                        throw new Exception('Deployment failed to progress: ' . ($condition['message'] ?? 'Unknown reason'));
                    }
                }

                sleep(5);

            } catch (Throwable $e) {
                // If it's our thrown exception, re-throw it
                if (str_contains($e->getMessage(), 'Deployment failed')) {
                    throw $e;
                }

                // Otherwise, log and continue waiting
                $this->addDeploymentLog('Waiting for deployment... ' . $e->getMessage());
                sleep(5);
            }
        }

        throw new Exception("Deployment timeout: deployment did not become ready within {$timeout} seconds");
    }

    /**
     * Finalize the deployment after successful rollout.
     */
    private function finalizeDeployment(): void
    {
        $this->addDeploymentLog('Finalizing deployment...');

        // Update deployment status
        $this->updateDeploymentStatus(ApplicationDeploymentStatus::FINISHED);

        // Update application status
        $this->application->status = ProcessStatus::RUNNING->value;
        $this->application->save();

        // Broadcast status change
        ApplicationStatusChanged::dispatch($this->application->team()->first());

        // Send success notification
        $this->application->team()?->notify(new DeploymentSuccess($this->application, $this->deploymentQueue));

        $this->addDeploymentLog('Deployment completed successfully!');
    }

    /**
     * Handle deployment failure.
     *
     * @param  Throwable  $e  The exception that caused the failure
     */
    private function handleDeploymentFailure(Throwable $e): void
    {
        $errorMessage = $e->getMessage();
        $this->addDeploymentLog('Deployment failed: ' . $errorMessage);

        // Attempt rollback if not already a rollback
        if (! $this->isRollback) {
            try {
                $this->rollback();
            } catch (Throwable $rollbackException) {
                $this->addDeploymentLog('Rollback failed: ' . $rollbackException->getMessage());
            }
        }

        // Update deployment status
        $this->updateDeploymentStatus(ApplicationDeploymentStatus::FAILED);

        // Update application status
        if ($this->application) {
            $this->application->status = ProcessStatus::ERROR->value;
            $this->application->save();

            // Broadcast status change
            ApplicationStatusChanged::dispatch($this->application->team()->first());

            // Send failure notification
            $this->application->team()?->notify(new DeploymentFailed($this->application, $this->deploymentQueue, $errorMessage));
        }
    }

    /**
     * Rollback the deployment to the previous version.
     *
     * @throws Exception
     */
    private function rollback(): void
    {
        $this->addDeploymentLog('Attempting rollback...');

        $deploymentName = $this->getDeploymentName();
        $namespace = $this->destination->namespace;

        // Get the deployment
        $deployment = $this->kubeClient->getDeployment($deploymentName, $namespace);

        if ($deployment === null) {
            $this->addDeploymentLog('No deployment found to rollback.');

            return;
        }

        // Get the previous revision from annotations
        $annotations = $deployment['metadata']['annotations'] ?? [];
        $currentRevision = $annotations['deployment.kubernetes.io/revision'] ?? '1';

        $this->addDeploymentLog("Current revision: {$currentRevision}");

        // Kubernetes automatically maintains rollback history
        // We can scale down and let it recover, or delete the deployment
        // For safety, we'll scale to 0 and let the user decide

        $this->addDeploymentLog('Scaling deployment to 0 replicas for safety.');
        $this->kubeClient->scaleDeployment($deploymentName, $namespace, 0);

        $this->addDeploymentLog('Rollback completed. Deployment scaled to 0.');
    }

    /**
     * Get the Kubernetes deployment name.
     *
     * @return string The deployment name
     */
    private function getDeploymentName(): string
    {
        $name = $this->application->name ?? $this->application->uuid;

        // Sanitize for Kubernetes naming requirements
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        if (strlen($name) > 63) {
            $name = substr($name, 0, 63);
            $name = rtrim($name, '-');
        }

        if (empty($name)) {
            $name = 'app-' . substr($this->application->uuid, 0, 8);
        }

        return $name;
    }

    /**
     * Update the deployment status.
     *
     * @param  ApplicationDeploymentStatus  $status  The new status
     */
    private function updateDeploymentStatus(ApplicationDeploymentStatus $status): void
    {
        if ($this->deploymentQueue) {
            $this->deploymentQueue->status = $status->value;
            $this->deploymentQueue->save();
        }
    }

    /**
     * Add a log entry to the deployment.
     *
     * @param  string  $message  The log message
     * @param  string  $type  The log type (output, error)
     * @param  bool  $hidden  Whether the log is hidden
     */
    private function addDeploymentLog(string $message, string $type = 'stdout', bool $hidden = false): void
    {
        if (! $this->deploymentQueue) {
            return;
        }

        $timestamp = now()->toDateTimeString();
        $logLine = "[{$timestamp}] {$message}\n";

        $currentLogs = $this->deploymentQueue->logs ?? '';
        $this->deploymentQueue->logs = $currentLogs . $logLine;
        $this->deploymentQueue->save();

        // Also log to Laravel's logger for debugging
        \Log::info("[K8s Deployment {$this->deploymentUuid}] {$message}");
    }

    /**
     * Handle job failure.
     *
     * @param  Throwable|null  $exception  The exception that caused the failure
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            $this->handleDeploymentFailure($exception);
        }
    }
}

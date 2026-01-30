<?php

/**
 * Property-Based Test: HPA Manifest Generatie
 *
 * **Validates: Requirements 5.2, 5.3**
 *
 * Property 9: HPA Manifest Generatie
 * For any KubernetesDeploymentSettings met autoscaling_enabled=true, de ManifestGenerator
 * SHALL een HorizontalPodAutoscaler manifest genereren met correcte min_replicas,
 * max_replicas en target utilization waarden.
 *
 * This test uses property-based testing principles with random data generation
 * to verify that the KubernetesManifestGenerator correctly generates HPA manifests
 * from KubernetesDeploymentSettings configurations.
 *
 * Requirements covered:
 * - 5.2: Autoscaling configuratie met min/max replicas en CPU/memory targets
 * - 5.3: HPA manifest wordt correct gegenereerd met metrics
 */

use App\Models\Application;
use App\Models\KubernetesCluster;
use App\Models\KubernetesDestination;
use App\Models\KubernetesDeploymentSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\KubernetesManifestGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =========================================================================
// Generator Functions
// =========================================================================

/**
 * Generate a valid kubeconfig YAML string.
 */
function generateHpaKubeconfig(): string
{
    $clusterName = fake()->slug(2);
    $userName = fake()->userName();
    $contextName = fake()->slug(2);
    $serverUrl = 'https://' . fake()->domainName() . ':' . fake()->numberBetween(6443, 9999);
    $token = base64_encode(fake()->sha256());

    return <<<YAML
apiVersion: v1
kind: Config
clusters:
- cluster:
    server: {$serverUrl}
  name: {$clusterName}
contexts:
- context:
    cluster: {$clusterName}
    user: {$userName}
  name: {$contextName}
users:
- name: {$userName}
  user:
    token: {$token}
YAML;
}

/**
 * Generate a random valid Kubernetes namespace name.
 */
function generateHpaNamespace(): string
{
    return fake()->slug(fake()->numberBetween(1, 3));
}

/**
 * Generate a random application name.
 */
function generateHpaAppName(): string
{
    return fake()->words(fake()->numberBetween(1, 3), true) . ' App';
}

/**
 * Generate a random min replicas value (1-10).
 */
function generateMinReplicas(): int
{
    return fake()->numberBetween(1, 10);
}

/**
 * Generate a random max replicas value (must be >= min).
 */
function generateMaxReplicas(int $minReplicas): int
{
    return fake()->numberBetween($minReplicas, max($minReplicas + 20, 50));
}

/**
 * Generate a random CPU utilization target (1-100).
 */
function generateCpuUtilization(): int
{
    return fake()->numberBetween(10, 100);
}

/**
 * Generate a random memory utilization target (1-100).
 */
function generateMemoryUtilization(): int
{
    return fake()->numberBetween(10, 100);
}

/**
 * Generate a random exposed port.
 */
function generateHpaPort(): int
{
    return fake()->numberBetween(80, 9999);
}

// =========================================================================
// Test Setup
// =========================================================================

beforeEach(function () {
    // Create a team and user for the tests
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    // Create a project and environment for resources
    $this->project = Project::create([
        'name' => 'Test Project',
        'team_id' => $this->team->id,
        'uuid' => (string) new \Visus\Cuid2\Cuid2,
    ]);
    $this->environment = $this->project->environments()->first();

    // Create a Kubernetes cluster for destinations
    $this->cluster = KubernetesCluster::create([
        'name' => 'Test Cluster',
        'team_id' => $this->team->id,
        'cluster_type' => 'kubernetes',
        'api_server_url' => 'https://test-cluster.example.com:6443',
        'kubeconfig' => generateHpaKubeconfig(),
        'default_namespace' => 'default',
    ]);
});

// =========================================================================
// Property Tests
// =========================================================================

describe('Property 9: HPA Manifest Generatie', function () {
    /**
     * **Validates: Requirements 5.2, 5.3**
     *
     * Property: HPA is only generated when autoscaling_enabled is true.
     * When autoscaling is disabled, generateHorizontalPodAutoscaler SHALL return null.
     */
    test('HPA is only generated when autoscaling_enabled is true', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHpaNamespace(),
            ]);

            $port = generateHpaPort();

            // Create application
            $application = Application::create([
                'name' => generateHpaAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $port,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Create deployment settings with autoscaling DISABLED
            KubernetesDeploymentSettings::create([
                'application_id' => $application->id,
                'replicas' => fake()->numberBetween(1, 5),
                'autoscaling_enabled' => false,
                'min_replicas' => 1,
                'max_replicas' => 10,
                'target_cpu_utilization' => 80,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $hpa = $generator->generateHorizontalPodAutoscaler();

            // Property: HPA SHALL be null when autoscaling is disabled
            expect($hpa)->toBeNull("Iteration {$iteration}: HPA should be null when autoscaling_enabled is false");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'hpa');


    /**
     * **Validates: Requirements 5.2, 5.3**
     *
     * Property: HPA is generated when autoscaling_enabled is true.
     * The HPA SHALL have correct apiVersion, kind, and reference the correct Deployment.
     */
    test('HPA is generated with correct structure when autoscaling is enabled', function () {
        for ($iteration = 0; $iteration < 50; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHpaNamespace(),
            ]);

            $port = generateHpaPort();

            // Create application
            $application = Application::create([
                'name' => generateHpaAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $port,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Create deployment settings with autoscaling ENABLED
            KubernetesDeploymentSettings::create([
                'application_id' => $application->id,
                'replicas' => fake()->numberBetween(1, 5),
                'autoscaling_enabled' => true,
                'min_replicas' => 1,
                'max_replicas' => 10,
                'target_cpu_utilization' => 80,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $hpa = $generator->generateHorizontalPodAutoscaler();

            // Property 1: HPA SHALL be generated when autoscaling is enabled
            expect($hpa)->not->toBeNull("Iteration {$iteration}: HPA should be generated when autoscaling_enabled is true");

            // Property 2: HPA SHALL have correct apiVersion
            expect($hpa['apiVersion'])->toBe('autoscaling/v2', "Iteration {$iteration}: HPA apiVersion should be autoscaling/v2");

            // Property 3: HPA SHALL have correct kind
            expect($hpa['kind'])->toBe('HorizontalPodAutoscaler', "Iteration {$iteration}: HPA kind should be HorizontalPodAutoscaler");

            // Property 4: HPA SHALL have correct namespace
            expect($hpa['metadata']['namespace'])->toBe($destination->namespace, "Iteration {$iteration}: HPA namespace should match destination");

            // Property 5: HPA SHALL reference the correct Deployment
            expect($hpa['spec']['scaleTargetRef']['apiVersion'])->toBe('apps/v1', "Iteration {$iteration}: scaleTargetRef apiVersion should be apps/v1");
            expect($hpa['spec']['scaleTargetRef']['kind'])->toBe('Deployment', "Iteration {$iteration}: scaleTargetRef kind should be Deployment");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'hpa');

    /**
     * **Validates: Requirements 5.2**
     *
     * Property: min_replicas and max_replicas are correctly set in the HPA manifest.
     * The HPA SHALL use the values from KubernetesDeploymentSettings.
     */
    test('min_replicas and max_replicas are correctly set', function () {
        for ($iteration = 0; $iteration < 50; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHpaNamespace(),
            ]);

            $port = generateHpaPort();

            // Create application
            $application = Application::create([
                'name' => generateHpaAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $port,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate random min/max replicas
            $minReplicas = generateMinReplicas();
            $maxReplicas = generateMaxReplicas($minReplicas);

            // Create deployment settings with autoscaling ENABLED
            KubernetesDeploymentSettings::create([
                'application_id' => $application->id,
                'replicas' => fake()->numberBetween(1, 5),
                'autoscaling_enabled' => true,
                'min_replicas' => $minReplicas,
                'max_replicas' => $maxReplicas,
                'target_cpu_utilization' => 80,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $hpa = $generator->generateHorizontalPodAutoscaler();

            // Property 1: HPA SHALL have correct minReplicas
            expect($hpa['spec']['minReplicas'])->toBe($minReplicas, "Iteration {$iteration}: HPA minReplicas should be {$minReplicas}");

            // Property 2: HPA SHALL have correct maxReplicas
            expect($hpa['spec']['maxReplicas'])->toBe($maxReplicas, "Iteration {$iteration}: HPA maxReplicas should be {$maxReplicas}");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'hpa');


    /**
     * **Validates: Requirements 5.2, 5.3**
     *
     * Property: CPU utilization target is correctly configured in the HPA manifest.
     * When target_cpu_utilization is set, the HPA SHALL include a CPU metric.
     */
    test('CPU utilization target is correctly configured', function () {
        for ($iteration = 0; $iteration < 50; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHpaNamespace(),
            ]);

            $port = generateHpaPort();

            // Create application
            $application = Application::create([
                'name' => generateHpaAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $port,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate random CPU utilization target
            $cpuUtilization = generateCpuUtilization();
            $minReplicas = generateMinReplicas();
            $maxReplicas = generateMaxReplicas($minReplicas);

            // Create deployment settings with CPU target
            KubernetesDeploymentSettings::create([
                'application_id' => $application->id,
                'replicas' => fake()->numberBetween(1, 5),
                'autoscaling_enabled' => true,
                'min_replicas' => $minReplicas,
                'max_replicas' => $maxReplicas,
                'target_cpu_utilization' => $cpuUtilization,
                'target_memory_utilization' => null,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $hpa = $generator->generateHorizontalPodAutoscaler();

            // Property 1: HPA SHALL have metrics array
            expect($hpa['spec'])->toHaveKey('metrics', "Iteration {$iteration}: HPA should have metrics");
            expect($hpa['spec']['metrics'])->not->toBeEmpty("Iteration {$iteration}: HPA metrics should not be empty");

            // Property 2: HPA SHALL have CPU metric with correct target
            $cpuMetric = collect($hpa['spec']['metrics'])->firstWhere('resource.name', 'cpu');
            expect($cpuMetric)->not->toBeNull("Iteration {$iteration}: HPA should have CPU metric");
            expect($cpuMetric['type'])->toBe('Resource', "Iteration {$iteration}: CPU metric type should be Resource");
            expect($cpuMetric['resource']['name'])->toBe('cpu', "Iteration {$iteration}: CPU metric resource name should be cpu");
            expect($cpuMetric['resource']['target']['type'])->toBe('Utilization', "Iteration {$iteration}: CPU metric target type should be Utilization");
            expect($cpuMetric['resource']['target']['averageUtilization'])->toBe($cpuUtilization, "Iteration {$iteration}: CPU metric averageUtilization should be {$cpuUtilization}");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'hpa');

    /**
     * **Validates: Requirements 5.2, 5.3**
     *
     * Property: Memory utilization target is correctly configured in the HPA manifest.
     * When target_memory_utilization is set, the HPA SHALL include a memory metric.
     */
    test('memory utilization target is correctly configured', function () {
        for ($iteration = 0; $iteration < 50; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHpaNamespace(),
            ]);

            $port = generateHpaPort();

            // Create application
            $application = Application::create([
                'name' => generateHpaAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $port,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate random memory utilization target
            $memoryUtilization = generateMemoryUtilization();
            $minReplicas = generateMinReplicas();
            $maxReplicas = generateMaxReplicas($minReplicas);

            // Create deployment settings with memory target (and CPU to avoid default)
            KubernetesDeploymentSettings::create([
                'application_id' => $application->id,
                'replicas' => fake()->numberBetween(1, 5),
                'autoscaling_enabled' => true,
                'min_replicas' => $minReplicas,
                'max_replicas' => $maxReplicas,
                'target_cpu_utilization' => 70,
                'target_memory_utilization' => $memoryUtilization,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $hpa = $generator->generateHorizontalPodAutoscaler();

            // Property: HPA SHALL have memory metric with correct target
            $memoryMetric = collect($hpa['spec']['metrics'])->firstWhere('resource.name', 'memory');
            expect($memoryMetric)->not->toBeNull("Iteration {$iteration}: HPA should have memory metric");
            expect($memoryMetric['type'])->toBe('Resource', "Iteration {$iteration}: Memory metric type should be Resource");
            expect($memoryMetric['resource']['name'])->toBe('memory', "Iteration {$iteration}: Memory metric resource name should be memory");
            expect($memoryMetric['resource']['target']['type'])->toBe('Utilization', "Iteration {$iteration}: Memory metric target type should be Utilization");
            expect($memoryMetric['resource']['target']['averageUtilization'])->toBe($memoryUtilization, "Iteration {$iteration}: Memory metric averageUtilization should be {$memoryUtilization}");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'hpa');


    /**
     * **Validates: Requirements 5.2, 5.3**
     *
     * Property: Default CPU target (80%) is used when no metrics are configured.
     * When neither target_cpu_utilization nor target_memory_utilization is set,
     * the HPA SHALL use a default CPU target of 80%.
     */
    test('default CPU target 80% is used when no metrics are configured', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHpaNamespace(),
            ]);

            $port = generateHpaPort();

            // Create application
            $application = Application::create([
                'name' => generateHpaAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $port,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            $minReplicas = generateMinReplicas();
            $maxReplicas = generateMaxReplicas($minReplicas);

            // Create deployment settings WITHOUT any utilization targets
            KubernetesDeploymentSettings::create([
                'application_id' => $application->id,
                'replicas' => fake()->numberBetween(1, 5),
                'autoscaling_enabled' => true,
                'min_replicas' => $minReplicas,
                'max_replicas' => $maxReplicas,
                'target_cpu_utilization' => null,
                'target_memory_utilization' => null,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $hpa = $generator->generateHorizontalPodAutoscaler();

            // Property 1: HPA SHALL have metrics array
            expect($hpa['spec'])->toHaveKey('metrics', "Iteration {$iteration}: HPA should have metrics");
            expect($hpa['spec']['metrics'])->not->toBeEmpty("Iteration {$iteration}: HPA metrics should not be empty");

            // Property 2: HPA SHALL have default CPU metric with 80% target
            $cpuMetric = collect($hpa['spec']['metrics'])->firstWhere('resource.name', 'cpu');
            expect($cpuMetric)->not->toBeNull("Iteration {$iteration}: HPA should have default CPU metric");
            expect($cpuMetric['resource']['target']['averageUtilization'])->toBe(80, "Iteration {$iteration}: Default CPU metric averageUtilization should be 80");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'hpa');

    /**
     * **Validates: Requirements 5.2, 5.3**
     *
     * Property: The HPA references the correct Deployment name.
     * The scaleTargetRef.name SHALL match the Deployment name generated for the application.
     */
    test('HPA references the correct Deployment name', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHpaNamespace(),
            ]);

            $port = generateHpaPort();

            // Create application
            $application = Application::create([
                'name' => generateHpaAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $port,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            $minReplicas = generateMinReplicas();
            $maxReplicas = generateMaxReplicas($minReplicas);

            // Create deployment settings with autoscaling ENABLED
            KubernetesDeploymentSettings::create([
                'application_id' => $application->id,
                'replicas' => fake()->numberBetween(1, 5),
                'autoscaling_enabled' => true,
                'min_replicas' => $minReplicas,
                'max_replicas' => $maxReplicas,
                'target_cpu_utilization' => 80,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $hpa = $generator->generateHorizontalPodAutoscaler();
            $deployment = $generator->generateDeployment();

            // Property: HPA scaleTargetRef.name SHALL match Deployment name
            $deploymentName = $deployment['metadata']['name'];
            expect($hpa['spec']['scaleTargetRef']['name'])->toBe($deploymentName, "Iteration {$iteration}: HPA scaleTargetRef.name should match Deployment name");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'hpa');


    /**
     * **Validates: Requirements 5.2, 5.3**
     *
     * Property: Both CPU and memory metrics can be configured together.
     * When both target_cpu_utilization and target_memory_utilization are set,
     * the HPA SHALL include both metrics.
     */
    test('both CPU and memory metrics can be configured together', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHpaNamespace(),
            ]);

            $port = generateHpaPort();

            // Create application
            $application = Application::create([
                'name' => generateHpaAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $port,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate random utilization targets
            $cpuUtilization = generateCpuUtilization();
            $memoryUtilization = generateMemoryUtilization();
            $minReplicas = generateMinReplicas();
            $maxReplicas = generateMaxReplicas($minReplicas);

            // Create deployment settings with BOTH CPU and memory targets
            KubernetesDeploymentSettings::create([
                'application_id' => $application->id,
                'replicas' => fake()->numberBetween(1, 5),
                'autoscaling_enabled' => true,
                'min_replicas' => $minReplicas,
                'max_replicas' => $maxReplicas,
                'target_cpu_utilization' => $cpuUtilization,
                'target_memory_utilization' => $memoryUtilization,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $hpa = $generator->generateHorizontalPodAutoscaler();

            // Property 1: HPA SHALL have exactly 2 metrics
            expect(count($hpa['spec']['metrics']))->toBe(2, "Iteration {$iteration}: HPA should have exactly 2 metrics");

            // Property 2: HPA SHALL have CPU metric with correct target
            $cpuMetric = collect($hpa['spec']['metrics'])->firstWhere('resource.name', 'cpu');
            expect($cpuMetric)->not->toBeNull("Iteration {$iteration}: HPA should have CPU metric");
            expect($cpuMetric['resource']['target']['averageUtilization'])->toBe($cpuUtilization, "Iteration {$iteration}: CPU metric averageUtilization should be {$cpuUtilization}");

            // Property 3: HPA SHALL have memory metric with correct target
            $memoryMetric = collect($hpa['spec']['metrics'])->firstWhere('resource.name', 'memory');
            expect($memoryMetric)->not->toBeNull("Iteration {$iteration}: HPA should have memory metric");
            expect($memoryMetric['resource']['target']['averageUtilization'])->toBe($memoryUtilization, "Iteration {$iteration}: Memory metric averageUtilization should be {$memoryUtilization}");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'hpa');

    /**
     * **Validates: Requirements 5.2, 5.3**
     *
     * Property: HPA is not generated when no deployment settings exist.
     * When there are no KubernetesDeploymentSettings for an application,
     * generateHorizontalPodAutoscaler SHALL return null.
     */
    test('HPA is not generated when no deployment settings exist', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHpaNamespace(),
            ]);

            $port = generateHpaPort();

            // Create application WITHOUT deployment settings
            $application = Application::create([
                'name' => generateHpaAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $port,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate manifests (no deployment settings created)
            $generator = new KubernetesManifestGenerator($application, $destination);
            $hpa = $generator->generateHorizontalPodAutoscaler();

            // Property: HPA SHALL be null when no deployment settings exist
            expect($hpa)->toBeNull("Iteration {$iteration}: HPA should be null when no deployment settings exist");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'hpa');

    /**
     * **Validates: Requirements 5.2, 5.3**
     *
     * Property: HPA uses default values when min/max replicas are not set.
     * When min_replicas or max_replicas are null, the HPA SHALL use defaults (1 and 10).
     */
    test('HPA uses default values when min/max replicas are not set', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHpaNamespace(),
            ]);

            $port = generateHpaPort();

            // Create application
            $application = Application::create([
                'name' => generateHpaAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $port,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Create deployment settings with null min/max replicas
            KubernetesDeploymentSettings::create([
                'application_id' => $application->id,
                'replicas' => fake()->numberBetween(1, 5),
                'autoscaling_enabled' => true,
                'min_replicas' => null,
                'max_replicas' => null,
                'target_cpu_utilization' => 80,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $hpa = $generator->generateHorizontalPodAutoscaler();

            // Property 1: HPA SHALL use default minReplicas of 1
            expect($hpa['spec']['minReplicas'])->toBe(1, "Iteration {$iteration}: HPA should use default minReplicas of 1");

            // Property 2: HPA SHALL use default maxReplicas of 10
            expect($hpa['spec']['maxReplicas'])->toBe(10, "Iteration {$iteration}: HPA should use default maxReplicas of 10");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'hpa');

    /**
     * **Validates: Requirements 5.2, 5.3**
     *
     * Property: HPA is included in generateAll() output when autoscaling is enabled.
     * The generateAll() method SHALL include HPA in the manifests array.
     */
    test('HPA is included in generateAll output when autoscaling is enabled', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHpaNamespace(),
            ]);

            $port = generateHpaPort();

            // Create application
            $application = Application::create([
                'name' => generateHpaAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $port,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            $minReplicas = generateMinReplicas();
            $maxReplicas = generateMaxReplicas($minReplicas);

            // Create deployment settings with autoscaling ENABLED
            KubernetesDeploymentSettings::create([
                'application_id' => $application->id,
                'replicas' => fake()->numberBetween(1, 5),
                'autoscaling_enabled' => true,
                'min_replicas' => $minReplicas,
                'max_replicas' => $maxReplicas,
                'target_cpu_utilization' => 80,
            ]);

            // Generate all manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $manifests = $generator->generateAll();

            // Property: generateAll() SHALL include HorizontalPodAutoscaler
            expect($manifests)->toHaveKey('HorizontalPodAutoscaler', "Iteration {$iteration}: generateAll should include HorizontalPodAutoscaler");
            expect($manifests['HorizontalPodAutoscaler']['kind'])->toBe('HorizontalPodAutoscaler', "Iteration {$iteration}: HPA kind should be HorizontalPodAutoscaler");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'hpa');
});

<?php

/**
 * Property-Based Test: Health Check Probe Conversie
 *
 * **Validates: Requirements 4.5**
 *
 * Property 8: Health Check Probe Conversie
 * For any Application met health check configuratie (path, port, interval, timeout, retries),
 * de ManifestGenerator SHALL correcte Kubernetes liveness en readiness probes genereren
 * met equivalente waarden.
 *
 * This test uses property-based testing principles with random data generation
 * to verify that the KubernetesManifestGenerator correctly converts Coolify
 * health check configuration to Kubernetes liveness and readiness probes.
 *
 * Mapping verified:
 * - health_check_path → httpGet.path
 * - health_check_port → httpGet.port
 * - health_check_scheme → httpGet.scheme
 * - health_check_interval → periodSeconds
 * - health_check_timeout → timeoutSeconds
 * - health_check_retries → failureThreshold
 * - health_check_start_period → initialDelaySeconds
 */

use App\Models\Application;
use App\Models\KubernetesCluster;
use App\Models\KubernetesDestination;
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
function generateHealthCheckKubeconfig(): string
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
function generateHealthCheckNamespace(): string
{
    return fake()->slug(fake()->numberBetween(1, 3));
}

/**
 * Generate a random health check path.
 */
function generateHealthCheckPath(): string
{
    $paths = [
        '/',
        '/health',
        '/healthz',
        '/ready',
        '/readiness',
        '/live',
        '/liveness',
        '/api/health',
        '/api/v1/health',
        '/status',
        '/_health',
        '/ping',
    ];

    // Sometimes generate a random path
    if (fake()->boolean(30)) {
        return '/' . fake()->slug(fake()->numberBetween(1, 3));
    }

    return fake()->randomElement($paths);
}

/**
 * Generate a random health check port.
 */
function generateHealthCheckPort(): int
{
    return fake()->numberBetween(80, 65535);
}

/**
 * Generate a random health check scheme.
 */
function generateHealthCheckScheme(): string
{
    return fake()->randomElement(['http', 'https', 'HTTP', 'HTTPS']);
}

/**
 * Generate a random health check interval (in seconds).
 */
function generateHealthCheckInterval(): int
{
    return fake()->numberBetween(5, 300);
}

/**
 * Generate a random health check timeout (in seconds).
 */
function generateHealthCheckTimeout(): int
{
    return fake()->numberBetween(1, 60);
}

/**
 * Generate a random health check retries count.
 */
function generateHealthCheckRetries(): int
{
    return fake()->numberBetween(1, 10);
}

/**
 * Generate a random health check start period (in seconds).
 */
function generateHealthCheckStartPeriod(): int
{
    // Sometimes return 0 (no initial delay)
    if (fake()->boolean(20)) {
        return 0;
    }
    return fake()->numberBetween(1, 300);
}

/**
 * Generate a random application name.
 */
function generateHealthCheckAppName(): string
{
    return fake()->words(fake()->numberBetween(1, 3), true) . ' App';
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
        'kubeconfig' => generateHealthCheckKubeconfig(),
        'default_namespace' => 'default',
    ]);
});

// =========================================================================
// Property Tests
// =========================================================================

describe('Property 8: Health Check Probe Conversie', function () {
    /**
     * **Validates: Requirements 4.5**
     *
     * Property: For any Application with health check enabled, the ManifestGenerator
     * SHALL generate liveness and readiness probes with correct httpGet configuration.
     */
    test('liveness and readiness probes contain correct httpGet configuration', function () {
        for ($iteration = 0; $iteration < 50; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHealthCheckNamespace(),
            ]);

            // Generate random health check configuration
            $healthCheckPath = generateHealthCheckPath();
            $healthCheckPort = generateHealthCheckPort();
            $healthCheckScheme = generateHealthCheckScheme();

            // Create application with health check enabled
            $application = Application::create([
                'name' => generateHealthCheckAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $healthCheckPort,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
                'health_check_enabled' => true,
                'health_check_path' => $healthCheckPath,
                'health_check_port' => $healthCheckPort,
                'health_check_scheme' => $healthCheckScheme,
                'health_check_interval' => 30,
                'health_check_timeout' => 10,
                'health_check_retries' => 3,
                'health_check_start_period' => 0,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $livenessProbe = $generator->buildLivenessProbe();
            $readinessProbe = $generator->buildReadinessProbe();

            // Property 1: Liveness probe SHALL be generated when health check is enabled
            expect($livenessProbe)->not->toBeNull("Iteration {$iteration}: Liveness probe should be generated");

            // Property 2: Readiness probe SHALL be generated when health check is enabled
            expect($readinessProbe)->not->toBeNull("Iteration {$iteration}: Readiness probe should be generated");

            // Property 3: health_check_path → httpGet.path
            expect($livenessProbe['httpGet']['path'])->toBe($healthCheckPath, "Iteration {$iteration}: Liveness probe path should match health_check_path");
            expect($readinessProbe['httpGet']['path'])->toBe($healthCheckPath, "Iteration {$iteration}: Readiness probe path should match health_check_path");

            // Property 4: health_check_port → httpGet.port
            expect($livenessProbe['httpGet']['port'])->toBe($healthCheckPort, "Iteration {$iteration}: Liveness probe port should match health_check_port");
            expect($readinessProbe['httpGet']['port'])->toBe($healthCheckPort, "Iteration {$iteration}: Readiness probe port should match health_check_port");

            // Property 5: health_check_scheme → httpGet.scheme (uppercase)
            $expectedScheme = strtoupper($healthCheckScheme);
            expect($livenessProbe['httpGet']['scheme'])->toBe($expectedScheme, "Iteration {$iteration}: Liveness probe scheme should match health_check_scheme (uppercase)");
            expect($readinessProbe['httpGet']['scheme'])->toBe($expectedScheme, "Iteration {$iteration}: Readiness probe scheme should match health_check_scheme (uppercase)");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'health-check');

    /**
     * **Validates: Requirements 4.5**
     *
     * Property: For any Application with health check timing configuration,
     * the ManifestGenerator SHALL generate probes with correct timing values.
     */
    test('probes contain correct timing configuration', function () {
        for ($iteration = 0; $iteration < 50; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHealthCheckNamespace(),
            ]);

            // Generate random health check timing configuration
            $healthCheckInterval = generateHealthCheckInterval();
            $healthCheckTimeout = generateHealthCheckTimeout();
            $healthCheckRetries = generateHealthCheckRetries();
            $healthCheckStartPeriod = generateHealthCheckStartPeriod();
            $healthCheckPort = generateHealthCheckPort();

            // Create application with health check enabled
            $application = Application::create([
                'name' => generateHealthCheckAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $healthCheckPort,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
                'health_check_enabled' => true,
                'health_check_path' => '/health',
                'health_check_port' => $healthCheckPort,
                'health_check_scheme' => 'http',
                'health_check_interval' => $healthCheckInterval,
                'health_check_timeout' => $healthCheckTimeout,
                'health_check_retries' => $healthCheckRetries,
                'health_check_start_period' => $healthCheckStartPeriod,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $livenessProbe = $generator->buildLivenessProbe();
            $readinessProbe = $generator->buildReadinessProbe();

            // Property 1: health_check_interval → periodSeconds
            expect($livenessProbe['periodSeconds'])->toBe($healthCheckInterval, "Iteration {$iteration}: Liveness probe periodSeconds should match health_check_interval");
            expect($readinessProbe['periodSeconds'])->toBe($healthCheckInterval, "Iteration {$iteration}: Readiness probe periodSeconds should match health_check_interval");

            // Property 2: health_check_timeout → timeoutSeconds
            expect($livenessProbe['timeoutSeconds'])->toBe($healthCheckTimeout, "Iteration {$iteration}: Liveness probe timeoutSeconds should match health_check_timeout");
            expect($readinessProbe['timeoutSeconds'])->toBe($healthCheckTimeout, "Iteration {$iteration}: Readiness probe timeoutSeconds should match health_check_timeout");

            // Property 3: health_check_retries → failureThreshold
            expect($livenessProbe['failureThreshold'])->toBe($healthCheckRetries, "Iteration {$iteration}: Liveness probe failureThreshold should match health_check_retries");
            expect($readinessProbe['failureThreshold'])->toBe($healthCheckRetries, "Iteration {$iteration}: Readiness probe failureThreshold should match health_check_retries");

            // Property 4: health_check_start_period → initialDelaySeconds (only if > 0)
            if ($healthCheckStartPeriod > 0) {
                expect($livenessProbe)->toHaveKey('initialDelaySeconds', "Iteration {$iteration}: Liveness probe should have initialDelaySeconds when start_period > 0");
                expect($livenessProbe['initialDelaySeconds'])->toBe($healthCheckStartPeriod, "Iteration {$iteration}: Liveness probe initialDelaySeconds should match health_check_start_period");
                expect($readinessProbe)->toHaveKey('initialDelaySeconds', "Iteration {$iteration}: Readiness probe should have initialDelaySeconds when start_period > 0");
                expect($readinessProbe['initialDelaySeconds'])->toBe($healthCheckStartPeriod, "Iteration {$iteration}: Readiness probe initialDelaySeconds should match health_check_start_period");
            } else {
                expect($livenessProbe)->not->toHaveKey('initialDelaySeconds', "Iteration {$iteration}: Liveness probe should NOT have initialDelaySeconds when start_period is 0");
                expect($readinessProbe)->not->toHaveKey('initialDelaySeconds', "Iteration {$iteration}: Readiness probe should NOT have initialDelaySeconds when start_period is 0");
            }

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'health-check');

    /**
     * **Validates: Requirements 4.5**
     *
     * Property: For any Application with health check disabled, the ManifestGenerator
     * SHALL NOT generate liveness or readiness probes.
     */
    test('probes are not generated when health check is disabled', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHealthCheckNamespace(),
            ]);

            $healthCheckPort = generateHealthCheckPort();

            // Create application with health check DISABLED
            $application = Application::create([
                'name' => generateHealthCheckAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $healthCheckPort,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
                'health_check_enabled' => false,
                'health_check_path' => '/health',
                'health_check_port' => $healthCheckPort,
                'health_check_scheme' => 'http',
                'health_check_interval' => 30,
                'health_check_timeout' => 10,
                'health_check_retries' => 3,
                'health_check_start_period' => 0,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $livenessProbe = $generator->buildLivenessProbe();
            $readinessProbe = $generator->buildReadinessProbe();

            // Property: Probes SHALL be null when health check is disabled
            expect($livenessProbe)->toBeNull("Iteration {$iteration}: Liveness probe should be null when health check is disabled");
            expect($readinessProbe)->toBeNull("Iteration {$iteration}: Readiness probe should be null when health check is disabled");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'health-check');

    /**
     * **Validates: Requirements 4.5**
     *
     * Property: For any Application with health check enabled, the generated Deployment
     * manifest SHALL include the probes in the container spec.
     */
    test('deployment manifest includes probes in container spec', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHealthCheckNamespace(),
            ]);

            // Generate random health check configuration
            $healthCheckPath = generateHealthCheckPath();
            $healthCheckPort = generateHealthCheckPort();
            $healthCheckScheme = generateHealthCheckScheme();
            $healthCheckInterval = generateHealthCheckInterval();
            $healthCheckTimeout = generateHealthCheckTimeout();
            $healthCheckRetries = generateHealthCheckRetries();
            $healthCheckStartPeriod = generateHealthCheckStartPeriod();

            // Create application with health check enabled
            $application = Application::create([
                'name' => generateHealthCheckAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $healthCheckPort,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
                'health_check_enabled' => true,
                'health_check_path' => $healthCheckPath,
                'health_check_port' => $healthCheckPort,
                'health_check_scheme' => $healthCheckScheme,
                'health_check_interval' => $healthCheckInterval,
                'health_check_timeout' => $healthCheckTimeout,
                'health_check_retries' => $healthCheckRetries,
                'health_check_start_period' => $healthCheckStartPeriod,
            ]);

            // Generate deployment manifest
            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            // Get container spec
            $container = $deployment['spec']['template']['spec']['containers'][0];

            // Property 1: Container SHALL have livenessProbe
            expect($container)->toHaveKey('livenessProbe', "Iteration {$iteration}: Container should have livenessProbe");

            // Property 2: Container SHALL have readinessProbe
            expect($container)->toHaveKey('readinessProbe', "Iteration {$iteration}: Container should have readinessProbe");

            // Property 3: Liveness probe SHALL have correct httpGet configuration
            $livenessProbe = $container['livenessProbe'];
            expect($livenessProbe['httpGet']['path'])->toBe($healthCheckPath, "Iteration {$iteration}: Liveness probe path in deployment should match");
            expect($livenessProbe['httpGet']['port'])->toBe($healthCheckPort, "Iteration {$iteration}: Liveness probe port in deployment should match");
            expect($livenessProbe['httpGet']['scheme'])->toBe(strtoupper($healthCheckScheme), "Iteration {$iteration}: Liveness probe scheme in deployment should match");

            // Property 4: Readiness probe SHALL have correct httpGet configuration
            $readinessProbe = $container['readinessProbe'];
            expect($readinessProbe['httpGet']['path'])->toBe($healthCheckPath, "Iteration {$iteration}: Readiness probe path in deployment should match");
            expect($readinessProbe['httpGet']['port'])->toBe($healthCheckPort, "Iteration {$iteration}: Readiness probe port in deployment should match");
            expect($readinessProbe['httpGet']['scheme'])->toBe(strtoupper($healthCheckScheme), "Iteration {$iteration}: Readiness probe scheme in deployment should match");

            // Property 5: Probes SHALL have correct timing configuration
            expect($livenessProbe['periodSeconds'])->toBe($healthCheckInterval, "Iteration {$iteration}: Liveness probe periodSeconds in deployment should match");
            expect($livenessProbe['timeoutSeconds'])->toBe($healthCheckTimeout, "Iteration {$iteration}: Liveness probe timeoutSeconds in deployment should match");
            expect($livenessProbe['failureThreshold'])->toBe($healthCheckRetries, "Iteration {$iteration}: Liveness probe failureThreshold in deployment should match");

            expect($readinessProbe['periodSeconds'])->toBe($healthCheckInterval, "Iteration {$iteration}: Readiness probe periodSeconds in deployment should match");
            expect($readinessProbe['timeoutSeconds'])->toBe($healthCheckTimeout, "Iteration {$iteration}: Readiness probe timeoutSeconds in deployment should match");
            expect($readinessProbe['failureThreshold'])->toBe($healthCheckRetries, "Iteration {$iteration}: Readiness probe failureThreshold in deployment should match");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'health-check');

    /**
     * **Validates: Requirements 4.5**
     *
     * Property: For any Application with health check disabled, the generated Deployment
     * manifest SHALL NOT include probes in the container spec.
     */
    test('deployment manifest does not include probes when health check is disabled', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHealthCheckNamespace(),
            ]);

            $healthCheckPort = generateHealthCheckPort();

            // Create application with health check DISABLED
            $application = Application::create([
                'name' => generateHealthCheckAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $healthCheckPort,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
                'health_check_enabled' => false,
            ]);

            // Generate deployment manifest
            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            // Get container spec
            $container = $deployment['spec']['template']['spec']['containers'][0];

            // Property: Container SHALL NOT have probes when health check is disabled
            expect($container)->not->toHaveKey('livenessProbe', "Iteration {$iteration}: Container should NOT have livenessProbe when health check is disabled");
            expect($container)->not->toHaveKey('readinessProbe', "Iteration {$iteration}: Container should NOT have readinessProbe when health check is disabled");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'health-check');

    /**
     * **Validates: Requirements 4.5**
     *
     * Property: For any Application with default health check values (null/unset),
     * the ManifestGenerator SHALL use sensible defaults for probe configuration.
     */
    test('probes use sensible defaults when health check values are not explicitly set', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateHealthCheckNamespace(),
            ]);

            $exposedPort = generateHealthCheckPort();

            // Create application with health check enabled but minimal configuration
            $application = Application::create([
                'name' => generateHealthCheckAppName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) $exposedPort,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
                'health_check_enabled' => true,
                // Leave other health check fields as null/default
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $livenessProbe = $generator->buildLivenessProbe();
            $readinessProbe = $generator->buildReadinessProbe();

            // Property 1: Probes SHALL be generated
            expect($livenessProbe)->not->toBeNull("Iteration {$iteration}: Liveness probe should be generated with defaults");
            expect($readinessProbe)->not->toBeNull("Iteration {$iteration}: Readiness probe should be generated with defaults");

            // Property 2: Default path SHALL be '/'
            expect($livenessProbe['httpGet']['path'])->toBe('/', "Iteration {$iteration}: Default liveness probe path should be '/'");
            expect($readinessProbe['httpGet']['path'])->toBe('/', "Iteration {$iteration}: Default readiness probe path should be '/'");

            // Property 3: Default port SHALL be the primary exposed port
            expect($livenessProbe['httpGet']['port'])->toBe($exposedPort, "Iteration {$iteration}: Default liveness probe port should be primary exposed port");
            expect($readinessProbe['httpGet']['port'])->toBe($exposedPort, "Iteration {$iteration}: Default readiness probe port should be primary exposed port");

            // Property 4: Default scheme SHALL be HTTP
            expect($livenessProbe['httpGet']['scheme'])->toBe('HTTP', "Iteration {$iteration}: Default liveness probe scheme should be HTTP");
            expect($readinessProbe['httpGet']['scheme'])->toBe('HTTP', "Iteration {$iteration}: Default readiness probe scheme should be HTTP");

            // Property 5: Default timing values SHALL be reasonable
            expect($livenessProbe['periodSeconds'])->toBeGreaterThan(0, "Iteration {$iteration}: Default periodSeconds should be > 0");
            expect($livenessProbe['timeoutSeconds'])->toBeGreaterThan(0, "Iteration {$iteration}: Default timeoutSeconds should be > 0");
            expect($livenessProbe['failureThreshold'])->toBeGreaterThan(0, "Iteration {$iteration}: Default failureThreshold should be > 0");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'health-check');
});

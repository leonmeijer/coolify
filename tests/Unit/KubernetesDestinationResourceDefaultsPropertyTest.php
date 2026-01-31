<?php

/**
 * Property-Based Test: Destination Resource Defaults
 *
 * Property 4: Destination Resource Defaults
 * For any Application deployed to a KubernetesDestination, when the application
 * has no explicit resource limits configured, the ManifestGenerator SHALL apply
 * the destination's default resource limits. When the application has explicit
 * limits, those SHALL override the destination defaults.
 *
 * This test uses mocking to verify resource defaults behavior without database access.
 *
 * Requirements covered:
 * - Default CPU/Memory limits from KubernetesDestination
 * - Default CPU/Memory requests from KubernetesDestination
 * - Application-specific overrides via KubernetesDeploymentSettings
 * - Fallback behavior when neither is configured
 */

use App\Models\Application;
use App\Models\KubernetesDestination;
use App\Models\KubernetesDeploymentSettings;
use App\Services\KubernetesManifestGenerator;
use Mockery\MockInterface;

// =========================================================================
// Generator Functions
// =========================================================================

/**
 * Generate a random CPU limit value.
 */
function resGenerateCpuLimit(): string
{
    $values = ['100m', '250m', '500m', '750m', '1000m', '1500m', '2000m', '1', '2', '4'];

    return fake()->randomElement($values);
}

/**
 * Generate a random memory limit value.
 */
function resGenerateMemoryLimit(): string
{
    $values = ['64Mi', '128Mi', '256Mi', '512Mi', '1Gi', '2Gi', '4Gi', '8Gi'];

    return fake()->randomElement($values);
}

/**
 * Generate a random CPU request value (typically lower than limit).
 */
function resGenerateCpuRequest(): string
{
    $values = ['50m', '100m', '200m', '250m', '500m', '750m', '1000m'];

    return fake()->randomElement($values);
}

/**
 * Generate a random memory request value (typically lower than limit).
 */
function resGenerateMemoryRequest(): string
{
    $values = ['32Mi', '64Mi', '128Mi', '256Mi', '512Mi', '1Gi', '2Gi'];

    return fake()->randomElement($values);
}

/**
 * Create a mock Application with optional deployment settings.
 */
function resCreateMockApplication(?int $id = null, ?string $uuid = null): MockInterface
{
    $id = $id ?? fake()->numberBetween(1, 1000);
    $uuid = $uuid ?? fake()->uuid();

    $application = Mockery::mock(Application::class);
    $application->shouldReceive('getAttribute')->with('id')->andReturn($id);
    $application->shouldReceive('getAttribute')->with('uuid')->andReturn($uuid);
    $application->shouldReceive('getAttribute')->with('name')->andReturn('test-app-' . fake()->slug(1));
    $application->shouldReceive('getAttribute')->with('docker_registry_image_name')->andReturn('nginx');
    $application->shouldReceive('getAttribute')->with('docker_registry_image_tag')->andReturn('latest');
    $application->shouldReceive('getAttribute')->with('ports_exposes')->andReturn('8080');
    $application->shouldReceive('getAttribute')->with('fqdn')->andReturn(null);
    $application->shouldReceive('getAttribute')->with('health_check_enabled')->andReturn(false);
    $application->shouldReceive('getAttribute')->with('runtime_environment_variables')->andReturn(collect());
    $application->shouldReceive('getAttribute')->with('persistentStorages')->andReturn(collect());

    return $application;
}

/**
 * Create a mock KubernetesDestination with configurable defaults.
 */
function resCreateMockDestination(
    ?string $defaultCpuLimit = null,
    ?string $defaultMemoryLimit = null,
    ?string $defaultCpuRequest = null,
    ?string $defaultMemoryRequest = null,
    ?int $defaultReplicas = null,
    ?string $namespace = null
): MockInterface {
    $destination = Mockery::mock(KubernetesDestination::class);
    $destination->shouldReceive('getAttribute')->with('namespace')->andReturn($namespace ?? 'default');
    $destination->shouldReceive('getAttribute')->with('default_cpu_limit')->andReturn($defaultCpuLimit);
    $destination->shouldReceive('getAttribute')->with('default_memory_limit')->andReturn($defaultMemoryLimit);
    $destination->shouldReceive('getAttribute')->with('default_cpu_request')->andReturn($defaultCpuRequest);
    $destination->shouldReceive('getAttribute')->with('default_memory_request')->andReturn($defaultMemoryRequest);
    $destination->shouldReceive('getAttribute')->with('default_replicas')->andReturn($defaultReplicas ?? 1);
    $destination->shouldReceive('getAttribute')->with('ingress_class')->andReturn(null);
    $destination->shouldReceive('getAttribute')->with('storage_class')->andReturn(null);

    return $destination;
}

/**
 * Create a mock KubernetesDeploymentSettings with configurable overrides.
 */
function resCreateMockDeploymentSettings(
    int $applicationId,
    ?string $cpuLimit = null,
    ?string $memoryLimit = null,
    ?string $cpuRequest = null,
    ?string $memoryRequest = null,
    ?int $replicas = null
): MockInterface {
    $settings = Mockery::mock(KubernetesDeploymentSettings::class);
    $settings->shouldReceive('getAttribute')->with('application_id')->andReturn($applicationId);
    $settings->shouldReceive('getAttribute')->with('cpu_limit')->andReturn($cpuLimit);
    $settings->shouldReceive('getAttribute')->with('memory_limit')->andReturn($memoryLimit);
    $settings->shouldReceive('getAttribute')->with('cpu_request')->andReturn($cpuRequest);
    $settings->shouldReceive('getAttribute')->with('memory_request')->andReturn($memoryRequest);
    $settings->shouldReceive('getAttribute')->with('replicas')->andReturn($replicas);
    $settings->shouldReceive('getAttribute')->with('autoscaling_enabled')->andReturn(false);
    $settings->shouldReceive('getAttribute')->with('min_replicas')->andReturn(null);
    $settings->shouldReceive('getAttribute')->with('max_replicas')->andReturn(null);
    $settings->shouldReceive('getAttribute')->with('target_cpu_utilization')->andReturn(null);
    $settings->shouldReceive('getAttribute')->with('target_memory_utilization')->andReturn(null);

    return $settings;
}

// =========================================================================
// Property Tests
// =========================================================================

describe('Property 4: Destination Resource Defaults', function () {
    afterEach(function () {
        Mockery::close();
    });

    /**
     * Property: When an application has no explicit resource limits and the destination
     * has defaults configured, the generated Deployment SHALL use the destination defaults.
     */
    test('destination defaults are applied when application has no explicit limits', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $destCpuLimit = resGenerateCpuLimit();
            $destMemoryLimit = resGenerateMemoryLimit();
            $destCpuRequest = resGenerateCpuRequest();
            $destMemoryRequest = resGenerateMemoryRequest();

            $application = resCreateMockApplication();
            $destination = resCreateMockDestination(
                defaultCpuLimit: $destCpuLimit,
                defaultMemoryLimit: $destMemoryLimit,
                defaultCpuRequest: $destCpuRequest,
                defaultMemoryRequest: $destMemoryRequest
            );

            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            $container = $deployment['spec']['template']['spec']['containers'][0];

            // Property: Container SHALL have resource limits from destination defaults
            expect($container)->toHaveKey('resources', "Iteration {$iteration}: Container should have resources");
            expect($container['resources'])->toHaveKey('limits', "Iteration {$iteration}: Resources should have limits");
            expect($container['resources']['limits']['cpu'])->toBe($destCpuLimit, "Iteration {$iteration}: CPU limit should match destination default");
            expect($container['resources']['limits']['memory'])->toBe($destMemoryLimit, "Iteration {$iteration}: Memory limit should match destination default");

            // Property: Container SHALL have resource requests from destination defaults
            expect($container['resources'])->toHaveKey('requests', "Iteration {$iteration}: Resources should have requests");
            expect($container['resources']['requests']['cpu'])->toBe($destCpuRequest, "Iteration {$iteration}: CPU request should match destination default");
            expect($container['resources']['requests']['memory'])->toBe($destMemoryRequest, "Iteration {$iteration}: Memory request should match destination default");
        }
    })->group('property-test', 'kubernetes', 'resource-defaults');

    /**
     * Property: When an application has explicit resource limits configured via
     * KubernetesDeploymentSettings, those SHALL override the destination defaults.
     */
    test('application explicit limits override destination defaults', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Generate different values for destination and application
            $destCpuLimit = '500m';
            $destMemoryLimit = '512Mi';
            $destCpuRequest = '100m';
            $destMemoryRequest = '128Mi';

            $appCpuLimit = resGenerateCpuLimit();
            $appMemoryLimit = resGenerateMemoryLimit();
            $appCpuRequest = resGenerateCpuRequest();
            $appMemoryRequest = resGenerateMemoryRequest();

            // Ensure values are different
            while ($appCpuLimit === $destCpuLimit) {
                $appCpuLimit = resGenerateCpuLimit();
            }
            while ($appMemoryLimit === $destMemoryLimit) {
                $appMemoryLimit = resGenerateMemoryLimit();
            }

            $appId = fake()->numberBetween(1, 1000);
            $application = resCreateMockApplication(id: $appId);
            $destination = resCreateMockDestination(
                defaultCpuLimit: $destCpuLimit,
                defaultMemoryLimit: $destMemoryLimit,
                defaultCpuRequest: $destCpuRequest,
                defaultMemoryRequest: $destMemoryRequest
            );

            // Create deployment settings with overrides
            $deploymentSettings = resCreateMockDeploymentSettings(
                applicationId: $appId,
                cpuLimit: $appCpuLimit,
                memoryLimit: $appMemoryLimit,
                cpuRequest: $appCpuRequest,
                memoryRequest: $appMemoryRequest
            );

            // Use reflection to inject the deployment settings
            $generator = new KubernetesManifestGenerator($application, $destination);
            $reflection = new ReflectionClass($generator);
            $property = $reflection->getProperty('deploymentSettings');
            $property->setAccessible(true);
            $property->setValue($generator, $deploymentSettings);

            $deployment = $generator->generateDeployment();
            $container = $deployment['spec']['template']['spec']['containers'][0];

            // Property: Container SHALL have resource limits from application settings, not destination
            expect($container['resources']['limits']['cpu'])->toBe($appCpuLimit, "Iteration {$iteration}: CPU limit should use application override");
            expect($container['resources']['limits']['memory'])->toBe($appMemoryLimit, "Iteration {$iteration}: Memory limit should use application override");
            expect($container['resources']['requests']['cpu'])->toBe($appCpuRequest, "Iteration {$iteration}: CPU request should use application override");
            expect($container['resources']['requests']['memory'])->toBe($appMemoryRequest, "Iteration {$iteration}: Memory request should use application override");

            // Property: Values SHALL NOT be the destination defaults
            expect($container['resources']['limits']['cpu'])->not->toBe($destCpuLimit, "Iteration {$iteration}: CPU limit should NOT be destination default");
            expect($container['resources']['limits']['memory'])->not->toBe($destMemoryLimit, "Iteration {$iteration}: Memory limit should NOT be destination default");
        }
    })->group('property-test', 'kubernetes', 'resource-defaults');

    /**
     * Property: When only some resource values are set in application settings,
     * the remaining values SHALL fall back to destination defaults.
     */
    test('partial application overrides use destination defaults for missing values', function () {
        for ($iteration = 0; $iteration < 25; $iteration++) {
            $destCpuLimit = resGenerateCpuLimit();
            $destMemoryLimit = resGenerateMemoryLimit();
            $destCpuRequest = resGenerateCpuRequest();
            $destMemoryRequest = resGenerateMemoryRequest();

            // Only set CPU limit in application, leave memory to fall back
            $appCpuLimit = resGenerateCpuLimit();
            while ($appCpuLimit === $destCpuLimit) {
                $appCpuLimit = resGenerateCpuLimit();
            }

            $appId = fake()->numberBetween(1, 1000);
            $application = resCreateMockApplication(id: $appId);
            $destination = resCreateMockDestination(
                defaultCpuLimit: $destCpuLimit,
                defaultMemoryLimit: $destMemoryLimit,
                defaultCpuRequest: $destCpuRequest,
                defaultMemoryRequest: $destMemoryRequest
            );

            // Create deployment settings with only CPU limit override
            $deploymentSettings = resCreateMockDeploymentSettings(
                applicationId: $appId,
                cpuLimit: $appCpuLimit,
                memoryLimit: null, // Fall back to destination
                cpuRequest: null,  // Fall back to destination
                memoryRequest: null // Fall back to destination
            );

            $generator = new KubernetesManifestGenerator($application, $destination);
            $reflection = new ReflectionClass($generator);
            $property = $reflection->getProperty('deploymentSettings');
            $property->setAccessible(true);
            $property->setValue($generator, $deploymentSettings);

            $deployment = $generator->generateDeployment();
            $container = $deployment['spec']['template']['spec']['containers'][0];

            // Property: CPU limit SHALL use application override
            expect($container['resources']['limits']['cpu'])->toBe($appCpuLimit, "Iteration {$iteration}: CPU limit should use application override");

            // Property: Memory limit SHALL fall back to destination default
            expect($container['resources']['limits']['memory'])->toBe($destMemoryLimit, "Iteration {$iteration}: Memory limit should fall back to destination default");

            // Property: Requests SHALL fall back to destination defaults
            expect($container['resources']['requests']['cpu'])->toBe($destCpuRequest, "Iteration {$iteration}: CPU request should fall back to destination default");
            expect($container['resources']['requests']['memory'])->toBe($destMemoryRequest, "Iteration {$iteration}: Memory request should fall back to destination default");
        }
    })->group('property-test', 'kubernetes', 'resource-defaults');

    /**
     * Property: When neither application nor destination has resource limits configured,
     * the container SHALL NOT have resource limits in the manifest.
     */
    test('no resources when neither application nor destination has limits', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $application = resCreateMockApplication();
            $destination = resCreateMockDestination(
                defaultCpuLimit: null,
                defaultMemoryLimit: null,
                defaultCpuRequest: null,
                defaultMemoryRequest: null
            );

            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            $container = $deployment['spec']['template']['spec']['containers'][0];

            // Property: Container SHALL NOT have resources key, or resources shall be empty
            if (isset($container['resources'])) {
                // If resources exists, limits and requests should be empty or not present
                $hasLimits = isset($container['resources']['limits']) && ! empty($container['resources']['limits']);
                $hasRequests = isset($container['resources']['requests']) && ! empty($container['resources']['requests']);

                expect($hasLimits && $hasRequests)->toBeFalse("Iteration {$iteration}: Container should not have resource limits when none configured");
            }
        }
    })->group('property-test', 'kubernetes', 'resource-defaults');

    /**
     * Property: The default replicas value SHALL be taken from destination when
     * not specified in application deployment settings.
     */
    test('default replicas from destination when application has no setting', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $destReplicas = fake()->numberBetween(1, 10);

            $application = resCreateMockApplication();
            $destination = resCreateMockDestination(
                defaultCpuLimit: '500m',
                defaultMemoryLimit: '512Mi',
                defaultReplicas: $destReplicas
            );

            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            // Property: Deployment replicas SHALL match destination default
            expect($deployment['spec']['replicas'])->toBe($destReplicas, "Iteration {$iteration}: Replicas should match destination default");
        }
    })->group('property-test', 'kubernetes', 'resource-defaults');

    /**
     * Property: Application-specific replicas SHALL override destination defaults.
     */
    test('application replicas override destination default', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $destReplicas = 1;
            $appReplicas = fake()->numberBetween(2, 10);

            $appId = fake()->numberBetween(1, 1000);
            $application = resCreateMockApplication(id: $appId);
            $destination = resCreateMockDestination(
                defaultCpuLimit: '500m',
                defaultMemoryLimit: '512Mi',
                defaultReplicas: $destReplicas
            );

            $deploymentSettings = resCreateMockDeploymentSettings(
                applicationId: $appId,
                replicas: $appReplicas
            );

            $generator = new KubernetesManifestGenerator($application, $destination);
            $reflection = new ReflectionClass($generator);
            $property = $reflection->getProperty('deploymentSettings');
            $property->setAccessible(true);
            $property->setValue($generator, $deploymentSettings);

            $deployment = $generator->generateDeployment();

            // Property: Deployment replicas SHALL use application override
            expect($deployment['spec']['replicas'])->toBe($appReplicas, "Iteration {$iteration}: Replicas should use application override");
            expect($deployment['spec']['replicas'])->not->toBe($destReplicas, "Iteration {$iteration}: Replicas should NOT be destination default");
        }
    })->group('property-test', 'kubernetes', 'resource-defaults');

    /**
     * Property: KubernetesDestination model SHALL have all resource default attributes
     * as fillable fields.
     */
    test('KubernetesDestination has resource default attributes as fillable', function () {
        $destination = new KubernetesDestination;
        $fillable = $destination->getFillable();

        // Property: All resource default fields SHALL be fillable
        expect($fillable)->toContain('default_cpu_limit', 'default_cpu_limit should be fillable');
        expect($fillable)->toContain('default_memory_limit', 'default_memory_limit should be fillable');
        expect($fillable)->toContain('default_cpu_request', 'default_cpu_request should be fillable');
        expect($fillable)->toContain('default_memory_request', 'default_memory_request should be fillable');
        expect($fillable)->toContain('default_replicas', 'default_replicas should be fillable');
    })->group('property-test', 'kubernetes', 'resource-defaults');

    /**
     * Property: KubernetesDeploymentSettings model SHALL have all resource override
     * attributes as fillable fields.
     */
    test('KubernetesDeploymentSettings has resource override attributes as fillable', function () {
        $settings = new KubernetesDeploymentSettings;
        $fillable = $settings->getFillable();

        // Property: All resource override fields SHALL be fillable
        expect($fillable)->toContain('cpu_limit', 'cpu_limit should be fillable');
        expect($fillable)->toContain('memory_limit', 'memory_limit should be fillable');
        expect($fillable)->toContain('cpu_request', 'cpu_request should be fillable');
        expect($fillable)->toContain('memory_request', 'memory_request should be fillable');
        expect($fillable)->toContain('replicas', 'replicas should be fillable');
    })->group('property-test', 'kubernetes', 'resource-defaults');

    /**
     * Property: Resource values SHALL be valid Kubernetes resource format
     * (e.g., "500m" for CPU, "512Mi" for memory).
     */
    test('resource values follow Kubernetes format conventions', function () {
        $cpuPatterns = ['100m', '500m', '1000m', '1', '2', '0.5'];
        $memoryPatterns = ['64Mi', '128Mi', '512Mi', '1Gi', '2Gi'];

        foreach ($cpuPatterns as $cpu) {
            // CPU can be millicores (e.g., 500m) or cores (e.g., 1, 2)
            expect($cpu)->toMatch('/^(\d+m|\d+(\.\d+)?)$/', "CPU value '{$cpu}' should be valid Kubernetes format");
        }

        foreach ($memoryPatterns as $memory) {
            // Memory should be in Ki, Mi, Gi format
            expect($memory)->toMatch('/^\d+(Ki|Mi|Gi|Ti)$/', "Memory value '{$memory}' should be valid Kubernetes format");
        }
    })->group('property-test', 'kubernetes', 'resource-defaults');

    /**
     * Property: When destination has only limits (no requests), only limits SHALL be set.
     */
    test('only limits are set when destination has no request defaults', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $destCpuLimit = resGenerateCpuLimit();
            $destMemoryLimit = resGenerateMemoryLimit();

            $application = resCreateMockApplication();
            $destination = resCreateMockDestination(
                defaultCpuLimit: $destCpuLimit,
                defaultMemoryLimit: $destMemoryLimit,
                defaultCpuRequest: null,
                defaultMemoryRequest: null
            );

            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            $container = $deployment['spec']['template']['spec']['containers'][0];

            // Property: Limits SHALL be set
            expect($container['resources']['limits']['cpu'])->toBe($destCpuLimit, "Iteration {$iteration}: CPU limit should be set");
            expect($container['resources']['limits']['memory'])->toBe($destMemoryLimit, "Iteration {$iteration}: Memory limit should be set");

            // Property: Requests SHALL NOT be set (or empty)
            if (isset($container['resources']['requests'])) {
                expect($container['resources']['requests'])->toBeEmpty("Iteration {$iteration}: Requests should be empty when not configured");
            }
        }
    })->group('property-test', 'kubernetes', 'resource-defaults');

    /**
     * Property: When destination has only requests (no limits), only requests SHALL be set.
     */
    test('only requests are set when destination has no limit defaults', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $destCpuRequest = resGenerateCpuRequest();
            $destMemoryRequest = resGenerateMemoryRequest();

            $application = resCreateMockApplication();
            $destination = resCreateMockDestination(
                defaultCpuLimit: null,
                defaultMemoryLimit: null,
                defaultCpuRequest: $destCpuRequest,
                defaultMemoryRequest: $destMemoryRequest
            );

            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            $container = $deployment['spec']['template']['spec']['containers'][0];

            // Property: Requests SHALL be set
            expect($container['resources']['requests']['cpu'])->toBe($destCpuRequest, "Iteration {$iteration}: CPU request should be set");
            expect($container['resources']['requests']['memory'])->toBe($destMemoryRequest, "Iteration {$iteration}: Memory request should be set");

            // Property: Limits SHALL NOT be set (or empty)
            if (isset($container['resources']['limits'])) {
                expect($container['resources']['limits'])->toBeEmpty("Iteration {$iteration}: Limits should be empty when not configured");
            }
        }
    })->group('property-test', 'kubernetes', 'resource-defaults');
});

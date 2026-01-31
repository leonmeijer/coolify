<?php

/**
 * Property-Based Test: Image Reference Correctheid
 *
 * Property 11: Image Reference Correctheid
 * For any container image configuration, the KubernetesManifestGenerator SHALL
 * generate a correctly formatted image reference that includes the registry,
 * repository, and tag/digest in the proper format.
 *
 * This test uses mocking to verify image reference generation without database access.
 *
 * Image reference formats tested:
 * - Simple image with tag: nginx:latest
 * - Image with registry and tag: docker.io/library/nginx:1.21
 * - Image with digest: nginx@sha256:abc123...
 * - Private registry: registry.example.com/org/image:tag
 */

use App\Models\Application;
use App\Models\KubernetesDestination;
use App\Services\KubernetesManifestGenerator;
use Mockery\MockInterface;

// =========================================================================
// Generator Functions
// =========================================================================

/**
 * Generate a random simple image name (no registry prefix).
 */
function generateSimpleImage(): string
{
    $images = ['nginx', 'redis', 'postgres', 'mysql', 'node', 'python', 'php', 'golang'];

    return fake()->randomElement($images);
}

/**
 * Generate a random image tag.
 */
function generateImageTag(): string
{
    $tags = ['latest', 'stable', 'alpine', 'slim'];
    $versions = ['1.0', '1.21', '2.0', '3.5.2', '14-alpine', '8.1-fpm'];

    return fake()->randomElement(array_merge($tags, $versions));
}

/**
 * Generate a random SHA256 digest.
 */
function generateImageDigest(): string
{
    return 'sha256:' . fake()->sha256();
}

/**
 * Generate a random registry hostname.
 */
function generateRegistry(): string
{
    $registries = [
        'docker.io',
        'ghcr.io',
        'gcr.io',
        'quay.io',
        'registry.hub.docker.com',
        'registry.example.com',
        'private.registry.local',
        'harbor.internal.company.com',
    ];

    return fake()->randomElement($registries);
}

/**
 * Generate a random organization/namespace.
 */
function generateOrganization(): string
{
    $orgs = ['library', 'coolify', 'myorg', 'example', 'company'];

    return fake()->randomElement($orgs);
}

/**
 * Generate a complete image reference with registry, org, name, and tag.
 */
function generateFullImageReference(): array
{
    $registry = generateRegistry();
    $org = generateOrganization();
    $image = generateSimpleImage();
    $tag = generateImageTag();

    return [
        'registry' => $registry,
        'organization' => $org,
        'image' => $image,
        'tag' => $tag,
        'full' => "{$registry}/{$org}/{$image}:{$tag}",
    ];
}

/**
 * Generate an image reference with digest instead of tag.
 */
function generateImageWithDigest(): array
{
    $registry = generateRegistry();
    $org = generateOrganization();
    $image = generateSimpleImage();
    $digest = generateImageDigest();

    return [
        'registry' => $registry,
        'organization' => $org,
        'image' => $image,
        'digest' => $digest,
        'full' => "{$registry}/{$org}/{$image}@{$digest}",
    ];
}

/**
 * Create a mock Application with specified image configuration.
 */
function createMockApplication(
    ?string $dockerRegistryImageName = null,
    ?string $dockerRegistryImageTag = null,
    ?string $uuid = null
): MockInterface {
    $uuid = $uuid ?? fake()->uuid();

    $application = Mockery::mock(Application::class);
    $application->shouldReceive('getAttribute')->with('uuid')->andReturn($uuid);
    $application->shouldReceive('getAttribute')->with('name')->andReturn('test-app');
    $application->shouldReceive('getAttribute')->with('docker_registry_image_name')->andReturn($dockerRegistryImageName);
    $application->shouldReceive('getAttribute')->with('docker_registry_image_tag')->andReturn($dockerRegistryImageTag);
    $application->shouldReceive('getAttribute')->with('ports_exposes')->andReturn('8080');
    $application->shouldReceive('getAttribute')->with('fqdn')->andReturn(null);
    $application->shouldReceive('getAttribute')->with('health_check_enabled')->andReturn(false);
    $application->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $application->shouldReceive('getAttribute')->with('runtime_environment_variables')->andReturn(collect());
    $application->shouldReceive('getAttribute')->with('persistentStorages')->andReturn(collect());

    return $application;
}

/**
 * Create a mock KubernetesDestination.
 */
function createMockDestination(array $overrides = []): MockInterface
{
    $destination = Mockery::mock(KubernetesDestination::class);
    $destination->shouldReceive('getAttribute')->with('namespace')->andReturn($overrides['namespace'] ?? 'default');
    $destination->shouldReceive('getAttribute')->with('default_cpu_limit')->andReturn($overrides['default_cpu_limit'] ?? '500m');
    $destination->shouldReceive('getAttribute')->with('default_memory_limit')->andReturn($overrides['default_memory_limit'] ?? '512Mi');
    $destination->shouldReceive('getAttribute')->with('default_cpu_request')->andReturn($overrides['default_cpu_request'] ?? '100m');
    $destination->shouldReceive('getAttribute')->with('default_memory_request')->andReturn($overrides['default_memory_request'] ?? '128Mi');
    $destination->shouldReceive('getAttribute')->with('default_replicas')->andReturn($overrides['default_replicas'] ?? 1);
    $destination->shouldReceive('getAttribute')->with('ingress_class')->andReturn($overrides['ingress_class'] ?? null);
    $destination->shouldReceive('getAttribute')->with('storage_class')->andReturn($overrides['storage_class'] ?? null);

    return $destination;
}

// =========================================================================
// Property Tests
// =========================================================================

describe('Property 11: Image Reference Correctheid', function () {
    afterEach(function () {
        Mockery::close();
    });

    /**
     * Property: For any Application with docker_registry_image_name and docker_registry_image_tag,
     * the generated Deployment SHALL contain the correctly formatted image reference.
     */
    test('image reference includes registry, name and tag correctly', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $imageRef = generateFullImageReference();

            $application = createMockApplication(
                dockerRegistryImageName: "{$imageRef['registry']}/{$imageRef['organization']}/{$imageRef['image']}",
                dockerRegistryImageTag: $imageRef['tag']
            );

            $destination = createMockDestination();

            // Use reflection to test the private getContainerImage method
            $generator = new KubernetesManifestGenerator($application, $destination);
            $reflection = new ReflectionClass($generator);
            $method = $reflection->getMethod('getContainerImage');
            $method->setAccessible(true);

            $result = $method->invoke($generator);

            // Property: Image reference SHALL contain the full registry path
            expect($result)->toContain($imageRef['registry'], "Iteration {$iteration}: Image should contain registry");

            // Property: Image reference SHALL contain the organization
            expect($result)->toContain($imageRef['organization'], "Iteration {$iteration}: Image should contain organization");

            // Property: Image reference SHALL contain the image name
            expect($result)->toContain($imageRef['image'], "Iteration {$iteration}: Image should contain image name");

            // Property: Image reference SHALL contain the tag
            expect($result)->toContain($imageRef['tag'], "Iteration {$iteration}: Image should contain tag");

            // Property: Image reference SHALL be correctly formatted with colon separator
            expect($result)->toBe($imageRef['full'], "Iteration {$iteration}: Image reference should be correctly formatted");
        }
    })->group('property-test', 'kubernetes', 'image-reference');

    /**
     * Property: For any Application with only docker_registry_image_name (no tag),
     * the generated image reference SHALL use 'latest' as the default tag.
     */
    test('image reference defaults to latest tag when tag is not specified', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $imageName = generateRegistry() . '/' . generateOrganization() . '/' . generateSimpleImage();

            $application = createMockApplication(
                dockerRegistryImageName: $imageName,
                dockerRegistryImageTag: null
            );

            $destination = createMockDestination();

            $generator = new KubernetesManifestGenerator($application, $destination);
            $reflection = new ReflectionClass($generator);
            $method = $reflection->getMethod('getContainerImage');
            $method->setAccessible(true);

            $result = $method->invoke($generator);

            // Property: Image reference SHALL default to 'latest' tag
            expect($result)->toBe("{$imageName}:latest", "Iteration {$iteration}: Image should default to latest tag");
        }
    })->group('property-test', 'kubernetes', 'image-reference');

    /**
     * Property: For any Application without docker_registry_image_name,
     * the generated image reference SHALL use a fallback format based on UUID.
     */
    test('image reference falls back to UUID-based image when no registry image configured', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $uuid = fake()->uuid();

            $application = createMockApplication(
                dockerRegistryImageName: null,
                dockerRegistryImageTag: null,
                uuid: $uuid
            );

            $destination = createMockDestination();

            $generator = new KubernetesManifestGenerator($application, $destination);
            $reflection = new ReflectionClass($generator);
            $method = $reflection->getMethod('getContainerImage');
            $method->setAccessible(true);

            $result = $method->invoke($generator);

            // Property: Fallback image SHALL contain 'coolify/' prefix
            expect($result)->toStartWith('coolify/', "Iteration {$iteration}: Fallback image should start with coolify/");

            // Property: Fallback image SHALL contain the UUID
            expect($result)->toContain($uuid, "Iteration {$iteration}: Fallback image should contain UUID");

            // Property: Fallback image SHALL have 'latest' tag
            expect($result)->toEndWith(':latest', "Iteration {$iteration}: Fallback image should end with :latest");
        }
    })->group('property-test', 'kubernetes', 'image-reference');

    /**
     * Property: For any valid image reference format, the Deployment manifest
     * SHALL include the image in the container spec.
     */
    test('deployment manifest container spec includes correct image', function () {
        for ($iteration = 0; $iteration < 25; $iteration++) {
            $imageRef = generateFullImageReference();

            $application = createMockApplication(
                dockerRegistryImageName: "{$imageRef['registry']}/{$imageRef['organization']}/{$imageRef['image']}",
                dockerRegistryImageTag: $imageRef['tag']
            );

            $destination = createMockDestination();

            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            // Property: Deployment SHALL have containers array
            expect($deployment['spec']['template']['spec']['containers'])->not->toBeEmpty("Iteration {$iteration}: Deployment should have containers");

            // Property: First container SHALL have the correct image
            $container = $deployment['spec']['template']['spec']['containers'][0];
            expect($container)->toHaveKey('image', "Iteration {$iteration}: Container should have image key");
            expect($container['image'])->toBe($imageRef['full'], "Iteration {$iteration}: Container image should match");
        }
    })->group('property-test', 'kubernetes', 'image-reference');

    /**
     * Property: Image references with various special characters in tags
     * SHALL be handled correctly (alphanumeric, hyphens, dots, underscores).
     */
    test('image reference handles special characters in tags correctly', function () {
        $specialTags = [
            'v1.2.3',
            '1.0.0-alpha',
            '2.0.0-beta.1',
            'latest',
            'sha-abc123',
            'pr-123',
            'feature_branch',
            '14.5-alpine3.18',
            'arm64v8',
        ];

        foreach ($specialTags as $tag) {
            $imageName = 'docker.io/library/nginx';

            $application = createMockApplication(
                dockerRegistryImageName: $imageName,
                dockerRegistryImageTag: $tag
            );

            $destination = createMockDestination();

            $generator = new KubernetesManifestGenerator($application, $destination);
            $reflection = new ReflectionClass($generator);
            $method = $reflection->getMethod('getContainerImage');
            $method->setAccessible(true);

            $result = $method->invoke($generator);

            // Property: Image reference SHALL preserve the exact tag
            expect($result)->toBe("{$imageName}:{$tag}", "Tag '{$tag}' should be preserved in image reference");
        }
    })->group('property-test', 'kubernetes', 'image-reference');

    /**
     * Property: For images from public registries, the isPublicRegistry check
     * SHALL correctly identify them as public.
     */
    test('public registries are correctly identified', function () {
        $publicImages = [
            'docker.io/library/nginx:latest',
            'ghcr.io/owner/repo:v1.0',
            'gcr.io/project/image:tag',
            'quay.io/org/image:latest',
            'nginx:latest', // Docker Hub shorthand
            'library/nginx:latest', // Docker Hub with library
        ];

        foreach ($publicImages as $image) {
            $application = createMockApplication(
                dockerRegistryImageName: $image,
                dockerRegistryImageTag: 'latest'
            );

            $destination = createMockDestination();

            $generator = new KubernetesManifestGenerator($application, $destination);
            $reflection = new ReflectionClass($generator);
            $method = $reflection->getMethod('isPublicRegistry');
            $method->setAccessible(true);

            $result = $method->invoke($generator, $image);

            // Property: Known public registries SHALL be identified as public
            expect($result)->toBeTrue("Image '{$image}' should be identified as public registry");
        }
    })->group('property-test', 'kubernetes', 'image-reference');

    /**
     * Property: For images from private registries, the isPublicRegistry check
     * SHALL correctly identify them as private.
     */
    test('private registries are correctly identified', function () {
        $privateImages = [
            'registry.example.com/org/image:tag',
            'harbor.internal.company.com/project/app:v1',
            'private.registry.local:5000/image:latest',
            'my-registry.io/team/service:1.0',
        ];

        foreach ($privateImages as $image) {
            $application = createMockApplication(
                dockerRegistryImageName: $image,
                dockerRegistryImageTag: 'latest'
            );

            $destination = createMockDestination();

            $generator = new KubernetesManifestGenerator($application, $destination);
            $reflection = new ReflectionClass($generator);
            $method = $reflection->getMethod('isPublicRegistry');
            $method->setAccessible(true);

            $result = $method->invoke($generator, $image);

            // Property: Private registries SHALL be identified as not public
            expect($result)->toBeFalse("Image '{$image}' should be identified as private registry");
        }
    })->group('property-test', 'kubernetes', 'image-reference');

    /**
     * Property: For images from private registries, the Deployment SHALL
     * include imagePullSecrets configuration.
     */
    test('deployment includes imagePullSecrets for private registries', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $privateRegistry = fake()->randomElement([
                'registry.example.com',
                'harbor.internal.company.com',
                'private.registry.local:5000',
            ]);
            $imageName = "{$privateRegistry}/org/app";

            $application = createMockApplication(
                dockerRegistryImageName: $imageName,
                dockerRegistryImageTag: 'latest'
            );

            $destination = createMockDestination();

            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            // Property: Deployment for private registry images SHALL have imagePullSecrets
            expect($deployment['spec']['template']['spec'])->toHaveKey('imagePullSecrets', "Iteration {$iteration}: Deployment should have imagePullSecrets for private registry");
            expect($deployment['spec']['template']['spec']['imagePullSecrets'])->not->toBeEmpty("Iteration {$iteration}: imagePullSecrets should not be empty");
        }
    })->group('property-test', 'kubernetes', 'image-reference');

    /**
     * Property: For images from public registries, the Deployment SHALL NOT
     * include imagePullSecrets configuration.
     */
    test('deployment does not include imagePullSecrets for public registries', function () {
        $publicRegistries = [
            'docker.io/library/nginx',
            'ghcr.io/owner/repo',
            'gcr.io/project/image',
            'quay.io/org/image',
        ];

        foreach ($publicRegistries as $imageName) {
            $application = createMockApplication(
                dockerRegistryImageName: $imageName,
                dockerRegistryImageTag: 'latest'
            );

            $destination = createMockDestination();

            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            // Property: Deployment for public registry images SHALL NOT have imagePullSecrets
            // or should have empty array
            if (isset($deployment['spec']['template']['spec']['imagePullSecrets'])) {
                expect($deployment['spec']['template']['spec']['imagePullSecrets'])->toBeEmpty("Image '{$imageName}' should not have imagePullSecrets");
            }
        }
    })->group('property-test', 'kubernetes', 'image-reference');
});

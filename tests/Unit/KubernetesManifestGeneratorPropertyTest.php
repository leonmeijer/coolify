<?php

/**
 * Property-Based Test: Manifest Generatie Correctheid
 *
 * **Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6**
 *
 * Property 6: Manifest Generatie Correctheid
 * For any Application configuratie met container image, poorten, environment variables,
 * FQDN en volumes, de ManifestGenerator SHALL een complete set valide Kubernetes manifests
 * genereren (Deployment, Service, Ingress, ConfigMap, Secret, PVC) die alle configuratie
 * correct representeren.
 *
 * This test uses property-based testing principles with random data generation
 * to verify that the KubernetesManifestGenerator correctly generates Kubernetes
 * manifests from Application configurations.
 *
 * Requirements covered:
 * - 3.1: Deployment manifest met juiste container specificaties
 * - 3.2: Service manifest met juiste port mappings
 * - 3.3: Ingress manifest voor HTTP/HTTPS routing (when FQDN is set)
 * - 3.4: ConfigMap en/of Secret manifest voor environment variables
 * - 3.5: PersistentVolumeClaim manifests voor persistent volumes
 * - 3.6: Gegenereerde manifests als YAML kunnen exporteren
 */

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\KubernetesCluster;
use App\Models\KubernetesDestination;
use App\Models\KubernetesDeploymentSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\KubernetesManifestGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

// =========================================================================
// Generator Functions
// =========================================================================

/**
 * Generate a valid kubeconfig YAML string.
 */
function generateValidKubeconfig(): string
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
function generateValidNamespace(): string
{
    return fake()->slug(fake()->numberBetween(1, 3));
}

/**
 * Generate a random container image name.
 */
function generateContainerImage(): string
{
    $registries = ['docker.io', 'ghcr.io', 'gcr.io', 'quay.io', ''];
    $registry = fake()->randomElement($registries);
    $org = fake()->slug(1);
    $name = fake()->slug(1);
    $tag = fake()->randomElement(['latest', 'v1.0.0', 'v2.1.3', fake()->slug(1)]);

    if (empty($registry)) {
        return "{$org}/{$name}:{$tag}";
    }
    return "{$registry}/{$org}/{$name}:{$tag}";
}

/**
 * Generate random exposed ports (1-5 ports).
 */
function generateExposedPorts(): string
{
    $portCount = fake()->numberBetween(1, 5);
    $ports = [];
    for ($i = 0; $i < $portCount; $i++) {
        $ports[] = fake()->numberBetween(80, 9999);
    }
    return implode(',', array_unique($ports));
}

/**
 * Generate a random FQDN (fully qualified domain name).
 */
function generateFqdn(): string
{
    $scheme = fake()->randomElement(['http', 'https']);
    $domain = fake()->domainName();
    return "{$scheme}://{$domain}";
}

/**
 * Generate multiple FQDNs (comma-separated).
 */
function generateMultipleFqdns(): string
{
    $count = fake()->numberBetween(1, 3);
    $fqdns = [];
    for ($i = 0; $i < $count; $i++) {
        $scheme = fake()->randomElement(['http', 'https']);
        $domain = fake()->domainName();
        $fqdns[] = "{$scheme}://{$domain}";
    }
    return implode(',', $fqdns);
}

/**
 * Generate a random application name.
 */
function generateApplicationName(): string
{
    return fake()->words(fake()->numberBetween(1, 3), true) . ' App';
}

/**
 * Generate a random non-sensitive environment variable key.
 */
function generateNonSensitiveEnvKey(): string
{
    $prefixes = ['APP', 'NODE', 'LOG', 'DEBUG', 'PORT', 'HOST', 'ENV', 'CONFIG'];
    return fake()->randomElement($prefixes) . '_' . strtoupper(fake()->slug(1));
}

/**
 * Generate a random sensitive environment variable key.
 */
function generateSensitiveEnvKey(): string
{
    $patterns = ['PASSWORD', 'SECRET', 'TOKEN', 'API_KEY', 'PRIVATE_KEY', 'AUTH_TOKEN', 'DB_PASSWORD'];
    return fake()->randomElement($patterns) . '_' . strtoupper(fake()->slug(1));
}

/**
 * Generate a random environment variable value.
 */
function generateEnvValue(): string
{
    return fake()->randomElement([
        fake()->word(),
        fake()->url(),
        fake()->ipv4(),
        (string) fake()->numberBetween(1, 10000),
        fake()->sha256(),
        fake()->uuid(),
    ]);
}

/**
 * Generate a random mount path for persistent volumes.
 */
function generateMountPath(): string
{
    $paths = ['/data', '/var/data', '/app/storage', '/uploads', '/logs', '/cache'];
    return fake()->randomElement($paths) . '/' . fake()->slug(1);
}

/**
 * Generate random resource limits.
 */
function generateCpuLimit(): string
{
    return fake()->randomElement(['100m', '250m', '500m', '1000m', '2000m']);
}

function generateMemoryLimit(): string
{
    return fake()->randomElement(['128Mi', '256Mi', '512Mi', '1Gi', '2Gi']);
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
        'kubeconfig' => generateValidKubeconfig(),
        'default_namespace' => 'default',
    ]);
});

// =========================================================================
// Property Tests
// =========================================================================

describe('Property 6: Manifest Generatie Correctheid', function () {
    /**
     * **Validates: Requirements 3.1**
     *
     * Property: For any Application with container image and ports, the ManifestGenerator
     * SHALL generate a Deployment manifest containing the correct container image and ports.
     */
    test('deployment manifest contains correct container image and ports', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination with random resource limits
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
                'default_cpu_limit' => generateCpuLimit(),
                'default_memory_limit' => generateMemoryLimit(),
                'default_cpu_request' => generateCpuLimit(),
                'default_memory_request' => generateMemoryLimit(),
            ]);

            // Generate random configuration
            $containerImage = generateContainerImage();
            $exposedPorts = generateExposedPorts();
            $portArray = array_map('intval', explode(',', $exposedPorts));

            // Create application with random configuration
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => $exposedPorts,
                'docker_registry_image_name' => $containerImage,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            // Property 1: Deployment SHALL have correct apiVersion and kind
            expect($deployment['apiVersion'])->toBe('apps/v1', "Iteration {$iteration}: Deployment apiVersion should be apps/v1");
            expect($deployment['kind'])->toBe('Deployment', "Iteration {$iteration}: Deployment kind should be Deployment");

            // Property 2: Deployment SHALL have correct namespace
            expect($deployment['metadata']['namespace'])->toBe($destination->namespace, "Iteration {$iteration}: Deployment namespace should match destination");

            // Property 3: Container SHALL have correct image
            $container = $deployment['spec']['template']['spec']['containers'][0];
            expect($container['image'])->toContain($containerImage, "Iteration {$iteration}: Container image should match configured image");

            // Property 4: Container SHALL have all exposed ports
            $containerPorts = array_column($container['ports'], 'containerPort');
            foreach ($portArray as $port) {
                expect($containerPorts)->toContain($port, "Iteration {$iteration}: Container should expose port {$port}");
            }

            // Property 5: Container SHALL have resource limits from destination defaults
            expect($container['resources']['limits']['cpu'])->toBe($destination->default_cpu_limit, "Iteration {$iteration}: CPU limit should match destination default");
            expect($container['resources']['limits']['memory'])->toBe($destination->default_memory_limit, "Iteration {$iteration}: Memory limit should match destination default");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');

    /**
     * **Validates: Requirements 3.2**
     *
     * Property: For any Application with exposed ports, the ManifestGenerator
     * SHALL generate a Service manifest with correct port mappings.
     */
    test('service manifest contains correct port mappings', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
            ]);

            // Generate random ports
            $exposedPorts = generateExposedPorts();
            $portArray = array_map('intval', explode(',', $exposedPorts));

            // Create application
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => $exposedPorts,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $service = $generator->generateService();

            // Property 1: Service SHALL have correct apiVersion and kind
            expect($service['apiVersion'])->toBe('v1', "Iteration {$iteration}: Service apiVersion should be v1");
            expect($service['kind'])->toBe('Service', "Iteration {$iteration}: Service kind should be Service");

            // Property 2: Service SHALL have correct namespace
            expect($service['metadata']['namespace'])->toBe($destination->namespace, "Iteration {$iteration}: Service namespace should match destination");

            // Property 3: Service SHALL have ClusterIP type
            expect($service['spec']['type'])->toBe('ClusterIP', "Iteration {$iteration}: Service type should be ClusterIP");

            // Property 4: Service SHALL have all exposed ports with correct mappings
            $servicePorts = $service['spec']['ports'];
            foreach ($portArray as $port) {
                $found = false;
                foreach ($servicePorts as $servicePort) {
                    if ($servicePort['port'] === $port && $servicePort['targetPort'] === $port) {
                        $found = true;
                        break;
                    }
                }
                expect($found)->toBeTrue("Iteration {$iteration}: Service should have port mapping for {$port}");
            }

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');

    /**
     * **Validates: Requirements 3.3**
     *
     * Property: For any Application with FQDN configured, the ManifestGenerator
     * SHALL generate an Ingress manifest with correct host and path rules.
     */
    test('ingress manifest contains correct host and path rules when FQDN is set', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination with ingress class
            $ingressClass = fake()->randomElement(['nginx', 'traefik', 'haproxy', null]);
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
                'ingress_class' => $ingressClass,
            ]);

            // Generate random FQDN
            $fqdn = generateFqdn();
            $parsedUrl = parse_url($fqdn);
            $hostname = $parsedUrl['host'];
            $isHttps = ($parsedUrl['scheme'] ?? 'https') === 'https';

            // Create application with FQDN
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'fqdn' => $fqdn,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $ingress = $generator->generateIngress();

            // Property 1: Ingress SHALL be generated when FQDN is set
            expect($ingress)->not->toBeNull("Iteration {$iteration}: Ingress should be generated when FQDN is set");

            // Property 2: Ingress SHALL have correct apiVersion and kind
            expect($ingress['apiVersion'])->toBe('networking.k8s.io/v1', "Iteration {$iteration}: Ingress apiVersion should be networking.k8s.io/v1");
            expect($ingress['kind'])->toBe('Ingress', "Iteration {$iteration}: Ingress kind should be Ingress");

            // Property 3: Ingress SHALL have correct namespace
            expect($ingress['metadata']['namespace'])->toBe($destination->namespace, "Iteration {$iteration}: Ingress namespace should match destination");

            // Property 4: Ingress SHALL have correct host in rules
            $rules = $ingress['spec']['rules'];
            expect($rules)->not->toBeEmpty("Iteration {$iteration}: Ingress should have rules");
            expect($rules[0]['host'])->toBe($hostname, "Iteration {$iteration}: Ingress host should match FQDN hostname");

            // Property 5: Ingress SHALL have path rules
            expect($rules[0]['http']['paths'])->not->toBeEmpty("Iteration {$iteration}: Ingress should have path rules");
            expect($rules[0]['http']['paths'][0]['path'])->toBe('/', "Iteration {$iteration}: Ingress should have root path");
            expect($rules[0]['http']['paths'][0]['pathType'])->toBe('Prefix', "Iteration {$iteration}: Ingress pathType should be Prefix");

            // Property 6: Ingress SHALL have TLS configuration for HTTPS
            if ($isHttps) {
                expect($ingress['spec'])->toHaveKey('tls', "Iteration {$iteration}: Ingress should have TLS for HTTPS");
                expect($ingress['spec']['tls'][0]['hosts'])->toContain($hostname, "Iteration {$iteration}: TLS should include hostname");
            }

            // Property 7: Ingress SHALL have ingress class if configured
            if ($ingressClass) {
                expect($ingress['spec']['ingressClassName'])->toBe($ingressClass, "Iteration {$iteration}: Ingress class should match destination");
            }

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');

    /**
     * **Validates: Requirements 3.3**
     *
     * Property: For any Application without FQDN configured, the ManifestGenerator
     * SHALL NOT generate an Ingress manifest.
     */
    test('ingress manifest is not generated when FQDN is not set', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
            ]);

            // Create application WITHOUT FQDN
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'fqdn' => null,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $ingress = $generator->generateIngress();

            // Property: Ingress SHALL be null when FQDN is not set
            expect($ingress)->toBeNull("Iteration {$iteration}: Ingress should be null when FQDN is not set");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');

    /**
     * **Validates: Requirements 3.4**
     *
     * Property: For any Application with non-sensitive environment variables,
     * the ManifestGenerator SHALL generate a ConfigMap containing those variables.
     */
    test('configmap contains non-sensitive environment variables', function () {
        for ($iteration = 0; $iteration < 25; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
            ]);

            // Create application
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Create random non-sensitive environment variables
            $envVarCount = fake()->numberBetween(1, 5);
            $expectedEnvVars = [];
            for ($i = 0; $i < $envVarCount; $i++) {
                $key = generateNonSensitiveEnvKey();
                $value = generateEnvValue();
                $expectedEnvVars[$key] = $value;

                EnvironmentVariable::create([
                    'key' => $key,
                    'value' => $value,
                    'is_runtime' => true,
                    'is_buildtime' => false,
                    'is_preview' => false,
                    'resourceable_type' => Application::class,
                    'resourceable_id' => $application->id,
                ]);
            }

            // Refresh application to load relationships
            $application->refresh();

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $configMap = $generator->generateConfigMap();

            // Property 1: ConfigMap SHALL be generated when non-sensitive env vars exist
            expect($configMap)->not->toBeNull("Iteration {$iteration}: ConfigMap should be generated");

            // Property 2: ConfigMap SHALL have correct apiVersion and kind
            expect($configMap['apiVersion'])->toBe('v1', "Iteration {$iteration}: ConfigMap apiVersion should be v1");
            expect($configMap['kind'])->toBe('ConfigMap', "Iteration {$iteration}: ConfigMap kind should be ConfigMap");

            // Property 3: ConfigMap SHALL have correct namespace
            expect($configMap['metadata']['namespace'])->toBe($destination->namespace, "Iteration {$iteration}: ConfigMap namespace should match destination");

            // Property 4: ConfigMap SHALL contain all non-sensitive env vars
            foreach ($expectedEnvVars as $key => $value) {
                expect($configMap['data'])->toHaveKey($key, "Iteration {$iteration}: ConfigMap should contain key {$key}");
                expect($configMap['data'][$key])->toBe($value, "Iteration {$iteration}: ConfigMap value for {$key} should match");
            }

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');

    /**
     * **Validates: Requirements 3.4**
     *
     * Property: For any Application with sensitive environment variables,
     * the ManifestGenerator SHALL generate a Secret containing those variables (base64 encoded).
     */
    test('secret contains sensitive environment variables base64 encoded', function () {
        for ($iteration = 0; $iteration < 25; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
            ]);

            // Create application
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Create random sensitive environment variables
            $envVarCount = fake()->numberBetween(1, 5);
            $expectedEnvVars = [];
            for ($i = 0; $i < $envVarCount; $i++) {
                $key = generateSensitiveEnvKey();
                $value = generateEnvValue();
                $expectedEnvVars[$key] = $value;

                EnvironmentVariable::create([
                    'key' => $key,
                    'value' => $value,
                    'is_runtime' => true,
                    'is_buildtime' => false,
                    'is_preview' => false,
                    'resourceable_type' => Application::class,
                    'resourceable_id' => $application->id,
                ]);
            }

            // Refresh application to load relationships
            $application->refresh();

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $secret = $generator->generateSecret();

            // Property 1: Secret SHALL be generated when sensitive env vars exist
            expect($secret)->not->toBeNull("Iteration {$iteration}: Secret should be generated");

            // Property 2: Secret SHALL have correct apiVersion and kind
            expect($secret['apiVersion'])->toBe('v1', "Iteration {$iteration}: Secret apiVersion should be v1");
            expect($secret['kind'])->toBe('Secret', "Iteration {$iteration}: Secret kind should be Secret");

            // Property 3: Secret SHALL have correct namespace
            expect($secret['metadata']['namespace'])->toBe($destination->namespace, "Iteration {$iteration}: Secret namespace should match destination");

            // Property 4: Secret SHALL have type Opaque
            expect($secret['type'])->toBe('Opaque', "Iteration {$iteration}: Secret type should be Opaque");

            // Property 5: Secret SHALL contain all sensitive env vars base64 encoded
            foreach ($expectedEnvVars as $key => $value) {
                expect($secret['data'])->toHaveKey($key, "Iteration {$iteration}: Secret should contain key {$key}");
                expect($secret['data'][$key])->toBe(base64_encode($value), "Iteration {$iteration}: Secret value for {$key} should be base64 encoded");
            }

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');

    /**
     * **Validates: Requirements 3.5**
     *
     * Property: For any Application with persistent volumes configured,
     * the ManifestGenerator SHALL generate PersistentVolumeClaim manifests.
     */
    test('pvc manifests are generated for persistent volumes', function () {
        for ($iteration = 0; $iteration < 25; $iteration++) {
            // Create destination with storage class
            $storageClass = fake()->randomElement(['standard', 'fast', 'slow', null]);
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
                'storage_class' => $storageClass,
            ]);

            // Create application
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Create random persistent volumes
            $volumeCount = fake()->numberBetween(1, 3);
            $expectedMountPaths = [];
            for ($i = 0; $i < $volumeCount; $i++) {
                $mountPath = generateMountPath();
                $expectedMountPaths[] = $mountPath;

                LocalPersistentVolume::create([
                    'name' => 'volume-' . fake()->slug(2),
                    'mount_path' => $mountPath,
                    'resource_type' => Application::class,
                    'resource_id' => $application->id,
                ]);
            }

            // Refresh application to load relationships
            $application->refresh();

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $pvcs = $generator->generatePersistentVolumeClaim();

            // Property 1: PVCs SHALL be generated when persistent volumes exist
            expect($pvcs)->not->toBeNull("Iteration {$iteration}: PVCs should be generated");
            expect($pvcs)->toHaveCount($volumeCount, "Iteration {$iteration}: PVC count should match volume count");

            // Property 2: Each PVC SHALL have correct apiVersion and kind
            foreach ($pvcs as $index => $pvc) {
                expect($pvc['apiVersion'])->toBe('v1', "Iteration {$iteration}: PVC {$index} apiVersion should be v1");
                expect($pvc['kind'])->toBe('PersistentVolumeClaim', "Iteration {$iteration}: PVC {$index} kind should be PersistentVolumeClaim");

                // Property 3: PVC SHALL have correct namespace
                expect($pvc['metadata']['namespace'])->toBe($destination->namespace, "Iteration {$iteration}: PVC {$index} namespace should match destination");

                // Property 4: PVC SHALL have ReadWriteOnce access mode
                expect($pvc['spec']['accessModes'])->toContain('ReadWriteOnce', "Iteration {$iteration}: PVC {$index} should have ReadWriteOnce access mode");

                // Property 5: PVC SHALL have storage class if configured
                if ($storageClass) {
                    expect($pvc['spec']['storageClassName'])->toBe($storageClass, "Iteration {$iteration}: PVC {$index} storage class should match destination");
                }
            }

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');

    /**
     * **Validates: Requirements 3.5**
     *
     * Property: For any Application without persistent volumes,
     * the ManifestGenerator SHALL NOT generate PersistentVolumeClaim manifests.
     */
    test('pvc manifests are not generated when no persistent volumes exist', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
            ]);

            // Create application WITHOUT persistent volumes
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $pvcs = $generator->generatePersistentVolumeClaim();

            // Property: PVCs SHALL be null when no persistent volumes exist
            expect($pvcs)->toBeNull("Iteration {$iteration}: PVCs should be null when no persistent volumes exist");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');

    /**
     * **Validates: Requirements 3.6**
     *
     * Property: For any Application configuration, the ManifestGenerator
     * SHALL be able to export all generated manifests as valid YAML.
     */
    test('generated manifests can be exported as valid yaml', function () {
        for ($iteration = 0; $iteration < 25; $iteration++) {
            // Create destination with full configuration
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
                'default_cpu_limit' => generateCpuLimit(),
                'default_memory_limit' => generateMemoryLimit(),
                'ingress_class' => fake()->randomElement(['nginx', 'traefik', null]),
                'storage_class' => fake()->randomElement(['standard', 'fast', null]),
            ]);

            // Create application with full configuration
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => generateExposedPorts(),
                'docker_registry_image_name' => generateContainerImage(),
                'fqdn' => generateFqdn(),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Add environment variables
            EnvironmentVariable::create([
                'key' => generateNonSensitiveEnvKey(),
                'value' => generateEnvValue(),
                'is_runtime' => true,
                'resourceable_type' => Application::class,
                'resourceable_id' => $application->id,
            ]);
            EnvironmentVariable::create([
                'key' => generateSensitiveEnvKey(),
                'value' => generateEnvValue(),
                'is_runtime' => true,
                'resourceable_type' => Application::class,
                'resourceable_id' => $application->id,
            ]);

            // Add persistent volume
            LocalPersistentVolume::create([
                'name' => 'volume-' . fake()->slug(2),
                'mount_path' => generateMountPath(),
                'resource_type' => Application::class,
                'resource_id' => $application->id,
            ]);

            // Refresh application
            $application->refresh();

            // Generate manifests and export to YAML
            $generator = new KubernetesManifestGenerator($application, $destination);
            $yaml = $generator->toYaml();

            // Property 1: YAML output SHALL not be empty
            expect($yaml)->not->toBeEmpty("Iteration {$iteration}: YAML output should not be empty");

            // Property 2: YAML output SHALL be parseable
            $parsed = null;
            $parseError = null;
            try {
                // Split by document separator and parse each document
                $documents = preg_split('/^---$/m', $yaml);
                foreach ($documents as $doc) {
                    $doc = trim($doc);
                    if (!empty($doc)) {
                        $parsed = Yaml::parse($doc);
                        expect($parsed)->toBeArray("Iteration {$iteration}: Each YAML document should parse to array");
                    }
                }
            } catch (\Exception $e) {
                $parseError = $e->getMessage();
            }
            expect($parseError)->toBeNull("Iteration {$iteration}: YAML should be parseable without errors: {$parseError}");

            // Property 3: YAML SHALL contain expected manifest types
            expect($yaml)->toContain('kind: Deployment', "Iteration {$iteration}: YAML should contain Deployment");
            expect($yaml)->toContain('kind: Service', "Iteration {$iteration}: YAML should contain Service");
            expect($yaml)->toContain('kind: Ingress', "Iteration {$iteration}: YAML should contain Ingress");
            expect($yaml)->toContain('kind: ConfigMap', "Iteration {$iteration}: YAML should contain ConfigMap");
            expect($yaml)->toContain('kind: Secret', "Iteration {$iteration}: YAML should contain Secret");
            expect($yaml)->toContain('kind: PersistentVolumeClaim', "Iteration {$iteration}: YAML should contain PVC");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');

    /**
     * **Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6**
     *
     * Property: For any Application configuration, the generateAll() method
     * SHALL generate a complete set of all relevant Kubernetes manifests.
     */
    test('generateAll produces complete set of manifests', function () {
        for ($iteration = 0; $iteration < 25; $iteration++) {
            // Create destination with full configuration
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
                'default_cpu_limit' => generateCpuLimit(),
                'default_memory_limit' => generateMemoryLimit(),
            ]);

            // Create application with full configuration
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => generateExposedPorts(),
                'docker_registry_image_name' => generateContainerImage(),
                'fqdn' => generateFqdn(),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Add environment variables
            EnvironmentVariable::create([
                'key' => generateNonSensitiveEnvKey(),
                'value' => generateEnvValue(),
                'is_runtime' => true,
                'resourceable_type' => Application::class,
                'resourceable_id' => $application->id,
            ]);
            EnvironmentVariable::create([
                'key' => generateSensitiveEnvKey(),
                'value' => generateEnvValue(),
                'is_runtime' => true,
                'resourceable_type' => Application::class,
                'resourceable_id' => $application->id,
            ]);

            // Add persistent volume
            LocalPersistentVolume::create([
                'name' => 'volume-' . fake()->slug(2),
                'mount_path' => generateMountPath(),
                'resource_type' => Application::class,
                'resource_id' => $application->id,
            ]);

            // Refresh application
            $application->refresh();

            // Generate all manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $manifests = $generator->generateAll();

            // Property 1: Manifests SHALL contain Deployment
            expect($manifests)->toHaveKey('Deployment', "Iteration {$iteration}: Manifests should contain Deployment");
            expect($manifests['Deployment']['kind'])->toBe('Deployment');

            // Property 2: Manifests SHALL contain Service
            expect($manifests)->toHaveKey('Service', "Iteration {$iteration}: Manifests should contain Service");
            expect($manifests['Service']['kind'])->toBe('Service');

            // Property 3: Manifests SHALL contain Ingress (when FQDN is set)
            expect($manifests)->toHaveKey('Ingress', "Iteration {$iteration}: Manifests should contain Ingress");
            expect($manifests['Ingress']['kind'])->toBe('Ingress');

            // Property 4: Manifests SHALL contain ConfigMap (when non-sensitive env vars exist)
            expect($manifests)->toHaveKey('ConfigMap', "Iteration {$iteration}: Manifests should contain ConfigMap");
            expect($manifests['ConfigMap']['kind'])->toBe('ConfigMap');

            // Property 5: Manifests SHALL contain Secret (when sensitive env vars exist)
            expect($manifests)->toHaveKey('Secret', "Iteration {$iteration}: Manifests should contain Secret");
            expect($manifests['Secret']['kind'])->toBe('Secret');

            // Property 6: Manifests SHALL contain PersistentVolumeClaim (when volumes exist)
            expect($manifests)->toHaveKey('PersistentVolumeClaim', "Iteration {$iteration}: Manifests should contain PVC");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');

    /**
     * **Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6**
     *
     * Property: For any Application configuration with multiple FQDNs,
     * the ManifestGenerator SHALL generate Ingress rules for all hosts.
     */
    test('ingress manifest handles multiple fqdns correctly', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
            ]);

            // Generate multiple FQDNs
            $fqdns = generateMultipleFqdns();
            $fqdnArray = array_map('trim', explode(',', $fqdns));
            $expectedHosts = [];
            foreach ($fqdnArray as $fqdn) {
                $parsed = parse_url($fqdn);
                if (isset($parsed['host'])) {
                    $expectedHosts[] = $parsed['host'];
                }
            }

            // Create application with multiple FQDNs
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'fqdn' => $fqdns,
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $ingress = $generator->generateIngress();

            // Property 1: Ingress SHALL be generated
            expect($ingress)->not->toBeNull("Iteration {$iteration}: Ingress should be generated");

            // Property 2: Ingress SHALL have rules for all hosts
            $rules = $ingress['spec']['rules'];
            $actualHosts = array_column($rules, 'host');
            foreach ($expectedHosts as $host) {
                expect($actualHosts)->toContain($host, "Iteration {$iteration}: Ingress should have rule for host {$host}");
            }

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');

    /**
     * **Validates: Requirements 3.1**
     *
     * Property: For any Application configuration, the Deployment manifest
     * SHALL have correct Kubernetes labels for identification and management.
     */
    test('deployment manifest has correct kubernetes labels', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespace(),
            ]);

            // Create application
            $application = Application::create([
                'name' => generateApplicationName(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $deployment = $generator->generateDeployment();

            // Property 1: Deployment SHALL have app.kubernetes.io/name label
            expect($deployment['metadata']['labels'])->toHaveKey('app.kubernetes.io/name', "Iteration {$iteration}: Deployment should have app.kubernetes.io/name label");

            // Property 2: Deployment SHALL have app.kubernetes.io/instance label with UUID
            expect($deployment['metadata']['labels'])->toHaveKey('app.kubernetes.io/instance', "Iteration {$iteration}: Deployment should have app.kubernetes.io/instance label");
            expect($deployment['metadata']['labels']['app.kubernetes.io/instance'])->toBe($application->uuid, "Iteration {$iteration}: app.kubernetes.io/instance should be application UUID");

            // Property 3: Deployment SHALL have app.kubernetes.io/managed-by label
            expect($deployment['metadata']['labels'])->toHaveKey('app.kubernetes.io/managed-by', "Iteration {$iteration}: Deployment should have app.kubernetes.io/managed-by label");
            expect($deployment['metadata']['labels']['app.kubernetes.io/managed-by'])->toBe('coolify', "Iteration {$iteration}: app.kubernetes.io/managed-by should be coolify");

            // Property 4: Deployment SHALL have coolify.io/resource-uuid label
            expect($deployment['metadata']['labels'])->toHaveKey('coolify.io/resource-uuid', "Iteration {$iteration}: Deployment should have coolify.io/resource-uuid label");
            expect($deployment['metadata']['labels']['coolify.io/resource-uuid'])->toBe($application->uuid, "Iteration {$iteration}: coolify.io/resource-uuid should be application UUID");

            // Property 5: Pod template SHALL have matching labels for selector
            $podLabels = $deployment['spec']['template']['metadata']['labels'];
            $selectorLabels = $deployment['spec']['selector']['matchLabels'];
            foreach ($selectorLabels as $key => $value) {
                expect($podLabels)->toHaveKey($key, "Iteration {$iteration}: Pod template should have selector label {$key}");
                expect($podLabels[$key])->toBe($value, "Iteration {$iteration}: Pod template label {$key} should match selector");
            }

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'manifest-generator');
});

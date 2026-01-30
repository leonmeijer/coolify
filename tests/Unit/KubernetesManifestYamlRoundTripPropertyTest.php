<?php

/**
 * Property-Based Test: Manifest YAML Round-Trip
 *
 * **Validates: Requirements 3.7**
 *
 * Property 7: Manifest YAML Round-Trip
 * For any gegenereerd Kubernetes manifest, het parsen naar YAML, formatteren,
 * en opnieuw parsen SHALL een semantisch equivalent manifest produceren.
 *
 * This test uses property-based testing principles with random data generation
 * to verify that the KubernetesManifestGenerator produces manifests that survive
 * YAML serialization/deserialization round-trips without data loss or corruption.
 *
 * Requirements covered:
 * - 3.7: FOR ALL gegenereerde manifests, parsing dan formatting dan parsing
 *        SHALL een equivalent manifest produceren (round-trip property)
 */

use App\Models\Application;
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
function generateValidKubeconfigForRoundTrip(): string
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
function generateValidNamespaceForRoundTrip(): string
{
    return fake()->slug(fake()->numberBetween(1, 3));
}

/**
 * Generate a random container image name.
 */
function generateContainerImageForRoundTrip(): string
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
function generateExposedPortsForRoundTrip(): string
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
function generateFqdnForRoundTrip(): string
{
    $scheme = fake()->randomElement(['http', 'https']);
    $domain = fake()->domainName();
    return "{$scheme}://{$domain}";
}

/**
 * Generate a random application name.
 */
function generateApplicationNameForRoundTrip(): string
{
    return fake()->words(fake()->numberBetween(1, 3), true) . ' App';
}


/**
 * Generate a random non-sensitive environment variable key.
 */
function generateNonSensitiveEnvKeyForRoundTrip(): string
{
    $prefixes = ['APP', 'NODE', 'LOG', 'DEBUG', 'PORT', 'HOST', 'ENV', 'CONFIG'];
    return fake()->randomElement($prefixes) . '_' . strtoupper(fake()->slug(1));
}

/**
 * Generate a random sensitive environment variable key.
 */
function generateSensitiveEnvKeyForRoundTrip(): string
{
    $patterns = ['PASSWORD', 'SECRET', 'TOKEN', 'API_KEY', 'PRIVATE_KEY'];
    return fake()->randomElement($patterns) . '_' . strtoupper(fake()->slug(1));
}

/**
 * Generate a random environment variable value.
 */
function generateEnvValueForRoundTrip(): string
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
function generateMountPathForRoundTrip(): string
{
    $paths = ['/data', '/var/data', '/app/storage', '/uploads', '/logs', '/cache'];
    return fake()->randomElement($paths) . '/' . fake()->slug(1);
}

/**
 * Generate random resource limits.
 */
function generateCpuLimitForRoundTrip(): string
{
    return fake()->randomElement(['100m', '250m', '500m', '1000m', '2000m']);
}

function generateMemoryLimitForRoundTrip(): string
{
    return fake()->randomElement(['128Mi', '256Mi', '512Mi', '1Gi', '2Gi']);
}


// =========================================================================
// Helper Functions for Round-Trip Testing
// =========================================================================

/**
 * Recursively sort array keys for consistent comparison.
 * This ensures that key ordering differences don't cause false negatives.
 */
function sortArrayKeysRecursively(array $array): array
{
    ksort($array);
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $array[$key] = sortArrayKeysRecursively($value);
        }
    }
    return $array;
}

/**
 * Compare two manifests for semantic equivalence.
 * Returns true if the manifests are semantically equivalent.
 */
function areManifestsSemanticallyEquivalent(array $original, array $roundTripped): bool
{
    // Sort keys recursively for consistent comparison
    $sortedOriginal = sortArrayKeysRecursively($original);
    $sortedRoundTripped = sortArrayKeysRecursively($roundTripped);

    return $sortedOriginal === $sortedRoundTripped;
}

/**
 * Get detailed diff between two arrays for debugging.
 */
function getArrayDiff(array $original, array $roundTripped, string $path = ''): array
{
    $diffs = [];

    $allKeys = array_unique(array_merge(array_keys($original), array_keys($roundTripped)));

    foreach ($allKeys as $key) {
        $currentPath = $path ? "{$path}.{$key}" : $key;

        if (!array_key_exists($key, $original)) {
            $diffs[] = "Added key: {$currentPath}";
        } elseif (!array_key_exists($key, $roundTripped)) {
            $diffs[] = "Missing key: {$currentPath}";
        } elseif (is_array($original[$key]) && is_array($roundTripped[$key])) {
            $diffs = array_merge($diffs, getArrayDiff($original[$key], $roundTripped[$key], $currentPath));
        } elseif ($original[$key] !== $roundTripped[$key]) {
            $diffs[] = "Value changed at {$currentPath}: " . json_encode($original[$key]) . " -> " . json_encode($roundTripped[$key]);
        }
    }

    return $diffs;
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
        'kubeconfig' => generateValidKubeconfigForRoundTrip(),
        'default_namespace' => 'default',
    ]);
});


// =========================================================================
// Property Tests
// =========================================================================

describe('Property 7: Manifest YAML Round-Trip', function () {
    /**
     * **Validates: Requirements 3.7**
     *
     * Property: For any generated Deployment manifest, parsing to YAML, formatting,
     * and re-parsing SHALL produce a semantically equivalent manifest.
     */
    test('deployment manifest survives yaml round-trip', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination with random configuration
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespaceForRoundTrip(),
                'default_cpu_limit' => generateCpuLimitForRoundTrip(),
                'default_memory_limit' => generateMemoryLimitForRoundTrip(),
                'default_cpu_request' => generateCpuLimitForRoundTrip(),
                'default_memory_request' => generateMemoryLimitForRoundTrip(),
            ]);

            // Create application with random configuration
            $application = Application::create([
                'name' => generateApplicationNameForRoundTrip(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => generateExposedPortsForRoundTrip(),
                'docker_registry_image_name' => generateContainerImageForRoundTrip(),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate Deployment manifest
            $generator = new KubernetesManifestGenerator($application, $destination);
            $originalDeployment = $generator->generateDeployment();

            // Round-trip: Array -> YAML -> Array
            $yaml = Yaml::dump($originalDeployment, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $roundTrippedDeployment = Yaml::parse($yaml);

            // Property: Round-tripped manifest SHALL be semantically equivalent
            $isEquivalent = areManifestsSemanticallyEquivalent($originalDeployment, $roundTrippedDeployment);

            if (!$isEquivalent) {
                $diffs = getArrayDiff($originalDeployment, $roundTrippedDeployment);
                $diffMessage = implode("\n", $diffs);
                expect($isEquivalent)->toBeTrue("Iteration {$iteration}: Deployment manifest should survive YAML round-trip.\nDifferences:\n{$diffMessage}");
            }

            expect($isEquivalent)->toBeTrue("Iteration {$iteration}: Deployment manifest should survive YAML round-trip");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'yaml-round-trip');


    /**
     * **Validates: Requirements 3.7**
     *
     * Property: For any generated Service manifest, parsing to YAML, formatting,
     * and re-parsing SHALL produce a semantically equivalent manifest.
     */
    test('service manifest survives yaml round-trip', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespaceForRoundTrip(),
            ]);

            // Create application with random ports
            $application = Application::create([
                'name' => generateApplicationNameForRoundTrip(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => generateExposedPortsForRoundTrip(),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate Service manifest
            $generator = new KubernetesManifestGenerator($application, $destination);
            $originalService = $generator->generateService();

            // Round-trip: Array -> YAML -> Array
            $yaml = Yaml::dump($originalService, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $roundTrippedService = Yaml::parse($yaml);

            // Property: Round-tripped manifest SHALL be semantically equivalent
            $isEquivalent = areManifestsSemanticallyEquivalent($originalService, $roundTrippedService);

            if (!$isEquivalent) {
                $diffs = getArrayDiff($originalService, $roundTrippedService);
                $diffMessage = implode("\n", $diffs);
                expect($isEquivalent)->toBeTrue("Iteration {$iteration}: Service manifest should survive YAML round-trip.\nDifferences:\n{$diffMessage}");
            }

            expect($isEquivalent)->toBeTrue("Iteration {$iteration}: Service manifest should survive YAML round-trip");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'yaml-round-trip');


    /**
     * **Validates: Requirements 3.7**
     *
     * Property: For any generated Ingress manifest, parsing to YAML, formatting,
     * and re-parsing SHALL produce a semantically equivalent manifest.
     */
    test('ingress manifest survives yaml round-trip', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination with ingress class
            $ingressClass = fake()->randomElement(['nginx', 'traefik', 'haproxy', null]);
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespaceForRoundTrip(),
                'ingress_class' => $ingressClass,
            ]);

            // Create application with FQDN
            $application = Application::create([
                'name' => generateApplicationNameForRoundTrip(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'fqdn' => generateFqdnForRoundTrip(),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Generate Ingress manifest
            $generator = new KubernetesManifestGenerator($application, $destination);
            $originalIngress = $generator->generateIngress();

            // Skip if no ingress generated
            if ($originalIngress === null) {
                $application->forceDelete();
                $destination->delete();
                continue;
            }

            // Round-trip: Array -> YAML -> Array
            $yaml = Yaml::dump($originalIngress, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $roundTrippedIngress = Yaml::parse($yaml);

            // Property: Round-tripped manifest SHALL be semantically equivalent
            $isEquivalent = areManifestsSemanticallyEquivalent($originalIngress, $roundTrippedIngress);

            if (!$isEquivalent) {
                $diffs = getArrayDiff($originalIngress, $roundTrippedIngress);
                $diffMessage = implode("\n", $diffs);
                expect($isEquivalent)->toBeTrue("Iteration {$iteration}: Ingress manifest should survive YAML round-trip.\nDifferences:\n{$diffMessage}");
            }

            expect($isEquivalent)->toBeTrue("Iteration {$iteration}: Ingress manifest should survive YAML round-trip");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'yaml-round-trip');


    /**
     * **Validates: Requirements 3.7**
     *
     * Property: For any generated ConfigMap manifest, parsing to YAML, formatting,
     * and re-parsing SHALL produce a semantically equivalent manifest.
     */
    test('configmap manifest survives yaml round-trip', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespaceForRoundTrip(),
            ]);

            // Create application
            $application = Application::create([
                'name' => generateApplicationNameForRoundTrip(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Create non-sensitive environment variables
            $envVarCount = fake()->numberBetween(1, 5);
            for ($i = 0; $i < $envVarCount; $i++) {
                EnvironmentVariable::create([
                    'key' => generateNonSensitiveEnvKeyForRoundTrip(),
                    'value' => generateEnvValueForRoundTrip(),
                    'is_runtime' => true,
                    'is_buildtime' => false,
                    'is_preview' => false,
                    'resourceable_type' => Application::class,
                    'resourceable_id' => $application->id,
                ]);
            }

            $application->refresh();

            // Generate ConfigMap manifest
            $generator = new KubernetesManifestGenerator($application, $destination);
            $originalConfigMap = $generator->generateConfigMap();

            // Skip if no ConfigMap generated
            if ($originalConfigMap === null) {
                $application->forceDelete();
                $destination->delete();
                continue;
            }

            // Round-trip: Array -> YAML -> Array
            $yaml = Yaml::dump($originalConfigMap, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $roundTrippedConfigMap = Yaml::parse($yaml);

            // Property: Round-tripped manifest SHALL be semantically equivalent
            $isEquivalent = areManifestsSemanticallyEquivalent($originalConfigMap, $roundTrippedConfigMap);

            if (!$isEquivalent) {
                $diffs = getArrayDiff($originalConfigMap, $roundTrippedConfigMap);
                $diffMessage = implode("\n", $diffs);
                expect($isEquivalent)->toBeTrue("Iteration {$iteration}: ConfigMap manifest should survive YAML round-trip.\nDifferences:\n{$diffMessage}");
            }

            expect($isEquivalent)->toBeTrue("Iteration {$iteration}: ConfigMap manifest should survive YAML round-trip");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'yaml-round-trip');


    /**
     * **Validates: Requirements 3.7**
     *
     * Property: For any generated Secret manifest, parsing to YAML, formatting,
     * and re-parsing SHALL produce a semantically equivalent manifest.
     */
    test('secret manifest survives yaml round-trip', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespaceForRoundTrip(),
            ]);

            // Create application
            $application = Application::create([
                'name' => generateApplicationNameForRoundTrip(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Create sensitive environment variables
            $envVarCount = fake()->numberBetween(1, 5);
            for ($i = 0; $i < $envVarCount; $i++) {
                EnvironmentVariable::create([
                    'key' => generateSensitiveEnvKeyForRoundTrip(),
                    'value' => generateEnvValueForRoundTrip(),
                    'is_runtime' => true,
                    'is_buildtime' => false,
                    'is_preview' => false,
                    'resourceable_type' => Application::class,
                    'resourceable_id' => $application->id,
                ]);
            }

            $application->refresh();

            // Generate Secret manifest
            $generator = new KubernetesManifestGenerator($application, $destination);
            $originalSecret = $generator->generateSecret();

            // Skip if no Secret generated
            if ($originalSecret === null) {
                $application->forceDelete();
                $destination->delete();
                continue;
            }

            // Round-trip: Array -> YAML -> Array
            $yaml = Yaml::dump($originalSecret, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $roundTrippedSecret = Yaml::parse($yaml);

            // Property: Round-tripped manifest SHALL be semantically equivalent
            $isEquivalent = areManifestsSemanticallyEquivalent($originalSecret, $roundTrippedSecret);

            if (!$isEquivalent) {
                $diffs = getArrayDiff($originalSecret, $roundTrippedSecret);
                $diffMessage = implode("\n", $diffs);
                expect($isEquivalent)->toBeTrue("Iteration {$iteration}: Secret manifest should survive YAML round-trip.\nDifferences:\n{$diffMessage}");
            }

            expect($isEquivalent)->toBeTrue("Iteration {$iteration}: Secret manifest should survive YAML round-trip");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'yaml-round-trip');


    /**
     * **Validates: Requirements 3.7**
     *
     * Property: For any generated PersistentVolumeClaim manifests, parsing to YAML,
     * formatting, and re-parsing SHALL produce semantically equivalent manifests.
     */
    test('pvc manifests survive yaml round-trip', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination with storage class
            $storageClass = fake()->randomElement(['standard', 'fast', 'slow', null]);
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespaceForRoundTrip(),
                'storage_class' => $storageClass,
            ]);

            // Create application
            $application = Application::create([
                'name' => generateApplicationNameForRoundTrip(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Create persistent volumes
            $volumeCount = fake()->numberBetween(1, 3);
            for ($i = 0; $i < $volumeCount; $i++) {
                LocalPersistentVolume::create([
                    'name' => 'volume-' . fake()->slug(2),
                    'mount_path' => generateMountPathForRoundTrip(),
                    'resource_type' => Application::class,
                    'resource_id' => $application->id,
                ]);
            }

            $application->refresh();

            // Generate PVC manifests
            $generator = new KubernetesManifestGenerator($application, $destination);
            $originalPvcs = $generator->generatePersistentVolumeClaim();

            // Skip if no PVCs generated
            if ($originalPvcs === null) {
                $application->forceDelete();
                $destination->delete();
                continue;
            }

            // Test each PVC individually
            foreach ($originalPvcs as $index => $originalPvc) {
                // Round-trip: Array -> YAML -> Array
                $yaml = Yaml::dump($originalPvc, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
                $roundTrippedPvc = Yaml::parse($yaml);

                // Property: Round-tripped manifest SHALL be semantically equivalent
                $isEquivalent = areManifestsSemanticallyEquivalent($originalPvc, $roundTrippedPvc);

                if (!$isEquivalent) {
                    $diffs = getArrayDiff($originalPvc, $roundTrippedPvc);
                    $diffMessage = implode("\n", $diffs);
                    expect($isEquivalent)->toBeTrue("Iteration {$iteration}, PVC {$index}: PVC manifest should survive YAML round-trip.\nDifferences:\n{$diffMessage}");
                }

                expect($isEquivalent)->toBeTrue("Iteration {$iteration}, PVC {$index}: PVC manifest should survive YAML round-trip");
            }

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'yaml-round-trip');


    /**
     * **Validates: Requirements 3.7**
     *
     * Property: For any generated HorizontalPodAutoscaler manifest, parsing to YAML,
     * formatting, and re-parsing SHALL produce a semantically equivalent manifest.
     */
    test('hpa manifest survives yaml round-trip', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            // Create destination
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespaceForRoundTrip(),
            ]);

            // Create application
            $application = Application::create([
                'name' => generateApplicationNameForRoundTrip(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Create deployment settings with autoscaling enabled
            KubernetesDeploymentSettings::create([
                'application_id' => $application->id,
                'replicas' => fake()->numberBetween(1, 5),
                'autoscaling_enabled' => true,
                'min_replicas' => fake()->numberBetween(1, 3),
                'max_replicas' => fake()->numberBetween(5, 20),
                'target_cpu_utilization' => fake()->numberBetween(50, 90),
                'target_memory_utilization' => fake()->randomElement([null, fake()->numberBetween(50, 90)]),
            ]);

            $application->refresh();

            // Generate HPA manifest
            $generator = new KubernetesManifestGenerator($application, $destination);
            $originalHpa = $generator->generateHorizontalPodAutoscaler();

            // Skip if no HPA generated
            if ($originalHpa === null) {
                $application->forceDelete();
                $destination->delete();
                continue;
            }

            // Round-trip: Array -> YAML -> Array
            $yaml = Yaml::dump($originalHpa, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $roundTrippedHpa = Yaml::parse($yaml);

            // Property: Round-tripped manifest SHALL be semantically equivalent
            $isEquivalent = areManifestsSemanticallyEquivalent($originalHpa, $roundTrippedHpa);

            if (!$isEquivalent) {
                $diffs = getArrayDiff($originalHpa, $roundTrippedHpa);
                $diffMessage = implode("\n", $diffs);
                expect($isEquivalent)->toBeTrue("Iteration {$iteration}: HPA manifest should survive YAML round-trip.\nDifferences:\n{$diffMessage}");
            }

            expect($isEquivalent)->toBeTrue("Iteration {$iteration}: HPA manifest should survive YAML round-trip");

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'yaml-round-trip');


    /**
     * **Validates: Requirements 3.7**
     *
     * Property: For any complete set of generated manifests (via generateAll()),
     * parsing to YAML, formatting, and re-parsing SHALL produce semantically
     * equivalent manifests for all manifest types.
     */
    test('complete manifest set survives yaml round-trip via toYaml', function () {
        for ($iteration = 0; $iteration < 25; $iteration++) {
            // Create destination with full configuration
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateValidNamespaceForRoundTrip(),
                'default_cpu_limit' => generateCpuLimitForRoundTrip(),
                'default_memory_limit' => generateMemoryLimitForRoundTrip(),
                'ingress_class' => fake()->randomElement(['nginx', 'traefik', null]),
                'storage_class' => fake()->randomElement(['standard', 'fast', null]),
            ]);

            // Create application with full configuration
            $application = Application::create([
                'name' => generateApplicationNameForRoundTrip(),
                'environment_id' => $this->environment->id,
                'destination_type' => KubernetesDestination::class,
                'destination_id' => $destination->id,
                'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => generateExposedPortsForRoundTrip(),
                'docker_registry_image_name' => generateContainerImageForRoundTrip(),
                'fqdn' => generateFqdnForRoundTrip(),
                'uuid' => (string) new \Visus\Cuid2\Cuid2,
            ]);

            // Add environment variables
            EnvironmentVariable::create([
                'key' => generateNonSensitiveEnvKeyForRoundTrip(),
                'value' => generateEnvValueForRoundTrip(),
                'is_runtime' => true,
                'resourceable_type' => Application::class,
                'resourceable_id' => $application->id,
            ]);
            EnvironmentVariable::create([
                'key' => generateSensitiveEnvKeyForRoundTrip(),
                'value' => generateEnvValueForRoundTrip(),
                'is_runtime' => true,
                'resourceable_type' => Application::class,
                'resourceable_id' => $application->id,
            ]);

            // Add persistent volume
            LocalPersistentVolume::create([
                'name' => 'volume-' . fake()->slug(2),
                'mount_path' => generateMountPathForRoundTrip(),
                'resource_type' => Application::class,
                'resource_id' => $application->id,
            ]);

            $application->refresh();

            // Generate all manifests and export to YAML
            $generator = new KubernetesManifestGenerator($application, $destination);
            $originalManifests = $generator->generateAll();
            $yaml = $generator->toYaml();

            // Parse the YAML back - split by document separator
            $documents = preg_split('/^---$/m', $yaml);
            $parsedManifests = [];

            foreach ($documents as $doc) {
                $doc = trim($doc);
                if (!empty($doc)) {
                    $parsed = Yaml::parse($doc);
                    if (is_array($parsed) && isset($parsed['kind'])) {
                        $kind = $parsed['kind'];
                        // Handle multiple PVCs
                        if ($kind === 'PersistentVolumeClaim') {
                            if (!isset($parsedManifests[$kind])) {
                                $parsedManifests[$kind] = [];
                            }
                            $parsedManifests[$kind][] = $parsed;
                        } else {
                            $parsedManifests[$kind] = $parsed;
                        }
                    }
                }
            }

            // Verify each manifest type survives round-trip
            foreach ($originalManifests as $kind => $originalManifest) {
                expect($parsedManifests)->toHaveKey($kind, "Iteration {$iteration}: Parsed YAML should contain {$kind}");

                if ($kind === 'PersistentVolumeClaim' && is_array($originalManifest) && isset($originalManifest[0])) {
                    // Handle array of PVCs
                    foreach ($originalManifest as $index => $originalPvc) {
                        $roundTrippedPvc = $parsedManifests[$kind][$index] ?? null;
                        expect($roundTrippedPvc)->not->toBeNull("Iteration {$iteration}: PVC {$index} should exist in parsed YAML");

                        $isEquivalent = areManifestsSemanticallyEquivalent($originalPvc, $roundTrippedPvc);
                        expect($isEquivalent)->toBeTrue("Iteration {$iteration}: PVC {$index} should survive YAML round-trip");
                    }
                } else {
                    $roundTrippedManifest = $parsedManifests[$kind];
                    $isEquivalent = areManifestsSemanticallyEquivalent($originalManifest, $roundTrippedManifest);

                    if (!$isEquivalent) {
                        $diffs = getArrayDiff($originalManifest, $roundTrippedManifest);
                        $diffMessage = implode("\n", $diffs);
                        expect($isEquivalent)->toBeTrue("Iteration {$iteration}: {$kind} should survive YAML round-trip.\nDifferences:\n{$diffMessage}");
                    }

                    expect($isEquivalent)->toBeTrue("Iteration {$iteration}: {$kind} should survive YAML round-trip");
                }
            }

            // Cleanup
            $application->forceDelete();
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'yaml-round-trip');
});

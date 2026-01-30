<?php

/**
 * Property-Based Test: KubernetesCluster Model Storage
 *
 * **Validates: Requirements 1.1**
 *
 * Property 1: Cluster Configuratie Opslag
 * For any KubernetesCluster configuratie met geldige naam, kubeconfig en context,
 * het opslaan en ophalen van het model SHALL alle velden ongewijzigd behouden.
 *
 * This test uses property-based testing principles with random data generation
 * to verify that the KubernetesCluster model correctly persists and retrieves
 * all field values across many different valid configurations.
 */

use App\Models\KubernetesCluster;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Generate a valid kubeconfig YAML string with random values.
 */
function generateRandomKubeconfig(): string
{
    $clusterName = fake()->slug(2);
    $userName = fake()->userName();
    $contextName = fake()->slug(2);
    $serverUrl = 'https://'.fake()->domainName().':'.fake()->numberBetween(6443, 9999);
    $token = base64_encode(fake()->sha256());

    return <<<YAML
apiVersion: v1
kind: Config
clusters:
- cluster:
    server: {$serverUrl}
    certificate-authority-data: {$token}
  name: {$clusterName}
contexts:
- context:
    cluster: {$clusterName}
    user: {$userName}
    namespace: default
  name: {$contextName}
current-context: {$contextName}
users:
- name: {$userName}
  user:
    token: {$token}
YAML;
}

/**
 * Generate a random valid cluster type.
 */
function generateRandomClusterType(): string
{
    return fake()->randomElement(KubernetesCluster::CLUSTER_TYPES);
}

/**
 * Generate a random valid API server URL.
 */
function generateRandomApiServerUrl(): string
{
    return 'https://'.fake()->domainName().':'.fake()->numberBetween(6443, 9999);
}

/**
 * Generate a random valid namespace name (Kubernetes naming conventions).
 */
function generateRandomNamespace(): string
{
    // Kubernetes namespace names must be lowercase, alphanumeric with dashes
    return fake()->slug(fake()->numberBetween(1, 3));
}

/**
 * Generate a random valid cluster name.
 */
function generateRandomClusterName(): string
{
    return fake()->words(fake()->numberBetween(1, 4), true).' Cluster';
}

/**
 * Generate a random description (nullable).
 */
function generateRandomDescription(): ?string
{
    return fake()->boolean(70) ? fake()->sentence(fake()->numberBetween(3, 15)) : null;
}

/**
 * Generate a random context name (nullable).
 */
function generateRandomContextName(): ?string
{
    return fake()->boolean(80) ? fake()->slug(fake()->numberBetween(1, 3)) : null;
}

beforeEach(function () {
    // Create a team and user for the tests
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('Property 1: Cluster Configuratie Opslag', function () {
    /**
     * **Validates: Requirements 1.1**
     *
     * Property: For any valid KubernetesCluster configuration, saving to database
     * and retrieving should preserve all field values exactly.
     *
     * This test runs 100 iterations with randomly generated valid configurations
     * to verify the property holds across the input space.
     */
    test('saving and retrieving KubernetesCluster preserves all fields for any valid configuration', function () {
        // Run 100 iterations with different random configurations
        for ($iteration = 0; $iteration < 100; $iteration++) {
            // Generate random valid configuration
            $name = generateRandomClusterName();
            $description = generateRandomDescription();
            $clusterType = generateRandomClusterType();
            $apiServerUrl = generateRandomApiServerUrl();
            $kubeconfig = generateRandomKubeconfig();
            $contextName = generateRandomContextName();
            $defaultNamespace = generateRandomNamespace();
            $isReachable = fake()->boolean();
            $lastCheckedAt = fake()->boolean(60) ? fake()->dateTimeBetween('-1 year', 'now') : null;

            // Create and save the model
            $cluster = KubernetesCluster::create([
                'name' => $name,
                'description' => $description,
                'team_id' => $this->team->id,
                'cluster_type' => $clusterType,
                'api_server_url' => $apiServerUrl,
                'kubeconfig' => $kubeconfig,
                'context_name' => $contextName,
                'default_namespace' => $defaultNamespace,
                'is_reachable' => $isReachable,
                'last_checked_at' => $lastCheckedAt,
            ]);

            // Retrieve the model fresh from database
            $retrieved = KubernetesCluster::find($cluster->id);

            // Assert all fields are preserved
            expect($retrieved)->not->toBeNull()
                ->and($retrieved->name)->toBe($name, "Iteration {$iteration}: name mismatch")
                ->and($retrieved->description)->toBe($description, "Iteration {$iteration}: description mismatch")
                ->and($retrieved->team_id)->toBe($this->team->id, "Iteration {$iteration}: team_id mismatch")
                ->and($retrieved->cluster_type)->toBe($clusterType, "Iteration {$iteration}: cluster_type mismatch")
                ->and($retrieved->api_server_url)->toBe($apiServerUrl, "Iteration {$iteration}: api_server_url mismatch")
                ->and($retrieved->kubeconfig)->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig mismatch (encryption/decryption)")
                ->and($retrieved->context_name)->toBe($contextName, "Iteration {$iteration}: context_name mismatch")
                ->and($retrieved->default_namespace)->toBe($defaultNamespace, "Iteration {$iteration}: default_namespace mismatch")
                ->and($retrieved->is_reachable)->toBe($isReachable, "Iteration {$iteration}: is_reachable mismatch");

            // Handle datetime comparison (may have microsecond differences)
            if ($lastCheckedAt !== null) {
                expect($retrieved->last_checked_at)->not->toBeNull("Iteration {$iteration}: last_checked_at should not be null");
                // Compare timestamps with 1 second tolerance for database precision
                $expectedTimestamp = $lastCheckedAt instanceof \DateTime
                    ? $lastCheckedAt->getTimestamp()
                    : strtotime($lastCheckedAt);
                $actualTimestamp = $retrieved->last_checked_at->getTimestamp();
                expect(abs($actualTimestamp - $expectedTimestamp))->toBeLessThanOrEqual(1, "Iteration {$iteration}: last_checked_at timestamp mismatch");
            } else {
                expect($retrieved->last_checked_at)->toBeNull("Iteration {$iteration}: last_checked_at should be null");
            }

            // Verify UUID was auto-generated
            expect($retrieved->uuid)->not->toBeNull()
                ->and($retrieved->uuid)->not->toBeEmpty();

            // Clean up for next iteration
            $cluster->forceDelete();
        }
    })->group('property-test', 'kubernetes');

    /**
     * Additional property test: Verify that encrypted kubeconfig is stored encrypted
     * in the database but decrypted when retrieved through the model.
     */
    test('kubeconfig is encrypted in database but decrypted when retrieved', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $kubeconfig = generateRandomKubeconfig();

            $cluster = KubernetesCluster::create([
                'name' => generateRandomClusterName(),
                'team_id' => $this->team->id,
                'cluster_type' => generateRandomClusterType(),
                'api_server_url' => generateRandomApiServerUrl(),
                'kubeconfig' => $kubeconfig,
                'default_namespace' => generateRandomNamespace(),
            ]);

            // Get raw value from database (bypassing model encryption)
            $rawValue = \DB::table('kubernetes_clusters')
                ->where('id', $cluster->id)
                ->value('kubeconfig');

            // Raw value should NOT equal the original (it should be encrypted)
            expect($rawValue)->not->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be encrypted in database");

            // But when retrieved through model, it should be decrypted
            $retrieved = KubernetesCluster::find($cluster->id);
            expect($retrieved->kubeconfig)->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be decrypted when retrieved");

            $cluster->forceDelete();
        }
    })->group('property-test', 'kubernetes', 'encryption');

    /**
     * Property test: Verify that all valid cluster types can be stored and retrieved.
     */
    test('all valid cluster types can be stored and retrieved correctly', function () {
        foreach (KubernetesCluster::CLUSTER_TYPES as $clusterType) {
            // Test each cluster type multiple times with random data
            for ($i = 0; $i < 10; $i++) {
                $cluster = KubernetesCluster::create([
                    'name' => generateRandomClusterName(),
                    'team_id' => $this->team->id,
                    'cluster_type' => $clusterType,
                    'api_server_url' => generateRandomApiServerUrl(),
                    'kubeconfig' => generateRandomKubeconfig(),
                    'default_namespace' => generateRandomNamespace(),
                ]);

                $retrieved = KubernetesCluster::find($cluster->id);

                expect($retrieved->cluster_type)->toBe($clusterType, "Cluster type '{$clusterType}' iteration {$i}: mismatch");

                $cluster->forceDelete();
            }
        }
    })->group('property-test', 'kubernetes');

    /**
     * Property test: Verify boolean is_reachable field is correctly cast.
     */
    test('is_reachable boolean field is correctly stored and cast', function () {
        for ($iteration = 0; $iteration < 50; $iteration++) {
            $isReachable = fake()->boolean();

            $cluster = KubernetesCluster::create([
                'name' => generateRandomClusterName(),
                'team_id' => $this->team->id,
                'cluster_type' => generateRandomClusterType(),
                'api_server_url' => generateRandomApiServerUrl(),
                'kubeconfig' => generateRandomKubeconfig(),
                'default_namespace' => generateRandomNamespace(),
                'is_reachable' => $isReachable,
            ]);

            $retrieved = KubernetesCluster::find($cluster->id);

            // Verify it's a boolean type and has correct value
            expect($retrieved->is_reachable)->toBeBool()
                ->and($retrieved->is_reachable)->toBe($isReachable, "Iteration {$iteration}: is_reachable mismatch");

            $cluster->forceDelete();
        }
    })->group('property-test', 'kubernetes');

    /**
     * Property test: Verify datetime field last_checked_at is correctly cast.
     */
    test('last_checked_at datetime field is correctly stored and cast', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $lastCheckedAt = fake()->dateTimeBetween('-1 year', 'now');

            $cluster = KubernetesCluster::create([
                'name' => generateRandomClusterName(),
                'team_id' => $this->team->id,
                'cluster_type' => generateRandomClusterType(),
                'api_server_url' => generateRandomApiServerUrl(),
                'kubeconfig' => generateRandomKubeconfig(),
                'default_namespace' => generateRandomNamespace(),
                'last_checked_at' => $lastCheckedAt,
            ]);

            $retrieved = KubernetesCluster::find($cluster->id);

            // Verify it's a Carbon instance
            expect($retrieved->last_checked_at)->toBeInstanceOf(\Carbon\Carbon::class);

            // Compare timestamps (allowing 1 second tolerance for database precision)
            $expectedTimestamp = $lastCheckedAt->getTimestamp();
            $actualTimestamp = $retrieved->last_checked_at->getTimestamp();
            expect(abs($actualTimestamp - $expectedTimestamp))->toBeLessThanOrEqual(1, "Iteration {$iteration}: timestamp mismatch");

            $cluster->forceDelete();
        }
    })->group('property-test', 'kubernetes');
});

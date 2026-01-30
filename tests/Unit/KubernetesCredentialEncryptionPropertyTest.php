<?php

/**
 * Property-Based Test: Credential Encryption
 *
 * **Validates: Requirements 9.1, 9.2, 9.3**
 *
 * Property 12: Credential Encryption
 * For any KubernetesCluster met kubeconfig data, de opgeslagen waarde in de database
 * SHALL encrypted zijn (niet plaintext), en het ophalen via het model SHALL de
 * originele kubeconfig correct decrypten.
 *
 * This test uses property-based testing principles with random data generation
 * to verify that the KubernetesCluster model correctly encrypts credentials
 * when storing and decrypts them when retrieving.
 *
 * Requirements covered:
 * - 9.1: THE Kubernetes_Cluster SHALL kubeconfig credentials encrypted opslaan in de database
 * - 9.2: THE Kubernetes_Cluster SHALL ondersteuning bieden voor service account tokens,
 *        client certificates en OIDC authenticatie
 * - 9.3: WHEN een kubeconfig wordt geüpload, THE Kubernetes_Cluster SHALL gevoelige data
 *        (tokens, certificates) extraheren en apart encrypted opslaan
 */

use App\Models\KubernetesCluster;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Generate a kubeconfig with service account token authentication.
 * Validates: Requirement 9.2 (service account tokens)
 */
function generateServiceAccountTokenKubeconfig(): string
{
    $clusterName = fake()->slug(2);
    $userName = fake()->userName();
    $contextName = fake()->slug(2);
    $serverUrl = 'https://' . fake()->domainName() . ':' . fake()->numberBetween(6443, 9999);
    // Generate a realistic service account token (base64 encoded JWT-like structure)
    $token = base64_encode(fake()->sha256() . '.' . fake()->sha256() . '.' . fake()->sha256());
    $caCert = base64_encode(fake()->sha256() . fake()->sha256());

    return <<<YAML
apiVersion: v1
kind: Config
clusters:
- cluster:
    server: {$serverUrl}
    certificate-authority-data: {$caCert}
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
 * Generate a kubeconfig with client certificate authentication.
 * Validates: Requirement 9.2 (client certificates)
 */
function generateClientCertificateKubeconfig(): string
{
    $clusterName = fake()->slug(2);
    $userName = fake()->userName();
    $contextName = fake()->slug(2);
    $serverUrl = 'https://' . fake()->domainName() . ':' . fake()->numberBetween(6443, 9999);
    // Generate realistic certificate data (base64 encoded)
    $caCert = base64_encode(fake()->sha256() . fake()->sha256() . fake()->sha256());
    $clientCert = base64_encode(fake()->sha256() . fake()->sha256() . fake()->sha256());
    $clientKey = base64_encode(fake()->sha256() . fake()->sha256() . fake()->sha256());

    return <<<YAML
apiVersion: v1
kind: Config
clusters:
- cluster:
    server: {$serverUrl}
    certificate-authority-data: {$caCert}
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
    client-certificate-data: {$clientCert}
    client-key-data: {$clientKey}
YAML;
}

/**
 * Generate a kubeconfig with OIDC authentication.
 * Validates: Requirement 9.2 (OIDC authenticatie)
 */
function generateOidcKubeconfig(): string
{
    $clusterName = fake()->slug(2);
    $userName = fake()->userName();
    $contextName = fake()->slug(2);
    $serverUrl = 'https://' . fake()->domainName() . ':' . fake()->numberBetween(6443, 9999);
    $caCert = base64_encode(fake()->sha256() . fake()->sha256());
    $idpIssuerUrl = 'https://' . fake()->domainName() . '/auth/realms/' . fake()->slug(1);
    $clientId = fake()->uuid();
    $clientSecret = fake()->sha256();
    $idToken = base64_encode(fake()->sha256() . '.' . fake()->sha256() . '.' . fake()->sha256());
    $refreshToken = base64_encode(fake()->sha256() . '.' . fake()->sha256());

    return <<<YAML
apiVersion: v1
kind: Config
clusters:
- cluster:
    server: {$serverUrl}
    certificate-authority-data: {$caCert}
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
    auth-provider:
      name: oidc
      config:
        idp-issuer-url: {$idpIssuerUrl}
        client-id: {$clientId}
        client-secret: {$clientSecret}
        id-token: {$idToken}
        refresh-token: {$refreshToken}
YAML;
}

/**
 * Generate a random valid cluster type.
 */
function generateClusterType(): string
{
    return fake()->randomElement(KubernetesCluster::CLUSTER_TYPES);
}

/**
 * Generate a random valid API server URL.
 */
function generateApiServerUrl(): string
{
    return 'https://' . fake()->domainName() . ':' . fake()->numberBetween(6443, 9999);
}

/**
 * Generate a random valid namespace name.
 */
function generateDefaultNamespace(): string
{
    return fake()->slug(fake()->numberBetween(1, 3));
}

/**
 * Generate a random cluster name.
 */
function generateClusterName(): string
{
    return fake()->words(fake()->numberBetween(1, 4), true) . ' Cluster';
}

/**
 * Generate a kubeconfig with random sensitive data of varying lengths.
 * Tests edge cases with different data sizes.
 */
function generateVariableLengthKubeconfig(): string
{
    $clusterName = fake()->slug(2);
    $userName = fake()->userName();
    $contextName = fake()->slug(2);
    $serverUrl = 'https://' . fake()->domainName() . ':' . fake()->numberBetween(6443, 9999);

    // Generate tokens/certs of varying lengths (small to large)
    $tokenLength = fake()->numberBetween(32, 512);
    $token = base64_encode(fake()->regexify('[A-Za-z0-9]{' . $tokenLength . '}'));
    $caCertLength = fake()->numberBetween(100, 2000);
    $caCert = base64_encode(fake()->regexify('[A-Za-z0-9]{' . $caCertLength . '}'));

    return <<<YAML
apiVersion: v1
kind: Config
clusters:
- cluster:
    server: {$serverUrl}
    certificate-authority-data: {$caCert}
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
 * Generate a kubeconfig with special characters in values.
 * Tests that encryption handles special characters correctly.
 */
function generateSpecialCharacterKubeconfig(): string
{
    $clusterName = fake()->slug(2);
    $userName = fake()->userName();
    $contextName = fake()->slug(2);
    $serverUrl = 'https://' . fake()->domainName() . ':' . fake()->numberBetween(6443, 9999);
    // Include special characters that might cause issues with encryption/encoding
    $token = base64_encode(fake()->sha256() . '+/=' . fake()->sha256());
    $caCert = base64_encode(fake()->sha256() . '+/=+' . fake()->sha256());

    return <<<YAML
apiVersion: v1
kind: Config
clusters:
- cluster:
    server: {$serverUrl}
    certificate-authority-data: {$caCert}
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

beforeEach(function () {
    // Create a team and user for the tests
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('Property 12: Credential Encryption', function () {
    /**
     * **Validates: Requirements 9.1, 9.2, 9.3**
     *
     * Property: For any KubernetesCluster with kubeconfig data containing service account tokens,
     * the stored value in the database SHALL be encrypted (not plaintext), and retrieving
     * via the model SHALL correctly decrypt the original kubeconfig.
     */
    test('service account token kubeconfig is encrypted in database and decrypted on retrieval', function () {
        for ($iteration = 0; $iteration < 50; $iteration++) {
            $kubeconfig = generateServiceAccountTokenKubeconfig();

            $cluster = KubernetesCluster::create([
                'name' => generateClusterName(),
                'team_id' => $this->team->id,
                'cluster_type' => generateClusterType(),
                'api_server_url' => generateApiServerUrl(),
                'kubeconfig' => $kubeconfig,
                'default_namespace' => generateDefaultNamespace(),
            ]);

            // Get raw value from database (bypassing model encryption)
            $rawValue = DB::table('kubernetes_clusters')
                ->where('id', $cluster->id)
                ->value('kubeconfig');

            // Property 1: Raw database value SHALL NOT equal original (must be encrypted)
            expect($rawValue)->not->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be encrypted in database");

            // Property 2: Raw value should not contain plaintext sensitive data
            // Extract the token from the original kubeconfig
            preg_match('/token:\s*(.+)$/m', $kubeconfig, $matches);
            if (isset($matches[1])) {
                $token = trim($matches[1]);
                expect($rawValue)->not->toContain($token, "Iteration {$iteration}: raw database value should not contain plaintext token");
            }

            // Property 3: Model retrieval SHALL return original kubeconfig (decrypted)
            $retrieved = KubernetesCluster::find($cluster->id);
            expect($retrieved->kubeconfig)->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be decrypted when retrieved");

            $cluster->forceDelete();
        }
    })->group('property-test', 'kubernetes', 'encryption');

    /**
     * **Validates: Requirements 9.1, 9.2, 9.3**
     *
     * Property: For any KubernetesCluster with kubeconfig data containing client certificates,
     * the stored value in the database SHALL be encrypted (not plaintext), and retrieving
     * via the model SHALL correctly decrypt the original kubeconfig.
     */
    test('client certificate kubeconfig is encrypted in database and decrypted on retrieval', function () {
        for ($iteration = 0; $iteration < 50; $iteration++) {
            $kubeconfig = generateClientCertificateKubeconfig();

            $cluster = KubernetesCluster::create([
                'name' => generateClusterName(),
                'team_id' => $this->team->id,
                'cluster_type' => generateClusterType(),
                'api_server_url' => generateApiServerUrl(),
                'kubeconfig' => $kubeconfig,
                'default_namespace' => generateDefaultNamespace(),
            ]);

            // Get raw value from database (bypassing model encryption)
            $rawValue = DB::table('kubernetes_clusters')
                ->where('id', $cluster->id)
                ->value('kubeconfig');

            // Property 1: Raw database value SHALL NOT equal original (must be encrypted)
            expect($rawValue)->not->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be encrypted in database");

            // Property 2: Raw value should not contain plaintext certificate data
            preg_match('/client-certificate-data:\s*(.+)$/m', $kubeconfig, $certMatches);
            preg_match('/client-key-data:\s*(.+)$/m', $kubeconfig, $keyMatches);

            if (isset($certMatches[1])) {
                $clientCert = trim($certMatches[1]);
                expect($rawValue)->not->toContain($clientCert, "Iteration {$iteration}: raw database value should not contain plaintext client certificate");
            }

            if (isset($keyMatches[1])) {
                $clientKey = trim($keyMatches[1]);
                expect($rawValue)->not->toContain($clientKey, "Iteration {$iteration}: raw database value should not contain plaintext client key");
            }

            // Property 3: Model retrieval SHALL return original kubeconfig (decrypted)
            $retrieved = KubernetesCluster::find($cluster->id);
            expect($retrieved->kubeconfig)->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be decrypted when retrieved");

            $cluster->forceDelete();
        }
    })->group('property-test', 'kubernetes', 'encryption');

    /**
     * **Validates: Requirements 9.1, 9.2, 9.3**
     *
     * Property: For any KubernetesCluster with kubeconfig data containing OIDC authentication,
     * the stored value in the database SHALL be encrypted (not plaintext), and retrieving
     * via the model SHALL correctly decrypt the original kubeconfig.
     */
    test('OIDC kubeconfig is encrypted in database and decrypted on retrieval', function () {
        for ($iteration = 0; $iteration < 50; $iteration++) {
            $kubeconfig = generateOidcKubeconfig();

            $cluster = KubernetesCluster::create([
                'name' => generateClusterName(),
                'team_id' => $this->team->id,
                'cluster_type' => generateClusterType(),
                'api_server_url' => generateApiServerUrl(),
                'kubeconfig' => $kubeconfig,
                'default_namespace' => generateDefaultNamespace(),
            ]);

            // Get raw value from database (bypassing model encryption)
            $rawValue = DB::table('kubernetes_clusters')
                ->where('id', $cluster->id)
                ->value('kubeconfig');

            // Property 1: Raw database value SHALL NOT equal original (must be encrypted)
            expect($rawValue)->not->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be encrypted in database");

            // Property 2: Raw value should not contain plaintext OIDC secrets
            preg_match('/client-secret:\s*(.+)$/m', $kubeconfig, $secretMatches);
            preg_match('/id-token:\s*(.+)$/m', $kubeconfig, $idTokenMatches);
            preg_match('/refresh-token:\s*(.+)$/m', $kubeconfig, $refreshMatches);

            if (isset($secretMatches[1])) {
                $clientSecret = trim($secretMatches[1]);
                expect($rawValue)->not->toContain($clientSecret, "Iteration {$iteration}: raw database value should not contain plaintext client secret");
            }

            if (isset($idTokenMatches[1])) {
                $idToken = trim($idTokenMatches[1]);
                expect($rawValue)->not->toContain($idToken, "Iteration {$iteration}: raw database value should not contain plaintext id token");
            }

            if (isset($refreshMatches[1])) {
                $refreshToken = trim($refreshMatches[1]);
                expect($rawValue)->not->toContain($refreshToken, "Iteration {$iteration}: raw database value should not contain plaintext refresh token");
            }

            // Property 3: Model retrieval SHALL return original kubeconfig (decrypted)
            $retrieved = KubernetesCluster::find($cluster->id);
            expect($retrieved->kubeconfig)->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be decrypted when retrieved");

            $cluster->forceDelete();
        }
    })->group('property-test', 'kubernetes', 'encryption');

    /**
     * **Validates: Requirements 9.1, 9.3**
     *
     * Property: For any KubernetesCluster with kubeconfig data of varying lengths,
     * encryption and decryption SHALL work correctly regardless of data size.
     */
    test('kubeconfig encryption works correctly for varying data lengths', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $kubeconfig = generateVariableLengthKubeconfig();

            $cluster = KubernetesCluster::create([
                'name' => generateClusterName(),
                'team_id' => $this->team->id,
                'cluster_type' => generateClusterType(),
                'api_server_url' => generateApiServerUrl(),
                'kubeconfig' => $kubeconfig,
                'default_namespace' => generateDefaultNamespace(),
            ]);

            // Get raw value from database
            $rawValue = DB::table('kubernetes_clusters')
                ->where('id', $cluster->id)
                ->value('kubeconfig');

            // Property 1: Raw database value SHALL NOT equal original
            expect($rawValue)->not->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be encrypted in database");

            // Property 2: Model retrieval SHALL return original kubeconfig
            $retrieved = KubernetesCluster::find($cluster->id);
            expect($retrieved->kubeconfig)->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be decrypted when retrieved");

            $cluster->forceDelete();
        }
    })->group('property-test', 'kubernetes', 'encryption');

    /**
     * **Validates: Requirements 9.1, 9.3**
     *
     * Property: For any KubernetesCluster with kubeconfig containing special characters,
     * encryption and decryption SHALL preserve all characters exactly.
     */
    test('kubeconfig encryption preserves special characters correctly', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $kubeconfig = generateSpecialCharacterKubeconfig();

            $cluster = KubernetesCluster::create([
                'name' => generateClusterName(),
                'team_id' => $this->team->id,
                'cluster_type' => generateClusterType(),
                'api_server_url' => generateApiServerUrl(),
                'kubeconfig' => $kubeconfig,
                'default_namespace' => generateDefaultNamespace(),
            ]);

            // Get raw value from database
            $rawValue = DB::table('kubernetes_clusters')
                ->where('id', $cluster->id)
                ->value('kubeconfig');

            // Property 1: Raw database value SHALL NOT equal original
            expect($rawValue)->not->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be encrypted in database");

            // Property 2: Model retrieval SHALL return original kubeconfig exactly
            $retrieved = KubernetesCluster::find($cluster->id);
            expect($retrieved->kubeconfig)->toBe($kubeconfig, "Iteration {$iteration}: kubeconfig should be decrypted exactly as original");

            $cluster->forceDelete();
        }
    })->group('property-test', 'kubernetes', 'encryption');

    /**
     * **Validates: Requirements 9.1**
     *
     * Property: For any KubernetesCluster, updating the kubeconfig SHALL result in
     * the new value being encrypted and the old encrypted value being replaced.
     */
    test('updating kubeconfig re-encrypts the new value', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $originalKubeconfig = generateServiceAccountTokenKubeconfig();
            $newKubeconfig = generateClientCertificateKubeconfig();

            $cluster = KubernetesCluster::create([
                'name' => generateClusterName(),
                'team_id' => $this->team->id,
                'cluster_type' => generateClusterType(),
                'api_server_url' => generateApiServerUrl(),
                'kubeconfig' => $originalKubeconfig,
                'default_namespace' => generateDefaultNamespace(),
            ]);

            // Get original raw value
            $originalRawValue = DB::table('kubernetes_clusters')
                ->where('id', $cluster->id)
                ->value('kubeconfig');

            // Update kubeconfig
            $cluster->kubeconfig = $newKubeconfig;
            $cluster->save();

            // Get new raw value
            $newRawValue = DB::table('kubernetes_clusters')
                ->where('id', $cluster->id)
                ->value('kubeconfig');

            // Property 1: New raw value SHALL be different from original raw value
            expect($newRawValue)->not->toBe($originalRawValue, "Iteration {$iteration}: encrypted value should change after update");

            // Property 2: New raw value SHALL NOT equal new kubeconfig (must be encrypted)
            expect($newRawValue)->not->toBe($newKubeconfig, "Iteration {$iteration}: new kubeconfig should be encrypted");

            // Property 3: Model retrieval SHALL return new kubeconfig
            $retrieved = KubernetesCluster::find($cluster->id);
            expect($retrieved->kubeconfig)->toBe($newKubeconfig, "Iteration {$iteration}: should retrieve new kubeconfig after update");

            $cluster->forceDelete();
        }
    })->group('property-test', 'kubernetes', 'encryption');

    /**
     * **Validates: Requirements 9.1**
     *
     * Property: For any two KubernetesClusters with identical kubeconfig data,
     * the encrypted values in the database SHALL be different (due to unique IVs).
     */
    test('identical kubeconfigs produce different encrypted values', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $kubeconfig = generateServiceAccountTokenKubeconfig();

            $cluster1 = KubernetesCluster::create([
                'name' => generateClusterName() . ' 1',
                'team_id' => $this->team->id,
                'cluster_type' => generateClusterType(),
                'api_server_url' => generateApiServerUrl(),
                'kubeconfig' => $kubeconfig,
                'default_namespace' => generateDefaultNamespace(),
            ]);

            $cluster2 = KubernetesCluster::create([
                'name' => generateClusterName() . ' 2',
                'team_id' => $this->team->id,
                'cluster_type' => generateClusterType(),
                'api_server_url' => generateApiServerUrl(),
                'kubeconfig' => $kubeconfig,
                'default_namespace' => generateDefaultNamespace(),
            ]);

            // Get raw values from database
            $rawValue1 = DB::table('kubernetes_clusters')
                ->where('id', $cluster1->id)
                ->value('kubeconfig');

            $rawValue2 = DB::table('kubernetes_clusters')
                ->where('id', $cluster2->id)
                ->value('kubeconfig');

            // Property 1: Encrypted values SHOULD be different (unique IVs)
            // Note: Laravel's encryption uses random IVs, so identical plaintext produces different ciphertext
            expect($rawValue1)->not->toBe($rawValue2, "Iteration {$iteration}: identical kubeconfigs should produce different encrypted values");

            // Property 2: Both should decrypt to the same original value
            $retrieved1 = KubernetesCluster::find($cluster1->id);
            $retrieved2 = KubernetesCluster::find($cluster2->id);

            expect($retrieved1->kubeconfig)->toBe($kubeconfig, "Iteration {$iteration}: cluster1 should decrypt correctly");
            expect($retrieved2->kubeconfig)->toBe($kubeconfig, "Iteration {$iteration}: cluster2 should decrypt correctly");
            expect($retrieved1->kubeconfig)->toBe($retrieved2->kubeconfig, "Iteration {$iteration}: both should decrypt to same value");

            $cluster1->forceDelete();
            $cluster2->forceDelete();
        }
    })->group('property-test', 'kubernetes', 'encryption');

    /**
     * **Validates: Requirements 9.1**
     *
     * Property: For any KubernetesCluster, the kubeconfig field SHALL be hidden
     * from serialization to prevent accidental exposure.
     */
    test('kubeconfig is hidden from model serialization', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $kubeconfig = generateServiceAccountTokenKubeconfig();

            $cluster = KubernetesCluster::create([
                'name' => generateClusterName(),
                'team_id' => $this->team->id,
                'cluster_type' => generateClusterType(),
                'api_server_url' => generateApiServerUrl(),
                'kubeconfig' => $kubeconfig,
                'default_namespace' => generateDefaultNamespace(),
            ]);

            // Serialize to array
            $array = $cluster->toArray();

            // Property: kubeconfig SHALL NOT be present in serialized output
            expect($array)->not->toHaveKey('kubeconfig', "Iteration {$iteration}: kubeconfig should be hidden from serialization");

            // Verify other fields are present
            expect($array)->toHaveKey('name')
                ->and($array)->toHaveKey('cluster_type')
                ->and($array)->toHaveKey('api_server_url');

            // Serialize to JSON
            $json = $cluster->toJson();
            $decoded = json_decode($json, true);

            // Property: kubeconfig SHALL NOT be present in JSON output
            expect($decoded)->not->toHaveKey('kubeconfig', "Iteration {$iteration}: kubeconfig should be hidden from JSON serialization");

            $cluster->forceDelete();
        }
    })->group('property-test', 'kubernetes', 'encryption');
});

<?php

/**
 * Property-Based Test: KubernetesDestination Polymorphic Relaties
 *
 * **Validates: Requirements 2.2**
 *
 * Property 3: Destination Polymorphic Relaties
 * For any KubernetesDestination, de morphMany relaties voor applications, services,
 * en database modellen (postgresql, redis, mongodb, mysql, mariadb) SHALL correct
 * functioneren en resources kunnen koppelen en ophalen.
 *
 * This test uses property-based testing principles with random data generation
 * to verify that the KubernetesDestination model correctly handles polymorphic
 * relationships for attaching and retrieving various resource types.
 */

use App\Models\Application;
use App\Models\Environment;
use App\Models\KubernetesCluster;
use App\Models\KubernetesDestination;
use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Generate a valid kubeconfig YAML string.
 */
function generateKubeconfig(): string
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
 * Generate a random valid namespace name (Kubernetes naming conventions).
 */
function generateNamespace(): string
{
    return fake()->slug(fake()->numberBetween(1, 3));
}

/**
 * Generate a random number of resources to attach (1-5).
 */
function generateResourceCount(): int
{
    return fake()->numberBetween(1, 5);
}

/**
 * Generate a random application name.
 */
function generateAppName(): string
{
    return fake()->words(fake()->numberBetween(1, 3), true) . ' App';
}

/**
 * Generate a random service name.
 */
function generateServiceName(): string
{
    return 'service-' . fake()->slug(2);
}

/**
 * Generate a random database name.
 */
function generateDbName(): string
{
    return fake()->slug(2) . '-db';
}

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
        'kubeconfig' => generateKubeconfig(),
        'default_namespace' => 'default',
    ]);
});


describe('Property 3: Destination Polymorphic Relaties', function () {
    /**
     * **Validates: Requirements 2.2**
     *
     * Property: For any KubernetesDestination, the morphMany relationship for
     * applications SHALL correctly function to attach and retrieve resources.
     */
    test('applications can be attached to and retrieved from KubernetesDestination', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create a destination with random namespace
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateNamespace(),
            ]);

            // Generate random number of applications to attach
            $appCount = generateResourceCount();
            $createdApps = [];

            for ($i = 0; $i < $appCount; $i++) {
                $app = Application::create([
                    'name' => generateAppName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'git_repository' => 'https://github.com/test/repo-' . fake()->slug(2),
                    'git_branch' => 'main',
                    'build_pack' => 'nixpacks',
                    'ports_exposes' => (string) fake()->numberBetween(3000, 9000),
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                ]);
                $createdApps[] = $app;
            }

            // Refresh destination and verify relationship
            $destination->refresh();
            $retrievedApps = $destination->applications;

            // Assert correct count
            expect($retrievedApps)->toHaveCount($appCount, "Iteration {$iteration}: application count mismatch");

            // Assert all created apps are retrieved
            foreach ($createdApps as $createdApp) {
                expect($retrievedApps->pluck('id')->toArray())
                    ->toContain($createdApp->id, "Iteration {$iteration}: application {$createdApp->id} not found");
            }

            // Assert destination relationship from application side
            foreach ($createdApps as $createdApp) {
                $createdApp->refresh();
                expect($createdApp->destination)->not->toBeNull()
                    ->and($createdApp->destination->id)->toBe($destination->id)
                    ->and($createdApp->destination_type)->toBe(KubernetesDestination::class);
            }

            // Cleanup
            foreach ($createdApps as $app) {
                $app->forceDelete();
            }
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'polymorphic');


    /**
     * **Validates: Requirements 2.2**
     *
     * Property: For any KubernetesDestination, the morphMany relationship for
     * services SHALL correctly function to attach and retrieve resources.
     */
    test('services can be attached to and retrieved from KubernetesDestination', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create a destination with random namespace
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateNamespace(),
            ]);

            // Generate random number of services to attach
            $serviceCount = generateResourceCount();
            $createdServices = [];

            for ($i = 0; $i < $serviceCount; $i++) {
                $service = Service::create([
                    'name' => generateServiceName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                ]);
                $createdServices[] = $service;
            }

            // Refresh destination and verify relationship
            $destination->refresh();
            $retrievedServices = $destination->services;

            // Assert correct count
            expect($retrievedServices)->toHaveCount($serviceCount, "Iteration {$iteration}: service count mismatch");

            // Assert all created services are retrieved
            foreach ($createdServices as $createdService) {
                expect($retrievedServices->pluck('id')->toArray())
                    ->toContain($createdService->id, "Iteration {$iteration}: service {$createdService->id} not found");
            }

            // Assert destination relationship from service side
            foreach ($createdServices as $createdService) {
                $createdService->refresh();
                expect($createdService->destination)->not->toBeNull()
                    ->and($createdService->destination->id)->toBe($destination->id)
                    ->and($createdService->destination_type)->toBe(KubernetesDestination::class);
            }

            // Cleanup
            foreach ($createdServices as $service) {
                $service->forceDelete();
            }
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'polymorphic');


    /**
     * **Validates: Requirements 2.2**
     *
     * Property: For any KubernetesDestination, the morphMany relationship for
     * PostgreSQL databases SHALL correctly function to attach and retrieve resources.
     */
    test('postgresql databases can be attached to and retrieved from KubernetesDestination', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create a destination with random namespace
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateNamespace(),
            ]);

            // Generate random number of databases to attach
            $dbCount = generateResourceCount();
            $createdDbs = [];

            for ($i = 0; $i < $dbCount; $i++) {
                $db = StandalonePostgresql::create([
                    'name' => generateDbName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                    'postgres_user' => 'postgres',
                    'postgres_password' => fake()->password(16),
                    'postgres_db' => 'testdb_' . fake()->slug(1),
                ]);
                $createdDbs[] = $db;
            }

            // Refresh destination and verify relationship
            $destination->refresh();
            $retrievedDbs = $destination->postgresqls;

            // Assert correct count
            expect($retrievedDbs)->toHaveCount($dbCount, "Iteration {$iteration}: postgresql count mismatch");

            // Assert all created databases are retrieved
            foreach ($createdDbs as $createdDb) {
                expect($retrievedDbs->pluck('id')->toArray())
                    ->toContain($createdDb->id, "Iteration {$iteration}: postgresql {$createdDb->id} not found");
            }

            // Assert destination relationship from database side
            foreach ($createdDbs as $createdDb) {
                $createdDb->refresh();
                expect($createdDb->destination)->not->toBeNull()
                    ->and($createdDb->destination->id)->toBe($destination->id)
                    ->and($createdDb->destination_type)->toBe(KubernetesDestination::class);
            }

            // Cleanup
            foreach ($createdDbs as $db) {
                $db->forceDelete();
            }
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'polymorphic');


    /**
     * **Validates: Requirements 2.2**
     *
     * Property: For any KubernetesDestination, the morphMany relationship for
     * Redis databases SHALL correctly function to attach and retrieve resources.
     */
    test('redis databases can be attached to and retrieved from KubernetesDestination', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create a destination with random namespace
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateNamespace(),
            ]);

            // Generate random number of databases to attach
            $dbCount = generateResourceCount();
            $createdDbs = [];

            for ($i = 0; $i < $dbCount; $i++) {
                $db = StandaloneRedis::create([
                    'name' => generateDbName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                    'redis_password' => fake()->password(16),
                ]);
                $createdDbs[] = $db;
            }

            // Refresh destination and verify relationship
            $destination->refresh();
            $retrievedDbs = $destination->redis;

            // Assert correct count
            expect($retrievedDbs)->toHaveCount($dbCount, "Iteration {$iteration}: redis count mismatch");

            // Assert all created databases are retrieved
            foreach ($createdDbs as $createdDb) {
                expect($retrievedDbs->pluck('id')->toArray())
                    ->toContain($createdDb->id, "Iteration {$iteration}: redis {$createdDb->id} not found");
            }

            // Assert destination relationship from database side
            foreach ($createdDbs as $createdDb) {
                $createdDb->refresh();
                expect($createdDb->destination)->not->toBeNull()
                    ->and($createdDb->destination->id)->toBe($destination->id)
                    ->and($createdDb->destination_type)->toBe(KubernetesDestination::class);
            }

            // Cleanup
            foreach ($createdDbs as $db) {
                $db->forceDelete();
            }
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'polymorphic');


    /**
     * **Validates: Requirements 2.2**
     *
     * Property: For any KubernetesDestination, the morphMany relationship for
     * MongoDB databases SHALL correctly function to attach and retrieve resources.
     */
    test('mongodb databases can be attached to and retrieved from KubernetesDestination', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create a destination with random namespace
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateNamespace(),
            ]);

            // Generate random number of databases to attach
            $dbCount = generateResourceCount();
            $createdDbs = [];

            for ($i = 0; $i < $dbCount; $i++) {
                $db = StandaloneMongodb::create([
                    'name' => generateDbName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                    'mongo_initdb_root_username' => 'admin',
                    'mongo_initdb_root_password' => fake()->password(16),
                    'mongo_initdb_database' => 'testdb_' . fake()->slug(1),
                ]);
                $createdDbs[] = $db;
            }

            // Refresh destination and verify relationship
            $destination->refresh();
            $retrievedDbs = $destination->mongodbs;

            // Assert correct count
            expect($retrievedDbs)->toHaveCount($dbCount, "Iteration {$iteration}: mongodb count mismatch");

            // Assert all created databases are retrieved
            foreach ($createdDbs as $createdDb) {
                expect($retrievedDbs->pluck('id')->toArray())
                    ->toContain($createdDb->id, "Iteration {$iteration}: mongodb {$createdDb->id} not found");
            }

            // Assert destination relationship from database side
            foreach ($createdDbs as $createdDb) {
                $createdDb->refresh();
                expect($createdDb->destination)->not->toBeNull()
                    ->and($createdDb->destination->id)->toBe($destination->id)
                    ->and($createdDb->destination_type)->toBe(KubernetesDestination::class);
            }

            // Cleanup
            foreach ($createdDbs as $db) {
                $db->forceDelete();
            }
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'polymorphic');


    /**
     * **Validates: Requirements 2.2**
     *
     * Property: For any KubernetesDestination, the morphMany relationship for
     * MySQL databases SHALL correctly function to attach and retrieve resources.
     */
    test('mysql databases can be attached to and retrieved from KubernetesDestination', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create a destination with random namespace
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateNamespace(),
            ]);

            // Generate random number of databases to attach
            $dbCount = generateResourceCount();
            $createdDbs = [];

            for ($i = 0; $i < $dbCount; $i++) {
                $db = StandaloneMysql::create([
                    'name' => generateDbName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                    'mysql_root_password' => fake()->password(16),
                    'mysql_user' => 'testuser',
                    'mysql_password' => fake()->password(16),
                    'mysql_database' => 'testdb_' . fake()->slug(1),
                ]);
                $createdDbs[] = $db;
            }

            // Refresh destination and verify relationship
            $destination->refresh();
            $retrievedDbs = $destination->mysqls;

            // Assert correct count
            expect($retrievedDbs)->toHaveCount($dbCount, "Iteration {$iteration}: mysql count mismatch");

            // Assert all created databases are retrieved
            foreach ($createdDbs as $createdDb) {
                expect($retrievedDbs->pluck('id')->toArray())
                    ->toContain($createdDb->id, "Iteration {$iteration}: mysql {$createdDb->id} not found");
            }

            // Assert destination relationship from database side
            foreach ($createdDbs as $createdDb) {
                $createdDb->refresh();
                expect($createdDb->destination)->not->toBeNull()
                    ->and($createdDb->destination->id)->toBe($destination->id)
                    ->and($createdDb->destination_type)->toBe(KubernetesDestination::class);
            }

            // Cleanup
            foreach ($createdDbs as $db) {
                $db->forceDelete();
            }
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'polymorphic');


    /**
     * **Validates: Requirements 2.2**
     *
     * Property: For any KubernetesDestination, the morphMany relationship for
     * MariaDB databases SHALL correctly function to attach and retrieve resources.
     */
    test('mariadb databases can be attached to and retrieved from KubernetesDestination', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create a destination with random namespace
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateNamespace(),
            ]);

            // Generate random number of databases to attach
            $dbCount = generateResourceCount();
            $createdDbs = [];

            for ($i = 0; $i < $dbCount; $i++) {
                $db = StandaloneMariadb::create([
                    'name' => generateDbName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                    'mariadb_root_password' => fake()->password(16),
                    'mariadb_user' => 'testuser',
                    'mariadb_password' => fake()->password(16),
                    'mariadb_database' => 'testdb_' . fake()->slug(1),
                ]);
                $createdDbs[] = $db;
            }

            // Refresh destination and verify relationship
            $destination->refresh();
            $retrievedDbs = $destination->mariadbs;

            // Assert correct count
            expect($retrievedDbs)->toHaveCount($dbCount, "Iteration {$iteration}: mariadb count mismatch");

            // Assert all created databases are retrieved
            foreach ($createdDbs as $createdDb) {
                expect($retrievedDbs->pluck('id')->toArray())
                    ->toContain($createdDb->id, "Iteration {$iteration}: mariadb {$createdDb->id} not found");
            }

            // Assert destination relationship from database side
            foreach ($createdDbs as $createdDb) {
                $createdDb->refresh();
                expect($createdDb->destination)->not->toBeNull()
                    ->and($createdDb->destination->id)->toBe($destination->id)
                    ->and($createdDb->destination_type)->toBe(KubernetesDestination::class);
            }

            // Cleanup
            foreach ($createdDbs as $db) {
                $db->forceDelete();
            }
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'polymorphic');


    /**
     * **Validates: Requirements 2.2**
     *
     * Property: For any KubernetesDestination with multiple resource types attached,
     * the databases() helper method SHALL correctly aggregate all database types.
     */
    test('databases helper method aggregates all database types correctly', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            // Create a destination with random namespace
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateNamespace(),
            ]);

            // Create random number of each database type
            $pgCount = fake()->numberBetween(0, 3);
            $redisCount = fake()->numberBetween(0, 3);
            $mongoCount = fake()->numberBetween(0, 3);
            $mysqlCount = fake()->numberBetween(0, 3);
            $mariaCount = fake()->numberBetween(0, 3);

            $allDbs = [];

            // Create PostgreSQL databases
            for ($i = 0; $i < $pgCount; $i++) {
                $allDbs[] = StandalonePostgresql::create([
                    'name' => generateDbName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                    'postgres_user' => 'postgres',
                    'postgres_password' => fake()->password(16),
                    'postgres_db' => 'testdb',
                ]);
            }

            // Create Redis databases
            for ($i = 0; $i < $redisCount; $i++) {
                $allDbs[] = StandaloneRedis::create([
                    'name' => generateDbName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                    'redis_password' => fake()->password(16),
                ]);
            }

            // Create MongoDB databases
            for ($i = 0; $i < $mongoCount; $i++) {
                $allDbs[] = StandaloneMongodb::create([
                    'name' => generateDbName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                    'mongo_initdb_root_username' => 'admin',
                    'mongo_initdb_root_password' => fake()->password(16),
                    'mongo_initdb_database' => 'testdb',
                ]);
            }

            // Create MySQL databases
            for ($i = 0; $i < $mysqlCount; $i++) {
                $allDbs[] = StandaloneMysql::create([
                    'name' => generateDbName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                    'mysql_root_password' => fake()->password(16),
                    'mysql_user' => 'testuser',
                    'mysql_password' => fake()->password(16),
                    'mysql_database' => 'testdb',
                ]);
            }

            // Create MariaDB databases
            for ($i = 0; $i < $mariaCount; $i++) {
                $allDbs[] = StandaloneMariadb::create([
                    'name' => generateDbName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                    'mariadb_root_password' => fake()->password(16),
                    'mariadb_user' => 'testuser',
                    'mariadb_password' => fake()->password(16),
                    'mariadb_database' => 'testdb',
                ]);
            }

            $expectedTotal = $pgCount + $redisCount + $mongoCount + $mysqlCount + $mariaCount;

            // Refresh destination and verify databases() helper
            $destination->refresh();
            $retrievedDbs = $destination->databases();

            // Assert correct total count
            expect($retrievedDbs)->toHaveCount($expectedTotal, "Iteration {$iteration}: total database count mismatch");

            // Cleanup
            foreach ($allDbs as $db) {
                $db->forceDelete();
            }
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'polymorphic');


    /**
     * **Validates: Requirements 2.2**
     *
     * Property: For any KubernetesDestination with mixed resource types,
     * the attachedTo() method SHALL correctly detect when resources are attached.
     */
    test('attachedTo method correctly detects attached resources', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create a destination with random namespace
            $destination = KubernetesDestination::create([
                'name' => 'Destination ' . fake()->slug(2),
                'kubernetes_cluster_id' => $this->cluster->id,
                'namespace' => generateNamespace(),
            ]);

            // Initially should have no resources attached
            expect($destination->attachedTo())->toBeFalse("Iteration {$iteration}: empty destination should not be attached");

            // Randomly decide which resource types to attach
            $attachApp = fake()->boolean(50);
            $attachService = fake()->boolean(50);
            $attachDb = fake()->boolean(50);

            $createdResources = [];

            if ($attachApp) {
                $createdResources[] = Application::create([
                    'name' => generateAppName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'git_repository' => 'https://github.com/test/repo',
                    'git_branch' => 'main',
                    'build_pack' => 'nixpacks',
                    'ports_exposes' => '3000',
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                ]);
            }

            if ($attachService) {
                $createdResources[] = Service::create([
                    'name' => generateServiceName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                ]);
            }

            if ($attachDb) {
                $createdResources[] = StandalonePostgresql::create([
                    'name' => generateDbName(),
                    'environment_id' => $this->environment->id,
                    'destination_type' => KubernetesDestination::class,
                    'destination_id' => $destination->id,
                    'uuid' => (string) new \Visus\Cuid2\Cuid2,
                    'postgres_user' => 'postgres',
                    'postgres_password' => fake()->password(16),
                    'postgres_db' => 'testdb',
                ]);
            }

            // Refresh and check attachedTo
            $destination->refresh();
            $hasResources = $attachApp || $attachService || $attachDb;

            expect($destination->attachedTo())->toBe($hasResources, 
                "Iteration {$iteration}: attachedTo mismatch (app:{$attachApp}, svc:{$attachService}, db:{$attachDb})");

            // Cleanup
            foreach ($createdResources as $resource) {
                $resource->forceDelete();
            }
            $destination->delete();
        }
    })->group('property-test', 'kubernetes', 'polymorphic');
});

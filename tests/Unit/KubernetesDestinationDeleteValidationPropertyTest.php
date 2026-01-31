<?php

/**
 * Property-Based Test: KubernetesDestination Delete Validation
 *
 * **Validates: Requirement 1.1 - Destination Delete Validatie**
 *
 * Property 5: Destination Delete Validatie
 * A KubernetesDestination SHALL NOT be deletable when it has linked resources
 * (applications, services, or databases).
 * A KubernetesDestination SHALL be deletable when no resources are attached.
 *
 * This test uses mocking to validate the canDelete() logic without database access.
 */

use App\Models\KubernetesDestination;
use App\Models\Application;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneClickhouse;
use Illuminate\Support\Collection;
use Mockery\MockInterface;

afterEach(function () {
    Mockery::close();
});

/**
 * Helper function to create a mock destination with configurable resources.
 *
 * @param  array{applications?: int, services?: int, postgresqls?: int, redis?: int, mongodbs?: int, mysqls?: int, mariadbs?: int, keydbs?: int, dragonflies?: int, clickhouses?: int}  $resources
 * @return MockInterface
 */
function createMockDestination(array $resources = []): MockInterface
{
    $destination = Mockery::mock(KubernetesDestination::class)->makePartial();

    // Create applications collection
    $applicationCount = $resources['applications'] ?? 0;
    $applications = collect(array_fill(0, $applicationCount, Mockery::mock(Application::class)));
    $destination->shouldReceive('getAttribute')->with('applications')->andReturn($applications);

    // Create services collection
    $serviceCount = $resources['services'] ?? 0;
    $services = collect(array_fill(0, $serviceCount, Mockery::mock(Service::class)));
    $destination->shouldReceive('getAttribute')->with('services')->andReturn($services);

    // Create database collections
    $postgresqls = collect(array_fill(0, $resources['postgresqls'] ?? 0, Mockery::mock(StandalonePostgresql::class)));
    $destination->shouldReceive('getAttribute')->with('postgresqls')->andReturn($postgresqls);

    $redis = collect(array_fill(0, $resources['redis'] ?? 0, Mockery::mock(StandaloneRedis::class)));
    $destination->shouldReceive('getAttribute')->with('redis')->andReturn($redis);

    $mongodbs = collect(array_fill(0, $resources['mongodbs'] ?? 0, Mockery::mock(StandaloneMongodb::class)));
    $destination->shouldReceive('getAttribute')->with('mongodbs')->andReturn($mongodbs);

    $mysqls = collect(array_fill(0, $resources['mysqls'] ?? 0, Mockery::mock(StandaloneMysql::class)));
    $destination->shouldReceive('getAttribute')->with('mysqls')->andReturn($mysqls);

    $mariadbs = collect(array_fill(0, $resources['mariadbs'] ?? 0, Mockery::mock(StandaloneMariadb::class)));
    $destination->shouldReceive('getAttribute')->with('mariadbs')->andReturn($mariadbs);

    $keydbs = collect(array_fill(0, $resources['keydbs'] ?? 0, Mockery::mock(StandaloneKeydb::class)));
    $destination->shouldReceive('getAttribute')->with('keydbs')->andReturn($keydbs);

    $dragonflies = collect(array_fill(0, $resources['dragonflies'] ?? 0, Mockery::mock(StandaloneDragonfly::class)));
    $destination->shouldReceive('getAttribute')->with('dragonflies')->andReturn($dragonflies);

    $clickhouses = collect(array_fill(0, $resources['clickhouses'] ?? 0, Mockery::mock(StandaloneClickhouse::class)));
    $destination->shouldReceive('getAttribute')->with('clickhouses')->andReturn($clickhouses);

    return $destination;
}

describe('Property 5: Destination Delete Validatie', function () {
    /**
     * Property: A destination with no resources SHALL be deletable.
     * When applications, services, and databases() are all empty, canDelete() SHALL return true.
     */
    test('destination without resources can be deleted', function () {
        $destination = createMockDestination();

        $result = $destination->canDelete();

        expect($result)->toBeTrue();
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A destination with applications SHALL NOT be deletable.
     */
    test('destination with applications cannot be deleted', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $applicationCount = fake()->numberBetween(1, 10);
            $destination = createMockDestination(['applications' => $applicationCount]);

            $result = $destination->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Destination with {$applicationCount} application(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A destination with services SHALL NOT be deletable.
     */
    test('destination with services cannot be deleted', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $serviceCount = fake()->numberBetween(1, 10);
            $destination = createMockDestination(['services' => $serviceCount]);

            $result = $destination->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Destination with {$serviceCount} service(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A destination with PostgreSQL databases SHALL NOT be deletable.
     */
    test('destination with PostgreSQL databases cannot be deleted', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $count = fake()->numberBetween(1, 5);
            $destination = createMockDestination(['postgresqls' => $count]);

            $result = $destination->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Destination with {$count} PostgreSQL database(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A destination with Redis instances SHALL NOT be deletable.
     */
    test('destination with Redis instances cannot be deleted', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $count = fake()->numberBetween(1, 5);
            $destination = createMockDestination(['redis' => $count]);

            $result = $destination->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Destination with {$count} Redis instance(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A destination with MongoDB databases SHALL NOT be deletable.
     */
    test('destination with MongoDB databases cannot be deleted', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $count = fake()->numberBetween(1, 5);
            $destination = createMockDestination(['mongodbs' => $count]);

            $result = $destination->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Destination with {$count} MongoDB database(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A destination with MySQL databases SHALL NOT be deletable.
     */
    test('destination with MySQL databases cannot be deleted', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $count = fake()->numberBetween(1, 5);
            $destination = createMockDestination(['mysqls' => $count]);

            $result = $destination->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Destination with {$count} MySQL database(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A destination with MariaDB databases SHALL NOT be deletable.
     */
    test('destination with MariaDB databases cannot be deleted', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $count = fake()->numberBetween(1, 5);
            $destination = createMockDestination(['mariadbs' => $count]);

            $result = $destination->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Destination with {$count} MariaDB database(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A destination with KeyDB instances SHALL NOT be deletable.
     */
    test('destination with KeyDB instances cannot be deleted', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $count = fake()->numberBetween(1, 5);
            $destination = createMockDestination(['keydbs' => $count]);

            $result = $destination->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Destination with {$count} KeyDB instance(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A destination with Dragonfly instances SHALL NOT be deletable.
     */
    test('destination with Dragonfly instances cannot be deleted', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $count = fake()->numberBetween(1, 5);
            $destination = createMockDestination(['dragonflies' => $count]);

            $result = $destination->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Destination with {$count} Dragonfly instance(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A destination with ClickHouse databases SHALL NOT be deletable.
     */
    test('destination with ClickHouse databases cannot be deleted', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $count = fake()->numberBetween(1, 5);
            $destination = createMockDestination(['clickhouses' => $count]);

            $result = $destination->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Destination with {$count} ClickHouse database(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A destination with multiple resource types SHALL NOT be deletable.
     */
    test('destination with mixed resources cannot be deleted', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $resources = [
                'applications' => fake()->numberBetween(0, 3),
                'services' => fake()->numberBetween(0, 3),
                'postgresqls' => fake()->numberBetween(0, 2),
                'redis' => fake()->numberBetween(0, 2),
                'mongodbs' => fake()->numberBetween(0, 2),
                'mysqls' => fake()->numberBetween(0, 2),
            ];

            // Ensure at least one resource exists
            $hasResources = collect($resources)->sum() > 0;
            if (! $hasResources) {
                $resources['applications'] = 1;
            }

            $destination = createMockDestination($resources);

            $result = $destination->canDelete();

            $totalResources = collect($resources)->sum();
            expect($result)->toBeFalse("Iteration {$iteration}: Destination with {$totalResources} mixed resource(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: getDeleteBlockedReason() SHALL return null when canDelete() is true.
     */
    test('delete blocked reason is null when destination can be deleted', function () {
        $destination = createMockDestination();

        $result = $destination->getDeleteBlockedReason();

        expect($result)->toBeNull();
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: getDeleteBlockedReason() SHALL include application count when applications exist.
     */
    test('delete blocked reason shows application count', function () {
        for ($iteration = 0; $iteration < 10; $iteration++) {
            $count = fake()->numberBetween(1, 10);
            $destination = createMockDestination(['applications' => $count]);

            $result = $destination->getDeleteBlockedReason();

            expect($result)->not->toBeNull()
                ->and($result)->toContain('Cannot delete destination')
                ->and($result)->toContain("{$count} application(s)");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: getDeleteBlockedReason() SHALL include service count when services exist.
     */
    test('delete blocked reason shows service count', function () {
        for ($iteration = 0; $iteration < 10; $iteration++) {
            $count = fake()->numberBetween(1, 10);
            $destination = createMockDestination(['services' => $count]);

            $result = $destination->getDeleteBlockedReason();

            expect($result)->not->toBeNull()
                ->and($result)->toContain("{$count} service(s)");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: getDeleteBlockedReason() SHALL include database count when databases exist.
     */
    test('delete blocked reason shows database count', function () {
        for ($iteration = 0; $iteration < 10; $iteration++) {
            // Add various database types
            $resources = [
                'postgresqls' => fake()->numberBetween(1, 3),
                'redis' => fake()->numberBetween(0, 2),
                'mysqls' => fake()->numberBetween(0, 2),
            ];

            $totalDatabases = $resources['postgresqls'] + $resources['redis'] + $resources['mysqls'];

            $destination = createMockDestination($resources);

            $result = $destination->getDeleteBlockedReason();

            expect($result)->not->toBeNull()
                ->and($result)->toContain("{$totalDatabases} database(s)");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: getDeleteBlockedReason() SHALL list all resource types when multiple exist.
     */
    test('delete blocked reason lists all resource types', function () {
        $destination = createMockDestination([
            'applications' => 2,
            'services' => 3,
            'postgresqls' => 1,
            'redis' => 1,
        ]);

        $result = $destination->getDeleteBlockedReason();

        expect($result)->not->toBeNull()
            ->and($result)->toContain('2 application(s)')
            ->and($result)->toContain('3 service(s)')
            ->and($result)->toContain('2 database(s)'); // 1 postgresql + 1 redis

        Mockery::close();
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: attachedTo() SHALL return true when any resource is attached.
     */
    test('attachedTo returns true when applications exist', function () {
        for ($iteration = 0; $iteration < 10; $iteration++) {
            $count = fake()->numberBetween(1, 5);
            $destination = createMockDestination(['applications' => $count]);

            $result = $destination->attachedTo();

            expect($result)->toBeTrue("Iteration {$iteration}: attachedTo should be true with {$count} application(s)");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: attachedTo() SHALL return true when any database is attached.
     */
    test('attachedTo returns true when databases exist', function () {
        for ($iteration = 0; $iteration < 10; $iteration++) {
            $destination = createMockDestination([
                'postgresqls' => fake()->numberBetween(1, 3),
            ]);

            $result = $destination->attachedTo();

            expect($result)->toBeTrue("Iteration {$iteration}: attachedTo should be true with databases");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: attachedTo() SHALL return true when services are attached.
     */
    test('attachedTo returns true when services exist', function () {
        for ($iteration = 0; $iteration < 10; $iteration++) {
            $count = fake()->numberBetween(1, 5);
            $destination = createMockDestination(['services' => $count]);

            $result = $destination->attachedTo();

            expect($result)->toBeTrue("Iteration {$iteration}: attachedTo should be true with {$count} service(s)");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: attachedTo() SHALL return false when no resources are attached.
     */
    test('attachedTo returns false when no resources exist', function () {
        $destination = createMockDestination();

        $result = $destination->attachedTo();

        expect($result)->toBeFalse();
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: canDelete() and attachedTo() SHALL be inversely related for simple cases.
     * When attachedTo() is true, canDelete() SHALL be false, and vice versa.
     */
    test('canDelete and attachedTo are inversely related', function () {
        // Test with no resources
        $emptyDestination = createMockDestination();
        expect($emptyDestination->attachedTo())->toBeFalse();
        expect($emptyDestination->canDelete())->toBeTrue();

        Mockery::close();

        // Test with applications
        $withApps = createMockDestination(['applications' => 2]);
        expect($withApps->attachedTo())->toBeTrue();
        expect($withApps->canDelete())->toBeFalse();

        Mockery::close();

        // Test with services
        $withServices = createMockDestination(['services' => 1]);
        expect($withServices->attachedTo())->toBeTrue();
        expect($withServices->canDelete())->toBeFalse();

        Mockery::close();

        // Test with databases
        $withDatabases = createMockDestination(['postgresqls' => 1, 'redis' => 1]);
        expect($withDatabases->attachedTo())->toBeTrue();
        expect($withDatabases->canDelete())->toBeFalse();
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: Random resource combinations should consistently prevent deletion.
     */
    test('random resource combinations prevent deletion consistently', function () {
        $databaseTypes = ['postgresqls', 'redis', 'mongodbs', 'mysqls', 'mariadbs', 'keydbs', 'dragonflies', 'clickhouses'];

        for ($iteration = 0; $iteration < 30; $iteration++) {
            $resources = [];

            // Randomly add resources
            if (fake()->boolean(30)) {
                $resources['applications'] = fake()->numberBetween(1, 5);
            }
            if (fake()->boolean(30)) {
                $resources['services'] = fake()->numberBetween(1, 5);
            }

            // Randomly add database types
            foreach ($databaseTypes as $dbType) {
                if (fake()->boolean(20)) {
                    $resources[$dbType] = fake()->numberBetween(1, 3);
                }
            }

            $hasResources = collect($resources)->sum() > 0;
            $destination = createMockDestination($resources);

            $canDelete = $destination->canDelete();
            $attachedTo = $destination->attachedTo();

            if ($hasResources) {
                expect($canDelete)->toBeFalse("Iteration {$iteration}: Should not be deletable with resources")
                    ->and($attachedTo)->toBeTrue("Iteration {$iteration}: Should show as attached");
            } else {
                expect($canDelete)->toBeTrue("Iteration {$iteration}: Should be deletable without resources")
                    ->and($attachedTo)->toBeFalse("Iteration {$iteration}: Should not show as attached");
            }

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');
});

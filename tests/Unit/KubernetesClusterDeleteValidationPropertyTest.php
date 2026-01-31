<?php

/**
 * Property-Based Test: KubernetesCluster Delete Validation
 *
 * **Validates: Requirement 1.1 - Cluster Delete Validatie**
 *
 * Property 2: Cluster Delete Validatie
 * A KubernetesCluster SHALL NOT be deletable when it has destinations with active resources.
 * A KubernetesCluster SHALL be deletable when all destinations are empty or no destinations exist.
 *
 * This test uses mocking to validate the canDelete() logic without database access.
 */

use App\Models\KubernetesCluster;
use App\Models\KubernetesDestination;
use Illuminate\Support\Collection;
use Mockery\MockInterface;

afterEach(function () {
    Mockery::close();
});

describe('Property 2: Cluster Delete Validatie', function () {
    /**
     * Property: A cluster with no destinations SHALL be deletable.
     * When destinations is an empty collection, canDelete() SHALL return true.
     */
    test('cluster without destinations can be deleted', function () {
        // Create a partial mock of KubernetesCluster
        $cluster = Mockery::mock(KubernetesCluster::class)->makePartial();

        // Mock the destinations relation to return empty collection
        $cluster->shouldReceive('getAttribute')
            ->with('destinations')
            ->andReturn(collect([]));

        // The canDelete method should return true when no destinations exist
        $result = $cluster->canDelete();

        expect($result)->toBeTrue();
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A cluster where ALL destinations can be deleted SHALL be deletable.
     * When every destination's canDelete() returns true, cluster's canDelete() SHALL return true.
     */
    test('cluster with empty destinations can be deleted', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create random number of destinations (1-5)
            $destinationCount = fake()->numberBetween(1, 5);
            $destinations = collect();

            for ($i = 0; $i < $destinationCount; $i++) {
                $destination = Mockery::mock(KubernetesDestination::class);
                // All destinations are empty (can be deleted)
                $destination->shouldReceive('canDelete')->andReturn(true);
                $destinations->push($destination);
            }

            // Create a partial mock of KubernetesCluster
            $cluster = Mockery::mock(KubernetesCluster::class)->makePartial();
            $cluster->shouldReceive('getAttribute')
                ->with('destinations')
                ->andReturn($destinations);

            $result = $cluster->canDelete();

            expect($result)->toBeTrue("Iteration {$iteration}: Cluster with {$destinationCount} empty destination(s) should be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A cluster where ANY destination has resources SHALL NOT be deletable.
     * When any destination's canDelete() returns false, cluster's canDelete() SHALL return false.
     */
    test('cluster with active destination cannot be deleted', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create random number of destinations (2-5)
            $destinationCount = fake()->numberBetween(2, 5);
            // Randomly select which destination has resources
            $activeDestinationIndex = fake()->numberBetween(0, $destinationCount - 1);

            $destinations = collect();

            for ($i = 0; $i < $destinationCount; $i++) {
                $destination = Mockery::mock(KubernetesDestination::class);
                // Only one destination has resources (cannot be deleted)
                $canDelete = ($i !== $activeDestinationIndex);
                $destination->shouldReceive('canDelete')->andReturn($canDelete);
                $destinations->push($destination);
            }

            // Create a partial mock of KubernetesCluster
            $cluster = Mockery::mock(KubernetesCluster::class)->makePartial();
            $cluster->shouldReceive('getAttribute')
                ->with('destinations')
                ->andReturn($destinations);

            $result = $cluster->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Cluster with destination at index {$activeDestinationIndex} having resources should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A cluster where MULTIPLE destinations have resources SHALL NOT be deletable.
     * This verifies the short-circuit logic works correctly.
     */
    test('cluster with multiple active destinations cannot be deleted', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Create random number of destinations (3-6)
            $destinationCount = fake()->numberBetween(3, 6);
            // Randomly select how many destinations have resources (2+)
            $activeCount = fake()->numberBetween(2, $destinationCount);

            $destinations = collect();

            for ($i = 0; $i < $destinationCount; $i++) {
                $destination = Mockery::mock(KubernetesDestination::class);
                // First $activeCount destinations have resources
                $canDelete = ($i >= $activeCount);
                $destination->shouldReceive('canDelete')->andReturn($canDelete);
                $destinations->push($destination);
            }

            // Create a partial mock of KubernetesCluster
            $cluster = Mockery::mock(KubernetesCluster::class)->makePartial();
            $cluster->shouldReceive('getAttribute')
                ->with('destinations')
                ->andReturn($destinations);

            $result = $cluster->canDelete();

            expect($result)->toBeFalse("Iteration {$iteration}: Cluster with {$activeCount} active destination(s) should NOT be deletable");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: The getDeleteBlockedReason() SHALL return null when canDelete() is true.
     */
    test('delete blocked reason is null when cluster can be deleted', function () {
        for ($iteration = 0; $iteration < 10; $iteration++) {
            // Create cluster with empty destinations
            $destinationCount = fake()->numberBetween(0, 3);
            $destinations = collect();

            for ($i = 0; $i < $destinationCount; $i++) {
                $destination = Mockery::mock(KubernetesDestination::class);
                $destination->shouldReceive('canDelete')->andReturn(true);
                $destinations->push($destination);
            }

            $cluster = Mockery::mock(KubernetesCluster::class)->makePartial();
            $cluster->shouldReceive('getAttribute')
                ->with('destinations')
                ->andReturn($destinations);

            $result = $cluster->getDeleteBlockedReason();

            expect($result)->toBeNull("Iteration {$iteration}: Reason should be null when cluster can be deleted");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: The getDeleteBlockedReason() SHALL return a descriptive message when canDelete() is false.
     * The message SHALL include the count of destinations with resources.
     */
    test('delete blocked reason shows count of blocking destinations', function () {
        for ($iteration = 0; $iteration < 10; $iteration++) {
            // Create cluster with some active destinations
            $destinationCount = fake()->numberBetween(2, 5);
            $activeCount = fake()->numberBetween(1, $destinationCount);

            $destinations = collect();

            for ($i = 0; $i < $destinationCount; $i++) {
                $destination = Mockery::mock(KubernetesDestination::class);
                // First $activeCount destinations have resources
                $canDelete = ($i >= $activeCount);
                $destination->shouldReceive('canDelete')->andReturn($canDelete);
                $destinations->push($destination);
            }

            $cluster = Mockery::mock(KubernetesCluster::class)->makePartial();
            $cluster->shouldReceive('getAttribute')
                ->with('destinations')
                ->andReturn($destinations);

            $result = $cluster->getDeleteBlockedReason();

            expect($result)->not->toBeNull()
                ->and($result)->toContain('Cannot delete cluster')
                ->and($result)->toContain("{$activeCount} destination(s)");

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: Single destination blocking deletion should be reported correctly.
     */
    test('single blocking destination is reported correctly', function () {
        $destination = Mockery::mock(KubernetesDestination::class);
        $destination->shouldReceive('canDelete')->andReturn(false);

        $cluster = Mockery::mock(KubernetesCluster::class)->makePartial();
        $cluster->shouldReceive('getAttribute')
            ->with('destinations')
            ->andReturn(collect([$destination]));

        $result = $cluster->getDeleteBlockedReason();

        expect($result)->not->toBeNull()
            ->and($result)->toContain('1 destination(s)');

        Mockery::close();
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: canDelete() SHALL short-circuit on first non-deletable destination.
     * This tests that we don't unnecessarily check all destinations.
     */
    test('canDelete short-circuits on first non-deletable destination', function () {
        // First destination cannot be deleted
        $destination1 = Mockery::mock(KubernetesDestination::class);
        $destination1->shouldReceive('canDelete')->once()->andReturn(false);

        // Second destination should NOT be called (short-circuit)
        $destination2 = Mockery::mock(KubernetesDestination::class);
        $destination2->shouldReceive('canDelete')->never();

        $cluster = Mockery::mock(KubernetesCluster::class)->makePartial();
        $cluster->shouldReceive('getAttribute')
            ->with('destinations')
            ->andReturn(collect([$destination1, $destination2]));

        $result = $cluster->canDelete();

        expect($result)->toBeFalse();

        // Mockery will verify that destination2->canDelete() was never called
    })->group('property-test', 'kubernetes', 'delete-validation');

    /**
     * Property: A mixed scenario with empty and non-empty destinations should not be deletable.
     */
    test('mixed destinations with some having resources prevents deletion', function () {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            // Create destinations with alternating state
            $destinationCount = fake()->numberBetween(3, 7);
            $destinations = collect();

            $hasActiveDestination = false;
            for ($i = 0; $i < $destinationCount; $i++) {
                $destination = Mockery::mock(KubernetesDestination::class);
                // Every other destination has resources
                $canDelete = ($i % 2 === 0);
                if (! $canDelete) {
                    $hasActiveDestination = true;
                }
                $destination->shouldReceive('canDelete')->andReturn($canDelete);
                $destinations->push($destination);
            }

            $cluster = Mockery::mock(KubernetesCluster::class)->makePartial();
            $cluster->shouldReceive('getAttribute')
                ->with('destinations')
                ->andReturn($destinations);

            $result = $cluster->canDelete();

            if ($hasActiveDestination) {
                expect($result)->toBeFalse("Iteration {$iteration}: Mixed destinations should prevent deletion");
            } else {
                expect($result)->toBeTrue("Iteration {$iteration}: All empty destinations should allow deletion");
            }

            Mockery::close();
        }
    })->group('property-test', 'kubernetes', 'delete-validation');
});

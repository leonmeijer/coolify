<?php

/**
 * Property-Based Test: Docker Compose to Kubernetes Conversion
 *
 * **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5**
 *
 * Property: Docker Compose Conversion Correctheid
 * For any valid Docker Compose configuration, the fromDockerCompose() method SHALL
 * correctly convert services to Kubernetes Deployments, volumes to PVCs, and
 * environment variables to ConfigMaps/Secrets.
 *
 * This test uses property-based testing principles with random data generation
 * to verify that the Docker Compose to Kubernetes conversion is correct and idempotent.
 *
 * Requirements covered:
 * - 7.1: Docker Compose services SHALL be converted to Kubernetes Deployments + Services
 * - 7.2: Docker Compose volumes SHALL be converted to PersistentVolumeClaims
 * - 7.3: Docker Compose environment variables SHALL be split into ConfigMaps/Secrets
 * - 7.4: Unsupported features SHALL generate warnings
 * - 7.5: Conversion SHALL be idempotent
 */

use App\Services\KubernetesManifestGenerator;
use Symfony\Component\Yaml\Yaml;

// =========================================================================
// Generator Functions
// =========================================================================

/**
 * Generate a random valid container image name.
 */
function generateRandomImage(): string
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
 * Generate a random Docker Compose service name.
 */
function generateServiceName(): string
{
    $prefixes = ['web', 'api', 'db', 'cache', 'worker', 'app', 'backend', 'frontend'];

    return fake()->randomElement($prefixes) . '-' . fake()->slug(1);
}

/**
 * Generate a random port number.
 */
function generatePort(): int
{
    return fake()->numberBetween(80, 65535);
}

/**
 * Generate a random port mapping string.
 */
function generatePortMapping(): string
{
    $containerPort = generatePort();
    $formats = [
        (string) $containerPort,
        generatePort() . ':' . $containerPort,
        '127.0.0.1:' . generatePort() . ':' . $containerPort,
    ];

    $mapping = fake()->randomElement($formats);

    // Occasionally add protocol
    if (fake()->boolean(20)) {
        $mapping .= '/' . fake()->randomElement(['tcp', 'udp']);
    }

    return $mapping;
}

/**
 * Generate a random non-sensitive environment variable key.
 */
function generateNonSensitiveKey(): string
{
    $prefixes = ['APP', 'NODE', 'LOG', 'DEBUG', 'PORT', 'HOST', 'ENV', 'CONFIG', 'MODE'];

    return fake()->randomElement($prefixes) . '_' . strtoupper(fake()->slug(1));
}

/**
 * Generate a random sensitive environment variable key.
 */
function generateSensitiveKey(): string
{
    $patterns = ['PASSWORD', 'SECRET', 'TOKEN', 'API_KEY', 'PRIVATE_KEY', 'AUTH_TOKEN', 'DB_PASSWORD'];

    return fake()->randomElement($patterns) . '_' . strtoupper(fake()->slug(1));
}

/**
 * Generate a random environment variable value.
 */
function generateEnvVarValue(): string
{
    return fake()->randomElement([
        fake()->word(),
        fake()->url(),
        fake()->ipv4(),
        (string) fake()->numberBetween(1, 10000),
        fake()->sha256(),
    ]);
}

/**
 * Generate a random volume name.
 */
function generateVolumeName(): string
{
    return fake()->slug(2) . '-data';
}

/**
 * Generate a random mount path.
 */
function generateMountPath(): string
{
    $paths = ['/data', '/var/data', '/app/storage', '/uploads', '/logs', '/cache', '/config'];

    return fake()->randomElement($paths) . '/' . fake()->slug(1);
}

/**
 * Generate a simple Docker Compose YAML with random configuration.
 */
function generateSimpleDockerCompose(array $options = []): string
{
    $serviceName = $options['service_name'] ?? generateServiceName();
    $image = $options['image'] ?? generateRandomImage();

    $compose = [
        'version' => '3.8',
        'services' => [
            $serviceName => [
                'image' => $image,
            ],
        ],
    ];

    // Add ports if requested
    if ($options['with_ports'] ?? true) {
        $portCount = fake()->numberBetween(1, 3);
        $ports = [];
        for ($i = 0; $i < $portCount; $i++) {
            $ports[] = generatePortMapping();
        }
        $compose['services'][$serviceName]['ports'] = $ports;
    }

    // Add environment variables if requested
    if ($options['with_env'] ?? false) {
        $envVars = [];

        // Add non-sensitive
        $nonSensitiveCount = fake()->numberBetween(1, 3);
        for ($i = 0; $i < $nonSensitiveCount; $i++) {
            $envVars[generateNonSensitiveKey()] = generateEnvVarValue();
        }

        // Add sensitive
        $sensitiveCount = fake()->numberBetween(1, 2);
        for ($i = 0; $i < $sensitiveCount; $i++) {
            $envVars[generateSensitiveKey()] = generateEnvVarValue();
        }

        $compose['services'][$serviceName]['environment'] = $envVars;
    }

    // Add volumes if requested
    if ($options['with_volumes'] ?? false) {
        $volumeCount = fake()->numberBetween(1, 2);
        $volumes = [];
        $topLevelVolumes = [];

        for ($i = 0; $i < $volumeCount; $i++) {
            $volumeName = generateVolumeName();
            $mountPath = generateMountPath();
            $volumes[] = $volumeName . ':' . $mountPath;
            $topLevelVolumes[$volumeName] = null;
        }

        $compose['services'][$serviceName]['volumes'] = $volumes;
        $compose['volumes'] = $topLevelVolumes;
    }

    // Add deploy resources if requested
    if ($options['with_resources'] ?? false) {
        $compose['services'][$serviceName]['deploy'] = [
            'replicas' => fake()->numberBetween(1, 5),
            'resources' => [
                'limits' => [
                    'cpus' => (string) (fake()->numberBetween(1, 4) / 10),
                    'memory' => fake()->randomElement(['128M', '256M', '512M', '1G']),
                ],
                'reservations' => [
                    'cpus' => (string) (fake()->numberBetween(1, 2) / 10),
                    'memory' => fake()->randomElement(['64M', '128M', '256M']),
                ],
            ],
        ];
    }

    // Add healthcheck if requested
    if ($options['with_healthcheck'] ?? false) {
        $compose['services'][$serviceName]['healthcheck'] = [
            'test' => ['CMD', 'curl', '-f', 'http://localhost/health'],
            'interval' => fake()->randomElement(['10s', '30s', '1m']),
            'timeout' => fake()->randomElement(['5s', '10s', '30s']),
            'retries' => fake()->numberBetween(3, 5),
            'start_period' => fake()->randomElement(['5s', '10s', '30s']),
        ];
    }

    return Yaml::dump($compose, 10, 2);
}

/**
 * Generate a multi-service Docker Compose YAML.
 */
function generateMultiServiceDockerCompose(): string
{
    $serviceCount = fake()->numberBetween(2, 4);
    $compose = [
        'version' => '3.8',
        'services' => [],
        'volumes' => [],
    ];

    for ($i = 0; $i < $serviceCount; $i++) {
        $serviceName = generateServiceName() . '-' . $i;
        $compose['services'][$serviceName] = [
            'image' => generateRandomImage(),
            'ports' => [generatePortMapping()],
        ];

        // Add a volume to some services
        if (fake()->boolean(50)) {
            $volumeName = generateVolumeName() . '-' . $i;
            $compose['services'][$serviceName]['volumes'] = [
                $volumeName . ':' . generateMountPath(),
            ];
            $compose['volumes'][$volumeName] = null;
        }
    }

    return Yaml::dump($compose, 10, 2);
}

// =========================================================================
// Property Tests
// =========================================================================

describe('Property 7: Docker Compose Conversion Correctheid', function () {
    /**
     * **Validates: Requirements 7.1**
     *
     * Property: For any Docker Compose service with an image, the converter
     * SHALL generate a Kubernetes Deployment with the correct image.
     */
    test('services are correctly converted to deployments', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $serviceName = generateServiceName();
            $image = generateRandomImage();
            $compose = generateSimpleDockerCompose([
                'service_name' => $serviceName,
                'image' => $image,
                'with_ports' => true,
            ]);

            // Convert to Kubernetes
            $result = KubernetesManifestGenerator::fromDockerCompose($compose, 'test-ns');

            // Property 1: Result SHALL have manifests
            expect($result)->toHaveKey('manifests', "Iteration {$iteration}: Result should have manifests");

            // Property 2: Manifests SHALL have at least one Deployment
            expect($result['manifests'])->toHaveKey('Deployment', "Iteration {$iteration}: Should have Deployment manifests");
            expect($result['manifests']['Deployment'])->not->toBeEmpty("Iteration {$iteration}: Deployment manifests should not be empty");

            // Property 3: Deployment SHALL have correct image
            $deployment = $result['manifests']['Deployment'][0];
            expect($deployment['kind'])->toBe('Deployment', "Iteration {$iteration}: Kind should be Deployment");
            expect($deployment['metadata']['namespace'])->toBe('test-ns', "Iteration {$iteration}: Namespace should match");

            $container = $deployment['spec']['template']['spec']['containers'][0];
            expect($container['image'])->toBe($image, "Iteration {$iteration}: Container image should match");

            // Property 4: Deployment SHALL have correct labels
            expect($deployment['metadata']['labels'])->toHaveKey('app.kubernetes.io/name');
            expect($deployment['metadata']['labels'])->toHaveKey('app.kubernetes.io/managed-by');
            expect($deployment['metadata']['labels']['app.kubernetes.io/managed-by'])->toBe('coolify');
        }
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * **Validates: Requirements 7.1**
     *
     * Property: For any Docker Compose service with ports, the converter
     * SHALL generate a Kubernetes Service with correct port mappings.
     */
    test('ports are correctly converted to service ports', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $containerPort = generatePort();
            $compose = generateSimpleDockerCompose([
                'with_ports' => false,
            ]);

            // Parse and add specific port
            $parsed = Yaml::parse($compose);
            $serviceName = array_key_first($parsed['services']);
            $parsed['services'][$serviceName]['ports'] = [(string) $containerPort];
            $compose = Yaml::dump($parsed, 10, 2);

            // Convert to Kubernetes
            $result = KubernetesManifestGenerator::fromDockerCompose($compose, 'test-ns');

            // Property 1: Manifests SHALL have Service
            expect($result['manifests'])->toHaveKey('Service', "Iteration {$iteration}: Should have Service manifests");
            expect($result['manifests']['Service'])->not->toBeEmpty("Iteration {$iteration}: Service manifests should not be empty");

            // Property 2: Service SHALL have correct ports
            $service = $result['manifests']['Service'][0];
            expect($service['kind'])->toBe('Service', "Iteration {$iteration}: Kind should be Service");
            expect($service['spec']['type'])->toBe('ClusterIP', "Iteration {$iteration}: Type should be ClusterIP");

            $ports = $service['spec']['ports'];
            $containerPorts = array_column($ports, 'port');
            expect($containerPorts)->toContain($containerPort, "Iteration {$iteration}: Service should contain port {$containerPort}");

            // Property 3: Service selector SHALL match Deployment labels
            $deployment = $result['manifests']['Deployment'][0];
            $serviceSelector = $service['spec']['selector'];
            $deploymentMatchLabels = $deployment['spec']['selector']['matchLabels'];

            foreach ($serviceSelector as $key => $value) {
                expect($deploymentMatchLabels)->toHaveKey($key, "Iteration {$iteration}: Deployment should have matching selector label");
                expect($deploymentMatchLabels[$key])->toBe($value, "Iteration {$iteration}: Selector values should match");
            }
        }
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * **Validates: Requirements 7.2**
     *
     * Property: For any Docker Compose service with named volumes, the converter
     * SHALL generate PersistentVolumeClaims.
     */
    test('volumes are converted to persistent volume claims', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $volumeName = generateVolumeName();
            $mountPath = generateMountPath();

            $compose = [
                'version' => '3.8',
                'services' => [
                    'app' => [
                        'image' => generateRandomImage(),
                        'volumes' => [
                            $volumeName . ':' . $mountPath,
                        ],
                    ],
                ],
                'volumes' => [
                    $volumeName => null,
                ],
            ];

            $composeYaml = Yaml::dump($compose, 10, 2);

            // Convert to Kubernetes
            $result = KubernetesManifestGenerator::fromDockerCompose($composeYaml, 'test-ns', [
                'storage_class' => 'standard',
            ]);

            // Property 1: Manifests SHALL have PersistentVolumeClaim
            expect($result['manifests'])->toHaveKey('PersistentVolumeClaim', "Iteration {$iteration}: Should have PVC manifests");
            expect($result['manifests']['PersistentVolumeClaim'])->not->toBeEmpty("Iteration {$iteration}: PVC manifests should not be empty");

            // Property 2: PVC SHALL have correct configuration
            $pvc = $result['manifests']['PersistentVolumeClaim'][0];
            expect($pvc['kind'])->toBe('PersistentVolumeClaim', "Iteration {$iteration}: Kind should be PersistentVolumeClaim");
            expect($pvc['metadata']['namespace'])->toBe('test-ns', "Iteration {$iteration}: Namespace should match");
            expect($pvc['spec']['accessModes'])->toContain('ReadWriteOnce', "Iteration {$iteration}: Should have ReadWriteOnce access mode");
            expect($pvc['spec']['storageClassName'])->toBe('standard', "Iteration {$iteration}: Storage class should match option");

            // Property 3: Deployment SHALL have volume mount
            $deployment = $result['manifests']['Deployment'][0];
            $container = $deployment['spec']['template']['spec']['containers'][0];
            expect($container)->toHaveKey('volumeMounts', "Iteration {$iteration}: Container should have volume mounts");

            $mountPaths = array_column($container['volumeMounts'], 'mountPath');
            expect($mountPaths)->toContain($mountPath, "Iteration {$iteration}: Should have mount path {$mountPath}");

            // Property 4: Deployment SHALL have volume definition
            expect($deployment['spec']['template']['spec'])->toHaveKey('volumes', "Iteration {$iteration}: Pod spec should have volumes");
        }
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * **Validates: Requirements 7.3**
     *
     * Property: For any Docker Compose service with environment variables,
     * the converter SHALL split them into ConfigMaps (non-sensitive) and Secrets (sensitive).
     */
    test('environment variables are split correctly', function () {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $nonSensitiveKey = generateNonSensitiveKey();
            $nonSensitiveValue = generateEnvVarValue();
            $sensitiveKey = generateSensitiveKey();
            $sensitiveValue = generateEnvVarValue();

            $compose = [
                'version' => '3.8',
                'services' => [
                    'app' => [
                        'image' => generateRandomImage(),
                        'environment' => [
                            $nonSensitiveKey => $nonSensitiveValue,
                            $sensitiveKey => $sensitiveValue,
                        ],
                    ],
                ],
            ];

            $composeYaml = Yaml::dump($compose, 10, 2);

            // Convert to Kubernetes
            $result = KubernetesManifestGenerator::fromDockerCompose($composeYaml, 'test-ns');

            // Property 1: Manifests SHALL have ConfigMap
            expect($result['manifests'])->toHaveKey('ConfigMap', "Iteration {$iteration}: Should have ConfigMap manifests");
            $configMap = $result['manifests']['ConfigMap'][0];
            expect($configMap['kind'])->toBe('ConfigMap', "Iteration {$iteration}: Kind should be ConfigMap");

            // Property 2: ConfigMap SHALL contain non-sensitive variable
            expect($configMap['data'])->toHaveKey($nonSensitiveKey, "Iteration {$iteration}: ConfigMap should have non-sensitive key");
            expect($configMap['data'][$nonSensitiveKey])->toBe($nonSensitiveValue, "Iteration {$iteration}: ConfigMap value should match");

            // Property 3: ConfigMap SHALL NOT contain sensitive variable
            expect($configMap['data'])->not->toHaveKey($sensitiveKey, "Iteration {$iteration}: ConfigMap should not have sensitive key");

            // Property 4: Manifests SHALL have Secret
            expect($result['manifests'])->toHaveKey('Secret', "Iteration {$iteration}: Should have Secret manifests");
            $secret = $result['manifests']['Secret'][0];
            expect($secret['kind'])->toBe('Secret', "Iteration {$iteration}: Kind should be Secret");
            expect($secret['type'])->toBe('Opaque', "Iteration {$iteration}: Secret type should be Opaque");

            // Property 5: Secret SHALL contain sensitive variable (base64 encoded)
            expect($secret['data'])->toHaveKey($sensitiveKey, "Iteration {$iteration}: Secret should have sensitive key");
            expect($secret['data'][$sensitiveKey])->toBe(base64_encode($sensitiveValue), "Iteration {$iteration}: Secret value should be base64 encoded");

            // Property 6: Secret SHALL NOT contain non-sensitive variable
            expect($secret['data'])->not->toHaveKey($nonSensitiveKey, "Iteration {$iteration}: Secret should not have non-sensitive key");

            // Property 7: Deployment SHALL reference both ConfigMap and Secret
            $deployment = $result['manifests']['Deployment'][0];
            $container = $deployment['spec']['template']['spec']['containers'][0];
            expect($container)->toHaveKey('envFrom', "Iteration {$iteration}: Container should have envFrom");

            $envFromTypes = array_map(function ($ef) {
                return array_key_first($ef);
            }, $container['envFrom']);

            expect($envFromTypes)->toContain('configMapRef', "Iteration {$iteration}: Should have configMapRef");
            expect($envFromTypes)->toContain('secretRef', "Iteration {$iteration}: Should have secretRef");
        }
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * **Validates: Requirements 7.4**
     *
     * Property: For any Docker Compose service with unsupported features,
     * the converter SHALL generate appropriate warnings.
     */
    test('unsupported features generate warnings', function () {
        // Test network_mode: host
        $compose = [
            'version' => '3.8',
            'services' => [
                'app' => [
                    'image' => 'nginx:latest',
                    'network_mode' => 'host',
                ],
            ],
        ];
        $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2));
        expect($result['warnings'])->not->toBeEmpty('Should have warning for network_mode: host');
        expect(implode(' ', $result['warnings']))->toContain('network_mode');

        // Test privileged: true
        $compose['services']['app'] = [
            'image' => 'nginx:latest',
            'privileged' => true,
        ];
        $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2));
        expect($result['warnings'])->not->toBeEmpty('Should have warning for privileged: true');
        expect(implode(' ', $result['warnings']))->toContain('privileged');

        // Test cap_add
        $compose['services']['app'] = [
            'image' => 'nginx:latest',
            'cap_add' => ['NET_ADMIN'],
        ];
        $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2));
        expect($result['warnings'])->not->toBeEmpty('Should have warning for cap_add');
        expect(implode(' ', $result['warnings']))->toContain('cap_add');

        // Test depends_on
        $compose['services']['app'] = [
            'image' => 'nginx:latest',
            'depends_on' => ['db'],
        ];
        $compose['services']['db'] = [
            'image' => 'postgres:latest',
        ];
        $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2));
        expect($result['warnings'])->not->toBeEmpty('Should have warning for depends_on');
        expect(implode(' ', $result['warnings']))->toContain('depends_on');

        // Test build directive
        $compose = [
            'version' => '3.8',
            'services' => [
                'app' => [
                    'build' => './app',
                    'image' => 'myapp:latest',
                ],
            ],
        ];
        $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2));
        expect($result['warnings'])->not->toBeEmpty('Should have warning for build directive');
        expect(implode(' ', $result['warnings']))->toContain('build');

        // Test bind mount
        $compose = [
            'version' => '3.8',
            'services' => [
                'app' => [
                    'image' => 'nginx:latest',
                    'volumes' => [
                        './data:/app/data',
                    ],
                ],
            ],
        ];
        $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2));
        expect($result['warnings'])->not->toBeEmpty('Should have warning for bind mount');
        expect(implode(' ', $result['warnings']))->toContain('Bind mount');
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * **Validates: Requirements 7.5**
     *
     * Property: Converting the same Docker Compose file twice SHALL produce
     * identical manifests (idempotent conversion).
     */
    test('conversion is idempotent', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            // Generate a random Docker Compose file
            $compose = generateSimpleDockerCompose([
                'with_ports' => true,
                'with_env' => true,
                'with_volumes' => true,
                'with_resources' => true,
            ]);

            // Convert twice
            $result1 = KubernetesManifestGenerator::fromDockerCompose($compose, 'test-ns');
            $result2 = KubernetesManifestGenerator::fromDockerCompose($compose, 'test-ns');

            // Property: Both conversions SHALL produce identical manifests
            // Compare JSON representations to check deep equality
            $json1 = json_encode($result1['manifests'], JSON_SORT_KEYS);
            $json2 = json_encode($result2['manifests'], JSON_SORT_KEYS);

            expect($json1)->toBe($json2, "Iteration {$iteration}: Conversion should be idempotent");

            // Property: Warnings SHALL also be identical
            expect($result1['warnings'])->toBe($result2['warnings'], "Iteration {$iteration}: Warnings should be identical");
        }
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * **Validates: Requirements 7.1**
     *
     * Property: For any Docker Compose file with multiple services,
     * the converter SHALL generate one Deployment per service.
     */
    test('multiple services are converted correctly', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $compose = generateMultiServiceDockerCompose();
            $parsed = Yaml::parse($compose);
            $serviceCount = count($parsed['services']);

            // Convert to Kubernetes
            $result = KubernetesManifestGenerator::fromDockerCompose($compose, 'test-ns');

            // Property 1: Should have exactly as many Deployments as services
            expect($result['manifests']['Deployment'])->toHaveCount($serviceCount, "Iteration {$iteration}: Should have {$serviceCount} Deployments");

            // Property 2: Should have exactly as many Services as services (assuming all have ports)
            expect($result['manifests']['Service'])->toHaveCount($serviceCount, "Iteration {$iteration}: Should have {$serviceCount} Services");

            // Property 3: Each Deployment SHALL have a unique name
            $deploymentNames = array_map(function ($d) {
                return $d['metadata']['name'];
            }, $result['manifests']['Deployment']);
            expect(count($deploymentNames))->toBe(count(array_unique($deploymentNames)), "Iteration {$iteration}: Deployment names should be unique");
        }
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * **Validates: Requirements 7.1**
     *
     * Property: For any Docker Compose service with deploy.replicas,
     * the converter SHALL set the correct replica count in the Deployment.
     */
    test('replicas are correctly set from deploy section', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $replicas = fake()->numberBetween(1, 10);

            $compose = [
                'version' => '3.8',
                'services' => [
                    'app' => [
                        'image' => generateRandomImage(),
                        'deploy' => [
                            'replicas' => $replicas,
                        ],
                    ],
                ],
            ];

            $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2), 'test-ns');

            // Property: Deployment SHALL have correct replica count
            $deployment = $result['manifests']['Deployment'][0];
            expect($deployment['spec']['replicas'])->toBe($replicas, "Iteration {$iteration}: Replicas should be {$replicas}");
        }
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * **Validates: Requirements 7.1**
     *
     * Property: For any Docker Compose service with deploy.resources,
     * the converter SHALL set correct resource limits and requests.
     */
    test('resource limits are correctly converted', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $cpuLimit = (string) (fake()->numberBetween(1, 10) / 10);
            $memoryLimit = fake()->randomElement(['128M', '256M', '512M', '1G']);

            $compose = [
                'version' => '3.8',
                'services' => [
                    'app' => [
                        'image' => generateRandomImage(),
                        'deploy' => [
                            'resources' => [
                                'limits' => [
                                    'cpus' => $cpuLimit,
                                    'memory' => $memoryLimit,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2), 'test-ns');

            // Property: Container SHALL have resource limits
            $container = $result['manifests']['Deployment'][0]['spec']['template']['spec']['containers'][0];
            expect($container)->toHaveKey('resources', "Iteration {$iteration}: Container should have resources");
            expect($container['resources'])->toHaveKey('limits', "Iteration {$iteration}: Resources should have limits");

            // CPU should be converted to millicores if < 1
            $expectedCpu = (float) $cpuLimit < 1
                ? (int) ((float) $cpuLimit * 1000) . 'm'
                : $cpuLimit;
            expect($container['resources']['limits']['cpu'])->toBe($expectedCpu, "Iteration {$iteration}: CPU limit should be converted correctly");
        }
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * **Validates: Requirements 7.1**
     *
     * Property: For any Docker Compose service with healthcheck,
     * the converter SHALL generate Kubernetes probes.
     */
    test('healthcheck is converted to probes', function () {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $compose = [
                'version' => '3.8',
                'services' => [
                    'app' => [
                        'image' => generateRandomImage(),
                        'healthcheck' => [
                            'test' => ['CMD', 'curl', '-f', 'http://localhost/health'],
                            'interval' => '30s',
                            'timeout' => '10s',
                            'retries' => 3,
                            'start_period' => '5s',
                        ],
                    ],
                ],
            ];

            $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2), 'test-ns');

            // Property 1: Container SHALL have liveness probe
            $container = $result['manifests']['Deployment'][0]['spec']['template']['spec']['containers'][0];
            expect($container)->toHaveKey('livenessProbe', "Iteration {$iteration}: Container should have livenessProbe");
            expect($container['livenessProbe']['exec']['command'])->toContain('curl', "Iteration {$iteration}: Probe command should contain curl");

            // Property 2: Container SHALL have readiness probe
            expect($container)->toHaveKey('readinessProbe', "Iteration {$iteration}: Container should have readinessProbe");

            // Property 3: Probe SHALL have correct timing values
            expect($container['livenessProbe']['periodSeconds'])->toBe(30, "Iteration {$iteration}: Interval should be 30s");
            expect($container['livenessProbe']['timeoutSeconds'])->toBe(10, "Iteration {$iteration}: Timeout should be 10s");
            expect($container['livenessProbe']['failureThreshold'])->toBe(3, "Iteration {$iteration}: Retries should be 3");
            expect($container['livenessProbe']['initialDelaySeconds'])->toBe(5, "Iteration {$iteration}: Start period should be 5s");
        }
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * **Validates: Requirements 7.1**
     *
     * Property: For any Docker Compose service with command/entrypoint,
     * the converter SHALL set args/command in the container spec.
     */
    test('command and entrypoint are correctly mapped', function () {
        // Test entrypoint (becomes command in K8s)
        $compose = [
            'version' => '3.8',
            'services' => [
                'app' => [
                    'image' => 'nginx:latest',
                    'entrypoint' => ['/custom-entrypoint.sh'],
                ],
            ],
        ];

        $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2), 'test-ns');
        $container = $result['manifests']['Deployment'][0]['spec']['template']['spec']['containers'][0];
        expect($container)->toHaveKey('command', 'Entrypoint should become command');
        expect($container['command'])->toContain('/custom-entrypoint.sh');

        // Test command (becomes args in K8s)
        $compose = [
            'version' => '3.8',
            'services' => [
                'app' => [
                    'image' => 'nginx:latest',
                    'command' => ['nginx', '-g', 'daemon off;'],
                ],
            ],
        ];

        $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2), 'test-ns');
        $container = $result['manifests']['Deployment'][0]['spec']['template']['spec']['containers'][0];
        expect($container)->toHaveKey('args', 'Command should become args');
        expect($container['args'])->toContain('nginx');
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * Error handling test: Invalid YAML should throw exception.
     */
    test('invalid yaml throws exception', function () {
        $invalidYaml = "this is not: valid: yaml: {{{}}}";

        expect(function () use ($invalidYaml) {
            KubernetesManifestGenerator::fromDockerCompose($invalidYaml);
        })->toThrow(\InvalidArgumentException::class);
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * Error handling test: Missing services section should throw exception.
     */
    test('missing services section throws exception', function () {
        $compose = Yaml::dump(['version' => '3.8']);

        expect(function () use ($compose) {
            KubernetesManifestGenerator::fromDockerCompose($compose);
        })->toThrow(\InvalidArgumentException::class);
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * Test: Environment variables in list format are handled correctly.
     */
    test('environment variables in list format are parsed', function () {
        $compose = [
            'version' => '3.8',
            'services' => [
                'app' => [
                    'image' => 'nginx:latest',
                    'environment' => [
                        'APP_ENV=production',
                        'DEBUG_MODE=false',
                        'DB_PASSWORD=secret123',
                    ],
                ],
            ],
        ];

        $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2), 'test-ns');

        // ConfigMap should have non-sensitive vars
        $configMap = $result['manifests']['ConfigMap'][0];
        expect($configMap['data'])->toHaveKey('APP_ENV');
        expect($configMap['data']['APP_ENV'])->toBe('production');
        expect($configMap['data'])->toHaveKey('DEBUG_MODE');

        // Secret should have sensitive var
        $secret = $result['manifests']['Secret'][0];
        expect($secret['data'])->toHaveKey('DB_PASSWORD');
        expect($secret['data']['DB_PASSWORD'])->toBe(base64_encode('secret123'));
    })->group('property-test', 'kubernetes', 'docker-compose');

    /**
     * Test: Default namespace and options work correctly.
     */
    test('default options are applied correctly', function () {
        $compose = [
            'version' => '3.8',
            'services' => [
                'app' => [
                    'image' => 'nginx:latest',
                ],
            ],
        ];

        // Without options
        $result = KubernetesManifestGenerator::fromDockerCompose(Yaml::dump($compose, 10, 2));
        $deployment = $result['manifests']['Deployment'][0];
        expect($deployment['metadata']['namespace'])->toBe('default', 'Default namespace should be "default"');
        expect($deployment['spec']['replicas'])->toBe(1, 'Default replicas should be 1');

        // With custom options
        $result = KubernetesManifestGenerator::fromDockerCompose(
            Yaml::dump($compose, 10, 2),
            'custom-ns',
            ['default_replicas' => 3]
        );
        $deployment = $result['manifests']['Deployment'][0];
        expect($deployment['metadata']['namespace'])->toBe('custom-ns');
        expect($deployment['spec']['replicas'])->toBe(3);
    })->group('property-test', 'kubernetes', 'docker-compose');
});

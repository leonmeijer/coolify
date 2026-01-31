<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\KubernetesDestination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class KubernetesPods extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public array $pods = [];

    public array $events = [];

    public ?string $selectedPodName = null;

    public ?string $selectedContainerName = null;

    public string $podLogs = '';

    public bool $isLoadingPods = false;

    public bool $isLoadingLogs = false;

    public bool $isLoadingEvents = false;

    public ?string $error = null;

    public function getListeners()
    {
        return [
            'refreshPods' => 'loadPods',
        ];
    }

    public function mount(): void
    {
        $this->authorize('view', $this->application);
        $this->loadPods();
    }

    #[Computed]
    public function isKubernetesDestination(): bool
    {
        return $this->application->destination instanceof KubernetesDestination;
    }

    #[Computed]
    public function destination(): ?KubernetesDestination
    {
        if (! $this->isKubernetesDestination) {
            return null;
        }

        return $this->application->destination;
    }

    #[Computed]
    public function cluster()
    {
        return $this->destination?->cluster;
    }

    #[Computed]
    public function namespace(): string
    {
        return $this->destination?->namespace ?? 'default';
    }

    public function loadPods(): void
    {
        if (! $this->isKubernetesDestination) {
            return;
        }

        $this->isLoadingPods = true;
        $this->error = null;
        $this->pods = [];

        try {
            $client = $this->cluster->getClient();

            // Get pods with the application label
            $labelSelector = [
                'app.kubernetes.io/name' => $this->application->uuid,
            ];

            $pods = $client->getPods($this->namespace, $labelSelector);

            $this->pods = collect($pods)->map(function ($pod) {
                return $this->formatPod($pod);
            })->toArray();

            // Load events as well
            $this->loadEvents();
        } catch (\Throwable $e) {
            $this->error = 'Failed to load pods: '.$e->getMessage();
            $this->dispatch('error', $this->error);
        } finally {
            $this->isLoadingPods = false;
        }
    }

    private function formatPod(array $pod): array
    {
        $metadata = $pod['metadata'] ?? [];
        $status = $pod['status'] ?? [];
        $spec = $pod['spec'] ?? [];

        $containers = collect($spec['containers'] ?? [])->map(function ($container) use ($status) {
            $containerStatuses = collect($status['containerStatuses'] ?? []);
            $containerStatus = $containerStatuses->firstWhere('name', $container['name']);

            return [
                'name' => $container['name'],
                'image' => $container['image'] ?? 'unknown',
                'ready' => $containerStatus['ready'] ?? false,
                'restartCount' => $containerStatus['restartCount'] ?? 0,
                'state' => $this->getContainerState($containerStatus['state'] ?? []),
            ];
        })->toArray();

        $phase = $status['phase'] ?? 'Unknown';
        $conditions = collect($status['conditions'] ?? []);
        $readyCondition = $conditions->firstWhere('type', 'Ready');
        $isReady = ($readyCondition['status'] ?? 'False') === 'True';

        return [
            'name' => $metadata['name'] ?? 'unknown',
            'namespace' => $metadata['namespace'] ?? 'default',
            'phase' => $phase,
            'isReady' => $isReady,
            'podIP' => $status['podIP'] ?? null,
            'hostIP' => $status['hostIP'] ?? null,
            'nodeName' => $spec['nodeName'] ?? null,
            'startTime' => $status['startTime'] ?? null,
            'containers' => $containers,
            'conditions' => $status['conditions'] ?? [],
        ];
    }

    private function getContainerState(array $state): string
    {
        if (isset($state['running'])) {
            return 'Running';
        }

        if (isset($state['waiting'])) {
            return 'Waiting: '.($state['waiting']['reason'] ?? 'Unknown');
        }

        if (isset($state['terminated'])) {
            return 'Terminated: '.($state['terminated']['reason'] ?? 'Unknown');
        }

        return 'Unknown';
    }

    public function loadEvents(): void
    {
        if (! $this->isKubernetesDestination) {
            return;
        }

        $this->isLoadingEvents = true;
        $this->events = [];

        try {
            $client = $this->cluster->getClient();

            // Get events related to pods with our application label
            $allEvents = $client->getEvents($this->namespace);

            // Filter events related to our pods
            $podNames = collect($this->pods)->pluck('name')->toArray();

            $this->events = collect($allEvents)
                ->filter(function ($event) use ($podNames) {
                    $involvedObject = $event['involvedObject'] ?? [];
                    $objectName = $involvedObject['name'] ?? '';

                    return in_array($objectName, $podNames) ||
                           str_contains($objectName, $this->application->uuid);
                })
                ->map(function ($event) {
                    return [
                        'type' => $event['type'] ?? 'Unknown',
                        'reason' => $event['reason'] ?? 'Unknown',
                        'message' => $event['message'] ?? '',
                        'object' => ($event['involvedObject']['kind'] ?? 'Unknown').'/'.($event['involvedObject']['name'] ?? 'unknown'),
                        'firstTimestamp' => $event['firstTimestamp'] ?? null,
                        'lastTimestamp' => $event['lastTimestamp'] ?? null,
                        'count' => $event['count'] ?? 1,
                    ];
                })
                ->sortByDesc('lastTimestamp')
                ->take(20)
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            $this->dispatch('warning', 'Failed to load events: '.$e->getMessage());
        } finally {
            $this->isLoadingEvents = false;
        }
    }

    public function selectPod(string $podName, ?string $containerName = null): void
    {
        $this->selectedPodName = $podName;
        $this->selectedContainerName = $containerName;
        $this->podLogs = '';

        if ($containerName === null) {
            // Select the first container if none specified
            $pod = collect($this->pods)->firstWhere('name', $podName);
            if ($pod && ! empty($pod['containers'])) {
                $this->selectedContainerName = $pod['containers'][0]['name'];
            }
        }

        $this->loadLogs();
    }

    public function loadLogs(): void
    {
        if (! $this->isKubernetesDestination || ! $this->selectedPodName) {
            return;
        }

        $this->isLoadingLogs = true;
        $this->podLogs = '';

        try {
            $client = $this->cluster->getClient();

            $logs = $client->getPodLogs(
                $this->selectedPodName,
                $this->namespace,
                $this->selectedContainerName
            );

            $this->podLogs = $logs;
        } catch (\Throwable $e) {
            $this->podLogs = 'Failed to load logs: '.$e->getMessage();
            $this->dispatch('error', 'Failed to load logs: '.$e->getMessage());
        } finally {
            $this->isLoadingLogs = false;
        }
    }

    public function clearSelection(): void
    {
        $this->selectedPodName = null;
        $this->selectedContainerName = null;
        $this->podLogs = '';
    }

    public function getStatusColor(string $phase): string
    {
        return match ($phase) {
            'Running' => 'success',
            'Pending' => 'warning',
            'Succeeded' => 'success',
            'Failed' => 'error',
            'Unknown' => 'neutral',
            default => 'neutral',
        };
    }

    public function render()
    {
        return view('livewire.project.application.kubernetes-pods');
    }
}

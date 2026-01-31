<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\KubernetesDeploymentSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class KubernetesSettings extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public ?KubernetesDeploymentSettings $settings = null;

    #[Validate(['required', 'integer', 'min:1', 'max:100'])]
    public int $replicas = 1;

    #[Validate(['required', 'string', 'regex:/^\d+m?$/'])]
    public string $cpuLimit = '500m';

    #[Validate(['required', 'string', 'regex:/^\d+(Mi|Gi)?$/'])]
    public string $memoryLimit = '512Mi';

    #[Validate(['required', 'string', 'regex:/^\d+m?$/'])]
    public string $cpuRequest = '100m';

    #[Validate(['required', 'string', 'regex:/^\d+(Mi|Gi)?$/'])]
    public string $memoryRequest = '128Mi';

    #[Validate(['boolean'])]
    public bool $autoscalingEnabled = false;

    #[Validate(['required_if:autoscalingEnabled,true', 'integer', 'min:1', 'max:100'])]
    public int $minReplicas = 1;

    #[Validate(['required_if:autoscalingEnabled,true', 'integer', 'min:1', 'max:100'])]
    public int $maxReplicas = 3;

    #[Validate(['required_if:autoscalingEnabled,true', 'integer', 'min:1', 'max:100'])]
    public int $targetCpuUtilization = 80;

    #[Validate(['nullable', 'integer', 'min:1', 'max:100'])]
    public ?int $targetMemoryUtilization = null;

    protected function rules(): array
    {
        return [
            'replicas' => ['required', 'integer', 'min:1', 'max:100'],
            'cpuLimit' => ['required', 'string', 'regex:/^\d+m?$/'],
            'memoryLimit' => ['required', 'string', 'regex:/^\d+(Mi|Gi)?$/'],
            'cpuRequest' => ['required', 'string', 'regex:/^\d+m?$/'],
            'memoryRequest' => ['required', 'string', 'regex:/^\d+(Mi|Gi)?$/'],
            'autoscalingEnabled' => ['boolean'],
            'minReplicas' => ['required_if:autoscalingEnabled,true', 'integer', 'min:1', 'max:100'],
            'maxReplicas' => ['required_if:autoscalingEnabled,true', 'integer', 'min:1', 'max:100'],
            'targetCpuUtilization' => ['required_if:autoscalingEnabled,true', 'integer', 'min:1', 'max:100'],
            'targetMemoryUtilization' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function messages(): array
    {
        return [
            'replicas.required' => 'The number of replicas is required.',
            'replicas.min' => 'At least 1 replica is required.',
            'cpuLimit.regex' => 'Invalid CPU limit format. Use format like "500m" or "1".',
            'memoryLimit.regex' => 'Invalid memory limit format. Use format like "512Mi" or "1Gi".',
            'cpuRequest.regex' => 'Invalid CPU request format. Use format like "100m" or "1".',
            'memoryRequest.regex' => 'Invalid memory request format. Use format like "128Mi" or "1Gi".',
            'minReplicas.required_if' => 'Minimum replicas is required when autoscaling is enabled.',
            'maxReplicas.required_if' => 'Maximum replicas is required when autoscaling is enabled.',
            'maxReplicas.min' => 'Maximum replicas must be at least 1.',
            'targetCpuUtilization.required_if' => 'Target CPU utilization is required when autoscaling is enabled.',
        ];
    }

    public function mount(): void
    {
        $this->authorize('view', $this->application);
        $this->loadSettings();
    }

    private function loadSettings(): void
    {
        // Load or create Kubernetes deployment settings
        $this->settings = KubernetesDeploymentSettings::firstOrCreate(
            ['application_id' => $this->application->id],
            [
                'replicas' => $this->getDefaultReplicas(),
                'cpu_limit' => $this->getDefaultCpuLimit(),
                'memory_limit' => $this->getDefaultMemoryLimit(),
                'cpu_request' => $this->getDefaultCpuRequest(),
                'memory_request' => $this->getDefaultMemoryRequest(),
                'autoscaling_enabled' => false,
                'min_replicas' => 1,
                'max_replicas' => 3,
                'target_cpu_utilization' => 80,
                'target_memory_utilization' => null,
            ]
        );

        $this->syncFromModel();
    }

    private function getDefaultReplicas(): int
    {
        $destination = $this->application->destination;

        if ($destination && method_exists($destination, 'cluster')) {
            return $destination->default_replicas ?? 1;
        }

        return 1;
    }

    private function getDefaultCpuLimit(): string
    {
        $destination = $this->application->destination;

        if ($destination && property_exists($destination, 'default_cpu_limit')) {
            return $destination->default_cpu_limit ?? '500m';
        }

        return '500m';
    }

    private function getDefaultMemoryLimit(): string
    {
        $destination = $this->application->destination;

        if ($destination && property_exists($destination, 'default_memory_limit')) {
            return $destination->default_memory_limit ?? '512Mi';
        }

        return '512Mi';
    }

    private function getDefaultCpuRequest(): string
    {
        $destination = $this->application->destination;

        if ($destination && property_exists($destination, 'default_cpu_request')) {
            return $destination->default_cpu_request ?? '100m';
        }

        return '100m';
    }

    private function getDefaultMemoryRequest(): string
    {
        $destination = $this->application->destination;

        if ($destination && property_exists($destination, 'default_memory_request')) {
            return $destination->default_memory_request ?? '128Mi';
        }

        return '128Mi';
    }

    private function syncFromModel(): void
    {
        $this->replicas = $this->settings->replicas;
        $this->cpuLimit = $this->settings->cpu_limit;
        $this->memoryLimit = $this->settings->memory_limit;
        $this->cpuRequest = $this->settings->cpu_request;
        $this->memoryRequest = $this->settings->memory_request;
        $this->autoscalingEnabled = $this->settings->autoscaling_enabled;
        $this->minReplicas = $this->settings->min_replicas;
        $this->maxReplicas = $this->settings->max_replicas;
        $this->targetCpuUtilization = $this->settings->target_cpu_utilization;
        $this->targetMemoryUtilization = $this->settings->target_memory_utilization;
    }

    private function syncToModel(): void
    {
        $this->settings->replicas = $this->replicas;
        $this->settings->cpu_limit = $this->cpuLimit;
        $this->settings->memory_limit = $this->memoryLimit;
        $this->settings->cpu_request = $this->cpuRequest;
        $this->settings->memory_request = $this->memoryRequest;
        $this->settings->autoscaling_enabled = $this->autoscalingEnabled;
        $this->settings->min_replicas = $this->minReplicas;
        $this->settings->max_replicas = $this->maxReplicas;
        $this->settings->target_cpu_utilization = $this->targetCpuUtilization;
        $this->settings->target_memory_utilization = $this->targetMemoryUtilization;
        $this->settings->save();
    }

    public function updatedAutoscalingEnabled(): void
    {
        if ($this->autoscalingEnabled) {
            // Ensure min/max replicas make sense
            if ($this->minReplicas > $this->maxReplicas) {
                $this->maxReplicas = $this->minReplicas;
            }
        }
    }

    public function submit(): void
    {
        try {
            $this->authorize('update', $this->application);
            $this->validate();

            // Validate that min <= max for autoscaling
            if ($this->autoscalingEnabled && $this->minReplicas > $this->maxReplicas) {
                $this->dispatch('error', 'Minimum replicas cannot be greater than maximum replicas.');

                return;
            }

            $this->syncToModel();
            $this->dispatch('success', 'Kubernetes settings saved.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave(): void
    {
        try {
            $this->authorize('update', $this->application);
            $this->syncToModel();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.kubernetes-settings');
    }
}

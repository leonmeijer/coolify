<div>
    <x-slot:title>
        Add Destination - {{ $cluster->name }} | Kubernetes | Coolify
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('kubernetes.index') }}" {{ wireNavigate() }} class="flex items-center gap-1 text-sm text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                Kubernetes Clusters
            </a>
            <span class="text-neutral-500 dark:text-neutral-400">/</span>
            <a href="{{ route('kubernetes.show', ['uuid' => $cluster->uuid]) }}" {{ wireNavigate() }} class="text-sm text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                {{ $cluster->name }}
            </a>
            <span class="text-neutral-500 dark:text-neutral-400">/</span>
            <a href="{{ route('kubernetes.destinations', ['uuid' => $cluster->uuid]) }}" {{ wireNavigate() }} class="text-sm text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                Destinations
            </a>
            <span class="text-neutral-500 dark:text-neutral-400">/</span>
            <h1>Add Destination</h1>
        </div>

        <div class="subtitle">
            Create a new deployment destination for {{ $cluster->name }}.
        </div>

        <div class="max-w-2xl">
            <form wire:submit="save" class="flex flex-col gap-4">
                <div class="flex flex-col gap-4">
                    <h3 class="text-lg font-semibold">Basic Information</h3>

                    <x-forms.input
                        id="name"
                        label="Destination Name"
                        placeholder="Production"
                        required
                        helper="A friendly name to identify this destination."
                    />

                    @if (count($availableNamespaces) > 0)
                        <x-forms.select
                            id="namespace"
                            label="Namespace"
                            required
                            helper="The Kubernetes namespace for deployments."
                        >
                            <option value="">Select a namespace</option>
                            @foreach ($availableNamespaces as $ns)
                                <option value="{{ $ns }}">{{ $ns }}</option>
                            @endforeach
                        </x-forms.select>
                    @else
                        <x-forms.input
                            id="namespace"
                            label="Namespace"
                            placeholder="default"
                            required
                            helper="The Kubernetes namespace for deployments. Must be lowercase with hyphens only."
                        />
                    @endif

                    <div class="border-t dark:border-coolgray-300 my-2"></div>

                    <h3 class="text-lg font-semibold">Ingress & Storage</h3>

                    <x-forms.input
                        id="ingressClass"
                        label="Ingress Class"
                        placeholder="nginx"
                        helper="The ingress class to use for exposing applications (e.g., nginx, traefik, haproxy)."
                    />

                    <x-forms.input
                        id="storageClass"
                        label="Storage Class"
                        placeholder="standard"
                        helper="The storage class to use for persistent volumes."
                    />

                    <div class="border-t dark:border-coolgray-300 my-2"></div>

                    <h3 class="text-lg font-semibold">Default Resource Limits</h3>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400 -mt-2">
                        These defaults will be applied to new deployments in this destination.
                    </p>

                    <div class="grid gap-4 md:grid-cols-2">
                        <x-forms.input
                            id="defaultCpuLimit"
                            label="CPU Limit"
                            placeholder="500m"
                            required
                            helper="Maximum CPU allocation (e.g., 500m, 1, 2)"
                        />

                        <x-forms.input
                            id="defaultMemoryLimit"
                            label="Memory Limit"
                            placeholder="512Mi"
                            required
                            helper="Maximum memory allocation (e.g., 512Mi, 1Gi)"
                        />

                        <x-forms.input
                            id="defaultCpuRequest"
                            label="CPU Request"
                            placeholder="100m"
                            required
                            helper="Guaranteed CPU allocation (e.g., 100m, 0.5)"
                        />

                        <x-forms.input
                            id="defaultMemoryRequest"
                            label="Memory Request"
                            placeholder="128Mi"
                            required
                            helper="Guaranteed memory allocation (e.g., 128Mi, 256Mi)"
                        />
                    </div>

                    <x-forms.input
                        id="defaultReplicas"
                        label="Default Replicas"
                        type="number"
                        min="1"
                        max="100"
                        required
                        helper="Default number of pod replicas for new deployments."
                    />
                </div>

                <div class="border-t dark:border-coolgray-300 my-2"></div>

                <div class="flex justify-end gap-2">
                    <a href="{{ route('kubernetes.destinations', ['uuid' => $cluster->uuid]) }}" {{ wireNavigate() }}>
                        <x-forms.button type="button">
                            Cancel
                        </x-forms.button>
                    </a>
                    <x-forms.button type="submit" isHighlighted>
                        Create Destination
                    </x-forms.button>
                </div>
            </form>
        </div>
    </div>
</div>

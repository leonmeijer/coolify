<div>
    <x-slot:title>
        {{ $destination->name }} - {{ $cluster->name }} | Kubernetes | Coolify
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
            <h1>{{ $destination->name }}</h1>
        </div>

        <div class="flex items-center gap-2">
            <span class="px-2 py-0.5 text-xs rounded-full bg-coolgray-200 dark:bg-coolgray-400 font-mono">
                {{ $destination->namespace }}
            </span>
            @if ($destination->ingress_class)
                <span class="px-2 py-0.5 text-xs rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                    Ingress: {{ $destination->ingress_class }}
                </span>
            @endif
            @if ($destination->storage_class)
                <span class="px-2 py-0.5 text-xs rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300">
                    Storage: {{ $destination->storage_class }}
                </span>
            @endif
        </div>

        <div class="max-w-2xl">
            <form wire:submit="save" class="flex flex-col gap-4">
                <h3 class="text-lg font-semibold">Basic Information</h3>

                <x-forms.input
                    id="name"
                    label="Destination Name"
                    required
                    helper="A friendly name to identify this destination."
                    canGate="update"
                    :canResource="$destination"
                />

                <x-forms.input
                    id="namespace"
                    label="Namespace"
                    required
                    disabled
                    helper="The Kubernetes namespace cannot be changed after creation."
                />

                <div class="border-t dark:border-coolgray-300 my-2"></div>

                <h3 class="text-lg font-semibold">Ingress & Storage</h3>

                <x-forms.input
                    id="ingressClass"
                    label="Ingress Class"
                    placeholder="nginx"
                    helper="The ingress class to use for exposing applications."
                    canGate="update"
                    :canResource="$destination"
                />

                <x-forms.input
                    id="storageClass"
                    label="Storage Class"
                    placeholder="standard"
                    helper="The storage class to use for persistent volumes."
                    canGate="update"
                    :canResource="$destination"
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
                        canGate="update"
                        :canResource="$destination"
                    />

                    <x-forms.input
                        id="defaultMemoryLimit"
                        label="Memory Limit"
                        placeholder="512Mi"
                        required
                        helper="Maximum memory allocation (e.g., 512Mi, 1Gi)"
                        canGate="update"
                        :canResource="$destination"
                    />

                    <x-forms.input
                        id="defaultCpuRequest"
                        label="CPU Request"
                        placeholder="100m"
                        required
                        helper="Guaranteed CPU allocation (e.g., 100m, 0.5)"
                        canGate="update"
                        :canResource="$destination"
                    />

                    <x-forms.input
                        id="defaultMemoryRequest"
                        label="Memory Request"
                        placeholder="128Mi"
                        required
                        helper="Guaranteed memory allocation (e.g., 128Mi, 256Mi)"
                        canGate="update"
                        :canResource="$destination"
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
                    canGate="update"
                    :canResource="$destination"
                />

                <div class="border-t dark:border-coolgray-300 my-2"></div>

                <div class="flex justify-between gap-2">
                    <div>
                        @can('delete', $destination)
                            <x-modal-confirmation
                                title="Delete Destination"
                                buttonTitle="Delete Destination"
                                isErrorButton
                                action="delete"
                                :disabled="!$destination->canDelete()"
                            >
                                <p class="text-sm">
                                    Are you sure you want to delete this destination?
                                    This action cannot be undone.
                                </p>
                                @if (!$destination->canDelete())
                                    <p class="mt-2 text-sm text-error">
                                        {{ $destination->getDeleteBlockedReason() }}
                                    </p>
                                @endif
                            </x-modal-confirmation>
                        @endcan
                    </div>
                    <x-forms.button type="submit" isHighlighted canGate="update" :canResource="$destination">
                        Save Changes
                    </x-forms.button>
                </div>
            </form>

            {{-- Statistics --}}
            <div class="border-t dark:border-coolgray-300 my-6"></div>

            <h3 class="text-lg font-semibold mb-4">Resources</h3>
            <div class="grid grid-cols-3 gap-4">
                <div class="p-4 border rounded-lg dark:border-coolgray-300">
                    <div class="text-2xl font-bold">{{ $destination->applications->count() }}</div>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400">Applications</div>
                </div>
                <div class="p-4 border rounded-lg dark:border-coolgray-300">
                    <div class="text-2xl font-bold">{{ $destination->databases()->count() }}</div>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400">Databases</div>
                </div>
                <div class="p-4 border rounded-lg dark:border-coolgray-300">
                    <div class="text-2xl font-bold">{{ $destination->services->count() }}</div>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400">Services</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div>
    <x-slot:title>
        Destinations - {{ $cluster->name }} | Kubernetes | Coolify
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
            <h1>Destinations</h1>
        </div>

        <div class="subtitle">
            Manage deployment destinations (namespaces) for {{ $cluster->name }}.
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('manageDestinations', $cluster)
                <a href="{{ route('kubernetes.destinations.create', ['uuid' => $cluster->uuid]) }}" {{ wireNavigate() }}>
                    <x-forms.button>
                        + Add Destination
                    </x-forms.button>
                </a>
            @endcan
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            @forelse ($cluster->destinations as $destination)
                <div class="p-4 border rounded-lg dark:border-coolgray-300">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <h4 class="font-semibold text-lg">{{ $destination->name }}</h4>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="px-2 py-0.5 text-xs rounded-full bg-coolgray-200 dark:bg-coolgray-400 font-mono">
                                    {{ $destination->namespace }}
                                </span>
                                @if ($destination->ingress_class)
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                                        {{ $destination->ingress_class }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        @can('delete', $destination)
                            <x-modal-confirmation
                                title="Delete Destination"
                                buttonTitle="Delete"
                                isErrorButton
                                action="deleteDestination({{ $destination->id }})"
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

                    <div class="space-y-2 text-sm">
                        <div class="grid grid-cols-2 gap-2">
                            <div class="p-2 rounded bg-neutral-100 dark:bg-coolgray-200">
                                <div class="text-neutral-500 dark:text-neutral-400 text-xs">Applications</div>
                                <div class="font-semibold">{{ $destination->applications->count() }}</div>
                            </div>
                            <div class="p-2 rounded bg-neutral-100 dark:bg-coolgray-200">
                                <div class="text-neutral-500 dark:text-neutral-400 text-xs">Databases</div>
                                <div class="font-semibold">{{ $destination->databases()->count() }}</div>
                            </div>
                        </div>

                        <div class="border-t dark:border-coolgray-300 pt-2 mt-2">
                            <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">Resource Defaults</div>
                            <div class="grid grid-cols-2 gap-1 text-xs">
                                <div class="flex justify-between">
                                    <span class="text-neutral-500 dark:text-neutral-400">CPU Limit:</span>
                                    <span class="font-mono">{{ $destination->default_cpu_limit }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-neutral-500 dark:text-neutral-400">Memory Limit:</span>
                                    <span class="font-mono">{{ $destination->default_memory_limit }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-neutral-500 dark:text-neutral-400">CPU Request:</span>
                                    <span class="font-mono">{{ $destination->default_cpu_request }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-neutral-500 dark:text-neutral-400">Memory Request:</span>
                                    <span class="font-mono">{{ $destination->default_memory_request }}</span>
                                </div>
                            </div>
                            <div class="flex justify-between mt-1 text-xs">
                                <span class="text-neutral-500 dark:text-neutral-400">Default Replicas:</span>
                                <span class="font-mono">{{ $destination->default_replicas }}</span>
                            </div>
                        </div>

                        @if ($destination->storage_class)
                            <div class="flex justify-between text-xs">
                                <span class="text-neutral-500 dark:text-neutral-400">Storage Class:</span>
                                <span class="font-mono">{{ $destination->storage_class }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-2">
                    <div class="p-4 text-center border rounded-lg border-coolgray-200 dark:border-coolgray-400">
                        <div class="text-lg font-semibold">No destinations found</div>
                        <div class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                            Add a destination to start deploying applications to this cluster.
                        </div>
                        @can('manageDestinations', $cluster)
                            <div class="mt-4">
                                <a href="{{ route('kubernetes.destinations.create', ['uuid' => $cluster->uuid]) }}" {{ wireNavigate() }}>
                                    <x-forms.button>
                                        + Add Destination
                                    </x-forms.button>
                                </a>
                            </div>
                        @endcan
                    </div>
                </div>
            @endforelse
        </div>
    </div>
</div>

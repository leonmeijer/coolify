<div>
    <x-slot:title>
        {{ $cluster->name }} | Kubernetes | Coolify
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
            <h1 class="flex items-center gap-2">
                {{ $cluster->name }}
                <span @class([
                    'px-2 py-0.5 text-xs rounded-full',
                    'bg-success/20 text-success' => $cluster->is_reachable,
                    'bg-error/20 text-error' => !$cluster->is_reachable,
                ])>
                    {{ $cluster->is_reachable ? 'Connected' : 'Unreachable' }}
                </span>
                <span class="px-2 py-0.5 text-xs rounded-full bg-coolgray-200 dark:bg-coolgray-400">
                    {{ ucfirst($cluster->cluster_type) }}
                </span>
            </h1>
        </div>

        @if ($cluster->description)
            <div class="text-sm text-neutral-500 dark:text-neutral-400">
                {{ $cluster->description }}
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            @can('testConnection', $cluster)
                <x-forms.button wire:click="testConnection">
                    Test Connection
                </x-forms.button>
            @endcan

            <a href="{{ route('kubernetes.destinations', ['uuid' => $cluster->uuid]) }}" {{ wireNavigate() }}>
                <x-forms.button>
                    Manage Destinations ({{ $cluster->destinations->count() }})
                </x-forms.button>
            </a>

            @can('update', $cluster)
                <x-modal-input buttonTitle="Edit Cluster" title="Edit Kubernetes Cluster" :closeOutside="false">
                    <livewire:kubernetes.form :cluster="$cluster" />
                </x-modal-input>
            @endcan

            @can('delete', $cluster)
                <x-modal-confirmation
                    title="Delete Cluster"
                    buttonTitle="Delete"
                    isErrorButton
                    action="delete"
                    :disabled="!$cluster->canDelete()"
                >
                    <p class="text-sm">
                        Are you sure you want to delete this Kubernetes cluster?
                        This action cannot be undone.
                    </p>
                    @if (!$cluster->canDelete())
                        <p class="mt-2 text-sm text-error">
                            {{ $cluster->getDeleteBlockedReason() }}
                        </p>
                    @endif
                </x-modal-confirmation>
            @endcan
        </div>

        <div class="border-t dark:border-coolgray-300 my-4"></div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="p-4 border rounded-lg dark:border-coolgray-300">
                <h3 class="text-lg font-semibold mb-3">Cluster Information</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-neutral-500 dark:text-neutral-400">API Server:</span>
                        <span class="font-mono">{{ $cluster->api_server_url ?? 'From kubeconfig' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-neutral-500 dark:text-neutral-400">Context:</span>
                        <span>{{ $cluster->context_name ?? 'Default' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-neutral-500 dark:text-neutral-400">Default Namespace:</span>
                        <span>{{ $cluster->default_namespace ?? 'default' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-neutral-500 dark:text-neutral-400">Destinations:</span>
                        <span>{{ $cluster->destinations->count() }}</span>
                    </div>
                    @if ($cluster->last_checked_at)
                        <div class="flex justify-between">
                            <span class="text-neutral-500 dark:text-neutral-400">Last Checked:</span>
                            <span>{{ $cluster->last_checked_at->diffForHumans() }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="p-4 border rounded-lg dark:border-coolgray-300">
                <h3 class="text-lg font-semibold mb-3">Quick Actions</h3>
                <div class="space-y-2">
                    <a href="{{ route('kubernetes.destinations', ['uuid' => $cluster->uuid]) }}" {{ wireNavigate() }}
                        class="flex items-center gap-2 p-2 rounded-lg hover:bg-neutral-100 dark:hover:bg-coolgray-200 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        <div>
                            <div class="font-medium">Manage Destinations</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">Configure namespaces for deployments</div>
                        </div>
                    </a>
                    @can('create', App\Models\KubernetesDestination::class)
                        <a href="{{ route('kubernetes.destinations.create', ['uuid' => $cluster->uuid]) }}" {{ wireNavigate() }}
                            class="flex items-center gap-2 p-2 rounded-lg hover:bg-neutral-100 dark:hover:bg-coolgray-200 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            <div>
                                <div class="font-medium">Add Destination</div>
                                <div class="text-xs text-neutral-500 dark:text-neutral-400">Create a new deployment destination</div>
                            </div>
                        </a>
                    @endcan
                </div>
            </div>
        </div>

        @if ($cluster->destinations->count() > 0)
            <div class="border-t dark:border-coolgray-300 my-4"></div>
            <h3 class="text-lg font-semibold">Destinations</h3>
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($cluster->destinations as $destination)
                    <div class="p-4 border rounded-lg dark:border-coolgray-300">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-semibold">{{ $destination->name }}</h4>
                            <span class="px-2 py-0.5 text-xs rounded-full bg-coolgray-200 dark:bg-coolgray-400">
                                {{ $destination->namespace }}
                            </span>
                        </div>
                        <div class="space-y-1 text-sm text-neutral-500 dark:text-neutral-400">
                            <div class="flex justify-between">
                                <span>Applications:</span>
                                <span>{{ $destination->applications->count() }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Databases:</span>
                                <span>{{ $destination->databases()->count() }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Services:</span>
                                <span>{{ $destination->services->count() }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

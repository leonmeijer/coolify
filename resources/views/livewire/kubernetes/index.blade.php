<div>
    <x-slot:title>
        Kubernetes Clusters | Coolify
    </x-slot>
    <div class="flex items-center gap-2">
        <h1>Kubernetes Clusters</h1>
        @can('create', App\Models\KubernetesCluster::class)
            <x-modal-input buttonTitle="+ Add" title="New Kubernetes Cluster" :closeOutside="false">
                <livewire:kubernetes.form />
            </x-modal-input>
        @endcan
    </div>
    <div class="subtitle">Manage your Kubernetes clusters for container orchestration.</div>
    <div class="grid gap-4 lg:grid-cols-2 -mt-1">
        @forelse ($clusters as $cluster)
            <a href="{{ route('kubernetes.show', ['uuid' => data_get($cluster, 'uuid')]) }}" {{ wireNavigate() }}
                @class([
                    'gap-2 border cursor-pointer coolbox group',
                    'border-red-500' => !$cluster->is_reachable,
                ])>
                <div class="flex flex-col justify-center mx-6">
                    <div class="flex items-center gap-2">
                        <div class="font-bold dark:text-white">
                            {{ $cluster->name }}
                        </div>
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
                    </div>
                    <div class="description">
                        {{ $cluster->description ?? 'No description' }}
                    </div>
                    <div class="flex gap-2 text-xs text-neutral-500 dark:text-neutral-400 mt-1">
                        @if ($cluster->destinations->count() > 0)
                            <span>{{ $cluster->destinations->count() }} destination(s)</span>
                        @else
                            <span>No destinations configured</span>
                        @endif
                        @if ($cluster->last_checked_at)
                            <span>Last checked: {{ $cluster->last_checked_at->diffForHumans() }}</span>
                        @endif
                    </div>
                </div>
                <div class="flex-1"></div>
            </a>
        @empty
            <div class="col-span-2">
                <div class="p-4 text-center border rounded-lg border-coolgray-200 dark:border-coolgray-400">
                    <div class="text-lg font-semibold">No Kubernetes clusters found</div>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                        Add a Kubernetes cluster to start deploying applications to your cluster.
                    </div>
                </div>
            </div>
        @endforelse
    </div>
</div>

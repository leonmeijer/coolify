<div>
    <x-slot:title>
        Destinations | Coolify
    </x-slot>
    <div class="flex items-center gap-2">
        <h1>Destinations</h1>
        @if ($servers->count() > 0)
            @can('createAnyResource')
                <x-modal-input buttonTitle="+ Add Docker" title="New Docker Destination">
                    <livewire:destination.new.docker />
                </x-modal-input>
            @endcan
        @endif
    </div>
    <div class="subtitle">Network endpoints to deploy your resources.</div>

    {{-- Docker Destinations --}}
    @if ($servers->count() > 0)
        <h3 class="text-lg font-semibold mt-4 mb-2">Docker Destinations</h3>
        <div class="grid gap-4 lg:grid-cols-2 -mt-1">
            @forelse ($servers as $server)
                @forelse ($server->destinations() as $destination)
                    @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                        <a class="coolbox group" {{ wireNavigate() }}
                            href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                            <div class="flex flex-col justify-center mx-6">
                                <div class="box-title">{{ $destination->name }}</div>
                                <div class="box-description">Server: {{ $destination->server->name }}</div>
                            </div>
                        </a>
                    @endif
                    @if ($destination->getMorphClass() === 'App\Models\SwarmDocker')
                        <a class="coolbox group" {{ wireNavigate() }}
                            href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                            <div class="flex flex-col mx-6">
                                <div class="box-title">{{ $destination->name }}</div>
                                <div class="box-description">Server: {{ $destination->server->name }}</div>
                            </div>
                        </a>
                    @endif
                @empty
                    <div>No Docker destinations found.</div>
                @endforelse
            @empty
                <div>No servers found.</div>
            @endforelse
        </div>
    @endif

    {{-- Kubernetes Destinations --}}
    @if ($kubernetesClusters->count() > 0)
        <h3 class="text-lg font-semibold mt-6 mb-2">Kubernetes Destinations</h3>
        <div class="grid gap-4 lg:grid-cols-2 -mt-1">
            @foreach ($kubernetesClusters as $cluster)
                @forelse ($cluster->destinations as $destination)
                    <a class="coolbox group" {{ wireNavigate() }}
                        href="{{ route('kubernetes.destinations', ['uuid' => $cluster->uuid]) }}">
                        <div class="flex flex-col justify-center mx-6">
                            <div class="flex items-center gap-2">
                                <div class="box-title">{{ $destination->name }}</div>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-coolgray-200 dark:bg-coolgray-400 font-mono">
                                    {{ $destination->namespace }}
                                </span>
                            </div>
                            <div class="box-description">Cluster: {{ $cluster->name }}</div>
                        </div>
                    </a>
                @empty
                    <div class="p-4 border rounded-lg border-coolgray-200 dark:border-coolgray-400">
                        <div class="font-medium">{{ $cluster->name }}</div>
                        <div class="text-sm text-neutral-500 dark:text-neutral-400">No destinations configured</div>
                        @can('manageDestinations', $cluster)
                            <a href="{{ route('kubernetes.destinations.create', ['uuid' => $cluster->uuid]) }}" {{ wireNavigate() }} class="text-sm text-blue-500 hover:underline mt-1 inline-block">
                                + Add Destination
                            </a>
                        @endcan
                    </div>
                @endforelse
            @endforeach
        </div>
    @endif

    @if ($servers->count() === 0 && $kubernetesClusters->count() === 0)
        <div class="p-4 text-center border rounded-lg border-coolgray-200 dark:border-coolgray-400 mt-4">
            <div class="text-lg font-semibold">No destinations available</div>
            <div class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                Add a server or Kubernetes cluster to create destinations.
            </div>
        </div>
    @endif
</div>

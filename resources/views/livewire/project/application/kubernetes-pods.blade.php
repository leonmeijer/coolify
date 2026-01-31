<div>
    @if (!$this->isKubernetesDestination)
        <div class="p-4 text-center border rounded-lg border-coolgray-200 dark:border-coolgray-400">
            <div class="text-lg font-semibold">Not a Kubernetes Application</div>
            <div class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                This component is only available for applications deployed to Kubernetes.
            </div>
        </div>
    @else
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2>Kubernetes Pods</h2>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400">
                        Namespace: <code class="px-1 py-0.5 rounded bg-coolgray-100 dark:bg-coolgray-500">{{ $this->namespace }}</code>
                    </div>
                </div>
                <x-forms.button wire:click="loadPods" :disabled="$isLoadingPods">
                    @if ($isLoadingPods)
                        <x-loading class="w-4 h-4" />
                        Refreshing...
                    @else
                        Refresh
                    @endif
                </x-forms.button>
            </div>

            @if ($error)
                <div class="p-3 text-sm rounded-lg bg-error/10 text-error border border-error/20">
                    {{ $error }}
                </div>
            @endif

            {{-- Pod List --}}
            <div class="flex flex-col gap-2">
                <h3>Pods</h3>
                @if (count($pods) > 0)
                    <div class="grid gap-3">
                        @foreach ($pods as $pod)
                            <div
                                wire:click="selectPod('{{ $pod['name'] }}')"
                                @class([
                                    'p-4 border rounded-lg cursor-pointer transition-colors',
                                    'border-coolgray-200 dark:border-coolgray-400 hover:border-primary' => $selectedPodName !== $pod['name'],
                                    'border-primary bg-primary/5' => $selectedPodName === $pod['name'],
                                ])
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold">{{ $pod['name'] }}</span>
                                        <span @class([
                                            'px-2 py-0.5 text-xs rounded-full',
                                            'bg-success/20 text-success' => $pod['phase'] === 'Running' && $pod['isReady'],
                                            'bg-warning/20 text-warning' => $pod['phase'] === 'Pending' || ($pod['phase'] === 'Running' && !$pod['isReady']),
                                            'bg-error/20 text-error' => $pod['phase'] === 'Failed',
                                            'bg-neutral-200 dark:bg-neutral-600' => !in_array($pod['phase'], ['Running', 'Pending', 'Failed']),
                                        ])>
                                            {{ $pod['phase'] }}{{ !$pod['isReady'] && $pod['phase'] === 'Running' ? ' (Not Ready)' : '' }}
                                        </span>
                                    </div>
                                    @if ($pod['nodeName'])
                                        <span class="text-xs text-neutral-500 dark:text-neutral-400">
                                            Node: {{ $pod['nodeName'] }}
                                        </span>
                                    @endif
                                </div>

                                <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
                                    @if ($pod['podIP'])
                                        <div>
                                            <span class="text-neutral-500 dark:text-neutral-400">Pod IP:</span>
                                            <span class="font-mono">{{ $pod['podIP'] }}</span>
                                        </div>
                                    @endif
                                    @if ($pod['startTime'])
                                        <div>
                                            <span class="text-neutral-500 dark:text-neutral-400">Started:</span>
                                            <span>{{ \Carbon\Carbon::parse($pod['startTime'])->diffForHumans() }}</span>
                                        </div>
                                    @endif
                                </div>

                                {{-- Containers --}}
                                @if (count($pod['containers']) > 0)
                                    <div class="mt-3 pt-3 border-t border-coolgray-200 dark:border-coolgray-400">
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-2">Containers</div>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($pod['containers'] as $container)
                                                <button
                                                    type="button"
                                                    wire:click.stop="selectPod('{{ $pod['name'] }}', '{{ $container['name'] }}')"
                                                    @class([
                                                        'px-2 py-1 text-xs rounded border transition-colors',
                                                        'border-coolgray-200 dark:border-coolgray-400 hover:border-primary' => !($selectedPodName === $pod['name'] && $selectedContainerName === $container['name']),
                                                        'border-primary bg-primary/10' => $selectedPodName === $pod['name'] && $selectedContainerName === $container['name'],
                                                    ])
                                                >
                                                    <div class="flex items-center gap-1">
                                                        <span @class([
                                                            'w-2 h-2 rounded-full',
                                                            'bg-success' => $container['ready'],
                                                            'bg-warning' => !$container['ready'] && str_starts_with($container['state'], 'Waiting'),
                                                            'bg-error' => !$container['ready'] && str_starts_with($container['state'], 'Terminated'),
                                                        ])></span>
                                                        {{ $container['name'] }}
                                                        @if ($container['restartCount'] > 0)
                                                            <span class="text-warning">({{ $container['restartCount'] }} restarts)</span>
                                                        @endif
                                                    </div>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-4 text-center border rounded-lg border-coolgray-200 dark:border-coolgray-400">
                        <div class="text-neutral-500 dark:text-neutral-400">
                            @if ($isLoadingPods)
                                Loading pods...
                            @else
                                No pods found for this application.
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            {{-- Pod Logs --}}
            @if ($selectedPodName)
                <div class="border-t dark:border-coolgray-300 my-2"></div>

                <div class="flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <h3>
                            Logs: {{ $selectedPodName }}
                            @if ($selectedContainerName)
                                / {{ $selectedContainerName }}
                            @endif
                        </h3>
                        <div class="flex items-center gap-2">
                            <x-forms.button wire:click="loadLogs" :disabled="$isLoadingLogs">
                                @if ($isLoadingLogs)
                                    <x-loading class="w-4 h-4" />
                                @else
                                    Refresh Logs
                                @endif
                            </x-forms.button>
                            <x-forms.button wire:click="clearSelection">
                                Close
                            </x-forms.button>
                        </div>
                    </div>

                    <div class="bg-black rounded-lg p-4 font-mono text-sm text-green-400 max-h-96 overflow-auto">
                        @if ($isLoadingLogs)
                            <div class="text-neutral-400">Loading logs...</div>
                        @elseif (empty($podLogs))
                            <div class="text-neutral-400">No logs available.</div>
                        @else
                            <pre class="whitespace-pre-wrap break-all">{{ $podLogs }}</pre>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Events --}}
            <div class="border-t dark:border-coolgray-300 my-2"></div>

            <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <h3>Events</h3>
                    <x-forms.button wire:click="loadEvents" :disabled="$isLoadingEvents">
                        @if ($isLoadingEvents)
                            <x-loading class="w-4 h-4" />
                        @else
                            Refresh Events
                        @endif
                    </x-forms.button>
                </div>

                @if (count($events) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b dark:border-coolgray-400">
                                    <th class="text-left py-2 px-3">Type</th>
                                    <th class="text-left py-2 px-3">Reason</th>
                                    <th class="text-left py-2 px-3">Object</th>
                                    <th class="text-left py-2 px-3">Message</th>
                                    <th class="text-left py-2 px-3">Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($events as $event)
                                    <tr class="border-b dark:border-coolgray-400">
                                        <td class="py-2 px-3">
                                            <span @class([
                                                'px-2 py-0.5 text-xs rounded-full',
                                                'bg-warning/20 text-warning' => $event['type'] === 'Warning',
                                                'bg-success/20 text-success' => $event['type'] === 'Normal',
                                                'bg-error/20 text-error' => $event['type'] === 'Error',
                                            ])>
                                                {{ $event['type'] }}
                                            </span>
                                        </td>
                                        <td class="py-2 px-3 font-medium">{{ $event['reason'] }}</td>
                                        <td class="py-2 px-3 font-mono text-xs">{{ $event['object'] }}</td>
                                        <td class="py-2 px-3 max-w-md truncate" title="{{ $event['message'] }}">
                                            {{ $event['message'] }}
                                        </td>
                                        <td class="py-2 px-3 text-neutral-500 dark:text-neutral-400 whitespace-nowrap">
                                            @if ($event['lastTimestamp'])
                                                {{ \Carbon\Carbon::parse($event['lastTimestamp'])->diffForHumans() }}
                                                @if ($event['count'] > 1)
                                                    ({{ $event['count'] }}x)
                                                @endif
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="p-4 text-center border rounded-lg border-coolgray-200 dark:border-coolgray-400">
                        <div class="text-neutral-500 dark:text-neutral-400">
                            @if ($isLoadingEvents)
                                Loading events...
                            @else
                                No events found.
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>

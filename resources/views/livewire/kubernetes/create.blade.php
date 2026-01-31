<div>
    <x-slot:title>
        Create Kubernetes Cluster | Coolify
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
            <h1>Create Kubernetes Cluster</h1>
        </div>

        <div class="subtitle">
            Add a new Kubernetes cluster to deploy your applications.
        </div>

        <div class="max-w-2xl">
            <livewire:kubernetes.form />
        </div>
    </div>
</div>

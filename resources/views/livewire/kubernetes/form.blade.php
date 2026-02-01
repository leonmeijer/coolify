<div class="w-full">
    @if ($isRunningInCluster && !$isEditMode)
        <div class="mb-4 p-4 rounded-lg bg-blue-500/10 border border-blue-500/20">
            <div class="flex items-center justify-between">
                <div>
                    <h4 class="text-sm font-semibold text-blue-500">Running inside Kubernetes</h4>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">
                        Coolify detected it's running inside a Kubernetes cluster. You can use the current cluster automatically.
                    </p>
                </div>
                <x-forms.button type="button" wire:click="useCurrentCluster">
                    Use Current Cluster
                </x-forms.button>
            </div>
        </div>
    @endif

    <form wire:submit="save" class="flex flex-col gap-4">
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-2">
                <x-forms.input
                    id="name"
                    label="Cluster Name"
                    placeholder="My Kubernetes Cluster"
                    required
                    helper="A friendly name to identify this cluster."
                />

                <x-forms.textarea
                    id="description"
                    label="Description"
                    placeholder="Optional description for this cluster..."
                    rows="2"
                    helper="A brief description of this cluster's purpose."
                />

                <x-forms.select
                    id="clusterType"
                    label="Cluster Type"
                    required
                    helper="Select the type of Kubernetes distribution."
                >
                    <option value="kubernetes">Kubernetes (Standard)</option>
                    <option value="k3s">K3s (Lightweight)</option>
                    <option value="okd">OKD (OpenShift Origin)</option>
                    <option value="openshift">OpenShift</option>
                </x-forms.select>
            </div>

            <div class="border-t dark:border-coolgray-300 my-2"></div>

            <div class="flex flex-col gap-2">
                <h4 class="text-sm font-semibold">Kubeconfig</h4>
                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                    Paste your kubeconfig file contents below. This will be encrypted and stored securely.
                </p>

                <x-forms.textarea
                    id="kubeconfig"
                    label="Kubeconfig Contents"
                    placeholder="apiVersion: v1
kind: Config
clusters:
- cluster:
    server: https://your-cluster.example.com
    certificate-authority-data: ...
  name: my-cluster
..."
                    rows="10"
                    required
                    helper="The contents of your kubeconfig file. Ensure it contains valid cluster and user credentials."
                />

                @if (count($availableContexts) > 0)
                    <x-forms.select
                        id="contextName"
                        label="Context"
                        helper="Select which context to use from the kubeconfig."
                    >
                        <option value="">Use default context</option>
                        @foreach ($availableContexts as $context)
                            <option value="{{ $context }}">{{ $context }}</option>
                        @endforeach
                    </x-forms.select>
                @endif
            </div>

            <div class="flex items-center gap-2">
                <x-forms.button
                    type="button"
                    wire:click="testConnection"
                    :disabled="$isTesting || empty($kubeconfig)"
                >
                    @if ($isTesting)
                        <x-loading class="w-4 h-4" />
                        Testing...
                    @else
                        Test Connection
                    @endif
                </x-forms.button>

                @if ($connectionTested)
                    @if ($connectionSuccessful)
                        <span class="flex items-center gap-1 text-sm text-success">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Connected
                        </span>
                    @else
                        <span class="flex items-center gap-1 text-sm text-error">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            Failed
                        </span>
                    @endif
                @endif
            </div>

            @if ($connectionError)
                <div class="p-3 text-sm rounded-lg bg-error/10 text-error border border-error/20">
                    <strong>Connection Error:</strong> {{ $connectionError }}
                </div>
            @endif

            @if ($connectionSuccessful && count($availableNamespaces) > 0)
                <div class="p-3 rounded-lg bg-success/10 border border-success/20">
                    <h5 class="text-sm font-semibold text-success mb-2">Available Namespaces</h5>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($availableNamespaces as $namespace)
                            <span class="px-2 py-0.5 text-xs rounded-full bg-success/20 text-success">
                                {{ $namespace }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="border-t dark:border-coolgray-300 my-2"></div>

        <div class="flex justify-end gap-2">
            <x-forms.button
                type="submit"
                isHighlighted
            >
                {{ $isEditMode ? 'Update Cluster' : 'Create Cluster' }}
            </x-forms.button>
        </div>
    </form>
</div>

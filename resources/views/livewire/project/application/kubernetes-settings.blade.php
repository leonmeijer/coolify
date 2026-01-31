<div>
    <form wire:submit="submit" class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <h2>Kubernetes Settings</h2>
        </div>
        <div class="text-sm text-neutral-500 dark:text-neutral-400">
            Configure Kubernetes-specific deployment settings for this application.
        </div>

        {{-- Replicas --}}
        <div class="flex flex-col gap-2">
            <h3>Replicas</h3>
            <div class="md:w-96">
                <x-forms.input
                    id="replicas"
                    type="number"
                    label="Number of Replicas"
                    min="1"
                    max="100"
                    required
                    helper="Number of pod replicas to run. Ignored when autoscaling is enabled."
                    canGate="update"
                    :canResource="$application"
                    :disabled="$autoscalingEnabled"
                />
            </div>
        </div>

        {{-- Resource Limits --}}
        <div class="border-t dark:border-coolgray-300 my-2"></div>

        <div class="flex flex-col gap-2">
            <h3>Resource Limits</h3>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Define CPU and memory resources for your application containers.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-forms.input
                    id="cpuRequest"
                    label="CPU Request"
                    placeholder="100m"
                    required
                    helper="Minimum CPU guaranteed for each pod (e.g., 100m, 0.5, 1)."
                    canGate="update"
                    :canResource="$application"
                />

                <x-forms.input
                    id="cpuLimit"
                    label="CPU Limit"
                    placeholder="500m"
                    required
                    helper="Maximum CPU allowed for each pod (e.g., 500m, 1, 2)."
                    canGate="update"
                    :canResource="$application"
                />

                <x-forms.input
                    id="memoryRequest"
                    label="Memory Request"
                    placeholder="128Mi"
                    required
                    helper="Minimum memory guaranteed for each pod (e.g., 128Mi, 1Gi)."
                    canGate="update"
                    :canResource="$application"
                />

                <x-forms.input
                    id="memoryLimit"
                    label="Memory Limit"
                    placeholder="512Mi"
                    required
                    helper="Maximum memory allowed for each pod (e.g., 512Mi, 1Gi)."
                    canGate="update"
                    :canResource="$application"
                />
            </div>
        </div>

        {{-- Autoscaling --}}
        <div class="border-t dark:border-coolgray-300 my-2"></div>

        <div class="flex flex-col gap-2">
            <h3>Autoscaling (HPA)</h3>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Configure Horizontal Pod Autoscaler to automatically scale your application based on resource utilization.
            </p>

            <div class="md:w-96">
                <x-forms.checkbox
                    id="autoscalingEnabled"
                    label="Enable Autoscaling"
                    helper="When enabled, Kubernetes will automatically adjust the number of replicas based on resource utilization."
                    instantSave
                    canGate="update"
                    :canResource="$application"
                />
            </div>

            @if ($autoscalingEnabled)
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 p-4 rounded-lg border border-coolgray-200 dark:border-coolgray-400">
                    <x-forms.input
                        id="minReplicas"
                        type="number"
                        label="Minimum Replicas"
                        min="1"
                        max="100"
                        required
                        helper="Minimum number of replicas to maintain."
                        canGate="update"
                        :canResource="$application"
                    />

                    <x-forms.input
                        id="maxReplicas"
                        type="number"
                        label="Maximum Replicas"
                        min="1"
                        max="100"
                        required
                        helper="Maximum number of replicas to scale to."
                        canGate="update"
                        :canResource="$application"
                    />

                    <x-forms.input
                        id="targetCpuUtilization"
                        type="number"
                        label="Target CPU Utilization (%)"
                        min="1"
                        max="100"
                        required
                        helper="Target average CPU utilization across all pods."
                        canGate="update"
                        :canResource="$application"
                    />

                    <x-forms.input
                        id="targetMemoryUtilization"
                        type="number"
                        label="Target Memory Utilization (%)"
                        min="1"
                        max="100"
                        helper="Target average memory utilization (optional)."
                        canGate="update"
                        :canResource="$application"
                    />
                </div>
            @endif
        </div>

        <div class="border-t dark:border-coolgray-300 my-2"></div>

        <div class="flex items-center gap-2">
            <x-forms.button type="submit" canGate="update" :canResource="$application">
                Save
            </x-forms.button>
        </div>
    </form>
</div>

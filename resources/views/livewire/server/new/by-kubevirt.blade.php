<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        @if ($current_step === 1)
            <div class="flex flex-col w-full gap-4">
                @if ($available_clusters->count() > 0)
                    <div class="flex flex-col gap-4">
                        <label class="text-sm font-medium">Select Kubernetes Cluster</label>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach ($available_clusters as $cluster)
                                <div wire:click="selectCluster({{ $cluster->id }})"
                                    @class([
                                        'cursor-pointer p-4 rounded-lg border transition-all duration-200',
                                        'border-coollabs bg-coollabs/10 dark:bg-coollabs/20' =>
                                            $selected_cluster_id === $cluster->id,
                                        'border-neutral-200 dark:border-coolgray-400 hover:border-coollabs dark:hover:border-coollabs' =>
                                            $selected_cluster_id !== $cluster->id,
                                    ])>
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 mt-1">
                                            <svg class="w-8 h-8" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M15.9.476a2.14 2.14 0 0 0-.823.218L3.932 6.01c-.582.277-1.005.804-1.15 1.432L.054 19.373c-.022.095-.033.19-.042.283v.158c0 .03.004.058.006.088l.008.05v.022c.002.024.006.048.01.072l.01.051.003.015c.004.022.009.044.015.066l.013.052c0 .008.004.016.006.023.006.02.012.042.02.063l.017.052.01.026c.007.02.015.04.024.06l.02.047.016.033.024.054.02.04.007.012a2.14 2.14 0 0 0 .063.108l.025.04.018.028.048.07.037.05.012.015c.02.025.04.05.06.074l.04.046.012.013.065.07.052.05.006.006a2.14 2.14 0 0 0 .094.083l.042.035.04.034.08.063.044.032.026.018.08.053.043.027.018.01.085.05.042.023.02.012.088.046.038.018.028.013.054.025.088.04h.005l11.12 5.2a2.14 2.14 0 0 0 1.636-.001l11.14-5.21h.001a2.14 2.14 0 0 0 1.158-1.437l2.728-11.931a2.14 2.14 0 0 0-.478-1.833L25.278 3.87a2.14 2.14 0 0 0-1.18-.638L12.91.093a2.14 2.14 0 0 0-.393-.035l-.106.003c-.082 0-.163.006-.245.016l-.032.002L12 .088a2.14 2.14 0 0 0-.235.043l-.024.006c-.078.02-.155.043-.23.07l-.037.016c-.07.027-.138.058-.204.092z"
                                                    fill="currentColor" />
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <h4 class="font-semibold text-base truncate">{{ $cluster->name }}</h4>
                                                @if ($cluster->is_reachable)
                                                    <span
                                                        class="px-2 py-0.5 text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400 rounded">
                                                        Online
                                                    </span>
                                                @else
                                                    <span
                                                        class="px-2 py-0.5 text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400 rounded">
                                                        Offline
                                                    </span>
                                                @endif
                                            </div>
                                            <p class="text-sm text-neutral-500 dark:text-neutral-400 truncate">
                                                {{ $cluster->cluster_type }} - {{ $cluster->api_server_url }}
                                            </p>
                                            @if ($cluster->description)
                                                <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-1 truncate">
                                                    {{ $cluster->description }}
                                                </p>
                                            @endif
                                        </div>
                                        @if ($selected_cluster_id === $cluster->id)
                                            <div class="flex-shrink-0">
                                                <svg class="w-5 h-5 text-coollabs" xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd"
                                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                        clip-rule="evenodd" />
                                                </svg>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 mt-4">
                        <x-forms.button canGate="create" :canResource="App\Models\Server::class" wire:click="nextStep"
                            :disabled="!$selected_cluster_id">
                            Continue
                        </x-forms.button>
                    </div>

                    <div class="text-center text-sm dark:text-neutral-500 mt-4">OR</div>
                @endif

                <div class="text-center">
                    <a href="{{ route('kubernetes.create') }}" {{ wireNavigate() }}
                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium border border-neutral-200 dark:border-coolgray-400 rounded-lg hover:border-coollabs dark:hover:border-coollabs transition-colors">
                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        {{ $available_clusters->count() > 0 ? 'Add New Cluster' : 'Add Kubernetes Cluster' }}
                    </a>
                </div>
            </div>
        @elseif ($current_step === 2)
            @if ($loading_data)
                <div class="flex items-center justify-center py-8">
                    <div class="text-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto"></div>
                        <p class="mt-4 text-sm dark:text-neutral-400">Loading cluster data...</p>
                    </div>
                </div>
            @else
                <form class="flex flex-col w-full gap-4" wire:submit='submit'>
                    <div class="p-4 bg-neutral-50 dark:bg-coolgray-200 rounded-lg border border-neutral-200 dark:border-coolgray-400 mb-2">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-neutral-500" viewBox="0 0 32 32"
                                xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M15.9.476a2.14 2.14 0 0 0-.823.218L3.932 6.01c-.582.277-1.005.804-1.15 1.432L.054 19.373c-.022.095-.033.19-.042.283v.158c0 .03.004.058.006.088l.008.05v.022c.002.024.006.048.01.072l.01.051.003.015c.004.022.009.044.015.066l.013.052c0 .008.004.016.006.023.006.02.012.042.02.063l.017.052.01.026c.007.02.015.04.024.06l.02.047.016.033.024.054.02.04.007.012a2.14 2.14 0 0 0 .063.108l.025.04.018.028.048.07.037.05.012.015c.02.025.04.05.06.074l.04.046.012.013.065.07.052.05.006.006a2.14 2.14 0 0 0 .094.083l.042.035.04.034.08.063.044.032.026.018.08.053.043.027.018.01.085.05.042.023.02.012.088.046.038.018.028.013.054.025.088.04h.005l11.12 5.2a2.14 2.14 0 0 0 1.636-.001l11.14-5.21h.001a2.14 2.14 0 0 0 1.158-1.437l2.728-11.931a2.14 2.14 0 0 0-.478-1.833L25.278 3.87a2.14 2.14 0 0 0-1.18-.638L12.91.093a2.14 2.14 0 0 0-.393-.035l-.106.003c-.082 0-.163.006-.245.016l-.032.002L12 .088a2.14 2.14 0 0 0-.235.043l-.024.006c-.078.02-.155.043-.23.07l-.037.016c-.07.027-.138.058-.204.092z"
                                    fill="currentColor" />
                            </svg>
                            <span class="text-sm font-medium">
                                Cluster: {{ $available_clusters->firstWhere('id', $selected_cluster_id)?->name }}
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <x-forms.select label="Namespace" id="selected_namespace" required>
                                <option value="">Select a namespace...</option>
                                @foreach ($namespaces as $namespace)
                                    <option value="{{ $namespace }}">{{ $namespace }}</option>
                                @endforeach
                            </x-forms.select>
                        </div>
                        <div>
                            <x-forms.input id="vm_name" label="VM Name" required
                                helper="A unique name for the virtual machine." />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <x-forms.input type="number" id="cpu_cores" label="CPU Cores" required min="1"
                                max="64" helper="Number of CPU cores (1-64)." />
                        </div>
                        <div>
                            <x-forms.input id="memory" label="Memory" required
                                helper="Memory allocation (e.g., 4Gi, 8Gi, 512Mi)." placeholder="4Gi" />
                        </div>
                    </div>

                    <div>
                        <x-forms.input id="container_image" label="Container Disk Image" required
                            helper="The container disk image to use. See <a class='inline-block underline dark:text-white' href='https://kubevirt.io/user-guide/virtual_machines/disks_and_volumes/#containerdisk' target='_blank'>KubeVirt documentation</a> for options." />
                        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                            Common images: quay.io/containerdisks/fedora:latest, quay.io/containerdisks/ubuntu:22.04,
                            quay.io/containerdisks/centos-stream:9
                        </p>
                    </div>

                    <div>
                        @if ($private_keys->count() === 0)
                            <div class="flex flex-col gap-2">
                                <label class="flex gap-1 items-center mb-1 text-sm font-medium">
                                    SSH Key
                                    <x-highlighted text="*" />
                                </label>
                                <div
                                    class="p-4 border border-warning-500 dark:border-warning-600 rounded bg-warning-50 dark:bg-warning-900/10">
                                    <p class="text-sm mb-3 text-neutral-700 dark:text-neutral-300">
                                        No SSH keys found. You need to create an SSH key to access the VM.
                                    </p>
                                    <x-modal-input buttonTitle="Create New SSH Key" title="New Private Key"
                                        isHighlightedButton>
                                        <livewire:security.private-key.create :modal_mode="true" from="server" />
                                    </x-modal-input>
                                </div>
                            </div>
                        @else
                            <x-forms.select label="SSH Key" id="private_key_id" wire:model.live="private_key_id" required
                                helper="This SSH key will be injected into the VM via cloud-init for SSH access.">
                                <option value="">Select an SSH key...</option>
                                @foreach ($private_keys as $key)
                                    <option value="{{ $key->id }}">
                                        {{ $key->name }}
                                    </option>
                                @endforeach
                            </x-forms.select>
                        @endif
                    </div>

                    <div class="flex flex-col gap-2">
                        <div class="flex justify-between items-center gap-2">
                            <label class="text-sm font-medium">Cloud-Init Script</label>
                            @if ($saved_cloud_init_scripts->count() > 0)
                                <div class="flex items-center gap-2 flex-1 max-w-md">
                                    <x-forms.select wire:model.live="selected_cloud_init_script_id" label=""
                                        helper="">
                                        <option value="">Load saved script...</option>
                                        @foreach ($saved_cloud_init_scripts as $script)
                                            <option value="{{ $script->id }}">{{ $script->name }}</option>
                                        @endforeach
                                    </x-forms.select>
                                    <x-forms.button type="button" wire:click="clearCloudInitScript">
                                        Reset
                                    </x-forms.button>
                                </div>
                            @endif
                        </div>
                        <x-forms.textarea id="cloud_init_script" label=""
                            helper="Cloud-init script for VM initialization. The default script installs Docker and configures SSH access with your selected key."
                            rows="10" />

                        <div class="flex items-center gap-2">
                            <x-forms.checkbox id="save_cloud_init_script" label="Save this script for later use" />
                            <div class="flex-1">
                                <x-forms.input id="cloud_init_script_name" label="" placeholder="Script name..." />
                            </div>
                        </div>
                    </div>

                    <div class="p-4 bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800 rounded-lg">
                        <div class="flex gap-3">
                            <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd"
                                    d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z"
                                    clip-rule="evenodd" />
                            </svg>
                            <div>
                                <p class="font-medium text-blue-800 dark:text-blue-300 mb-1">KubeVirt VM Information</p>
                                <p class="text-sm text-blue-700 dark:text-blue-400">
                                    This will create a KubeVirt VirtualMachine resource in your Kubernetes cluster.
                                    The VM will be managed through the Kubernetes API and can be accessed via SSH once
                                    running.
                                    Note: KubeVirt must be installed on the target cluster.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-2 justify-between">
                        <x-forms.button type="button" wire:click="previousStep">
                            Back
                        </x-forms.button>
                        <x-forms.button isHighlighted canGate="create" :canResource="App\Models\Server::class"
                            type="submit" :disabled="!$private_key_id">
                            Create KubeVirt VM
                        </x-forms.button>
                    </div>
                </form>
            @endif
        @endif
    @endif
</div>

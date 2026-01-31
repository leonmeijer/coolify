<div class="w-full">
    <div class="flex flex-col gap-4">
        @can('viewAny', App\Models\CloudProviderToken::class)
            <div>
                <x-modal-input title="Connect a Hetzner Server">
                    <x-slot:content>
                        <div class="relative gap-2 cursor-pointer coolbox group">
                            <div class="flex items-center gap-4 mx-6">
                                <svg class="w-10 h-10 flex-shrink-0" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                                    <rect width="200" height="200" fill="#D50C2D" rx="8" />
                                    <path d="M40 40 H60 V90 H140 V40 H160 V160 H140 V110 H60 V160 H40 Z" fill="white" />
                                </svg>
                                <div class="flex flex-col justify-center flex-1">
                                    <div class="box-title">Connect a Hetzner Server</div>
                                    <div class="box-description">
                                        Deploy servers directly from your Hetzner Cloud account
                                    </div>
                                </div>
                            </div>
                        </div>
                    </x-slot:content>
                    <livewire:server.new.by-hetzner :private_keys="$private_keys" :limit_reached="$limit_reached" />
                </x-modal-input>
            </div>
        @endcan

        @can('viewAny', App\Models\KubernetesCluster::class)
            <div>
                <x-modal-input title="Create KubeVirt VM">
                    <x-slot:content>
                        <div class="relative gap-2 cursor-pointer coolbox group">
                            <div class="flex items-center gap-4 mx-6">
                                <svg class="w-10 h-10 flex-shrink-0 text-orange-500" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M15.9.476a2.14 2.14 0 0 0-.823.218L3.932 6.01c-.582.277-1.005.804-1.15 1.432L.054 19.373c-.022.095-.033.19-.042.283v.158c0 .03.004.058.006.088l.008.05v.022c.002.024.006.048.01.072l.01.051.003.015c.004.022.009.044.015.066l.013.052c0 .008.004.016.006.023.006.02.012.042.02.063l.017.052.01.026c.007.02.015.04.024.06l.02.047.016.033.024.054.02.04.007.012a2.14 2.14 0 0 0 .063.108l.025.04.018.028.048.07.037.05.012.015c.02.025.04.05.06.074l.04.046.012.013.065.07.052.05.006.006a2.14 2.14 0 0 0 .094.083l.042.035.04.034.08.063.044.032.026.018.08.053.043.027.018.01.085.05.042.023.02.012.088.046.038.018.028.013.054.025.088.04h.005l11.12 5.2a2.14 2.14 0 0 0 1.636-.001l11.14-5.21h.001a2.14 2.14 0 0 0 1.158-1.437l2.728-11.931a2.14 2.14 0 0 0-.478-1.833L25.278 3.87a2.14 2.14 0 0 0-1.18-.638L12.91.093a2.14 2.14 0 0 0-.393-.035l-.106.003c-.082 0-.163.006-.245.016l-.032.002L12 .088a2.14 2.14 0 0 0-.235.043l-.024.006c-.078.02-.155.043-.23.07l-.037.016c-.07.027-.138.058-.204.092z" fill="currentColor"/>
                                </svg>
                                <div class="flex flex-col justify-center flex-1">
                                    <div class="box-title">Create KubeVirt VM</div>
                                    <div class="box-description">
                                        Create virtual machines on Kubernetes clusters with KubeVirt
                                    </div>
                                </div>
                            </div>
                        </div>
                    </x-slot:content>
                    <livewire:server.new.by-kubevirt :private_keys="$private_keys" :limit_reached="$limit_reached" />
                </x-modal-input>
            </div>
        @endcan

        <div class="border-t dark:border-coolgray-300 my-4"></div>

        <div>
            <h3 class="pb-2">Add Server by IP Address</h3>
            <livewire:server.new.by-ip :private_keys="$private_keys" :limit_reached="$limit_reached" />
        </div>
    </div>
</div>

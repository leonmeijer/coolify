<div>
    <x-slot:title>
        Servers | Coolify
    </x-slot>
    <div class="flex items-center gap-2">
        <h1>Servers</h1>
        @can('createAnyResource')
            <x-modal-input buttonTitle="+ Add" title="New Server" :closeOutside="false">
                <livewire:server.create />
            </x-modal-input>
        @endcan
    </div>
    <div class="subtitle">All your servers are here.</div>
    <div class="grid gap-4 lg:grid-cols-2 -mt-1">
        @forelse ($servers as $server)
            <div @class([
                'gap-2 border coolbox group relative',
                'border-red-500' =>
                    !$server->settings->is_reachable || $server->settings->force_disabled,
            ])>
                <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}" {{ wireNavigate() }}
                    class="flex flex-col justify-center mx-6 cursor-pointer flex-1">
                    <div class="font-bold dark:text-white">
                        {{ $server->name }}
                    </div>
                    <div class="description">
                        {{ $server->description }}</div>
                    <div class="flex gap-1 text-xs text-error">
                        @if (!$server->settings->is_reachable)
                            <span>Not reachable</span>
                        @endif
                        @if (!$server->settings->is_reachable && !$server->settings->is_usable)
                            &
                        @endif
                        @if (!$server->settings->is_usable)
                            <span>Not usable by Coolify</span>
                        @endif
                        @if ($server->settings->force_disabled)
                            <span>Disabled by the system</span>
                        @endif
                    </div>
                </a>
                <div class="flex-1"></div>
                @if ($server->id !== 0)
                    @can('delete', $server)
                        <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                            <x-modal-confirmation
                                title="Delete Server?"
                                isErrorButton
                                buttonTitle=""
                                :submitAction="'delete(' . $server->id . ', password)'"
                                :actions="['This server will be permanently deleted from Coolify.']"
                                :confirmationText="$server->name"
                                confirmationLabel="Please confirm by entering the server name"
                                shortConfirmationLabel="Server Name"
                            >
                                <x-slot:customButton>
                                    <button type="button"
                                        class="p-1.5 rounded hover:bg-red-500/20 text-neutral-500 hover:text-red-500 transition-colors"
                                        title="Delete server">
                                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                        </svg>
                                    </button>
                                </x-slot:customButton>
                            </x-modal-confirmation>
                        </div>
                    @endcan
                @endif
            </div>
        @empty
            <div>
                <div>No servers found. Without a server, you won't be able to do much.</div>
            </div>
        @endforelse
        @isset($error)
            <div class="text-center text-error">
                <span>{{ $error }}</span>
            </div>
        @endisset
    </div>
</div>

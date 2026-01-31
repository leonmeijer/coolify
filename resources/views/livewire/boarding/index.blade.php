@php use App\Enums\ProxyTypes; @endphp
<x-slot:title>
    Onboarding | Coolify
    </x-slot>
    <section class="w-full">
        <div class="flex flex-col items-center w-full space-y-8">
            @if ($currentState === 'welcome')
                <div class="w-full max-w-2xl text-center space-y-8">
                    <div class="space-y-4">
                        <h1 class="text-4xl font-bold lg:text-6xl">Welcome to Coolify</h1>
                        <p class="text-lg lg:text-xl dark:text-neutral-400">
                            Connect your first server and start deploying in minutes
                        </p>
                    </div>

                    <div class="text-left space-y-4 p-8 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                        <h2 class="text-sm font-bold uppercase tracking-wide dark:text-neutral-400">
                            What You'll Set Up
                        </h2>
                        <div class="space-y-3">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">Server Connection</div>
                                    <div class="text-sm dark:text-neutral-400">Connect via SSH to deploy your resources
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">Docker Environment</div>
                                    <div class="text-sm dark:text-neutral-400">Automated installation and configuration
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">Project Structure</div>
                                    <div class="text-sm dark:text-neutral-400">Organize your applications and resources
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col items-center gap-3 pt-4">
                        <x-forms.button class="justify-center px-12 py-4 text-lg font-bold box-boarding"
                            wire:click="explanation">
                            Let's go!
                        </x-forms.button>
                        <button wire:click="skipBoarding"
                            class="text-sm dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                            Skip Setup
                        </button>
                    </div>
                </div>
            @elseif ($currentState === 'explanation')
                <x-boarding-progress :currentStep="0" />
                <x-boarding-step title="Platform Overview">
                    <x-slot:question>
                        Coolify automates deployment and infrastructure management on your own servers. Deploy applications
                        from Git, manage databases, and monitor everything—without vendor lock-in.
                    </x-slot:question>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Automation:" /> Coolify handles server configuration, Docker management,
                            and
                            deployments automatically.
                        </p>
                        <p>
                            <x-highlighted text="Self-hosted:" /> All data and configurations live on your infrastructure.
                            Works offline except for external integrations.
                        </p>
                        <p>
                            <x-highlighted text="Monitoring & Alerts:" /> Get real-time notifications via Discord, Telegram,
                            Email, and other platforms.
                        </p>
                    </x-slot:explanation>
                    <x-slot:actions>
                        <x-forms.button class="justify-center w-full lg:w-auto px-8 py-3 box-boarding"
                            wire:click="explanation">
                            Continue
                        </x-forms.button>
                    </x-slot:actions>
                </x-boarding-step>
            @elseif ($currentState === 'select-server-type')
                <x-boarding-progress :currentStep="1" />
                <x-boarding-step title="Choose Server Type">
                    <x-slot:question>
                        Select where to deploy your applications and databases. You can add more servers later.
                    </x-slot:question>
                    <x-slot:actions>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 w-full">
                            <button
                                class="group relative box-without-bg cursor-pointer hover:border-coollabs transition-all duration-200 p-6"
                                wire:target="setServerType('localhost')" wire:click="setServerType('localhost')">
                                <div class="flex flex-col gap-4 text-left">
                                    <div class="flex items-center justify-between">
                                        <svg class="size-10" xmlns="http://www.w3.org/2000/svg" fill="none"
                                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M5.25 14.25h13.5m-13.5 0a3 3 0 01-3-3m3 3a3 3 0 100 6h13.5a3 3 0 100-6m-16.5-3a3 3 0 013-3h13.5a3 3 0 013 3m-19.5 0a4.5 4.5 0 01.9-2.7L5.737 5.1a3.375 3.375 0 012.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 01.9 2.7m0 0a3 3 0 01-3 3m0 3h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008zm-3 6h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008z" />
                                        </svg>
                                        <span
                                            class="px-2 py-1 text-xs font-bold uppercase tracking-wide bg-neutral-100 dark:bg-coolgray-300 dark:text-neutral-400 rounded">
                                            Quick Start
                                        </span>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold mb-2">This Machine</h3>
                                        <p class="text-sm dark:text-neutral-400">
                                            Deploy on the server running Coolify. Best for testing and single-server setups.
                                        </p>
                                    </div>
                                </div>
                            </button>

                            <button
                                class="group relative box-without-bg cursor-pointer hover:border-coollabs transition-all duration-200 p-6"
                                wire:target="setServerType('remote')" wire:click="setServerType('remote')">
                                <div class="flex flex-col gap-4 text-left">
                                    <div class="flex items-center justify-between">
                                        <svg class="size-10 " xmlns="http://www.w3.org/2000/svg" fill="none"
                                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M2.25 15a4.5 4.5 0 004.5 4.5H18a3.75 3.75 0 001.332-7.257 3 3 0 00-3.758-3.848 5.25 5.25 0 00-10.233 2.33A4.502 4.502 0 002.25 15z" />
                                        </svg>
                                        <span
                                            class="px-2 py-1 text-xs font-bold uppercase tracking-wide bg-coollabs/10 dark:bg-warning/20 text-coollabs dark:text-warning rounded">
                                            Recommended
                                        </span>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold mb-2">Remote Server</h3>
                                        <p class="text-sm dark:text-neutral-400">
                                            Connect via SSH to any server—cloud VPS, bare metal, or home infrastructure.
                                        </p>
                                    </div>
                                </div>
                            </button>

                            <button
                                class="group relative box-without-bg cursor-pointer hover:border-coollabs transition-all duration-200 p-6"
                                wire:target="setServerType('kubernetes')" wire:click="setServerType('kubernetes')">
                                <div class="flex flex-col gap-4 text-left">
                                    <div class="flex items-center justify-between">
                                        <svg class="size-10" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M15.9.476a2.14 2.14 0 0 0-.823.218L3.932 6.01c-.582.277-1.005.804-1.15 1.432L.054 19.373c-.022.095-.033.19-.042.283v.158c0 .03.004.058.006.088l.008.05v.022c.002.024.006.048.01.072l.01.051.003.015c.004.022.009.044.015.066l.013.052c0 .008.004.016.006.023.006.02.012.042.02.063l.017.052.01.026c.007.02.015.04.024.06l.02.047.016.033.024.054.02.04.007.012a2.14 2.14 0 0 0 .063.108l.025.04.018.028.048.07.037.05.012.015c.02.025.04.05.06.074l.04.046.012.013.065.07.052.05.006.006a2.14 2.14 0 0 0 .094.083l.042.035.04.034.08.063.044.032.026.018.08.053.043.027.018.01.085.05.042.023.02.012.088.046.038.018.028.013.054.025.088.04h.005l11.12 5.2a2.14 2.14 0 0 0 1.636-.001l11.14-5.21h.001a2.14 2.14 0 0 0 1.158-1.437l2.728-11.931a2.14 2.14 0 0 0-.478-1.833L25.278 3.87a2.14 2.14 0 0 0-1.18-.638L12.91.093a2.14 2.14 0 0 0-.393-.035l-.106.003c-.082 0-.163.006-.245.016l-.032.002L12 .088a2.14 2.14 0 0 0-.235.043l-.024.006c-.078.02-.155.043-.23.07l-.037.016c-.07.027-.138.058-.204.092zm-.704 4.474l.631.091.033.005.053.012.03.01.048.018.03.015.044.025.03.02.038.03.03.024.033.034.025.027.025.034.022.03.02.037.017.033.014.039.012.037.008.04.006.039.004.1-.004.038-.008.04-.007.033-.005.016-.02.056-.014.03-.012.022-.01.02-.026.04-.017.024-.036.044-.023.025-.03.03-.033.03-.003.002-.027.02-.033.024-.038.023-.032.017-.043.02-.03.012-.036.012-.049.012-.045.007-.051.004h-.038l-.053-.003-.046-.007-.055-.013-.006-.002h-.003l-1.14-.313
                                                .093-.461.39-1.942.497-2.476c.072-.004.145-.006.218-.006a9.7 9.7 0 0 1 .84.043zm1.93.457l.478 2.39.353 1.776.088.441-.998.318-1.06-.152-.022-.004-.598-2.996-.003-.014a9.63 9.63 0 0 1 1.762.24zm-3.8.647l.562 2.833.063.32-1.038.15-1.05-.318.36-1.802.096-.48.268-1.34a9.71 9.71 0 0 1 .74.637zm5.442 1.14a9.66 9.66 0 0 1 1.308 1.265l-.348.232-.644.43-.906.605-.097-.04-.156-.058-.24-.078-.06-.015-.093-.018-.062-.008-.094-.006h-.062l-.082.003-.083.01-.003.001-.073.012-.062.015-.09.027-.056.022-.088.04-.053.028-.084.052-.048.034-.035.028-.082.073-.04.04-.073.082-.035.044-.038.052-.053.086-.03.056-.044.1-.023.062-.017.053-.035.134-.005.033-.017.12-.003.05-.003.07v.063l.003.07.006.05.012.074.003.012.02.085.007.027.01.034.04.11.036.08.012.024.04.073.034.055.022.033.057.075.03.035.034.038.028.03.066.063.04.036.06.047.05.036.054.035.015.009.042.024 3.674 1.96.664.355-.187.723-.238.916-.215.833-6.338.003-.323.002-.666-.006-.66-.136-.16-.031-.108-.023-.166-.038-.223-.06a9.66 9.66 0 0 1-.09-2.795zm4.424 2.752l.775.618c.26.236.502.49.725.76l-1.296.866-.283-.117-.274-.087-.065.198-.274-.147-1.52-.81.104-.34.2-.655.04-.133 1.09-.34.778.188zm-8.924.128l.764-.11 1.108.334.002.006.098.342.158.53.063.214-.134.08-.322.22-.16.126-.058.052-.156.152-.053.057-.136.164-.047.064-.117.177-.04.07-.053.108-.048.108-.043.106-.036.107-.03.1-.024.102-.024.127-.013.1-.008.09-.004.12v.09l.004.093.006.078.01.094.016.096.01.048.022.098.024.086.018.057-1.063.14-1.157-.022-.015-.04a9.7 9.7 0 0 1-.34-2.047l.04-.292.11-.063.655-.378.637-.356.244-.128zm10.79 2.166a9.7 9.7 0 0 1-.185 1.972l-.227-.063-.15-.04-.196.22-.066.075-.15-.1-1.346-1.038-.176-.135.07-.263.124-.47.03-.114 1.14-.356.073.018.62.152.438.142zm-10.036.086l1.21.023-.056.27-.106.52-.042.207-.12.017-.078.013-.052.01-.1.022-.098.027-.086.027-.122.046-.07.03-.114.057-.068.037-.108.068-.063.044-.1.078-.056.048-.092.086-.05.052-.085.097-.045.057-.078.108-.04.06-.07.116-.036.068-.023.05-.04.085-.04.107-.034.107-.027.1-.02.102-.017.118-.01.12-.003.1.003.1.008.1.01.082.016.1.017.078.024.097.016.05.042.123.04.093.05.102.044.08.057.088.054.074.063.078.062.068.07.072.068.062.073.06.076.056.03.02 1.852 1.194.56.365-.09.62-.25 1.757-.006.05-2.19.98-2.156.968-.016-.02a9.7 9.7 0 0 1-1.08-1.315l-.083-.124.052-.162.23-.735.156-.512.037-.124-.004-.01.062-.02zm8.295.598l-.135.51-.066.253.336.26.45.348.002.015-.11.088-1.95 1.572-.666.538-.503-.344-.158-.115.2-.598.264-1.053.064-.183 1.24-.357 1.032.066zm-6.395.024l1.06.1.257.033.1.014.073.247.21.78.05.196-.16.174-.166.21-.04.056-.102.16-.028.048-.016.032-.083.16-.025.056-.07.166-.02.058-.056.173-.014.056-.043.175-.01.054-.03.18-.006.057-.018.18-.002.057-.008.188v.057l.004.183.003.05.003.04.02.193.004.036.002.02.042.235.02.082.048.192.04.124.064.166.062.136.078.15.078.126.09.133.088.11.102.118.096.096.11.1.002.002.058.048.116.09.058.042.068.046.13.082.073.04.14.072.073.033.14.056.073.026.077.025.14.04.073.016.145.028.07.01.146.012.065.003.148.002h.02l.047-.002.133-.005.064-.005.132-.014.063-.01.148-.027.06-.013.146-.038.06-.018.143-.05.055-.022.134-.06.054-.028.13-.074.05-.032.124-.085.05-.04.074-.063.105-.098.046-.048.096-.108.04-.05.088-.118.037-.054.04-.063.077-.13.03-.058.067-.14.026-.06.02-.054.05-.14.022-.072.04-.15.01-.047 2.63 1.306.006.033a9.67 9.67 0 0 1-1.04 1.386l-.1.108-.205-.06-.67-.2-.48-.148-.107.15-.14-.072-1.646-.818-.014-.007-.014-.007 1.67-1.348.177-.143.024-.022.075-.063.12-.1.06-.056.094-.093.055-.06.087-.1.05-.063.08-.108.047-.067.073-.115.042-.073.064-.122.037-.077.055-.128.03-.082.045-.136.024-.086.034-.143.017-.088.023-.15.01-.09.012-.155.003-.093.002-.078v-.09l-.003-.076-.006-.093-.01-.113-.008-.07-.023-.136-.023-.096-.025-.093-.037-.115-.04-.106-.044-.102-.018-.04-.042-.08-.055-.095-.055-.084-.063-.087-.07-.086-.076-.083-.08-.078-.088-.076-.09-.07-.095-.063-.1-.057-.103-.053-.108-.045-.112-.04-.114-.033-.048-.012-.12-.024-.07-.01-.137-.013-.067-.003-.123-.002-.134.005-.057.005-.084.01-.115.02-.054.01-.11.03-.05.015-.107.038-.047.02-.104.048-.043.023-.098.057-.04.026-.093.065-.037.03-.087.073-.034.032-.08.082-.03.035-.073.09-.027.037-.065.097-.024.04-.06.107-.02.042-.052.115-.016.043-.044.124-.013.042-.035.13-.01.042-.026.138-.006.04-.017.145-.003.037-.008.152v.037l.002.073v.088l.006.09.008.087.012.092.016.09.02.09.023.084.028.086.03.08.035.08.01.02.062.127.032.057.032.052.082.123.052.068.05.06.038.045.1.102.056.052.066.054.112.084.058.038.126.073 1.37.7.02.01-1.84.823-1.838.825-.014-.012a9.7 9.7 0 0 1-.847-.83l.01-.065.11-.773.106-.742.007-.052-.51-.328-1.77-1.143-.147-.094.014-.077.05-.232.045-.184.056-.177.04-.114.05-.13.05-.112.066-.127.064-.107.078-.117.078-.1.084-.095.087-.086.006-.006.093-.08.043-.034.056-.04.104-.07.065-.038.08-.043.132-.06.034-.015.082-.03.15-.048.047-.012.042-.01.167-.033.042-.005h.02l.063-.008.104-.006.04-.002h.1l.118.003.098.007.045.006.133.018.09.017.072.017.145.04.062.02.14.053.058.026.048.024.082.042.05.027.078.05.048.033.075.058.05.04zm-2.97 3.31l.064.233.054.19.258.04.027.005.076-.1.077-.1.035-.042.066-.072.05-.05.078-.072.044-.036.085-.063.048-.033.09-.055.056-.03.09-.043.054-.022.1-.035.054-.016.108-.026.056-.01.103-.013.054-.004.113-.004h.05l.106.006.054.007.102.015.052.012.097.024.05.017.092.035.048.02.087.044.044.025.08.052.04.03.075.058.036.032.068.065.033.035.06.072.03.038.054.078.024.04.046.083.02.044.04.09.016.047.03.098.012.053.02.107.006.056.01.117v.055l-.003.115-.006.055-.013.106-.012.052-.024.103-.02.052-.032.095-.023.045-.018.033-.043.078-.044.07-.035.052-.05.067-.053.062-.055.058-.063.06-.068.055-.073.052-.078.048-.082.042-.086.038-.09.032-.092.025-.095.018-.095.012-.1.004-.097-.002-.096-.008-.094-.015-.094-.022-.092-.028-.088-.035-.086-.042-.082-.048-.078-.055-.073-.06-.07-.067-.064-.073-.057-.077-.053-.082-.046-.087-.04-.09-.032-.094-.025-.097-.017-.1-.01-.1-.001-.1.006-.1.013-.1.02-.097.028-.095.034-.09.042-.088.048-.082.055-.078.06-.072.067-.066.073-.06.077-.053.082-.046.07-.033-.128-.11-.056-.107-.017-.037-.045-.106-.034-.098-.024-.09-.03-.137-.013-.09-.008-.088-.006-.14v-.088l.005-.14.01-.138.008-.068.01-.065.028-.135.024-.087.035-.116.04-.106.046-.104.058-.11.06-.096.065-.088.078-.098.078-.084.086-.08.09-.072.095-.062.108-.06.102-.044.103-.037.107-.03.11-.02.113-.013.115-.004.116.004.062.005.106.014.06.012.106.027.057.018.098.036.056.025.093.046.05.03.087.055.048.035.082.065.044.04.074.074.036.04.05.058.03.04c-.234.176-.46.362-.676.558l-.043-.16zm5.5.27l.018.062.065.22.3-.24.27-.216.078.166.007.016.04.084.052.098.055.088.064.09.07.085.075.078.082.073.087.066.092.06.097.052.102.044.106.036.11.027.112.018.115.008.115-.002.115-.012.112-.022.11-.03.105-.042.1-.05.095-.058.09-.066.082-.074.077-.08.07-.088.062-.093.055-.1.047-.104.038-.108.03-.112.02-.114.01-.116v-.116l-.012-.115-.02-.113-.03-.11-.04-.107-.048-.103-.057-.098-.065-.092-.072-.086-.08-.08-.086-.072-.092-.064-.097-.055-.1-.046-.106-.037-.11-.026-.112-.017-.113-.006-.113.004-.11.015-.11.024-.107.033-.1.043-.097.052-.09.06-.085.07-.078.077-.07.084-.064.09-.055.097-.016.03.046-.09.043-.068.034-.05.063-.08.055-.065.047-.05.074-.07.06-.053.056-.044.084-.06.06-.04.063-.038.09-.048.065-.03.067-.028.092-.033.066-.02.073-.018.096-.02.073-.01.076-.008.1-.004.072-.001h.003l.077.003.097.007.076.01.075.011.097.02.076.02.073.023.092.034.072.03.068.034.088.048.066.04.064.044.082.062.06.05.058.053.074.075.054.06.05.06.065.086.048.07.043.068.055.098.04.082.036.08.042.107.03.092.024.088.026.12.017.102.012.098.008.13.002.113v.107l-.008.14-.012.12-.02.116-.03.13-.04.127-.048.12-.06.118-.068.11-.077.106-.087.1-.095.09-.102.083-.11.073-.114.062-.12.052-.125.04-.127.027-.13.016-.132.003-.132-.01-.13-.023-.126-.035-.122-.048-.117-.06-.11-.07-.104-.082-.097-.092-.087-.1-.08-.11-.07-.118-.058-.123-.048-.13-.036-.133-.024-.136-.01-.14.003-.14.013-.126.006-.04.018-.087c.26-.154.53-.293.808-.414zm-4.61 1.18l1.58.785 1.638.814-1.87.84-.004.002-1.958.88-.182-.37a9.7 9.7 0 0 1-.542-1.53l.068-.105.43-.663.84-1.294v-.36zm3.93 1.95l1.998-.894 1.898-.85.003.023a9.67 9.67 0 0 1-.283 1.633l-.328.248-.773.588-1.104.84-.668-.302-.744-.334v-.953z" fill="currentColor"/>
                                        </svg>
                                        <span
                                            class="px-2 py-1 text-xs font-bold uppercase tracking-wide bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded">
                                            New
                                        </span>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold mb-2">Kubernetes</h3>
                                        <p class="text-sm dark:text-neutral-400">
                                            Deploy to Kubernetes, OKD, or OpenShift clusters with native support.
                                        </p>
                                    </div>
                                </div>
                            </button>
                        </div>

                        @if (!$serverReachable)
                            <div class="mt-6 p-4 border border-error rounded-lg text-gray-800 dark:text-gray-200">
                                <h2 class="text-lg font-bold mb-2">Server is not reachable</h2>
                                <p class="mb-4">Please check the connection details below and correct them if they are
                                    incorrect.</p>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <x-forms.input placeholder="Default is 22" label="Port" id="remoteServerPort"
                                        wire:model="remoteServerPort" :value="$remoteServerPort" />
                                    <div>
                                        <x-forms.input placeholder="Default is root" label="User" id="remoteServerUser"
                                            wire:model="remoteServerUser" :value="$remoteServerUser" />
                                        <p class="text-xs mt-1">
                                            Non-root user is experimental:
                                            <a class="font-bold underline" target="_blank"
                                                href="https://coolify.io/docs/knowledge-base/server/non-root-user">docs</a>
                                        </p>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <p class="mb-2">If the connection details are correct, please ensure:</p>
                                    <ul class="list-disc list-inside">
                                        <li>The correct public key is in your <code
                                                class="bg-red-200 dark:bg-red-900 px-1 rounded-sm">~/.ssh/authorized_keys</code>
                                            file for the specified user</li>
                                        <li>Or skip the boarding process and manually add a new private key to Coolify and
                                            the server</li>
                                    </ul>
                                </div>

                                <p class="mb-4">
                                    For more help, check this <a target="_blank" class="underline font-semibold"
                                        href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a>.
                                </p>

                                <x-forms.input readonly id="serverPublicKey" class="mb-4"
                                    label="Current Public Key"></x-forms.input>

                                <x-forms.button class="w-full box-boarding" wire:click="saveAndValidateServer">
                                    Check Again
                                </x-forms.button>
                            </div>
                        @endif
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Servers" /> host your applications, databases, and services (collectively
                            called resources). All CPU-intensive operations run on the target server.
                        </p>
                        <p>
                            <x-highlighted text="Localhost:" /> The machine running Coolify. Not recommended for production
                            workloads due to resource contention.
                        </p>
                        <p>
                            <x-highlighted text="Remote Server:" /> Any SSH-accessible server—cloud providers (AWS, Hetzner,
                            DigitalOcean), bare metal, or self-hosted infrastructure.
                        </p>
                        <p>
                            <x-highlighted text="Kubernetes:" /> Deploy directly to Kubernetes, OKD, or OpenShift clusters
                            with automatic manifest generation, scaling, and health checks.
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'private-key')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="SSH Authentication">
                    <x-slot:question>
                        Configure SSH key-based authentication for secure server access.
                    </x-slot:question>
                    <x-slot:actions>
                        @if ($privateKeys && $privateKeys->count() > 0)
                            <div class="w-full space-y-4">
                                <div class="p-4 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                                    <form wire:submit='selectExistingPrivateKey' class="flex flex-col gap-4">
                                        <x-forms.select label="Existing SSH Keys" id='selectedExistingPrivateKey'>
                                            @foreach ($privateKeys as $privateKey)
                                                <option wire:key="{{ $loop->index }}" value="{{ $privateKey->id }}">
                                                    {{ $privateKey->name }}
                                                </option>
                                            @endforeach
                                        </x-forms.select>
                                        <x-forms.button type="submit" class="w-full lg:w-auto">Use Selected Key</x-forms.button>
                                    </form>
                                </div>
                                <div class="relative">
                                    <div class="absolute inset-0 flex items-center">
                                        <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                                    </div>
                                    <div class="relative flex justify-center text-sm">
                                        <div
                                            class="px-2 py-1 bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-300 rounded text-xs font-bold text-neutral-500 dark:text-neutral-400">
                                            OR
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 w-full">
                            <x-forms.button
                                class="justify-center h-auto py-6 box-without-bg hover:border-coollabs transition-all duration-200"
                                wire:target="setPrivateKey('own')" wire:click="setPrivateKey('own')">
                                <div class="flex flex-col items-center gap-2">
                                    <svg class="size-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                                    </svg>
                                    <div class="text-center">
                                        <h3 class="text-xl font-bold mb-2">Use Existing Key</h3>
                                        <p class="text-sm dark:text-neutral-400">I have my own SSH key</p>
                                    </div>
                                </div>
                            </x-forms.button>
                            <x-forms.button
                                class="justify-center h-auto py-6 box-without-bg hover:border-coollabs transition-all duration-200"
                                wire:target="setPrivateKey('create')" wire:click="setPrivateKey('create')">
                                <div class="flex flex-col items-center gap-2">
                                    <svg class="size-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z" />
                                    </svg>
                                    <div class="text-center">
                                        <h3 class="text-xl font-bold mb-2">Generate New Key</h3>
                                        <p class="text-sm dark:text-neutral-400">Create ED25519 key pair</p>
                                    </div>
                                </div>
                            </x-forms.button>
                        </div>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="SSH Key Authentication:" /> Uses public-key cryptography for secure,
                            password-less server access.
                        </p>
                        <p>
                            <x-highlighted text="Public Key Deployment:" /> Add the public key to your server's
                            <code
                                class="text-xs bg-coolgray-300 dark:bg-coolgray-400 px-1 py-0.5 rounded">~/.ssh/authorized_keys</code>
                            file.
                        </p>
                        <p>
                            <x-highlighted text="Key Generation:" /> Coolify generates ED25519 keys by default for optimal
                            security and performance.
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'create-private-key')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="SSH Key Configuration">
                    <x-slot:question>
                        Configure your SSH key for server authentication.
                    </x-slot:question>
                    <x-slot:actions>
                        <form wire:submit='savePrivateKey' class="flex flex-col w-full gap-4">
                            <x-forms.input required placeholder="e.g., production-server-key" label="Key Name"
                                id="privateKeyName" />
                            <x-forms.input placeholder="Optional: Note what this key is used for" label="Description"
                                id="privateKeyDescription" />
                            @if ($privateKeyType === 'create')
                                <x-forms.textarea required readonly label="Private Key" id="privateKey" rows="8" />
                                <x-forms.textarea rows="7" readonly label="Public Key" id="publicKey" />
                            @else
                                <x-forms.textarea required placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" label="Private Key"
                                    id="privateKey" rows="8" />
                            @endif
                            @if ($privateKeyType === 'create')
                                <div class="p-4 bg-warning/10 border border-warning rounded-lg">
                                    <div class="flex gap-3">
                                        <svg class="size-5 text-warning flex-shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd"
                                                d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z"
                                                clip-rule="evenodd" />
                                        </svg>
                                        <div>
                                            <p class="font-bold text-warning mb-1">Action Required</p>
                                            <p class="text-sm dark:text-white text-black">
                                                Copy the public key above and add it to your server's
                                                <code
                                                    class="text-xs bg-coolgray-300 dark:bg-coolgray-400 px-1 py-0.5 rounded">~/.ssh/authorized_keys</code>
                                                file.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            @endif
                            <x-forms.button type="submit" class="w-full lg:w-auto">Save SSH Key</x-forms.button>
                        </form>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Key Storage:" /> Private keys are encrypted at rest in Coolify's database.
                        </p>
                        <p>
                            <x-highlighted text="Public Key Distribution:" /> Deploy the public key to
                            <code
                                class="text-xs bg-coolgray-300 dark:bg-coolgray-400 px-1 py-0.5 rounded">~/.ssh/authorized_keys</code>
                            on your target server for the specified user.
                        </p>
                        <p>
                            <x-highlighted text="Key Format:" /> Supports RSA, ED25519, ECDSA, and DSA key types in OpenSSH
                            format.
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'create-server')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="Server Configuration">
                    <x-slot:question>
                        Provide connection details for your remote server.
                    </x-slot:question>
                    <x-slot:actions>
                        <form wire:submit='saveServer' class="flex flex-col w-full gap-4">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <x-forms.input required placeholder="e.g., production-app-server" label="Server Name"
                                    id="remoteServerName" wire:model="remoteServerName" />
                                <x-forms.input required placeholder="IP address or hostname" label="IP Address/Hostname"
                                    id="remoteServerHost" wire:model="remoteServerHost" />
                            </div>
                            <x-forms.input placeholder="Optional: Note what this server hosts" label="Description"
                                id="remoteServerDescription" wire:model="remoteServerDescription" />

                            <div x-data="{ showAdvanced: false }" class="flex flex-col gap-4">
                                <button @click="showAdvanced = !showAdvanced" type="button"
                                    class="flex items-center gap-2 text-left text-sm font-medium  hover:underline">
                                    <svg x-show="!showAdvanced" class="size-4" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z"
                                            clip-rule="evenodd" />
                                    </svg>
                                    <svg x-show="showAdvanced" class="size-4" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                                            clip-rule="evenodd" />
                                    </svg>
                                    Advanced Connection Settings
                                </button>
                                <div x-show="showAdvanced" x-cloak
                                    class="grid grid-cols-1 lg:grid-cols-2 gap-4 p-4 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                                    <x-forms.input placeholder="Default: 22" label="SSH Port" type="number"
                                        id="remoteServerPort" wire:model="remoteServerPort" />
                                    <div>
                                        <x-forms.input placeholder="Default: root" label="SSH User" id="remoteServerUser"
                                            wire:model="remoteServerUser" />
                                        <p class="mt-1 text-xs dark:text-white text-black">
                                            Non-root user support is experimental.
                                            <a class="font-bold underline hover:text-coollabs" target="_blank"
                                                href="https://coolify.io/docs/knowledge-base/server/non-root-user">Learn
                                                more</a>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <x-forms.button type="submit" class="w-full lg:w-auto">Validate Connection</x-forms.button>
                        </form>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Connection Requirements:" /> Server must be accessible via SSH on the
                            specified port (default 22).
                        </p>
                        <p>
                            <x-highlighted text="Hostname Resolution:" /> Use IP addresses for direct connections or ensure
                            DNS resolution is configured.
                        </p>
                        <p>
                            <x-highlighted text="User Permissions:" /> Root or sudo-enabled users recommended for full
                            Docker
                            management capabilities.
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'validate-server')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="Server Validation">
                    <x-slot:question>
                        Coolify will automatically install Docker {{ $minDockerVersion }}+ if not present.
                    </x-slot:question>
                    <x-slot:actions>
                        <div class="w-full space-y-6">
                            <div
                                class="p-6 bg-neutral-50 dark:bg-coolgray-200 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                                <h3 class="font-bold text-black dark:text-white mb-4">Validation Steps</h3>
                                <div class="space-y-3">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-base dark:text-white">Test SSH Connection</div>
                                            <div class="text-sm dark:text-neutral-400">Verify key-based authentication</div>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-base dark:text-white">Check OS Compatibility
                                            </div>
                                            <div class="text-sm dark:text-neutral-400">Verify supported Linux distribution
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-base dark:text-white">Install Docker Engine</div>
                                            <div class="text-sm dark:text-neutral-400">Auto-install if version
                                                {{ $minDockerVersion }}+ not
                                                found
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-base dark:text-white">Configure Network</div>
                                            <div class="text-sm dark:text-neutral-400">Set up Docker networks and proxy
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @if ($prerequisiteInstallAttempts > 0)
                                <div class="p-6 bg-neutral-50 dark:bg-coolgray-200 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                                    <h3 class="font-bold text-black dark:text-white mb-4">Installing Prerequisites</h3>
                                    <livewire:activity-monitor header="Prerequisites Installation Logs" :showWaiting="false" />
                                </div>
                            @endif

                            <x-slide-over closeWithX fullScreen>
                                <x-slot:title>Server Validation</x-slot:title>
                                <x-slot:content>
                                    <livewire:server.validate-and-install :server="$this->createdServer" />
                                </x-slot:content>
                                <x-forms.button @click="slideOverOpen=true" class="w-full font-bold py-4 box-boarding"
                                    wire:click.prevent='installServer' isHighlighted>
                                    Start Validation
                                </x-forms.button>
                            </x-slide-over>
                        </div>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Automated Setup:" /> Coolify installs Docker Engine, Docker Compose, and
                            configures system requirements automatically.
                        </p>
                        <p>
                            <x-highlighted text="Version Requirements:" /> Minimum Docker Engine {{ $minDockerVersion }}.x
                            required.
                            <a target="_blank" class="underline hover:text-coollabs"
                                href="https://docs.docker.com/engine/install/#server">Manual installation guide</a>
                        </p>
                        <p>
                            <x-highlighted text="System Configuration:" /> Sets up Docker networks, proxy configuration, and
                            resource monitoring.
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'create-project')
                <x-boarding-progress :currentStep="3" />
                <x-boarding-step title="Project Setup">
                    <x-slot:question>
                        @if ($projects && $projects->count() > 0)
                            You have existing projects. Select one or create a new project to organize your resources.
                        @else
                            Create your first project to organize applications, databases, and services.
                        @endif
                    </x-slot:question>
                    <x-slot:actions>
                        <div class="w-full space-y-4">
                            <x-forms.button class="justify-center w-full py-4 font-bold box-boarding"
                                wire:click="createNewProject" isHighlighted>
                                Create "My First Project"
                            </x-forms.button>

                            @if ($projects && $projects->count() > 0)
                                <div class="relative">
                                    <div class="absolute inset-0 flex items-center">
                                        <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                                    </div>
                                    <div class="relative flex justify-center text-sm">
                                        <span class="px-2 text-neutral-500 dark:text-neutral-400">Or use existing</span>
                                    </div>
                                </div>
                                <form wire:submit='selectExistingProject' class="flex flex-col gap-4">
                                    <x-forms.select label="Existing Projects" id='selectedProject'>
                                        @foreach ($projects as $project)
                                            <option wire:key="{{ $loop->index }}" value="{{ $project->id }}">
                                                {{ $project->name }}
                                            </option>
                                        @endforeach
                                    </x-forms.select>
                                    <x-forms.button type="submit" class="w-full lg:w-auto">Use Selected Project</x-forms.button>
                                </form>
                            @endif
                        </div>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Project Organization:" /> Group related resources (apps, databases,
                            services)
                            into logical projects.
                        </p>
                        <p>
                            <x-highlighted text="Environments:" /> Each project includes a production environment by
                            default.
                            Add staging, development, or custom environments as needed.
                        </p>
                        <p>
                            <x-highlighted text="Team Access:" /> Projects inherit team permissions and can be managed
                            collaboratively.
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'create-resource')
                <x-boarding-progress :currentStep="3" />
                <div class="w-full max-w-2xl text-center space-y-8">
                    <div class="space-y-4">
                        <div class="flex justify-center">
                            <svg class="size-16 text-success" xmlns="http://www.w3.org/2000/svg" fill="none"
                                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h1 class="text-4xl font-bold lg:text-5xl">Setup Complete!</h1>
                        <p class="text-lg dark:text-neutral-400">
                            Your server is connected and ready. Start deploying your first resource.
                        </p>
                    </div>

                    <div class="text-left space-y-4 p-8 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                        <h2 class="text-sm font-bold uppercase tracking-wide dark:text-neutral-400">
                            What's Configured
                        </h2>
                        <div class="space-y-3">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">Server: {{ $createdServer->name }}
                                    </div>
                                    <div class="text-sm dark:text-neutral-400">{{ $createdServer->ip }}</div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">Project:
                                        {{ $createdProject->name }}
                                    </div>
                                    <div class="text-sm dark:text-neutral-400">Production environment ready</div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">Docker Engine</div>
                                    <div class="text-sm dark:text-neutral-400">Installed and running</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        <x-forms.button class="justify-center w-full py-4 text-lg font-bold box-boarding"
                            wire:click="showNewResource" isHighlighted>
                            Deploy Your First Resource
                        </x-forms.button>
                        <button wire:click="skipBoarding"
                            class="text-sm dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                            Go to Dashboard
                        </button>
                    </div>
                </div>
            @endif
        </div>

        @if ($currentState !== 'welcome' && $currentState !== 'create-resource')
            <div class="flex flex-col items-center gap-4 pt-8 mt-8 border-t border-neutral-200 dark:border-coolgray-400">
                <div class="flex justify-center gap-6 text-sm">
                    <button wire:click='skipBoarding'
                        class="dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                        Skip Setup
                    </button>
                    <button wire:click='restartBoarding'
                        class="dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                        Restart
                    </button>
                </div>
                <x-modal-input title="Need Help?">
                    <x-slot:content>
                        <button
                            class="text-sm dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                            Contact Support
                        </button>
                    </x-slot:content>
                    <livewire:help />
                </x-modal-input>
            </div>
        @endif
    </section>
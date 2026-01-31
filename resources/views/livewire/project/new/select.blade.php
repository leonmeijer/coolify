<div x-data x-init="$wire.loadServers">
    <div x-data="searchResources()">
        @if ($current_step === 'type')
            <div x-init="window.addEventListener('scroll', () => isSticky = window.pageYOffset > 100)"
                class="sticky z-10 top-0  backdrop-blur-sm border-b border-neutral-200 dark:border-coolgray-400">
                <div class="flex flex-col gap-4 lg:flex-row">
                    <h1>New Resource</h1>
                    <div class="w-full lg:w-96">
                        <x-forms.select wire:model.live="selectedEnvironment">
                            @foreach ($environments as $environment)
                                <option value="{{ $environment->name }}">Environment: {{ $environment->name }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                </div>
                <div class="mb-4">Deploy resources, like Applications, Databases, Services...</div>
                <div class="flex gap-2 items-start">
                    <input autocomplete="off" x-ref="searchInput" class="input-sticky flex-1"
                        :class="{ 'input-sticky-active': isSticky }" x-model="search" placeholder="Type / to search..."
                        @keydown.window.slash.prevent="$refs.searchInput.focus()">
                    <!-- Category Filter Dropdown -->
                    <div class="relative" x-data="{ openCategoryDropdown: false, categorySearch: '' }" @click.outside="openCategoryDropdown = false">
                        <!-- Loading/Disabled State -->
                        <div x-show="loading || categories.length === 0"
                            class="flex items-center justify-between gap-2 py-1.5 px-3 w-64 text-sm rounded-sm border-0 ring-2 ring-inset ring-neutral-200 dark:ring-coolgray-300 bg-neutral-100 dark:bg-coolgray-200 cursor-not-allowed whitespace-nowrap opacity-50">
                            <span class="text-sm text-neutral-400 dark:text-neutral-600">Filter by category</span>
                            <svg class="w-4 h-4 text-neutral-400 shrink-0" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>
                        <!-- Active State -->
                        <div x-show="!loading && categories.length > 0"
                            @click="openCategoryDropdown = !openCategoryDropdown; $nextTick(() => { if (openCategoryDropdown) $refs.categorySearchInput.focus() })"
                            class="flex items-center justify-between gap-2 py-1.5 px-3 w-64 text-sm rounded-sm border-0 ring-2 ring-inset ring-neutral-200 dark:ring-coolgray-300 bg-white dark:bg-coolgray-100 cursor-pointer hover:ring-coolgray-400 transition-all whitespace-nowrap">
                            <span class="text-sm truncate"
                                x-text="selectedCategory === '' ? 'Filter by category' : selectedCategory"
                                :class="selectedCategory === '' ? 'text-neutral-400 dark:text-neutral-600' :
                                    'capitalize text-black dark:text-white'"></span>
                            <svg class="w-4 h-4 transition-transform text-neutral-400 shrink-0"
                                :class="{ 'rotate-180': openCategoryDropdown }" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>
                        <!-- Dropdown Menu -->
                        <div x-show="openCategoryDropdown" x-transition
                            class="absolute z-50 w-full mt-1 bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-400 rounded shadow-lg overflow-hidden">
                            <div
                                class="sticky top-0 p-2 bg-white dark:bg-coolgray-100 border-b border-neutral-300 dark:border-coolgray-400">
                                <input type="text" x-ref="categorySearchInput" x-model="categorySearch"
                                    placeholder="Search categories..."
                                    class="w-full px-2 py-1 text-sm rounded border border-neutral-300 dark:border-coolgray-400 bg-white dark:bg-coolgray-200 focus:outline-none focus:ring-2 focus:ring-coolgray-400"
                                    @click.stop>
                            </div>
                            <div class="max-h-60 overflow-auto scrollbar">
                                <div @click="selectedCategory = ''; categorySearch = ''; openCategoryDropdown = false"
                                    class="px-3 py-2 cursor-pointer hover:bg-neutral-100 dark:hover:bg-coolgray-200"
                                    :class="{ 'bg-neutral-50 dark:bg-coolgray-300': selectedCategory === '' }">
                                    <span class="text-sm">All Categories</span>
                                </div>
                                <template
                                    x-for="category in categories.filter(cat => categorySearch === '' || cat.toLowerCase().includes(categorySearch.toLowerCase()))"
                                    :key="category">
                                    <div @click="selectedCategory = category; categorySearch = ''; openCategoryDropdown = false"
                                        class="px-3 py-2 cursor-pointer hover:bg-neutral-100 dark:hover:bg-coolgray-200 capitalize"
                                        :class="{ 'bg-neutral-50 dark:bg-coolgray-300': selectedCategory === category }">
                                        <span class="text-sm" x-text="category"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div x-show="loading">Loading...</div>
            <div x-show="!loading" class="flex flex-col gap-4 py-4">
                <h2 x-show="filteredGitBasedApplications.length > 0">Applications</h2>
                <div x-show="filteredGitBasedApplications.length > 0 || filteredDockerBasedApplications.length > 0"
                    class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div x-show="filteredGitBasedApplications.length > 0" class="space-y-4">
                        <h4>Git Based</h4>
                        <div class="grid justify-start grid-cols-1 gap-4 text-left">
                            <template x-for="application in filteredGitBasedApplications" :key="application.name">
                                <div x-on:click='setType(application.id)'
                                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }">
                                    <x-resource-view>
                                        <x-slot:title><span x-text="application.name"></span></x-slot>
                                        <x-slot:description>
                                            <span x-html="window.sanitizeHTML(application.description)"></span>
                                        </x-slot>
                                        <x-slot:logo>
                                            <img class="w-full h-full p-2 transition-all duration-200 dark:bg-white/10 bg-black/10 object-contain"
                                                :src="application.logo">
                                        </x-slot:logo>
                                    </x-resource-view>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div x-show="filteredDockerBasedApplications.length > 0" class="space-y-4">
                        <h4>Docker Based</h4>
                        <div class="grid justify-start grid-cols-1 gap-4 text-left">
                            <template x-for="application in filteredDockerBasedApplications" :key="application.name">
                                <div x-on:click="setType(application.id)"
                                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }">
                                    <x-resource-view>
                                        <x-slot:title><span x-text="application.name"></span></x-slot>
                                        <x-slot:description><span x-text="application.description"></span></x-slot>
                                        <x-slot:logo> <img
                                                class="w-full h-full p-2 transition-all duration-200 dark:bg-white/10 bg-black/10 object-contain"
                                                :src="application.logo"></x-slot>
                                    </x-resource-view>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
                <div x-show="filteredDatabases.length > 0" class="mt-8">
                    <h2 class="mb-4">Databases</h2>
                    <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-3">
                        <template x-for="database in filteredDatabases" :key="database.id">
                            <div x-on:click="setType(database.id)"
                                :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }">
                                <x-resource-view>
                                    <x-slot:title><span x-text="database.name"></span></x-slot>
                                    <x-slot:description><span x-text="database.description"></span></x-slot>
                                    <x-slot:logo>
                                        <span x-show="database.logo">
                                            <span x-html="database.logo"></span>
                                        </span>
                                    </x-slot>
                                </x-resource-view>
                            </div>
                        </template>
                    </div>
                </div>
                <div x-show="filteredServices.length > 0" class="mt-8">
                    <div class="flex items-center gap-4" x-init="loadResources">
                        <h2>Services</h2>
                        <x-forms.button x-on:click="loadResources">Reload List</x-forms.button>
                    </div>
                    <x-callout type="info" title="Trademarks Policy" class="mt-4 mb-6">
                        The respective trademarks mentioned here are owned by the respective companies, and use of them
                        does not imply any affiliation or endorsement.
                    </x-callout>

                    <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-3">
                        <template x-for="service in filteredServices" :key="service.name">
                            <div class="relative" x-on:click="setType('one-click-service-' + service.name)"
                                :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }">
                                <x-resource-view>
                                    <x-slot:title>
                                        <template x-if="service.name">
                                            <span x-text="service.name"></span>
                                        </template>
                                    </x-slot>
                                    <x-slot:description>
                                        <template x-if="service.slogan">
                                            <span x-text="service.slogan"></span>
                                        </template>
                                    </x-slot>
                                    <x-slot:logo>
                                        <template x-if="service.logo">
                                            <img class="w-full h-full p-2 transition-all duration-200 dark:bg-white/10 bg-black/10 object-contain"
                                                :src='service.logo'
                                                x-on:error.window="$event.target.src = service.logo_github_url"
                                                onerror="this.onerror=null; this.src=this.getAttribute('data-fallback');"
                                                x-on:error="$event.target.src = '/coolify-logo.svg'"
                                                :data-fallback='service.logo_github_url' />
                                        </template>
                                    </x-slot:logo>
                                </x-resource-view>
                                <template x-if="shouldShowDocIcon(service)">
                                    <a :href="getDocLink(service) || coolifyDocsUrl(service.name)" target="_blank"
                                        @click.stop @mouseenter="resolveDocLink(service)"
                                        class="absolute top-2 right-2 p-1.5 rounded hover:bg-neutral-200 dark:hover:bg-coolgray-300 transition-colors"
                                        :class="{ 'opacity-50': docCheckInProgress[service.name] }"
                                        title="View documentation">
                                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                    </a>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
                <div
                    x-show="filteredGitBasedApplications.length === 0 && filteredDockerBasedApplications.length === 0 && filteredDatabases.length === 0 && filteredServices.length === 0 && loading === false">
                    <div>No resources found.</div>
                </div>
            </div>
            <script>
                function sortFn(a, b) {
                    return a.name.localeCompare(b.name)
                }

                function searchResources() {
                    return {
                        search: '',
                        selectedCategory: '',
                        categories: [],
                        loading: false,
                        isSticky: false,
                        selecting: false,
                        services: [],
                        gitBasedApplications: [],
                        dockerBasedApplications: [],
                        databases: [],
                        docLinkCache: {}, // Cache resolved doc URLs: { serviceName: url | null }
                        docCheckInProgress: {}, // Track ongoing checks: { serviceName: boolean }
                        setType(type) {
                            if (this.selecting) return;
                            this.selecting = true;
                            this.$wire.setType(type);
                        },
                        async loadResources() {
                            this.loading = true;
                            const {
                                services,
                                categories,
                                gitBasedApplications,
                                dockerBasedApplications,
                                databases
                            } = await this.$wire.loadServices();
                            this.services = services;
                            this.categories = categories || [];
                            this.gitBasedApplications = gitBasedApplications;
                            this.dockerBasedApplications = dockerBasedApplications;
                            this.databases = databases;
                            this.loading = false;
                            this.$nextTick(() => {
                                this.$refs.searchInput.focus();
                            });
                        },
                        extractBaseServiceName(serviceName) {
                            // Convert to lowercase and replace spaces with dashes to match original format
                            const normalized = serviceName.toLowerCase().replace(/\s+/g, '-');
                            // Remove flavor suffixes: -with-*, -without-*
                            return normalized.replace(/-(with|without)-.+$/, '');
                        },
                        coolifyDocsUrl(serviceName) {
                            const baseName = this.extractBaseServiceName(serviceName);
                            return 'https://coolify.io/docs/services/' + baseName;
                        },
                        officialDocsUrl(service) {
                            return service.documentation || null;
                        },
                        async checkUrlExists(url) {
                            if (!url) return false;
                            try {
                                const response = await fetch(url, {
                                    method: 'HEAD'
                                });
                                return response.ok;
                            } catch (e) {
                                // CORS error or network error - assume URL exists
                                return true;
                            }
                        },
                        async resolveDocLink(service) {
                            const serviceName = service.name;

                            // Already cached?
                            if (this.docLinkCache.hasOwnProperty(serviceName)) {
                                return this.docLinkCache[serviceName];
                            }

                            // Already checking?
                            if (this.docCheckInProgress[serviceName]) {
                                return null;
                            }

                            this.docCheckInProgress[serviceName] = true;

                            // 1. Try Coolify docs first
                            const coolifyUrl = this.coolifyDocsUrl(serviceName);
                            const coolifyExists = await this.checkUrlExists(coolifyUrl);

                            if (coolifyExists) {
                                this.docLinkCache[serviceName] = coolifyUrl;
                                this.docCheckInProgress[serviceName] = false;
                                return coolifyUrl;
                            }

                            // 2. Fall back to official docs
                            const officialUrl = this.officialDocsUrl(service);
                            if (officialUrl) {
                                const officialExists = await this.checkUrlExists(officialUrl);

                                if (officialExists) {
                                    this.docLinkCache[serviceName] = officialUrl;
                                    this.docCheckInProgress[serviceName] = false;
                                    return officialUrl;
                                }
                            }

                            // 3. Both failed - cache null to hide icon
                            this.docLinkCache[serviceName] = null;
                            this.docCheckInProgress[serviceName] = false;
                            return null;
                        },
                        getDocLink(service) {
                            return this.docLinkCache[service.name];
                        },
                        shouldShowDocIcon(service) {
                            const cached = this.docLinkCache[service.name];
                            // Show icon if: not checked yet OR has a valid URL
                            return cached === undefined || cached !== null;
                        },
                        filterAndSort(items, isSort = true) {
                            const searchLower = this.search.trim().toLowerCase();
                            let filtered = Object.values(items);

                            // Filter by category if selected
                            if (this.selectedCategory !== '') {
                                const selectedCategoryLower = this.selectedCategory.toLowerCase();
                                filtered = filtered.filter(item => {
                                    if (!item.category) return false;
                                    // Handle comma-separated categories
                                    const categories = item.category.includes(',') ?
                                        item.category.split(',').map(c => c.trim().toLowerCase()) : [item.category
                                            .toLowerCase()
                                        ];
                                    return categories.includes(selectedCategoryLower);
                                });
                            }

                            // Filter by search term
                            if (searchLower !== '') {
                                filtered = filtered.filter(item => {
                                    return (item.name?.toLowerCase().includes(searchLower) ||
                                        item.description?.toLowerCase().includes(searchLower) ||
                                        item.slogan?.toLowerCase().includes(searchLower))
                                });
                            }

                            return isSort ? filtered.sort(sortFn) : filtered;
                        },
                        get filteredGitBasedApplications() {
                            if (this.gitBasedApplications.length === 0) {
                                return [];
                            }
                            return [
                                this.gitBasedApplications,
                            ].flatMap((items) => this.filterAndSort(items, false));
                        },
                        get filteredDockerBasedApplications() {
                            if (this.dockerBasedApplications.length === 0) {
                                return [];
                            }
                            return [
                                this.dockerBasedApplications,
                            ].flatMap((items) => this.filterAndSort(items, false));
                        },
                        get filteredDatabases() {
                            if (this.databases.length === 0) {
                                return [];
                            }
                            return [
                                this.databases,
                            ].flatMap((items) => this.filterAndSort(items, false));
                        },
                        get filteredServices() {
                            if (this.services.length === 0) {
                                return [];
                            }
                            return [
                                this.services,
                            ].flatMap((items) => this.filterAndSort(items, true));
                        }
                    }
                }
            </script>
        @endif
    </div>
    @if ($current_step === 'select-deployment-target')
        <h2>Select deployment target</h2>
        <div class="pb-4">Choose where you want to deploy your resource.</div>
        <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
            <div class="w-full coolbox group" wire:click="setDeploymentTarget('docker')">
                <div class="flex flex-col mx-6">
                    <div class="box-title">
                        <svg class="inline-block w-6 h-6 mr-2" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M13.983 11.078h2.119a.186.186 0 0 0 .186-.185V9.006a.186.186 0 0 0-.186-.186h-2.119a.185.185 0 0 0-.185.185v1.888c0 .102.083.185.185.185m-2.954-5.43h2.118a.186.186 0 0 0 .186-.186V3.574a.186.186 0 0 0-.186-.185h-2.118a.185.185 0 0 0-.185.185v1.888c0 .102.082.185.185.186m0 2.716h2.118a.187.187 0 0 0 .186-.186V6.29a.186.186 0 0 0-.186-.185h-2.118a.185.185 0 0 0-.185.185v1.887c0 .102.082.185.185.186m-2.93 0h2.12a.186.186 0 0 0 .184-.186V6.29a.185.185 0 0 0-.185-.185H8.1a.185.185 0 0 0-.185.185v1.887c0 .102.083.185.185.186m-2.964 0h2.119a.186.186 0 0 0 .185-.186V6.29a.185.185 0 0 0-.185-.185H5.136a.186.186 0 0 0-.186.185v1.887c0 .102.084.185.186.186m5.893 2.715h2.118a.186.186 0 0 0 .186-.185V9.006a.186.186 0 0 0-.186-.186h-2.118a.185.185 0 0 0-.185.185v1.888c0 .102.082.185.185.185m-2.93 0h2.12a.185.185 0 0 0 .184-.185V9.006a.185.185 0 0 0-.184-.186h-2.12a.185.185 0 0 0-.184.185v1.888c0 .102.083.185.185.185m-2.964 0h2.119a.185.185 0 0 0 .185-.185V9.006a.185.185 0 0 0-.185-.186H5.136a.186.186 0 0 0-.186.185v1.888c0 .102.084.185.186.185m-2.92 0h2.12a.185.185 0 0 0 .184-.185V9.006a.185.185 0 0 0-.184-.186h-2.12a.185.185 0 0 0-.184.185v1.888c0 .102.082.185.185.185M23.763 9.89c-.065-.051-.672-.51-1.954-.51-.338.001-.676.03-1.01.087-.248-1.7-1.653-2.53-1.716-2.566l-.344-.199-.226.327c-.284.438-.49.922-.612 1.43-.23.97-.09 1.882.403 2.661-.595.332-1.55.413-1.744.42H.751a.751.751 0 0 0-.75.748 11.376 11.376 0 0 0 .692 4.062c.545 1.428 1.355 2.48 2.41 3.124 1.18.723 3.1 1.137 5.275 1.137.983.003 1.963-.086 2.93-.266a12.248 12.248 0 0 0 3.823-1.389c.98-.567 1.86-1.288 2.61-2.136 1.252-1.418 1.998-2.997 2.553-4.4h.221c1.372 0 2.215-.549 2.68-1.009.309-.293.55-.65.707-1.046l.098-.288z"/>
                        </svg>
                        Docker Server
                    </div>
                    <div class="box-description">
                        Deploy to a Docker server managed by Coolify
                    </div>
                </div>
            </div>
            <div class="w-full coolbox group" wire:click="setDeploymentTarget('kubernetes')">
                <div class="flex flex-col mx-6">
                    <div class="box-title">
                        <svg class="inline-block w-6 h-6 mr-2" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M10.204 14.35l.007.01-.999 2.413a5.171 5.171 0 0 1-2.075-2.597l2.578-.437.004.005a.44.44 0 0 1 .484.606zm-.833-2.129a.44.44 0 0 0 .173-.756l.002-.011L7.585 9.7a5.143 5.143 0 0 0-.73 3.255l2.514-.725.002-.009zm1.145-1.98a.44.44 0 0 0 .699-.337l.01-.005.15-2.62a5.144 5.144 0 0 0-3.01 1.442l2.147 1.523.004-.002zm.76 2.75l.723.349.722-.347.18-.78-.5-.623h-.804l-.5.623.179.778zm1.5-2.095a.44.44 0 0 0 .7.336l.008.003 2.134-1.513a5.188 5.188 0 0 0-2.992-1.442l.148 2.615.002.001zm10.876 5.97l-5.773 7.181a1.6 1.6 0 0 1-1.248.594H7.37a1.6 1.6 0 0 1-1.248-.593l-5.776-7.182a1.583 1.583 0 0 1-.307-1.34L2.1 5.573c.108-.47.425-.864.863-1.073L11.305.513a1.606 1.606 0 0 1 1.385 0l8.345 3.985c.438.209.755.604.863 1.073l2.062 9.404a1.581 1.581 0 0 1-.308 1.341zm-2.39-4.476l-.022-.05a6.668 6.668 0 0 0-4.883-3.931l-.002-.033c0-.038-.003-.076-.008-.113l-.01-.05a.44.44 0 0 0-.479-.34l-.007.003a6.64 6.64 0 0 0-3.814-1.86L12 4.703l-.006.001a6.64 6.64 0 0 0-3.82 1.862l-.003-.002a.45.45 0 0 0-.48.334l-.015.06a.67.67 0 0 0-.007.105l.001.042-.002.017a6.662 6.662 0 0 0-4.873 3.937l-.023.052-.016.074a.44.44 0 0 0 .265.533l.018.004a6.632 6.632 0 0 0 1.006 5.658l-.004.016a.64.64 0 0 0-.005.082v.053a.45.45 0 0 0 .447.447l.018-.002a6.617 6.617 0 0 0 4.632 3.053l.012.009.003.01a.67.67 0 0 0 .05.097l.028.053a.44.44 0 0 0 .575.19l.019-.012a6.632 6.632 0 0 0 5.768 0l.018.012a.44.44 0 0 0 .576-.187l.033-.065a.44.44 0 0 0 .045-.085l.001-.007.016-.012a6.617 6.617 0 0 0 4.624-3.05l.026.002a.45.45 0 0 0 .447-.447v-.074l-.006-.066-.003-.015a6.627 6.627 0 0 0 1.01-5.658l.009-.003a.44.44 0 0 0 .263-.53l-.014-.072zm-8.668 6.99a.44.44 0 0 0-.16.732l-.003.005 1.963 1.622a5.147 5.147 0 0 0 2.217-2.626l-2.702.08-.004-.007-.003.001-1.308.193zm.426-1.038a.44.44 0 0 0 .77-.086l.003.002 1.083-2.372a5.188 5.188 0 0 0-3.327-.021l1.46 2.48.01-.003zm1.477.682l-.003-.002a.44.44 0 0 0-.163-.735l-1.298-.195-.003.01-.003-.008-2.714-.078a5.142 5.142 0 0 0 2.219 2.632l1.965-1.624zm3.142-5.478l.001.01a.44.44 0 0 0 .17.754l-.001.012 2.52.729a5.144 5.144 0 0 0-.734-3.26l-1.955 1.755zm-.765 1.39a.44.44 0 0 0 .769.085l.001-.001 1.457-2.474a5.183 5.183 0 0 0-3.317.012l1.087 2.378.003-.001z"/>
                        </svg>
                        Kubernetes Cluster
                    </div>
                    <div class="box-description">
                        Deploy to a Kubernetes cluster (OKD, OpenShift, K3s, etc.)
                    </div>
                </div>
            </div>
        </div>
    @endif
    @if ($current_step === 'servers')
        <h2>Select a server</h2>
        <div class="pb-5"></div>
        <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
            @if ($onlyBuildServerAvailable)
                <div> Only build servers are available, you need at least one server that is not set as build
                    server. <a class="underline dark:text-white" href="/servers" {{ wireNavigate() }}>
                        Go to servers page
                    </a> </div>
            @else
                @forelse($servers as $server)
                    <div class="w-full coolbox group" wire:click="setServer({{ $server }})">
                        <div class="flex flex-col mx-6">
                            <div class="box-title">
                                {{ $server->name }}
                            </div>
                            <div class="box-description">
                                {{ $server->description }}
                            </div>
                        </div>
                    </div>
                @empty
                    <div>

                        <div>No validated & reachable servers found. <a class="underline dark:text-white"
                                href="/servers" {{ wireNavigate() }}>
                                Go to servers page
                            </a></div>
                    </div>
                @endforelse
            @endif
        </div>
    @endif
    @if ($current_step === 'destinations')
        <h2>Select a destination</h2>
        <div class="pb-4">Destinations are used to segregate resources by network. If you are unsure, select the
            default
            Standalone Docker (coolify).</div>
        <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
            @if ($server->isSwarm())
                @foreach ($swarmDockers as $swarmDocker)
                    <div class="w-full coolbox group" wire:click="setDestination('{{ $swarmDocker->uuid }}')">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold dark:group-hover:text-white">
                                Swarm Docker <span class="text-xs">({{ $swarmDocker->name }})</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            @else
                @foreach ($standaloneDockers as $standaloneDocker)
                    <div class="w-full coolbox group" wire:click="setDestination('{{ $standaloneDocker->uuid }}')">
                        <div class="flex flex-col mx-6">
                            <div class="box-title">
                                Standalone Docker <span class="text-xs">({{ $standaloneDocker->name }})</span>
                            </div>
                            <div class="box-description">
                                Network: {{ $standaloneDocker->network }}</div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    @endif
    @if ($current_step === 'kubernetes-clusters')
        <h2>Select a Kubernetes cluster</h2>
        <div class="pb-4">Choose the Kubernetes cluster where you want to deploy your resource.</div>
        <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
            @forelse($kubernetesClusters as $cluster)
                <div class="w-full coolbox group" wire:click="setKubernetesCluster('{{ $cluster->uuid }}')">
                    <div class="flex flex-col mx-6">
                        <div class="box-title">
                            <svg class="inline-block w-5 h-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M10.204 14.35l.007.01-.999 2.413a5.171 5.171 0 0 1-2.075-2.597l2.578-.437.004.005a.44.44 0 0 1 .484.606zm-.833-2.129a.44.44 0 0 0 .173-.756l.002-.011L7.585 9.7a5.143 5.143 0 0 0-.73 3.255l2.514-.725.002-.009zm1.145-1.98a.44.44 0 0 0 .699-.337l.01-.005.15-2.62a5.144 5.144 0 0 0-3.01 1.442l2.147 1.523.004-.002zm.76 2.75l.723.349.722-.347.18-.78-.5-.623h-.804l-.5.623.179.778zm1.5-2.095a.44.44 0 0 0 .7.336l.008.003 2.134-1.513a5.188 5.188 0 0 0-2.992-1.442l.148 2.615.002.001zm10.876 5.97l-5.773 7.181a1.6 1.6 0 0 1-1.248.594H7.37a1.6 1.6 0 0 1-1.248-.593l-5.776-7.182a1.583 1.583 0 0 1-.307-1.34L2.1 5.573c.108-.47.425-.864.863-1.073L11.305.513a1.606 1.606 0 0 1 1.385 0l8.345 3.985c.438.209.755.604.863 1.073l2.062 9.404a1.581 1.581 0 0 1-.308 1.341zm-2.39-4.476l-.022-.05a6.668 6.668 0 0 0-4.883-3.931l-.002-.033c0-.038-.003-.076-.008-.113l-.01-.05a.44.44 0 0 0-.479-.34l-.007.003a6.64 6.64 0 0 0-3.814-1.86L12 4.703l-.006.001a6.64 6.64 0 0 0-3.82 1.862l-.003-.002a.45.45 0 0 0-.48.334l-.015.06a.67.67 0 0 0-.007.105l.001.042-.002.017a6.662 6.662 0 0 0-4.873 3.937l-.023.052-.016.074a.44.44 0 0 0 .265.533l.018.004a6.632 6.632 0 0 0 1.006 5.658l-.004.016a.64.64 0 0 0-.005.082v.053a.45.45 0 0 0 .447.447l.018-.002a6.617 6.617 0 0 0 4.632 3.053l.012.009.003.01a.67.67 0 0 0 .05.097l.028.053a.44.44 0 0 0 .575.19l.019-.012a6.632 6.632 0 0 0 5.768 0l.018.012a.44.44 0 0 0 .576-.187l.033-.065a.44.44 0 0 0 .045-.085l.001-.007.016-.012a6.617 6.617 0 0 0 4.624-3.05l.026.002a.45.45 0 0 0 .447-.447v-.074l-.006-.066-.003-.015a6.627 6.627 0 0 0 1.01-5.658l.009-.003a.44.44 0 0 0 .263-.53l-.014-.072z"/>
                            </svg>
                            {{ $cluster->name }}
                            @if ($cluster->cluster_type)
                                <span class="text-xs text-neutral-500 dark:text-neutral-400 ml-2">({{ ucfirst($cluster->cluster_type) }})</span>
                            @endif
                        </div>
                        <div class="box-description">
                            {{ $cluster->description ?? $cluster->api_server_url }}
                        </div>
                        @if (!$cluster->is_reachable)
                            <div class="text-xs text-warning mt-1">Cluster may be unreachable</div>
                        @endif
                    </div>
                </div>
            @empty
                <div>
                    <div>No Kubernetes clusters found. <a class="underline dark:text-white"
                            href="/kubernetes" {{ wireNavigate() }}>
                            Add a Kubernetes cluster
                        </a></div>
                </div>
            @endforelse
        </div>
    @endif
    @if ($current_step === 'kubernetes-destinations')
        <h2>Select a namespace</h2>
        <div class="pb-4">Choose the Kubernetes namespace where you want to deploy your resource.</div>
        <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
            @forelse($kubernetesDestinations as $destination)
                <div class="w-full coolbox group" wire:click="setKubernetesDestination('{{ $destination->uuid }}')">
                    <div class="flex flex-col mx-6">
                        <div class="box-title">
                            {{ $destination->name }}
                        </div>
                        <div class="box-description">
                            Namespace: {{ $destination->namespace }}
                        </div>
                        @if ($destination->storage_class)
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                Storage Class: {{ $destination->storage_class }}
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div>
                    <div>No namespaces configured for this cluster. <a class="underline dark:text-white"
                            href="/kubernetes/{{ $kubernetesCluster?->uuid }}" {{ wireNavigate() }}>
                            Configure namespaces
                        </a></div>
                </div>
            @endforelse
        </div>
    @endif
    @if ($current_step === 'select-postgresql-type')
        <div x-data="{ selecting: false }">
            <h2>Select a Postgresql type</h2>
            <div>If you need extra extensions, you can select Supabase PostgreSQL (or others), otherwise select
                PostgreSQL
                17 (default).</div>
            <div class="flex flex-col gap-6 pt-8">
                <div class="gap-2 coolbox group flex relative"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('postgres:17-alpine'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="box-title">PostgreSQL 17 (default)</div>
                        <div class="box-description">
                            PostgreSQL is a powerful, open-source object-relational database system (no extensions).
                        </div>
                    </div>
                    <a href="https://hub.docker.com/_/postgres/" target="_blank"
                        @click.stop
                        class="absolute top-2 right-2 p-1.5 rounded hover:bg-neutral-200 dark:hover:bg-coolgray-300 transition-colors"
                        title="View documentation">
                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </a>
                </div>
                <div class="gap-2 coolbox group flex relative"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('supabase/postgres:17.4.1.032'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="box-title">Supabase PostgreSQL (with extensions)</div>
                        <div class="box-description">
                            Supabase is a modern, open-source alternative to PostgreSQL with lots of extensions.
                        </div>
                    </div>
                    <a href="https://github.com/supabase/postgres" target="_blank"
                        @click.stop
                        class="absolute top-2 right-2 p-1.5 rounded hover:bg-neutral-200 dark:hover:bg-coolgray-300 transition-colors"
                        title="View documentation">
                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </a>
                </div>
                <div class="gap-2 coolbox group flex relative"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('postgis/postgis:17-3.5-alpine'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="box-title">PostGIS (AMD only)</div>
                        <div class="box-description">
                            PostGIS is a PostgreSQL extension for geographic objects.
                        </div>
                    </div>
                    <a href="https://github.com/postgis/docker-postgis" target="_blank"
                        @click.stop
                        class="absolute top-2 right-2 p-1.5 rounded hover:bg-neutral-200 dark:hover:bg-coolgray-300 transition-colors"
                        title="View documentation">
                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </a>
                </div>
                <div class="gap-2 coolbox group flex relative"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('pgvector/pgvector:pg17'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="box-title">PGVector (17)</div>
                        <div class="box-description">
                            PGVector is a PostgreSQL extension for vector data types.
                        </div>
                    </div>
                    <a href="https://github.com/pgvector/pgvector" target="_blank"
                        @click.stop
                        class="absolute top-2 right-2 p-1.5 rounded hover:bg-neutral-200 dark:hover:bg-coolgray-300 transition-colors"
                        title="View documentation">
                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    @endif
    @if ($current_step === 'existing-postgresql')
        <form wire:submit='addExistingPostgresql' class="flex items-end gap-4">
            <x-forms.input placeholder="postgres://username:password@database:5432" label="Database URL"
                id="existingPostgresqlUrl" />
            <x-forms.button type="submit">Add Database</x-forms.button>
        </form>
    @endif
</div>

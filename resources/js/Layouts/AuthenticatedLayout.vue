<script setup>
import { computed, reactive, ref, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';

const sidebarOpen = ref(false);
const sidebarCollapsed = ref(localStorage.getItem('sidebarCollapsed') === '1');

watch(sidebarCollapsed, (value) => {
    localStorage.setItem('sidebarCollapsed', value ? '1' : '0');
});

const mainLinks = [
    { label: 'Dashboard', route: 'dashboard', active: 'dashboard' },
    { label: 'Lançamento rápido', route: 'finance.transactions.quick', active: 'finance.transactions.quick' },
    { label: 'Relatórios', route: 'finance.reports.index', active: 'finance.reports.*' },
    { label: 'Quanto me devem', route: 'finance.owed.index', active: 'finance.owed.*' },
    { label: 'Contas', route: 'finance.accounts.index', active: 'finance.accounts.*' },
    {
        label: 'Transações',
        route: 'finance.transactions.index',
        active: ['finance.transactions.index', 'finance.transactions.create', 'finance.transactions.edit'],
    },
    {
        label: 'Cartões de crédito',
        route: 'finance.credit-cards.index',
        active: ['finance.credit-cards.index', 'finance.invoices.show'],
    },
    { label: 'Importar fatura', route: 'finance.statement-imports.create', active: 'finance.statement-imports.*' },
    { label: 'Transferências', route: 'finance.transfers.index', active: 'finance.transfers.*' },
    { label: 'Trocas', route: 'finance.swaps.index', active: 'finance.swaps.*' },
    { label: 'Classificar com IA', route: 'finance.transaction-classification.index', active: 'finance.transaction-classification.*' },
    { label: 'Recorrências', route: 'finance.recurring.index', active: 'finance.recurring.*' },
];

const registrationLinks = [
    { label: 'Pessoas', route: 'finance.people.index', active: 'finance.people.*' },
    { label: 'Instituições', route: 'finance.institutions.index', active: 'finance.institutions.*' },
    { label: 'Categorias', route: 'finance.categories.index', active: 'finance.categories.*' },
];

const menuClickCounts = reactive(JSON.parse(localStorage.getItem('menuClickCounts') ?? '{}'));

function trackMenuClick(link) {
    menuClickCounts[link.route] = (menuClickCounts[link.route] ?? 0) + 1;
    localStorage.setItem('menuClickCounts', JSON.stringify(menuClickCounts));
}

function sortByClicks(links) {
    return [...links].sort((a, b) => (menuClickCounts[b.route] ?? 0) - (menuClickCounts[a.route] ?? 0));
}

const sortedMainLinks = computed(() => {
    const pinned = mainLinks.filter((link) => link.route === 'dashboard');
    const rest = mainLinks.filter((link) => link.route !== 'dashboard');

    return [...pinned, ...sortByClicks(rest)];
});
const sortedRegistrationLinks = computed(() => sortByClicks(registrationLinks));

const isActive = (link) => {
    const patterns = Array.isArray(link.active) ? link.active : [link.active];

    return patterns.some((pattern) => route().current(pattern));
};

const linkClasses = (active) =>
    active
        ? 'flex items-center rounded-md px-3 py-2 text-sm font-medium bg-indigo-50 text-indigo-700'
        : 'flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900';
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100 sm:flex">
            <!-- Mobile backdrop -->
            <div
                v-show="sidebarOpen"
                class="fixed inset-0 z-40 bg-gray-900/50 sm:hidden"
                @click="sidebarOpen = false"
            ></div>

            <!-- Sidebar -->
            <aside
                class="fixed inset-y-0 left-0 z-50 flex w-64 shrink-0 -translate-x-full transform flex-col border-r border-gray-200 bg-white transition-transform duration-200 ease-in-out sm:static sm:translate-x-0"
                :class="{ 'translate-x-0': sidebarOpen, 'sm:hidden': sidebarCollapsed }"
            >
                <div class="flex h-16 shrink-0 items-center justify-between px-4">
                    <Link :href="route('dashboard')" class="flex items-center">
                        <ApplicationLogo class="block h-8 w-auto fill-current text-gray-800" />
                    </Link>
                    <div class="flex items-center">
                        <!-- Close on desktop (collapses sidebar) -->
                        <button
                            type="button"
                            title="Fechar menu"
                            class="hidden rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 sm:inline-flex"
                            @click="sidebarCollapsed = true"
                        >
                            <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7M4 12h16" />
                            </svg>
                        </button>
                        <!-- Close on mobile (hides off-canvas sidebar) -->
                        <button
                            type="button"
                            title="Fechar menu"
                            class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 sm:hidden"
                            @click="sidebarOpen = false"
                        >
                            <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
                    <div class="space-y-1">
                        <Link
                            v-for="link in sortedMainLinks"
                            :key="link.route"
                            :href="route(link.route)"
                            :class="linkClasses(isActive(link))"
                            @click="trackMenuClick(link)"
                        >
                            {{ link.label }}
                        </Link>
                    </div>

                    <div>
                        <p class="px-3 text-xs font-semibold uppercase tracking-wider text-gray-400">
                            Cadastros
                        </p>
                        <div class="mt-1 space-y-1">
                            <Link
                                v-for="link in sortedRegistrationLinks"
                                :key="link.route"
                                :href="route(link.route)"
                                :class="linkClasses(isActive(link))"
                                @click="trackMenuClick(link)"
                            >
                                {{ link.label }}
                            </Link>
                        </div>
                    </div>
                </nav>

                <div class="shrink-0 border-t border-gray-200 p-3">
                    <div class="px-3 py-1">
                        <div class="truncate text-sm font-medium text-gray-800">
                            {{ $page.props.auth.user.name }}
                        </div>
                        <div class="truncate text-xs text-gray-500">
                            {{ $page.props.auth.user.email }}
                        </div>
                    </div>
                    <div class="mt-2 space-y-1">
                        <Link
                            :href="route('profile.edit')"
                            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900"
                        >
                            Perfil
                        </Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="flex w-full items-center rounded-md px-3 py-2 text-left text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900"
                        >
                            Sair
                        </Link>
                    </div>
                </div>
            </aside>

            <!-- Content column -->
            <div class="flex min-w-0 flex-1 flex-col">
                <!-- Mobile top bar -->
                <div class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-4 border-b border-gray-100 bg-white px-4 sm:hidden">
                    <button
                        type="button"
                        class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500"
                        @click="sidebarOpen = true"
                    >
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <ApplicationLogo class="block h-8 w-auto fill-current text-gray-800" />
                </div>

                <!-- Reopen button when sidebar is collapsed on desktop -->
                <div v-show="sidebarCollapsed" class="sticky top-0 z-30 hidden shrink-0 border-b border-gray-100 bg-white px-4 py-3 sm:block">
                    <button
                        type="button"
                        title="Abrir menu"
                        class="inline-flex rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500"
                        @click="sidebarCollapsed = false"
                    >
                        <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M20 12H4" />
                        </svg>
                    </button>
                </div>

                <!-- Page Heading -->
                <header class="bg-white shadow" v-if="$slots.header">
                    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        <slot name="header" />
                    </div>
                </header>

                <!-- Page Content -->
                <main class="flex-1">
                    <slot />
                </main>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watchEffect } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

// FE-APP-01 backoffice shell: a left rail of role-aware module navigation, a top bar with
// operator context + global search + environment marker + profile, and the page surface in the
// main column. Operator theming (logo/name/primary colour) is applied at runtime so one build
// skins per operator. Nav visibility is UI convenience (permission-gated) — never security.
const { t, locale } = useI18n();
const page = usePage();
const op = computed(() => page.props.operatorConfig ?? null);
const user = computed(() => page.props.auth?.user ?? null);
const roles = computed(() => page.props.auth?.roles ?? []);
const perms = computed(() => page.props.auth?.permissions ?? []);
const env = computed(() => page.props.appEnv ?? 'local');
const isProd = computed(() => env.value === 'production');
const sidebarOpen = ref(false);
const search = ref('');

watchEffect(() => {
    if (op.value?.theme_primary_color) document.documentElement.style.setProperty('--op-primary', op.value.theme_primary_color);
});

// Portal-aware nav: the sidebar shows only the CURRENT app's nav (config/portals.php, shared as
// portal.current). A nav item shows when it has no permission, the user is SUPER_ADMIN, or holds
// the permission. Links are relative so navigation stays on this app's subdomain.
const portal = computed(() => page.props.portal ?? {});
const currentApp = computed(() => portal.value.current ?? null);
const launcherUrl = computed(() => {
    const base = portal.value.baseDomain;
    return base && base !== 'localhost' ? `${window.location.protocol}//app.${base}` : '/';
});
const can = (perm) => !perm || roles.value.includes('SUPER_ADMIN') || perms.value.includes(perm);
const visibleGroups = computed(() => (currentApp.value?.nav ?? [])
    .map(([name, items]) => [name, items.filter(([, , p]) => can(p))])
    .filter(([, items]) => items.length));

const href = (r) => route(r, undefined, false); // relative — stay on this subdomain
const isActive = (name) => route().current(name);

// Federated global search (FE-APP-01 §16): debounced query → grouped hits. A customer/account
// hit deep-links to its 360; other types open their owning console (which carries its own search).
const searchGroups = ref([]);
const searchOpen = ref(false);
let searchTimer = null;
const consoleRoute = { subscription: 'subscriptions.index', invoice: 'billing.console', payment: 'billing.console', workorder: 'workorders.console', equipment: 'equipment.console', ticket: 'tickets.index' };
function onSearchInput() {
    clearTimeout(searchTimer);
    const q = search.value.trim();
    if (q.length < 2) { searchGroups.value = []; searchOpen.value = false; return; }
    searchTimer = setTimeout(async () => {
        try {
            const { data } = await window.axios.get('/api/search', { params: { q } });
            searchGroups.value = data.groups ?? [];
            searchOpen.value = true;
        } catch (e) { searchGroups.value = []; }
    }, 250);
}
function goToHit(type, item) {
    searchOpen.value = false; search.value = '';
    if (type === 'customer' || type === 'account') router.visit(route('customers.show', item.id, false));
    else if (consoleRoute[type]) router.visit(route(consoleRoute[type], undefined, false));
}
</script>

<template>
    <div class="min-h-screen bg-gray-100">
        <!-- Sidebar -->
        <aside class="fixed inset-y-0 left-0 z-30 w-60 -translate-x-full transform border-r border-gray-200 bg-white transition-transform lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : ''">
            <div class="flex h-16 items-center gap-2 border-b border-gray-100 px-4">
                <img v-if="op?.theme_logo_url" :src="op.theme_logo_url" class="h-8 w-auto" />
                <ApplicationLogo v-else class="h-8 w-auto shrink-0 fill-current" :style="{ color: op?.theme_primary_color ?? '#4f46e5' }" />
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold" :style="{ color: op?.theme_primary_color }">{{ t(currentApp?.label ?? (op?.display_name ?? 'SOPHIX')) }}</div>
                    <div class="truncate text-[10px] text-gray-400">{{ op?.display_name ?? 'SOPHIX' }}</div>
                </div>
            </div>
            <nav class="h-[calc(100vh-7rem)] overflow-y-auto px-3 py-4">
                <div v-for="[name, items] in visibleGroups" :key="name" class="mb-4">
                    <div class="px-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-gray-400">{{ t(name) }}</div>
                    <Link v-for="[r, label] in items" :key="r" :href="href(r)"
                        class="flex items-center rounded-lg px-2 py-1.5 text-sm font-medium transition"
                        :class="isActive(r) ? 'bg-op-soft text-op' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
                        :style="isActive(r) && op?.theme_primary_color ? { color: op.theme_primary_color, backgroundColor: op.theme_primary_color + '14' } : {}">
                        {{ t(label) }}
                    </Link>
                </div>
            </nav>
            <!-- App switcher: back to the launcher (other apps) -->
            <a :href="launcherUrl" class="absolute inset-x-0 bottom-0 flex h-12 items-center gap-2 border-t border-gray-100 bg-white px-4 text-sm font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-800">
                <span class="grid grid-cols-2 gap-0.5"><span class="h-1.5 w-1.5 rounded-sm bg-current"></span><span class="h-1.5 w-1.5 rounded-sm bg-current"></span><span class="h-1.5 w-1.5 rounded-sm bg-current"></span><span class="h-1.5 w-1.5 rounded-sm bg-current"></span></span>
                {{ t('All apps') }}
            </a>
        </aside>
        <div v-if="sidebarOpen" class="fixed inset-0 z-20 bg-gray-900/30 lg:hidden" @click="sidebarOpen = false"></div>

        <!-- Main column -->
        <div class="lg:pl-60">
            <header class="sticky top-0 z-10 flex h-16 items-center gap-3 border-b border-gray-200 bg-white/80 px-4 backdrop-blur sm:px-6">
                <button class="rounded p-1.5 text-gray-500 hover:bg-gray-100 lg:hidden" @click="sidebarOpen = !sidebarOpen" :aria-label="t('Menu')">☰</button>
                <div class="relative hidden flex-1 sm:block">
                    <input v-model="search" @input="onSearchInput" @focus="searchGroups.length && (searchOpen = true)"
                        @blur="setTimeout(() => (searchOpen = false), 150)" :placeholder="t('Search customers, accounts, subscriptions, invoices, tickets…')"
                        class="w-full max-w-md rounded-lg border-gray-200 bg-gray-50 text-sm focus:border-op focus:ring-op" />
                    <div v-if="searchOpen && searchGroups.length" class="absolute left-0 top-10 z-20 max-h-96 w-full max-w-md overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                        <div v-for="g in searchGroups" :key="g.type">
                            <div class="px-3 pt-2 text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ t(g.label) }}</div>
                            <button v-for="item in g.items" :key="g.type + item.id" @mousedown.prevent="goToHit(g.type, item)"
                                class="flex w-full items-baseline justify-between gap-2 px-3 py-1.5 text-left text-sm hover:bg-gray-50">
                                <span class="truncate font-medium text-gray-700">{{ item.title }}</span>
                                <span class="shrink-0 truncate text-xs text-gray-400">{{ item.subtitle }}</span>
                            </button>
                        </div>
                    </div>
                    <div v-else-if="searchOpen && search.trim().length >= 2" class="absolute left-0 top-10 z-20 w-full max-w-md rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-400 shadow-lg">{{ t('No matches.') }}</div>
                </div>
                <span v-if="!isProd" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold uppercase text-amber-700" :title="t('Non-production environment')">{{ env }}</span>
                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-600 hover:bg-gray-100">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-op-soft text-xs font-semibold text-op">{{ (user?.name ?? '?').slice(0, 1) }}</span>
                            <span class="hidden text-left sm:block">
                                <span class="block leading-tight">{{ user?.name }}</span>
                                <span class="block text-[10px] leading-tight text-gray-400">{{ roles.join(', ') || t('No role') }}</span>
                            </span>
                        </button>
                    </template>
                    <template #content>
                        <div class="border-b border-gray-100 px-4 py-2 text-xs text-gray-400">
                            {{ t('Language') }}: {{ locale }} · {{ op?.currency_code ?? 'KES' }}
                        </div>
                        <DropdownLink :href="route('profile.edit')">{{ t('Profile') }}</DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button">{{ t('Log Out') }}</DropdownLink>
                    </template>
                </Dropdown>
            </header>

            <header v-if="$slots.header" class="border-b border-gray-100 bg-white px-4 py-5 sm:px-6">
                <slot name="header" />
            </header>

            <main class="px-4 py-6 sm:px-6"><slot /></main>
        </div>
    </div>
</template>

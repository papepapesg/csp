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

// A nav item shows when it has no permission, the user is SUPER_ADMIN, or holds the permission.
const can = (perm) => !perm || roles.value.includes('SUPER_ADMIN') || perms.value.includes(perm);
const groups = [
    ['Operations', [
        ['dashboard', 'Dashboard', null], ['customers.index', 'Customers', 'customer.read'],
        ['subscriptions.index', 'Subscriptions', 'subscription.read'], ['tickets.index', 'Tickets', 'ticket.read'],
        ['fulfillment.console', 'Fulfillment', 'fulfillment.read'], ['workorders.console', 'Work Orders', 'workorder.read'],
    ]],
    ['Commerce', [
        ['billing.console', 'Billing', 'invoice.read'], ['catalog.setup', 'Catalog', 'catalog.read'],
        ['commercial.studio', 'Commercial', 'catalog.read'], ['equipment.console', 'Equipment', 'stock.read'],
        ['workforce.console', 'Workforce', 'workforce.read'],
    ]],
    ['Insight', [['reports.index', 'Reports', 'report.view']]],
    ['Studios', [
        ['workflow.studio', 'Workflow', 'workflow.view'], ['rules.studio', 'Rules', 'rules.view'],
        ['templates.studio', 'Templates', 'notification.read'], ['dunning.studio', 'Dunning', 'dunning.admin'],
        ['i18n.studio', 'Localization', null],
    ]],
    ['Admin', [
        ['rbac.admin', 'RBAC', 'rbac.manage'], ['noc.console', 'NOC', 'itops.view'],
        ['workflow.ops', 'Operations console', 'workflow.view'], ['itops.console', 'IT-Ops', 'itops.view'],
        ['admin.console', 'Admin', 'itops.view'],
    ]],
];
const visibleGroups = computed(() => groups
    .map(([name, items]) => [name, items.filter(([, , p]) => can(p))])
    .filter(([, items]) => items.length));

const isActive = (name) => route().current(name);
function submitSearch() {
    const q = search.value.trim();
    if (q) router.visit(route('customers.index') + '?q=' + encodeURIComponent(q));
}
</script>

<template>
    <div class="min-h-screen bg-gray-100">
        <!-- Sidebar -->
        <aside class="fixed inset-y-0 left-0 z-30 w-60 -translate-x-full transform border-r border-gray-200 bg-white transition-transform lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : ''">
            <div class="flex h-16 items-center gap-2 border-b border-gray-100 px-4">
                <Link :href="route('dashboard')" class="flex items-center gap-2">
                    <img v-if="op?.theme_logo_url" :src="op.theme_logo_url" class="h-8 w-auto" />
                    <ApplicationLogo v-else class="h-8 w-auto fill-current" :style="{ color: op?.theme_primary_color ?? '#4f46e5' }" />
                    <span class="text-sm font-semibold" :style="{ color: op?.theme_primary_color }">{{ op?.display_name ?? 'SOPHIX' }}</span>
                </Link>
            </div>
            <nav class="h-[calc(100vh-4rem)] overflow-y-auto px-3 py-4">
                <div v-for="[name, items] in visibleGroups" :key="name" class="mb-4">
                    <div class="px-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-gray-400">{{ t(name) }}</div>
                    <Link v-for="[r, label] in items" :key="r" :href="route(r)"
                        class="flex items-center rounded-lg px-2 py-1.5 text-sm font-medium transition"
                        :class="isActive(r) ? 'bg-op-soft text-op' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
                        :style="isActive(r) && op?.theme_primary_color ? { color: op.theme_primary_color, backgroundColor: op.theme_primary_color + '14' } : {}">
                        {{ t(label) }}
                    </Link>
                </div>
            </nav>
        </aside>
        <div v-if="sidebarOpen" class="fixed inset-0 z-20 bg-gray-900/30 lg:hidden" @click="sidebarOpen = false"></div>

        <!-- Main column -->
        <div class="lg:pl-60">
            <header class="sticky top-0 z-10 flex h-16 items-center gap-3 border-b border-gray-200 bg-white/80 px-4 backdrop-blur sm:px-6">
                <button class="rounded p-1.5 text-gray-500 hover:bg-gray-100 lg:hidden" @click="sidebarOpen = !sidebarOpen" :aria-label="t('Menu')">☰</button>
                <form class="hidden flex-1 sm:block" @submit.prevent="submitSearch">
                    <input v-model="search" :placeholder="t('Search customers, accounts, tickets…')"
                        class="w-full max-w-md rounded-lg border-gray-200 bg-gray-50 text-sm focus:border-op focus:ring-op" />
                </form>
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

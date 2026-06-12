<script setup>
import { ref, computed, watchEffect } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

const showingNavigationDropdown = ref(false);
const { t } = useI18n();

// Operator deployment config: brand identity + theme applied at runtime, so the
// same build skins per operator (display name, primary color, logo, currency).
const op = computed(() => usePage().props.operatorConfig ?? null);
watchEffect(() => {
    if (op.value?.theme_primary_color) {
        document.documentElement.style.setProperty('--op-primary', op.value.theme_primary_color);
    }
});
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav
                class="border-b border-gray-100 bg-white"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')" class="flex items-center gap-2">
                                    <img v-if="op?.theme_logo_url" :src="op.theme_logo_url" class="block h-9 w-auto" />
                                    <ApplicationLogo v-else class="block h-9 w-auto fill-current" :style="{ color: op?.theme_primary_color ?? '#1f2937' }" />
                                    <span v-if="op" class="font-semibold text-sm" :style="{ color: op.theme_primary_color }">{{ op.display_name }}</span>
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    {{ t('Dashboard') }}
                                </NavLink>
                                <NavLink
                                    :href="route('customers.index')"
                                    :active="route().current('customers.index')"
                                >
                                    {{ t('Customers') }}
                                </NavLink>
                                <NavLink
                                    :href="route('tickets.index')"
                                    :active="route().current('tickets.index')"
                                >
                                    {{ t('Tickets') }}
                                </NavLink>
                                <NavLink
                                    :href="route('reports.index')"
                                    :active="route().current('reports.index')"
                                >
                                    {{ t('Reports') }}
                                </NavLink>
                                <NavLink
                                    :href="route('workflow.studio')"
                                    :active="route().current('workflow.studio')"
                                >
                                    {{ t('Studio') }}
                                </NavLink>
                                <NavLink
                                    :href="route('rules.studio')"
                                    :active="route().current('rules.studio')"
                                >
                                    {{ t('Rules') }}
                                </NavLink>
                                <NavLink
                                    :href="route('commercial.studio')"
                                    :active="route().current('commercial.studio')"
                                >
                                    {{ t('Commercial') }}
                                </NavLink>
                                <NavLink
                                    :href="route('catalog.setup')"
                                    :active="route().current('catalog.setup')"
                                >
                                    {{ t('Catalog') }}
                                </NavLink>
                                <NavLink
                                    :href="route('templates.studio')"
                                    :active="route().current('templates.studio')"
                                >
                                    {{ t('Templates') }}
                                </NavLink>
                                <NavLink
                                    :href="route('rbac.admin')"
                                    :active="route().current('rbac.admin')"
                                >
                                    {{ t('RBAC') }}
                                </NavLink>
                                <NavLink
                                    :href="route('i18n.studio')"
                                    :active="route().current('i18n.studio')"
                                >
                                    {{ t('Localization') }}
                                </NavLink>
                                <NavLink
                                    :href="route('workflow.ops')"
                                    :active="route().current('workflow.ops')"
                                >
                                    {{ t('Ops') }}
                                </NavLink>
                                <NavLink
                                    :href="route('noc.console')"
                                    :active="route().current('noc.console')"
                                >
                                    {{ t('NOC') }}
                                </NavLink>
                                <NavLink
                                    :href="route('warehouse.console')"
                                    :active="route().current('warehouse.console')"
                                >
                                    {{ t('Warehouse') }}
                                </NavLink>
                                <NavLink
                                    :href="route('itops.console')"
                                    :active="route().current('itops.console')"
                                >
                                    {{ t('IT-Ops') }}
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <!-- Settings Dropdown -->
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            {{ t('Profile') }}
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            {{ t('Log Out') }}
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('dashboard')"
                            :active="route().current('dashboard')"
                        >
                            {{ t('Dashboard') }}
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div
                        class="border-t border-gray-200 pb-1 pt-4"
                    >
                        <div class="px-4">
                            <div
                                class="text-base font-medium text-gray-800"
                            >
                                {{ $page.props.auth.user.name }}
                            </div>
                            <div class="text-sm font-medium text-gray-500">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                {{ t('Profile') }}
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                {{ t('Log Out') }}
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header
                class="bg-white shadow"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>

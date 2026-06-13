<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, watchEffect } from 'vue';
import { useI18n } from '@/i18n';

// FE-APP-01 launcher: the post-login app grid. Shows only the apps this user may open
// (portal.apps, server-filtered by permission); each tile opens that app's subdomain (SSO).
const { t } = useI18n();
const page = usePage();
const op = computed(() => page.props.operatorConfig ?? null);
const user = computed(() => page.props.auth?.user ?? null);
const roles = computed(() => page.props.auth?.roles ?? []);
const apps = computed(() => page.props.portal?.apps ?? []);

watchEffect(() => {
    if (op.value?.theme_primary_color) document.documentElement.style.setProperty('--op-primary', op.value.theme_primary_color);
});
</script>

<template>
    <Head :title="t('Apps')" />
    <div class="min-h-screen bg-gray-50">
        <header class="flex h-16 items-center justify-between border-b border-gray-200 bg-white px-6">
            <div class="flex items-center gap-2">
                <img v-if="op?.theme_logo_url" :src="op.theme_logo_url" class="h-8 w-auto" />
                <ApplicationLogo v-else class="h-8 w-auto fill-current" :style="{ color: op?.theme_primary_color ?? '#4f46e5' }" />
                <span class="text-sm font-semibold" :style="{ color: op?.theme_primary_color }">{{ op?.display_name ?? 'SOPHIX' }}</span>
            </div>
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
                    <DropdownLink :href="route('profile.edit')">{{ t('Profile') }}</DropdownLink>
                    <DropdownLink :href="route('logout')" method="post" as="button">{{ t('Log Out') }}</DropdownLink>
                </template>
            </Dropdown>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-12">
            <h1 class="text-2xl font-semibold text-gray-800">{{ t('Welcome to SOPHIX') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ t('Choose an app to get started.') }}</p>

            <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <a v-for="app in apps" :key="app.slug" :href="app.url"
                    class="group relative overflow-hidden rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100 transition hover:-translate-y-0.5 hover:shadow-md">
                    <span class="absolute inset-x-0 top-0 h-1.5" :style="{ backgroundColor: app.color }"></span>
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl text-lg font-bold text-white" :style="{ backgroundColor: app.color }">
                        {{ app.label.slice(0, 1) }}
                    </div>
                    <div class="mt-3 text-base font-semibold text-gray-800">{{ t(app.label) }}</div>
                    <div class="text-xs text-gray-500">{{ t(app.tagline) }}</div>
                    <span class="mt-3 inline-flex items-center text-xs font-medium text-gray-400 group-hover:text-gray-600">{{ t('Open') }} →</span>
                </a>
            </div>

            <p v-if="!apps.length" class="mt-8 rounded-xl bg-white p-6 text-center text-sm text-gray-400 ring-1 ring-gray-100">
                {{ t('You have no apps assigned. Ask an administrator for access.') }}
            </p>
        </main>
    </div>
</template>

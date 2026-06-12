<script setup>
// Standard surface header: breadcrumb trail + title + right-aligned #actions slot. Used in the
// AuthenticatedLayout #header slot so every surface gets the same breadcrumb/title treatment.
//   crumbs: [{ label, href? }]
import { Link } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
defineProps({ title: { type: String, required: true }, crumbs: { type: Array, default: () => [] } });
const { t } = useI18n();
</script>

<template>
    <div class="flex items-center justify-between gap-3">
        <div>
            <nav v-if="crumbs.length" class="mb-0.5 flex items-center gap-1 text-xs text-gray-400">
                <template v-for="(c, i) in crumbs" :key="i">
                    <Link v-if="c.href" :href="c.href" class="hover:text-gray-600">{{ t(c.label) }}</Link>
                    <span v-else>{{ t(c.label) }}</span>
                    <span v-if="i < crumbs.length - 1">/</span>
                </template>
            </nav>
            <h2 class="text-xl font-semibold text-gray-800">{{ t(title) }}</h2>
        </div>
        <div class="flex items-center gap-2"><slot name="actions" /></div>
    </div>
</template>

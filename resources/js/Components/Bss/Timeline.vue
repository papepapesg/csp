<script setup>
// Vertical event timeline — the visual for "view history" (audit trail, lifecycle events,
// interactions, ticket activity). Each event: { title, subtitle?, at?, tone? }.
import { useI18n } from '@/i18n';

defineProps({ events: { type: Array, default: () => [] } });
const { t } = useI18n();
const dot = { green: 'bg-emerald-500', red: 'bg-red-500', amber: 'bg-amber-500', blue: 'bg-blue-500', indigo: 'bg-op', gray: 'bg-gray-300' };
</script>

<template>
    <ol class="relative ml-2 border-l border-gray-200">
        <li v-for="(e, i) in events" :key="i" class="mb-4 ml-4">
            <span class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full ring-4 ring-white" :class="dot[e.tone] ?? dot.indigo"></span>
            <div class="flex items-baseline justify-between gap-2">
                <p class="text-sm font-medium text-gray-800">{{ t(e.title) }}</p>
                <time v-if="e.at" class="shrink-0 text-xs text-gray-400">{{ e.at }}</time>
            </div>
            <p v-if="e.subtitle" class="text-xs text-gray-500">{{ e.subtitle }}</p>
        </li>
        <li v-if="!events.length" class="ml-4 text-sm text-gray-400">{{ t('No history yet.') }}</li>
    </ol>
</template>

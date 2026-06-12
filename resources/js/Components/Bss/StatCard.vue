<script setup>
// Dashboard metric tile. A big number, a label, an optional sub-line and a tone accent bar.
// Clickable (emits select / acts as a link target) so widgets can drill into their queue.
import { useI18n } from '@/i18n';

defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], default: 0 },
    sub: { type: String, default: '' },
    tone: { type: String, default: 'indigo' }, // indigo|emerald|amber|red|cyan|gray
    loading: { type: Boolean, default: false },
});
const emit = defineEmits(['select']);
const { t } = useI18n();
const bar = { indigo: 'bg-op', emerald: 'bg-emerald-500', amber: 'bg-amber-500', red: 'bg-red-500', cyan: 'bg-cyan-500', gray: 'bg-gray-400' };
</script>

<template>
    <button type="button" @click="emit('select')"
        class="group relative overflow-hidden rounded-xl bg-white p-4 text-left shadow-sm ring-1 ring-gray-100 transition hover:shadow-md hover:ring-gray-200">
        <span class="absolute inset-x-0 top-0 h-1" :class="bar[tone] ?? bar.indigo"></span>
        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ t(label) }}</div>
        <div class="mt-1 text-3xl font-semibold text-gray-900">
            <span v-if="loading" class="inline-block h-8 w-12 animate-pulse rounded bg-gray-100"></span>
            <span v-else>{{ value }}</span>
        </div>
        <div v-if="sub" class="mt-1 text-xs text-gray-400">{{ sub }}</div>
    </button>
</template>

<script setup>
// Dependency-free SVG bar chart for the reporting surface. series: [{ label, value }].
// Bars use the operator theme colour (--op-primary); empty series shows a friendly note.
import { computed } from 'vue';
import { useI18n } from '@/i18n';

const props = defineProps({
    series: { type: Array, default: () => [] },
    height: { type: Number, default: 140 },
    format: { type: Function, default: (v) => v },
});
const { t } = useI18n();
const max = computed(() => Math.max(1, ...props.series.map((d) => Number(d.value) || 0)));
const bar = (v) => Math.max(2, (Number(v) || 0) / max.value * (props.height - 22));
</script>

<template>
    <div v-if="series.length" class="flex items-end gap-1 overflow-x-auto" :style="{ height: height + 'px' }">
        <div v-for="(d, i) in series" :key="i" class="group flex min-w-[14px] flex-1 flex-col items-center justify-end">
            <span class="mb-0.5 text-[9px] text-gray-400 opacity-0 transition group-hover:opacity-100">{{ format(d.value) }}</span>
            <div class="w-full rounded-t bg-op transition-all" :style="{ height: bar(d.value) + 'px' }" :title="`${d.label}: ${format(d.value)}`"></div>
            <span class="mt-0.5 max-w-full truncate text-[9px] text-gray-400">{{ d.label }}</span>
        </div>
    </div>
    <div v-else class="flex items-center justify-center text-sm text-gray-400" :style="{ height: height + 'px' }">{{ t('No data for this range.') }}</div>
</template>

<script setup>
// Lightweight, consistent table. columns: [{ key, label, class?, align? }]. Rows are plain
// objects. A #cell-<key> slot overrides any cell (for badges/links); a #row-actions slot adds
// a trailing actions column. Clicking a row emits select(row). Headers flow through i18n.
import { useI18n } from '@/i18n';

defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    rowKey: { type: String, default: 'id' },
    empty: { type: String, default: 'Nothing to show.' },
    loading: { type: Boolean, default: false },
});
const emit = defineEmits(['select']);
const { t } = useI18n();
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500">
                    <th v-for="c in columns" :key="c.key" class="px-2 py-2 font-medium" :class="c.align === 'right' ? 'text-right' : ''">{{ t(c.label) }}</th>
                    <th v-if="$slots['row-actions']" class="px-2 py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row[rowKey]" @click="emit('select', row)"
                    class="border-b border-gray-50 transition hover:bg-gray-50" :class="$attrs.onSelect || $slots['row-actions'] ? 'cursor-pointer' : ''">
                    <td v-for="c in columns" :key="c.key" class="px-2 py-2" :class="[c.class, c.align === 'right' ? 'text-right' : '']">
                        <slot :name="`cell-${c.key}`" :row="row" :value="row[c.key]">{{ row[c.key] ?? '—' }}</slot>
                    </td>
                    <td v-if="$slots['row-actions']" class="px-2 py-2 text-right" @click.stop>
                        <slot name="row-actions" :row="row" />
                    </td>
                </tr>
                <tr v-if="loading">
                    <td :colspan="columns.length + 1" class="px-2 py-6 text-center text-sm text-gray-400">{{ t('Loading…') }}</td>
                </tr>
                <tr v-else-if="!rows.length">
                    <td :colspan="columns.length + 1" class="px-2 py-6 text-center text-sm text-gray-400">{{ t(empty) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

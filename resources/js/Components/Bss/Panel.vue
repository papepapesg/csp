<script setup>
// Titled white card. The standard container for every panel/section so spacing and headers
// look identical everywhere. Title flows through i18n; #actions slot sits on the right.
import { useI18n } from '@/i18n';
defineProps({ title: { type: String, default: '' }, subtitle: { type: String, default: '' } });
const { t } = useI18n();
</script>

<template>
    <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
        <header v-if="title || $slots.actions" class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
            <div>
                <h3 class="text-sm font-semibold text-gray-800">{{ t(title) }}</h3>
                <p v-if="subtitle" class="text-xs text-gray-400">{{ t(subtitle) }}</p>
            </div>
            <div class="flex items-center gap-2"><slot name="actions" /></div>
        </header>
        <div class="p-4"><slot /></div>
    </section>
</template>

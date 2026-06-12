<script setup>
// Right-hand slide-over for detail views (subscription/order/WO/invoice detail) so a list and
// its detail coexist without a full navigation. v-model:open controls visibility.
import { useI18n } from '@/i18n';
defineProps({ open: { type: Boolean, default: false }, title: { type: String, default: '' }, width: { type: String, default: 'max-w-2xl' } });
const emit = defineEmits(['update:open']);
const { t } = useI18n();
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" enter-from-class="opacity-0" leave-active-class="transition" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-40 bg-gray-900/40" @click="emit('update:open', false)"></div>
        </Transition>
        <Transition enter-active-class="transition duration-200" enter-from-class="translate-x-full" leave-active-class="transition duration-150" leave-to-class="translate-x-full">
            <aside v-if="open" class="fixed inset-y-0 right-0 z-50 w-full bg-gray-50 shadow-xl" :class="width">
                <header class="flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3">
                    <h3 class="text-sm font-semibold text-gray-800">{{ t(title) }}</h3>
                    <button class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" @click="emit('update:open', false)" :aria-label="t('Close')">✕</button>
                </header>
                <div class="h-[calc(100vh-3.25rem)] overflow-y-auto p-4"><slot /></div>
            </aside>
        </Transition>
    </Teleport>
</template>

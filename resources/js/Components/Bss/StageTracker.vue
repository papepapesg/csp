<script setup>
// Horizontal process tracker — the visual spine of "view the onboarding/fulfillment process".
// Pass an ordered list of stages with a state; it renders a connected stepper with done
// (filled check), current (ringed + pulsing), pending (hollow) and failed (red) nodes.
//   stages: [{ key, label, state: 'done'|'current'|'pending'|'failed', at? }]
import { useI18n } from '@/i18n';

defineProps({ stages: { type: Array, default: () => [] } });
const { t } = useI18n();
const dot = {
    done: 'bg-emerald-500 text-white ring-emerald-100',
    current: 'bg-op text-white ring-op animate-pulse',
    failed: 'bg-red-500 text-white ring-red-100',
    pending: 'bg-white text-gray-400 ring-gray-200 border border-gray-300',
};
const line = (s) => (s === 'done' ? 'bg-emerald-400' : 'bg-gray-200');
</script>

<template>
    <ol class="flex items-start">
        <li v-for="(s, i) in stages" :key="s.key ?? i" class="relative flex-1 last:flex-none">
            <div class="flex items-center">
                <span class="z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold ring-4"
                    :class="dot[s.state] ?? dot.pending">
                    <svg v-if="s.state === 'done'" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4L8.5 12l6.8-6.7a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                    <span v-else-if="s.state === 'failed'">!</span>
                    <span v-else>{{ i + 1 }}</span>
                </span>
                <span v-if="i < stages.length - 1" class="mx-1 h-0.5 flex-1 rounded" :class="line(s.state)"></span>
            </div>
            <div class="mt-1.5 pr-2">
                <div class="text-xs font-medium" :class="s.state === 'pending' ? 'text-gray-400' : 'text-gray-700'">{{ t(s.label) }}</div>
                <div v-if="s.at" class="text-[10px] text-gray-400">{{ s.at }}</div>
            </div>
        </li>
    </ol>
</template>

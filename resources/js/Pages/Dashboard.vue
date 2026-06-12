<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import Panel from '@/Components/Bss/Panel.vue';
import Timeline from '@/Components/Bss/Timeline.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { useI18n } from '@/i18n';

// FE-APP-01 §7.1 home dashboard. Role-aware operational widgets + an onboarding pipeline funnel
// and a live activity feed, all from /api/dashboard/summary (resilient per-tile: a down module
// blanks only its tile).
const { t, dateFmt } = useI18n();
const loading = ref(true);
const w = ref({});
const onboarding = ref({});
const recent = ref([]);

// widget key → [label, tone, drill-to route]
const cards = [
    ['myTasks', 'My tasks', 'indigo', 'workflow.ops'],
    ['kycPending', 'KYC pending', 'amber', 'customers.index'],
    ['dunningRisk', 'Dunning risk', 'red', 'billing.console'],
    ['woBacklog', 'Work-order backlog', 'cyan', 'workorders.console'],
    ['activationFailures', 'Activation failures', 'red', 'fulfillment.console'],
    ['stockExceptions', 'Stock exceptions', 'amber', 'equipment.console'],
];
// The onboarding funnel, in journey order (FUL-02).
const stages = [
    ['CAPTURED', 'Captured'], ['AWAITING_KYC', 'KYC'], ['AWAITING_PAYMENT', 'Payment'],
    ['AWAITING_INSTALL', 'Install'], ['ACTIVATING', 'Activating'], ['COMPLETED', 'Active'],
];

const val = (key) => (w.value[key]?.available ? w.value[key].data.count : '—');
function go(name) { try { router.visit(route(name)); } catch (e) { /* route may be gated */ } }

async function load() {
    try {
        const { data } = await window.axios.get('/api/dashboard/summary');
        w.value = data.widgets ?? {};
        onboarding.value = (data.onboarding?.available ? data.onboarding.data : data.onboarding) ?? {};
        recent.value = (data.recent?.available ? data.recent.data : data.recent) ?? [];
    } catch (e) { /* leave tiles blank */ } finally { loading.value = false; }
}
onMounted(load);
</script>

<template>
    <Head :title="t('Dashboard')" />
    <AuthenticatedLayout>
        <template #header><PageHeader title="Dashboard" /></template>

        <div class="mx-auto max-w-7xl space-y-5">
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                <StatCard v-for="[key, label, tone, dest] in cards" :key="key"
                    :label="label" :tone="tone" :value="val(key)" :loading="loading" @select="go(dest)" />
            </div>

            <Panel title="Onboarding pipeline" subtitle="Live fulfillment orders by stage (FUL-02)">
                <template #actions>
                    <button class="text-xs text-op hover:underline" @click="go('fulfillment.console')">{{ t('Open fulfillment') }}</button>
                </template>
                <div class="flex items-stretch gap-2 overflow-x-auto">
                    <template v-for="([code, label], i) in stages" :key="code">
                        <div class="min-w-[110px] flex-1 rounded-lg bg-gray-50 p-3 text-center ring-1 ring-gray-100">
                            <div class="text-2xl font-semibold text-gray-800">{{ onboarding[code] ?? 0 }}</div>
                            <div class="text-xs text-gray-500">{{ t(label) }}</div>
                        </div>
                        <div v-if="i < stages.length - 1" class="flex items-center text-gray-300">→</div>
                    </template>
                </div>
            </Panel>

            <Panel title="Recent activity" subtitle="Latest domain events across the platform">
                <Timeline :events="recent.map((e) => ({ title: (e.type ?? '').replace(/([A-Z])/g, ' $1').trim(), subtitle: e.aggregate, at: dateFmt(e.at), tone: 'indigo' }))" />
            </Panel>
        </div>
    </AuthenticatedLayout>
</template>

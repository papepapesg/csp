<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();

// REP-01 dashboards — read only from the reporting mart (no operational fan-out).
const ops = ref({});
const revenue = ref({});
const loading = ref(true);
const error = ref(null);

const labels = {
    orders_captured: 'Orders captured',
    orders_completed: 'Orders completed',
    subscriptions_created: 'Subscriptions created',
    subscriptions_activated: 'Subscriptions activated',
    work_orders_finalized: 'Installs finalized',
    tickets_created: 'Tickets created',
    tickets_resolved: 'Tickets resolved',
    invoices_generated: 'Invoices generated',
    invoices_amount: 'Invoiced (KES)',
    invoices_paid: 'Invoices paid',
    payments_count: 'Payments',
    payments_amount: 'Collected (KES)',
    wallet_topups_amount: 'Wallet top-ups (KES)',
};

const fmt = (k, v) => (k.endsWith('_amount') ? Number(v).toLocaleString() : v);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const [o, r] = await Promise.all([
            window.axios.get('/api/reports/dashboards/operations-overview'),
            window.axios.get('/api/reports/dashboards/revenue-overview'),
        ]);
        ops.value = o.data.metrics ?? {};
        revenue.value = r.data.metrics ?? {};
    } catch (e) {
        error.value = e.response?.data?.message ?? t('Failed to load dashboards');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Head :title="t('Dashboards')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ t('Reporting (REP-01)') }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-8 sm:px-6 lg:px-8">
                <p v-if="error" class="rounded bg-red-50 p-4 text-sm text-red-600">{{ error }}</p>
                <p v-else-if="loading" class="text-sm text-gray-500">{{ t('Loading…') }}</p>

                <template v-else>
                    <section>
                        <h3 class="mb-3 text-sm font-semibold uppercase text-gray-500">{{ t('Operations') }}</h3>
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div v-for="(v, k) in ops" :key="k" class="rounded-lg bg-white p-5 shadow-sm">
                                <div class="text-2xl font-semibold text-gray-800">{{ fmt(k, v) }}</div>
                                <div class="mt-1 text-xs text-gray-500">{{ t(labels[k] ?? k) }}</div>
                            </div>
                        </div>
                    </section>

                    <section>
                        <h3 class="mb-3 text-sm font-semibold uppercase text-gray-500">{{ t('Revenue') }}</h3>
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div v-for="(v, k) in revenue" :key="k" class="rounded-lg bg-white p-5 shadow-sm">
                                <div class="text-2xl font-semibold text-gray-800">{{ fmt(k, v) }}</div>
                                <div class="mt-1 text-xs text-gray-500">{{ t(labels[k] ?? k) }}</div>
                            </div>
                        </div>
                    </section>

                    <p class="text-xs text-gray-400">
                        {{ t('Metrics are projected from committed domain events via the transactional outbox.') }}
                        {{ t('Run the queue +') }} <code>sophix:outbox:dispatch</code> {{ t('to refresh.') }}
                    </p>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

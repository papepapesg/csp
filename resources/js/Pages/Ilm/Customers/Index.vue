<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import Drawer from '@/Components/Bss/Drawer.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();

// ILM-CFG-01 Backoffice Customers screen. Reads/writes go through the ILM API
// (the owning module) per DD_API-00 — the page holds no business state.
const customers = ref([]);
const loading = ref(true);
const error = ref(null);
const search = ref('');
const form = ref({ type: 'RES', name: '', primary_msisdn: '', email: '' });
const creating = ref(false);
const showCreate = ref(false);

const columns = [
    { key: 'customerId', label: 'Customer ID' },
    { key: 'name', label: 'Name' },
    { key: 'type', label: 'Type' },
    { key: 'primaryMsisdn', label: 'MSISDN' },
    { key: 'kycStatus', label: 'KYC' },
];

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const params = search.value ? { q: search.value } : {};
        const { data } = await window.axios.get('/api/customers', { params });
        customers.value = data.items ?? [];
    } catch (e) {
        error.value = e.response?.data?.message ?? t('Failed to load customers');
    } finally {
        loading.value = false;
    }
}

async function create() {
    creating.value = true;
    error.value = null;
    try {
        await window.axios.post('/api/customers', form.value, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
        });
        form.value = { type: 'RES', name: '', primary_msisdn: '', email: '' };
        showCreate.value = false;
        await load();
    } catch (e) {
        error.value = e.response?.data?.message ?? t('Failed to create customer');
    } finally {
        creating.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Head :title="t('Customers')" />

    <AuthenticatedLayout>
        <template #header>
            <PageHeader :title="t('Customers')" :crumbs="[{ label: 'CRM' }, { label: 'Customers' }]">
                <template #actions>
                    <button @click="showCreate = true" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">+ {{ t('New customer') }}</button>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <Panel :title="t('All customers')" :subtitle="t('Click a customer to open their 360 view')">
                <template #actions>
                    <input v-model="search" :placeholder="t('Search name / msisdn / email')" class="w-72 rounded-md border-gray-300 text-sm" @keyup.enter="load" />
                    <button class="rounded-md border px-3 py-1.5 text-sm" @click="load">{{ t('Search') }}</button>
                </template>

                <p v-if="error" class="mb-3 rounded-lg bg-red-50 p-3 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</p>

                <DataTable :columns="columns" :rows="customers" row-key="customerId" :loading="loading" empty="No customers yet."
                    @select="router.visit(`/customers/${$event.customerId}`)">
                    <template #cell-customerId="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                    <template #cell-type="{ value }">{{ value === 'RES' ? t('Residential') : t('Commercial') }}</template>
                    <template #cell-kycStatus="{ value }"><StatusBadge :status="value" /></template>
                </DataTable>
            </Panel>
        </div>

        <!-- Create customer drawer -->
        <Drawer :open="showCreate" :title="t('New customer')" width="max-w-lg" @update:open="showCreate = $event">
            <form class="space-y-4" @submit.prevent="create">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Type') }}</label>
                    <select v-model="form.type" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="RES">{{ t('Residential') }}</option>
                        <option value="COM">{{ t('Commercial') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Name') }}</label>
                    <input v-model="form.name" :placeholder="t('Name')" required class="w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('MSISDN') }}</label>
                    <input v-model="form.primary_msisdn" placeholder="+2547..." required class="w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Email') }}</label>
                    <input v-model="form.email" :placeholder="t('Email')" type="email" class="w-full rounded-md border-gray-300 text-sm" />
                </div>
                <button :disabled="creating" class="w-full rounded-md bg-op px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
                    {{ creating ? t('Saving…') : t('Create customer') }}
                </button>
            </form>
        </Drawer>
    </AuthenticatedLayout>
</template>

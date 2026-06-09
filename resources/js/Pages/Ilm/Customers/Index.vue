<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

// ILM-CFG-01 Backoffice Customers screen. Reads/writes go through the ILM API
// (the owning module) per DD_API-00 — the page holds no business state.
const customers = ref([]);
const loading = ref(true);
const error = ref(null);
const search = ref('');
const form = ref({ type: 'RES', name: '', primary_msisdn: '', email: '' });
const creating = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const params = search.value ? { q: search.value } : {};
        const { data } = await window.axios.get('/api/customers', { params });
        customers.value = data.items ?? [];
    } catch (e) {
        error.value = e.response?.data?.message ?? 'Failed to load customers';
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
        await load();
    } catch (e) {
        error.value = e.response?.data?.message ?? 'Failed to create customer';
    } finally {
        creating.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Head title="Customers" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Customers (ILM-CFG-01)</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <!-- Create -->
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="mb-4 font-medium text-gray-700">New customer</h3>
                    <form class="grid grid-cols-1 gap-3 sm:grid-cols-5" @submit.prevent="create">
                        <select v-model="form.type" class="rounded-md border-gray-300 text-sm">
                            <option value="RES">Residential</option>
                            <option value="COM">Commercial</option>
                        </select>
                        <input v-model="form.name" placeholder="Name" required class="rounded-md border-gray-300 text-sm" />
                        <input v-model="form.primary_msisdn" placeholder="+2547..." required class="rounded-md border-gray-300 text-sm" />
                        <input v-model="form.email" placeholder="Email" type="email" class="rounded-md border-gray-300 text-sm" />
                        <button :disabled="creating" class="rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 disabled:opacity-50">
                            {{ creating ? 'Saving…' : 'Create' }}
                        </button>
                    </form>
                </div>

                <!-- List -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="flex items-center gap-3 border-b p-4">
                        <input v-model="search" placeholder="Search name / msisdn / email" class="w-72 rounded-md border-gray-300 text-sm" @keyup.enter="load" />
                        <button class="rounded-md border px-3 py-2 text-sm" @click="load">Search</button>
                    </div>

                    <p v-if="error" class="p-4 text-sm text-red-600">{{ error }}</p>
                    <p v-else-if="loading" class="p-4 text-sm text-gray-500">Loading…</p>

                    <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-2">Customer ID</th>
                                <th class="px-4 py-2">Name</th>
                                <th class="px-4 py-2">Type</th>
                                <th class="px-4 py-2">MSISDN</th>
                                <th class="px-4 py-2">KYC</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="c in customers" :key="c.customerId" class="cursor-pointer hover:bg-indigo-50" @click="router.visit(`/customers/${c.customerId}`)">
                                <td class="px-4 py-2 font-mono text-xs">{{ c.customerId }}</td>
                                <td class="px-4 py-2">{{ c.name }}</td>
                                <td class="px-4 py-2">{{ c.type }}</td>
                                <td class="px-4 py-2">{{ c.primaryMsisdn }}</td>
                                <td class="px-4 py-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs"
                                          :class="c.kycStatus === 'APPROVED' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">
                                        {{ c.kycStatus }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!customers.length">
                                <td colspan="5" class="px-4 py-6 text-center text-gray-400">No customers yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

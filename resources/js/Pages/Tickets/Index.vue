<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

// TCK-01 Backoffice tickets screen. All reads/writes go through the TCK API.
const tickets = ref([]);
const loading = ref(true);
const error = ref(null);
const form = ref({ category: 'TECHNICAL', priority: 'NORMAL', subject: '', customer_id: '' });
const creating = ref(false);

const badge = (s) => ({
    OPEN: 'bg-blue-100 text-blue-700',
    ASSIGNED: 'bg-indigo-100 text-indigo-700',
    IN_PROGRESS: 'bg-amber-100 text-amber-700',
    PENDING_WO: 'bg-purple-100 text-purple-700',
    RESOLVED: 'bg-green-100 text-green-700',
    CLOSED: 'bg-gray-100 text-gray-600',
}[s] ?? 'bg-gray-100 text-gray-600');

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await window.axios.get('/api/tickets');
        tickets.value = data.items ?? [];
    } catch (e) {
        error.value = e.response?.data?.message ?? 'Failed to load tickets';
    } finally {
        loading.value = false;
    }
}

async function create() {
    creating.value = true;
    error.value = null;
    try {
        await window.axios.post('/api/tickets', form.value, { headers: { 'Idempotency-Key': crypto.randomUUID() } });
        form.value = { category: 'TECHNICAL', priority: 'NORMAL', subject: '', customer_id: '' };
        await load();
    } catch (e) {
        error.value = e.response?.data?.message ?? 'Failed to create ticket';
    } finally {
        creating.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Head title="Tickets" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Tickets (TCK-01)</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="mb-4 font-medium text-gray-700">New ticket</h3>
                    <form class="grid grid-cols-1 gap-3 sm:grid-cols-5" @submit.prevent="create">
                        <select v-model="form.category" class="rounded-md border-gray-300 text-sm">
                            <option>TECHNICAL</option><option>BILLING</option><option>INFORMATION</option>
                            <option>COMPLAINT</option><option>SERVICE_REQUEST</option>
                        </select>
                        <select v-model="form.priority" class="rounded-md border-gray-300 text-sm">
                            <option>LOW</option><option>NORMAL</option><option>HIGH</option><option>URGENT</option>
                        </select>
                        <input v-model="form.subject" placeholder="Subject" required class="rounded-md border-gray-300 text-sm sm:col-span-2" />
                        <button :disabled="creating" class="rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 disabled:opacity-50">
                            {{ creating ? 'Saving…' : 'Create' }}
                        </button>
                    </form>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <p v-if="error" class="p-4 text-sm text-red-600">{{ error }}</p>
                    <p v-else-if="loading" class="p-4 text-sm text-gray-500">Loading…</p>
                    <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-2">Ticket</th><th class="px-4 py-2">Subject</th>
                                <th class="px-4 py-2">Category</th><th class="px-4 py-2">Priority</th><th class="px-4 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="t in tickets" :key="t.ticket_id">
                                <td class="px-4 py-2 font-mono text-xs">{{ t.ticket_id }}</td>
                                <td class="px-4 py-2">{{ t.subject }}</td>
                                <td class="px-4 py-2">{{ t.category }}</td>
                                <td class="px-4 py-2">{{ t.priority }}</td>
                                <td class="px-4 py-2"><span class="rounded-full px-2 py-0.5 text-xs" :class="badge(t.status)">{{ t.status }}</span></td>
                            </tr>
                            <tr v-if="!tickets.length"><td colspan="5" class="px-4 py-6 text-center text-gray-400">No tickets yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

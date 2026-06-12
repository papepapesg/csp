<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();

// IT-Ops: service/worker health + control, and searchable application logs.
const services = ref([]);
const queues = ref({});
const logs = ref([]);
const filters = ref({ level: '', q: '' });
const error = ref(null);
const notice = ref(null);
let poll = null;

const badge = (s) => (s === 'UP' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700');
const levelClass = (l) => ({
    error: 'text-red-600', critical: 'text-red-700 font-semibold', warning: 'text-amber-600',
    info: 'text-gray-700', debug: 'text-gray-400',
}[l] ?? 'text-gray-700');

async function loadServices() {
    const { data } = await window.axios.get('/api/itops/services');
    services.value = data.services ?? [];
    queues.value = data.queues ?? {};
}
async function loadLogs() {
    const params = { size: 100 };
    if (filters.value.level) params.level = filters.value.level;
    if (filters.value.q) params.q = filters.value.q;
    const { data } = await window.axios.get('/api/itops/logs', { params });
    logs.value = data.items ?? [];
}
async function refresh() {
    try { await Promise.all([loadServices(), loadLogs()]); error.value = null; }
    catch (e) { error.value = e.response?.data?.message ?? t('Load failed'); }
}
async function restart(service) {
    notice.value = null;
    try {
        await window.axios.post(`/api/itops/services/${service}/restart`, {}, { headers: { 'Idempotency-Key': crypto.randomUUID() } });
        notice.value = `Restart requested for ${service} (worker exits on next loop for supervisor restart).`;
        await loadServices();
    } catch (e) { error.value = e.response?.data?.message ?? t('Restart failed'); }
}

onMounted(() => { refresh(); poll = setInterval(loadServices, 5000); });
onUnmounted(() => clearInterval(poll));
</script>

<template>
    <Head :title="t('IT-Ops')" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ t('IT-Ops · Services & Logs') }}</h2>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-[100rem] space-y-6 px-4 sm:px-6 lg:px-8">
                <p v-if="error" class="rounded bg-red-50 p-2 text-sm text-red-600">{{ error }}</p>
                <p v-if="notice" class="rounded bg-green-50 p-2 text-sm text-green-700">{{ notice }}</p>

                <!-- Services -->
                <section>
                    <h3 class="mb-2 text-sm font-semibold uppercase text-gray-500">{{ t('Workers & services') }}</h3>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        <div v-for="s in services" :key="s.service" class="rounded-lg bg-white p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <strong class="text-sm">{{ s.service }}</strong>
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="badge(s.status)">{{ s.status }}</span>
                            </div>
                            <div class="mt-1 text-xs text-gray-400">{{ t('seen') }} {{ s.lastSeenAt?.substring(11,19) || '—' }}</div>
                            <button class="mt-2 w-full rounded bg-indigo-50 px-2 py-1 text-xs text-indigo-700" @click="restart(s.service)">{{ t('Restart') }}</button>
                        </div>
                        <div v-if="!services.length" class="col-span-full rounded-lg bg-white p-4 text-sm text-gray-400 shadow-sm">
                            {{ t('No heartbeats yet. Start a worker:') }} <code>php artisan sophix:workflow:work</code>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-4 text-xs text-gray-600">
                        <span>{{ t('Workflow tasks queued:') }} <strong>{{ queues.workflowTasksCreated ?? 0 }}</strong></span>
                        <span>{{ t('Incidents:') }} <strong class="text-red-600">{{ queues.workflowIncidents ?? 0 }}</strong></span>
                        <span>{{ t('Outbox unpublished:') }} <strong>{{ queues.outboxUnpublished ?? 0 }}</strong></span>
                    </div>
                </section>

                <!-- Logs -->
                <section>
                    <div class="mb-2 flex items-center gap-2">
                        <h3 class="text-sm font-semibold uppercase text-gray-500">{{ t('Logs') }}</h3>
                        <span class="flex-1"></span>
                        <select v-model="filters.level" class="rounded border-gray-300 text-sm">
                            <option value="">{{ t('All levels') }}</option>
                            <option>debug</option><option>info</option><option>warning</option><option>error</option><option>critical</option>
                        </select>
                        <input v-model="filters.q" :placeholder="t('search message')" class="w-64 rounded border-gray-300 text-sm" @keyup.enter="loadLogs" />
                        <button class="rounded border bg-white px-3 py-1 text-sm" @click="loadLogs">{{ t('Search') }}</button>
                    </div>
                    <div class="max-h-[40vh] overflow-auto rounded-lg bg-gray-900 p-3 font-mono text-xs text-gray-100">
                        <div v-for="l in logs" :key="l.id" class="border-b border-gray-800 py-1">
                            <span class="text-gray-500">{{ l.logged_at?.substring(0,19).replace('T',' ') }}</span>
                            <span class="ml-2 uppercase" :class="levelClass(l.level)">{{ l.level }}</span>
                            <span class="ml-2 text-gray-400">[{{ l.channel }}]</span>
                            <span class="ml-2">{{ l.message }}</span>
                            <span v-if="l.correlation_id" class="ml-2 text-indigo-300">{{ l.correlation_id }}</span>
                        </div>
                        <div v-if="!logs.length" class="text-gray-500">{{ t('No log entries match.') }}</div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

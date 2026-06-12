<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';
import { VueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import { useI18n } from '@/i18n';

const { t } = useI18n();

// IT-Ops console: monitor running processes, replay an end-to-end execution
// trace on the flow graph, and act on stuck external-task incidents.
const tab = ref('instances');
const instances = ref([]);
const tasks = ref([]);
const detail = ref(null);
const traceNodes = ref([]);
const traceEdges = ref([]);
const error = ref(null);
let poll = null;

const vfType = (t) => (t === 'startEvent' ? 'input' : t === 'endEvent' ? 'output' : 'default');
const badge = (s) => ({
    RUNNING: 'bg-blue-100 text-blue-700', COMPLETED: 'bg-green-100 text-green-700',
    FAILED: 'bg-red-100 text-red-700', INCIDENT: 'bg-red-100 text-red-700',
    CANCELLED: 'bg-gray-100 text-gray-600', CREATED: 'bg-amber-100 text-amber-700',
    LOCKED: 'bg-op-soft text-op',
}[s] ?? 'bg-gray-100 text-gray-600');

async function loadInstances() {
    const { data } = await window.axios.get('/api/workflow/instances', { params: { size: 50 } });
    instances.value = data.items ?? [];
}
async function loadTasks() {
    const { data } = await window.axios.get('/api/workflow/tasks', { params: { size: 50, status: 'CREATED,LOCKED,INCIDENT,FAILED' } });
    tasks.value = data.items ?? [];
}

async function openInstance(id) {
    error.value = null;
    const { data } = await window.axios.get(`/api/workflow/instances/${id}`);
    detail.value = data;
    // Load the definition graph and overlay the trace.
    const def = (await window.axios.get(`/api/workflow/definitions/${data.instance.definition_id}`)).data;
    const visited = new Set((data.trace ?? []).filter((t) => t.event === 'NODE_ENTER').map((t) => t.node_id));
    const active = new Set(data.instance.active_nodes ?? []);
    const completed = data.instance.status === 'COMPLETED';
    traceNodes.value = (def.graph?.nodes ?? []).map((n) => ({
        id: n.id, type: vfType(n.type),
        position: n.position ?? { x: Math.random() * 300, y: Math.random() * 200 },
        data: { label: n.data?.label ?? n.id },
        class: active.has(n.id) ? 'tr-active' : visited.has(n.id) ? (completed ? 'tr-done' : 'tr-visited') : 'tr-idle',
    }));
    traceEdges.value = (def.graph?.edges ?? []).map((e) => ({ id: e.id, source: e.source, target: e.target, animated: active.size > 0 }));
}

async function retry(taskId) {
    await window.axios.post(`/api/workflow/incidents/${taskId}/retry`);
    await loadTasks();
}

async function refresh() {
    try {
        await Promise.all([loadInstances(), loadTasks()]);
        if (detail.value) await openInstance(detail.value.instance.instance_id);
    } catch (e) { error.value = e.response?.data?.message ?? t('Load failed'); }
}

onMounted(() => { refresh(); poll = setInterval(refresh, 5000); });
onUnmounted(() => clearInterval(poll));
</script>

<template>
    <Head :title="t('Workflow Ops')" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ t('IT-Ops · Process Monitor') }}</h2>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-[110rem] space-y-4 px-4 sm:px-6 lg:px-8">
                <div class="flex gap-2 text-sm">
                    <button class="rounded px-3 py-1" :class="tab==='instances' ? 'bg-gray-800 text-white' : 'bg-white'" @click="tab='instances'">{{ t('Instances') }}</button>
                    <button class="rounded px-3 py-1" :class="tab==='tasks' ? 'bg-gray-800 text-white' : 'bg-white'" @click="tab='tasks'">{{ t('Tasks & Incidents') }}</button>
                    <span class="flex-1"></span>
                    <button class="rounded border bg-white px-3 py-1" @click="refresh">{{ t('Refresh') }}</button>
                </div>
                <p v-if="error" class="rounded bg-red-50 p-2 text-sm text-red-600">{{ error }}</p>

                <div v-show="tab==='instances'" class="grid grid-cols-12 gap-4">
                    <div class="col-span-5 overflow-hidden rounded-lg bg-white shadow-sm">
                        <table class="min-w-full divide-y text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr><th class="px-3 py-2">{{ t('Process') }}</th><th class="px-3 py-2">{{ t('Business key') }}</th><th class="px-3 py-2">{{ t('Status') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="i in instances" :key="i.instance_id" class="cursor-pointer hover:bg-gray-50" @click="openInstance(i.instance_id)">
                                    <td class="px-3 py-2">{{ i.process_key }} <span class="text-xs text-gray-400">v{{ i.definition_version }}</span></td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ i.business_key }}</td>
                                    <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs" :class="badge(i.status)">{{ i.status }}</span></td>
                                </tr>
                                <tr v-if="!instances.length"><td colspan="3" class="px-3 py-6 text-center text-gray-400">{{ t('No instances.') }}</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="col-span-7 space-y-3">
                        <div class="rounded-lg bg-white shadow-sm" style="height: 42vh">
                            <div class="border-b p-2 text-sm font-medium">
                                {{ t('Execution trace') }}
                                <span v-if="detail" class="text-xs text-gray-400">— {{ detail.instance.instance_id }}</span>
                            </div>
                            <VueFlow v-if="detail" v-model:nodes="traceNodes" v-model:edges="traceEdges" fit-view-on-init :nodes-draggable="false" :elements-selectable="false" class="tr-canvas">
                                <Background pattern-color="#e2e8f0" :gap="16" />
                            </VueFlow>
                            <p v-else class="p-4 text-sm text-gray-400">{{ t('Select an instance to replay its path.') }}</p>
                        </div>
                        <div v-if="detail" class="max-h-44 overflow-auto rounded-lg bg-white p-3 text-xs shadow-sm">
                            <div v-for="tr in detail.trace" :key="tr.id" class="flex gap-2 border-b py-1 last:border-0">
                                <span class="font-mono text-gray-400">{{ tr.created_at?.substring(11,19) }}</span>
                                <span class="font-medium">{{ tr.event }}</span>
                                <span class="text-gray-500">{{ tr.node_id }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-show="tab==='tasks'" class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <table class="min-w-full divide-y text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr><th class="px-3 py-2">{{ t('Task') }}</th><th class="px-3 py-2">{{ t('Topic') }}</th><th class="px-3 py-2">{{ t('Status') }}</th><th class="px-3 py-2">{{ t('Retries') }}</th><th class="px-3 py-2">{{ t('Error') }}</th><th class="px-3 py-2"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="task in tasks" :key="task.task_id">
                                <td class="px-3 py-2 font-mono text-xs">{{ task.task_id }}</td>
                                <td class="px-3 py-2">{{ task.topic }}</td>
                                <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs" :class="badge(task.status)">{{ task.status }}</span></td>
                                <td class="px-3 py-2">{{ task.retries }}</td>
                                <td class="px-3 py-2 text-xs text-red-500">{{ task.error_message }}</td>
                                <td class="px-3 py-2">
                                    <button v-if="task.status === 'INCIDENT' || task.status === 'FAILED'" class="rounded bg-op-soft px-2 py-1 text-xs text-op" @click="retry(task.task_id)">{{ t('Retry') }}</button>
                                </td>
                            </tr>
                            <tr v-if="!tasks.length"><td colspan="6" class="px-3 py-6 text-center text-gray-400">{{ t('No active tasks or incidents.') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style>
.tr-canvas { height: calc(42vh - 37px); }
.vue-flow__node.tr-idle { opacity: 0.45; }
.vue-flow__node.tr-visited { background: #dbeafe; border-color: #3b82f6; }
.vue-flow__node.tr-done { background: #dcfce7; border-color: #22c55e; }
.vue-flow__node.tr-active { background: #fef9c3; border-color: #eab308; box-shadow: 0 0 0 3px rgba(234,179,8,.4); }
</style>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, computed } from 'vue';
import { VueFlow, useVueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import { useI18n } from '@/i18n';

const { t } = useI18n();

// Workflow studio (FOUNDATION_CAMUNDA). Flows are DATA: load a process_definition
// graph, drag steps from the toolbox, connect them, save a draft, deploy.
const { onConnect, addEdges, project } = useVueFlow();

const definitions = ref([]);
const palette = ref({ nodeTypes: [], steps: [] });
const current = ref(null); // loaded definition
const nodes = ref([]);
const edges = ref([]);
const selected = ref(null);
const status = ref('');
const error = ref(null);

const vfType = (t) => (t === 'startEvent' ? 'input' : t === 'endEvent' ? 'output' : 'default');

function toVf(graph) {
    const ns = (graph.nodes ?? []).map((n) => ({
        id: n.id,
        type: vfType(n.type),
        position: n.position ?? { x: Math.random() * 400, y: Math.random() * 300 },
        data: { label: n.data?.label ?? n.id, domainType: n.type, topic: n.data?.topic, config: n.data?.config ?? {} },
        class: `wf-${n.type}`,
    }));
    const es = (graph.edges ?? []).map((e) => ({
        id: e.id,
        source: e.source,
        target: e.target,
        label: e.data?.label ?? (e.data?.default ? 'default' : ''),
        data: e.data ?? {},
        animated: true,
    }));
    return { ns, es };
}

function fromVf() {
    return {
        nodes: nodes.value.map((n) => ({
            id: n.id,
            type: n.data.domainType,
            position: n.position,
            data: { label: n.data.label, topic: n.data.topic, config: n.data.config },
        })),
        edges: edges.value.map((e) => ({
            id: e.id, source: e.source, target: e.target, data: e.data ?? {},
        })),
    };
}

onConnect((conn) => {
    const id = `e_${conn.source}_${conn.target}_${Date.now()}`;
    addEdges([{ ...conn, id, data: {}, animated: true }]);
});

async function loadLists() {
    const [d, p] = await Promise.all([
        window.axios.get('/api/workflow/definitions', { params: { size: 100 } }),
        window.axios.get('/api/workflow/palette'),
    ]);
    definitions.value = d.data.items ?? [];
    palette.value = p.data;
}

async function open(id) {
    error.value = null;
    const { data } = await window.axios.get(`/api/workflow/definitions/${id}`);
    current.value = data;
    const { ns, es } = toVf(data.graph ?? { nodes: [], edges: [] });
    nodes.value = ns;
    edges.value = es;
    selected.value = null;
}

function addStep(topic, label) {
    const id = `n_${Date.now()}`;
    nodes.value.push({ id, type: 'default', position: project({ x: 250, y: 120 }), data: { label: label, domainType: 'serviceTask', topic, config: {} }, class: 'wf-serviceTask' });
}
function addNode(domainType, label) {
    const id = `n_${Date.now()}`;
    nodes.value.push({ id, type: vfType(domainType), position: project({ x: 250, y: 200 }), data: { label, domainType, config: {} }, class: `wf-${domainType}` });
}

function onNodeClick({ node }) { selected.value = node; }
function deleteSelected() {
    if (!selected.value) return;
    nodes.value = nodes.value.filter((n) => n.id !== selected.value.id);
    edges.value = edges.value.filter((e) => e.source !== selected.value.id && e.target !== selected.value.id);
    selected.value = null;
}

async function saveDraft() {
    error.value = null;
    try {
        const graph = fromVf();
        if (current.value && current.value.status === 'DRAFT') {
            await window.axios.put(`/api/workflow/definitions/${current.value.definition_id}`, { graph });
            status.value = t('Draft saved');
        } else {
            const { data } = await window.axios.post('/api/workflow/definitions', {
                process_key: current.value?.process_key ?? prompt('Process key (e.g. sub-pause)') ?? 'new-flow',
                name: current.value?.name ?? 'New flow',
                operator_code: current.value?.operator_code ?? null,
                graph,
            });
            current.value = data;
            status.value = t('New draft v') + data.version + t(' created');
        }
        await loadLists();
    } catch (e) { error.value = e.response?.data?.message ?? t('Save failed'); }
}

async function deploy() {
    if (!current.value) return;
    try {
        await window.axios.post(`/api/workflow/definitions/${current.value.definition_id}/deploy`);
        status.value = t('Deployed — now the active version (no code change)');
        await loadLists();
        await open(current.value.definition_id);
    } catch (e) { error.value = e.response?.data?.message ?? t('Deploy failed'); }
}

const stepConfigJson = computed({
    get: () => JSON.stringify(selected.value?.data?.config ?? {}, null, 2),
    set: (v) => { try { selected.value.data.config = JSON.parse(v); } catch { /* ignore */ } },
});

onMounted(loadLists);
</script>

<template>
    <Head :title="t('Workflow Studio')" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ t('Workflow Studio (FOUNDATION_CAMUNDA)') }}</h2>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-[110rem] px-4 sm:px-6 lg:px-8">
                <p class="mb-3 text-sm text-gray-500">{{ t('Flows are configuration, not code. Edit a draft, deploy it, and the engine runs the new shape — an operator can override any flow without a redeploy.') }}</p>
                <p v-if="error" class="mb-3 rounded bg-red-50 p-2 text-sm text-red-600">{{ error }}</p>
                <p v-if="status" class="mb-3 rounded bg-green-50 p-2 text-sm text-green-700">{{ status }}</p>

                <div class="grid grid-cols-12 gap-4">
                    <!-- Definitions list -->
                    <div class="col-span-2 rounded-lg bg-white p-3 shadow-sm">
                        <h3 class="mb-2 text-xs font-semibold uppercase text-gray-500">{{ t('Definitions') }}</h3>
                        <ul class="space-y-1 text-sm">
                            <li v-for="d in definitions" :key="d.definition_id">
                                <button class="w-full rounded px-2 py-1 text-left hover:bg-gray-100"
                                        :class="current?.definition_id === d.definition_id ? 'bg-gray-100 font-medium' : ''"
                                        @click="open(d.definition_id)">
                                    {{ d.process_key }} <span class="text-xs text-gray-400">v{{ d.version }}{{ d.operator_code ? ' · '+d.operator_code : '' }}</span>
                                    <span class="ml-1 rounded px-1 text-[10px]"
                                          :class="d.status === 'DEPLOYED' ? 'bg-green-100 text-green-700' : d.status === 'DRAFT' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500'">{{ d.status }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- Canvas -->
                    <div class="col-span-8 rounded-lg bg-white shadow-sm" style="height: 70vh">
                        <div class="flex items-center gap-2 border-b p-2 text-sm">
                            <strong>{{ current ? current.process_key + ' v' + current.version : t('Select a flow') }}</strong>
                            <span class="flex-1"></span>
                            <button class="rounded border px-2 py-1" @click="addNode('exclusiveGateway','Gateway')">{{ t('+ Gateway') }}</button>
                            <button class="rounded border px-2 py-1" @click="addNode('endEvent','End')">{{ t('+ End') }}</button>
                            <button class="rounded bg-gray-800 px-3 py-1 text-white" @click="saveDraft">{{ t('Save draft') }}</button>
                            <button class="rounded bg-indigo-600 px-3 py-1 text-white" @click="deploy">{{ t('Deploy') }}</button>
                        </div>
                        <VueFlow v-model:nodes="nodes" v-model:edges="edges" fit-view-on-init
                                 @node-click="onNodeClick" class="wf-canvas">
                            <Background pattern-color="#cbd5e1" :gap="16" />
                            <Controls />
                        </VueFlow>
                    </div>

                    <!-- Toolbox + inspector -->
                    <div class="col-span-2 space-y-4">
                        <div class="rounded-lg bg-white p-3 shadow-sm">
                            <h3 class="mb-2 text-xs font-semibold uppercase text-gray-500">{{ t('Toolbox (steps)') }}</h3>
                            <button v-for="s in palette.steps" :key="s.topic"
                                    class="mb-1 w-full truncate rounded border px-2 py-1 text-left text-xs hover:bg-indigo-50"
                                    :title="s.topic" @click="addStep(s.topic, s.label)">+ {{ s.label }}</button>
                            <p v-if="!palette.steps.length" class="text-xs text-gray-400">{{ t('No steps registered.') }}</p>
                        </div>

                        <div v-if="selected" class="rounded-lg bg-white p-3 shadow-sm">
                            <h3 class="mb-2 text-xs font-semibold uppercase text-gray-500">{{ t('Node') }}</h3>
                            <label class="block text-xs text-gray-500">{{ t('Label') }}</label>
                            <input v-model="selected.data.label" class="mb-2 w-full rounded border-gray-300 text-sm" />
                            <p class="mb-2 text-xs text-gray-500">{{ t('Type:') }} <code>{{ selected.data.domainType }}</code></p>
                            <p v-if="selected.data.topic" class="mb-2 text-xs text-gray-500">{{ t('Topic:') }} <code>{{ selected.data.topic }}</code></p>
                            <label class="block text-xs text-gray-500">{{ t('Config (JSON)') }}</label>
                            <textarea v-model="stepConfigJson" rows="5" class="w-full rounded border-gray-300 font-mono text-xs"></textarea>
                            <button class="mt-2 w-full rounded bg-red-50 px-2 py-1 text-xs text-red-600" @click="deleteSelected">{{ t('Delete node') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style>
.wf-canvas { height: calc(70vh - 41px); }
.vue-flow__node.wf-serviceTask { background: #eef2ff; border-color: #6366f1; }
.vue-flow__node.wf-exclusiveGateway { background: #fef9c3; border-color: #ca8a04; transform: rotate(0deg); }
.vue-flow__node.wf-userTask { background: #fae8ff; border-color: #a21caf; }
</style>

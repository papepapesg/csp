<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
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

// Workflow studio (FOUNDATION_CAMUNDA). Flows are DATA: load a process_definition graph, drag
// elements from the palette, connect them, save a draft, deploy. The left palette lists every
// flow element and registered step; selecting a node shows — in the inspector — a plain-language
// description of "what this node is for", so a non-engineer can design a flow with confidence.
const { onConnect, addEdges, project } = useVueFlow();

const definitions = ref([]);
const palette = ref({ nodeTypes: [], steps: [] });
const current = ref(null); // loaded definition
const nodes = ref([]);
const edges = ref([]);
const selected = ref(null);
const status = ref('');
const error = ref(null);

const vfType = (ty) => (ty === 'startEvent' ? 'input' : ty === 'endEvent' ? 'output' : 'default');

// Description lookups so the inspector can explain any node from palette metadata.
const nodeTypeMeta = computed(() => Object.fromEntries((palette.value.nodeTypes ?? []).map((n) => [n.type, n])));
const stepMeta = computed(() => Object.fromEntries((palette.value.steps ?? []).map((s) => [s.topic, s])));
const groupedNodeTypes = computed(() => {
    const g = {};
    for (const n of palette.value.nodeTypes ?? []) (g[n.category ?? 'Elements'] ??= []).push(n);
    return g;
});

function describe(node) {
    if (!node) return '';
    const d = node.data ?? {};
    if (d.topic && stepMeta.value[d.topic]) return stepMeta.value[d.topic].description;
    if (nodeTypeMeta.value[d.domainType]) return nodeTypeMeta.value[d.domainType].description;
    return d.description ?? '';
}

function toVf(graph) {
    const ns = (graph.nodes ?? []).map((n) => ({
        id: n.id,
        type: vfType(n.type),
        position: n.position ?? { x: Math.random() * 400, y: Math.random() * 300 },
        data: { label: n.data?.label ?? n.id, domainType: n.type, topic: n.data?.topic, config: n.data?.config ?? {} },
        class: `wf-${n.type}`,
    }));
    const es = (graph.edges ?? []).map((e) => ({
        id: e.id, source: e.source, target: e.target,
        label: e.data?.label ?? (e.data?.default ? 'default' : ''),
        data: e.data ?? {}, animated: true,
    }));
    return { ns, es };
}

function fromVf() {
    return {
        nodes: nodes.value.map((n) => ({
            id: n.id, type: n.data.domainType, position: n.position,
            data: { label: n.data.label, topic: n.data.topic, config: n.data.config },
        })),
        edges: edges.value.map((e) => ({ id: e.id, source: e.source, target: e.target, data: e.data ?? {} })),
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

function addStep(step) {
    const id = `n_${Date.now()}`;
    nodes.value.push({ id, type: 'default', position: project({ x: 260, y: 140 }),
        data: { label: step.label, domainType: 'serviceTask', topic: step.topic, config: {} }, class: 'wf-serviceTask' });
}
function addNode(nt) {
    const id = `n_${Date.now()}`;
    nodes.value.push({ id, type: vfType(nt.type), position: project({ x: 260, y: 200 }),
        data: { label: nt.label, domainType: nt.type, config: {} }, class: `wf-${nt.type}` });
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
            status.value = t('New draft v:v created', { v: data.version });
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
            <PageHeader :title="t('Workflow Studio')" :crumbs="[{ label: 'Studios' }, { label: 'Workflow' }]">
                <template #actions>
                    <button class="rounded-md border px-3 py-1.5 text-sm" @click="addNode({ type: 'exclusiveGateway', label: t('Gateway') })">+ {{ t('Gateway') }}</button>
                    <button class="rounded-md border px-3 py-1.5 text-sm" @click="addNode({ type: 'endEvent', label: t('End') })">+ {{ t('End') }}</button>
                    <button class="rounded-md bg-gray-800 px-3 py-1.5 text-sm text-white" @click="saveDraft">{{ t('Save draft') }}</button>
                    <button class="rounded-md bg-op px-3 py-1.5 text-sm text-white" @click="deploy">{{ t('Deploy') }}</button>
                </template>
            </PageHeader>
        </template>

        <div class="px-4 py-4 sm:px-6 lg:px-8">
            <p class="mb-3 text-sm text-gray-500">{{ t('Flows are configuration, not code. Edit a draft, deploy it, and the engine runs the new shape — an operator can override any flow without a redeploy.') }}</p>
            <p v-if="error" class="mb-3 rounded-lg bg-red-50 p-2 text-sm text-red-600">{{ error }}</p>
            <p v-if="status" class="mb-3 rounded-lg bg-emerald-50 p-2 text-sm text-emerald-700">{{ status }}</p>

            <div class="grid grid-cols-12 gap-4">
                <!-- LEFT: palette + definitions -->
                <div class="col-span-12 space-y-4 lg:col-span-3">
                    <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-100">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Palette') }}</h3>
                        <div v-for="(items, cat) in groupedNodeTypes" :key="cat" class="mb-3">
                            <div class="mb-1 text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ t(cat) }}</div>
                            <button v-for="n in items" :key="n.type" :title="n.description"
                                class="mb-1 block w-full rounded-lg border border-gray-200 px-2 py-1.5 text-left text-xs hover:border-op hover:bg-op-soft"
                                @click="addNode(n)">
                                <span class="font-medium text-gray-700">+ {{ t(n.label) }}</span>
                            </button>
                        </div>
                        <div class="mb-1 text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ t('Steps') }}</div>
                        <button v-for="s in palette.steps" :key="s.topic" :title="s.description"
                            class="mb-1 block w-full truncate rounded-lg border border-gray-200 px-2 py-1.5 text-left text-xs hover:border-op hover:bg-op-soft"
                            @click="addStep(s)">
                            <span class="font-medium text-gray-700">+ {{ s.label }}</span>
                        </button>
                        <p v-if="!palette.steps.length" class="text-xs text-gray-400">{{ t('No steps registered.') }}</p>
                    </div>

                    <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-100">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Definitions') }}</h3>
                        <ul class="space-y-1 text-sm">
                            <li v-for="d in definitions" :key="d.definition_id">
                                <button class="flex w-full items-center gap-1 rounded-lg px-2 py-1 text-left hover:bg-gray-50"
                                    :class="current?.definition_id === d.definition_id ? 'bg-gray-50 font-medium' : ''" @click="open(d.definition_id)">
                                    <span class="truncate text-xs">{{ d.process_key }}</span>
                                    <span class="text-[10px] text-gray-400">v{{ d.version }}{{ d.operator_code ? ' · '+d.operator_code : '' }}</span>
                                    <span class="ml-auto"><StatusBadge :status="d.status" /></span>
                                </button>
                            </li>
                            <li v-if="!definitions.length" class="px-2 text-xs text-gray-400">{{ t('No definitions yet.') }}</li>
                        </ul>
                    </div>
                </div>

                <!-- CENTER: canvas -->
                <div class="col-span-12 rounded-xl bg-white shadow-sm ring-1 ring-gray-100 lg:col-span-6" style="height: 72vh">
                    <div class="flex items-center gap-2 border-b border-gray-100 px-3 py-2 text-sm">
                        <strong>{{ current ? current.process_key + ' v' + current.version : t('Select a flow') }}</strong>
                        <StatusBadge v-if="current" :status="current.status" />
                    </div>
                    <VueFlow v-model:nodes="nodes" v-model:edges="edges" fit-view-on-init @node-click="onNodeClick" class="wf-canvas">
                        <Background pattern-color="#cbd5e1" :gap="16" />
                        <Controls />
                    </VueFlow>
                </div>

                <!-- RIGHT: node inspector with "what is this node for" -->
                <div class="col-span-12 lg:col-span-3">
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Node inspector') }}</h3>
                        <template v-if="selected">
                            <label class="block text-xs text-gray-500">{{ t('Label') }}</label>
                            <input v-model="selected.data.label" class="mb-3 w-full rounded-md border-gray-300 text-sm" />

                            <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-gray-600">{{ selected.data.domainType }}</span>
                                <span v-if="selected.data.topic" class="rounded bg-op-soft px-1.5 py-0.5 font-mono text-op">{{ selected.data.topic }}</span>
                            </div>

                            <!-- What it does -->
                            <div class="mb-3 rounded-lg bg-op-soft/60 p-3 ring-1 ring-op/20">
                                <div class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-op">{{ t('What it does') }}</div>
                                <p class="text-xs leading-relaxed text-gray-700">{{ describe(selected) || t('No description available for this node.') }}</p>
                            </div>

                            <label class="block text-xs text-gray-500">{{ t('Config (JSON)') }}</label>
                            <textarea v-model="stepConfigJson" rows="6" class="w-full rounded-md border-gray-300 font-mono text-xs"></textarea>
                            <button class="mt-2 w-full rounded-md bg-red-50 px-2 py-1.5 text-xs font-medium text-red-600 hover:bg-red-100" @click="deleteSelected">{{ t('Delete node') }}</button>
                        </template>
                        <p v-else class="text-sm text-gray-400">{{ t('Select a node on the canvas to see what it does and edit it. Hover a palette item for its description.') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style>
.wf-canvas { height: calc(72vh - 41px); }
.vue-flow__node.wf-serviceTask { background: #eef2ff; border-color: #6366f1; }
.vue-flow__node.wf-exclusiveGateway { background: #fef9c3; border-color: #ca8a04; }
.vue-flow__node.wf-userTask { background: #fae8ff; border-color: #a21caf; }
.vue-flow__node.wf-timer { background: #ecfeff; border-color: #0891b2; }
.vue-flow__node.wf-messageCatch { background: #f0fdf4; border-color: #16a34a; }
</style>

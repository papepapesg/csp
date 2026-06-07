<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

// SOPHIX Field — installable PWA for FE-APP-02 (sales) + FE-APP-03 (contractor).
// Token auth, offline-tolerant: mutations are queued locally when offline and
// flushed when connectivity returns. The server stays the system of record.
const api = axios.create({ baseURL: '/api' });

const token = ref(localStorage.getItem('sophix_token') || '');
const user = ref(JSON.parse(localStorage.getItem('sophix_user') || 'null'));
const view = ref('login');
const tab = ref('contractor');
const online = ref(navigator.onLine);
const error = ref(null);
const notice = ref(null);

// ---- offline action queue ----
const queue = ref(JSON.parse(localStorage.getItem('sophix_queue') || '[]'));
function persistQueue() { localStorage.setItem('sophix_queue', JSON.stringify(queue.value)); }
function enqueue(action) { queue.value.push({ id: crypto.randomUUID(), ...action }); persistQueue(); }

async function send(method, url, body) {
    // Offline (or failed) mutations are queued and replayed later.
    if (!online.value) { enqueue({ method, url, body }); notice.value = 'Saved offline — will sync when back online.'; return { queued: true }; }
    try {
        const { data } = await api({ method, url, data: body, headers: { 'Idempotency-Key': crypto.randomUUID() } });
        return data;
    } catch (e) {
        if (!navigator.onLine) { enqueue({ method, url, body }); return { queued: true }; }
        throw e;
    }
}

async function flush() {
    if (!online.value || !queue.value.length) return;
    const pending = [...queue.value];
    for (const a of pending) {
        try {
            await api({ method: a.method, url: a.url, data: a.body, headers: { 'Idempotency-Key': a.id } });
            queue.value = queue.value.filter((q) => q.id !== a.id);
            persistQueue();
        } catch { /* keep in queue, retry next time */ }
    }
    notice.value = 'Synced.';
    await refresh();
}

if (token.value) api.defaults.headers.common.Authorization = `Bearer ${token.value}`;

// ---- auth ----
const creds = ref({ email: 'admin@sophix.local', password: 'password' });
const busy = ref(false);
async function login() {
    busy.value = true; error.value = null;
    try {
        const { data } = await api.post('/auth/token', { ...creds.value, device: 'pwa' });
        token.value = data.token; user.value = data.user;
        localStorage.setItem('sophix_token', token.value);
        localStorage.setItem('sophix_user', JSON.stringify(user.value));
        api.defaults.headers.common.Authorization = `Bearer ${token.value}`;
        view.value = 'home';
        await refresh();
    } catch (e) { error.value = e.response?.data?.message ?? 'Login failed'; }
    finally { busy.value = false; }
}
function logout() {
    localStorage.removeItem('sophix_token'); localStorage.removeItem('sophix_user');
    token.value = ''; user.value = null; view.value = 'login';
}

const can = (p) => (user.value?.permissions ?? []).includes(p);

// ---- contractor: my jobs ----
const jobs = ref([]);
const job = ref(null);
async function loadJobs() {
    try { const { data } = await api.get('/work-orders', { params: { status: 'PENDING,ASSIGNED,IN_PROGRESS', size: 50 } }); jobs.value = data.items ?? []; }
    catch (e) { if (online.value) error.value = e.response?.data?.message ?? 'Failed to load jobs'; }
}
async function openJob(id) {
    try { const { data } = await api.get(`/work-orders/${id}`); job.value = data; } catch { /* offline */ }
}
async function jobAction(action, body) {
    error.value = null; notice.value = null;
    const r = await send('post', `/work-orders/${job.value.work_order_id}/${action}`, body || {});
    if (!r?.queued) { await openJob(job.value.work_order_id); await loadJobs(); }
    else { job.value.status = action === 'start' ? 'IN_PROGRESS' : action === 'finalize' ? 'FINALIZED' : 'ASSIGNED'; }
}

// ---- sales: quick capture ----
const sale = ref({ name: '', primary_msisdn: '', type: 'RES', package_ref: 'pkg_triple', homepass_id: '' });
const serviceability = ref([]);
async function checkServiceability(q) {
    try { const { data } = await api.get('/homepass', { params: { q, status: 'SERVICEABLE', size: 10 } }); serviceability.value = data.items ?? []; } catch { /* offline */ }
}
async function captureOrder() {
    error.value = null; notice.value = null;
    try {
        const cust = await send('post', '/customers', { type: sale.value.type, name: sale.value.name, primary_msisdn: sale.value.primary_msisdn });
        if (cust?.queued) { notice.value = 'Lead saved offline — will create on sync.'; return; }
        const order = await send('post', '/fulfillment-orders', {
            customer_id: cust.customerId, account_id: cust.customerId, // simplified link
            homepass_id: sale.value.homepass_id || null, package_ref: sale.value.package_ref,
        });
        notice.value = order?.queued ? 'Order queued offline.' : `Order captured: ${order.order?.order_id}`;
        sale.value.name = ''; sale.value.primary_msisdn = '';
    } catch (e) { error.value = e.response?.data?.message ?? 'Capture failed'; }
}

async function refresh() { if (tab.value === 'contractor') await loadJobs(); }

function setOnline(v) { online.value = v; if (v) flush(); }
onMounted(() => {
    window.addEventListener('online', () => setOnline(true));
    window.addEventListener('offline', () => setOnline(false));
    if (token.value && user.value) { view.value = 'home'; refresh(); }
});
onUnmounted(() => {});

const pending = computed(() => queue.value.length);
</script>

<template>
    <div class="app">
        <!-- Login -->
        <template v-if="view === 'login'">
            <div class="bar"><h1>SOPHIX Field</h1></div>
            <div class="content">
                <div class="card">
                    <p class="muted">Sign in to your field account.</p>
                    <div v-if="error" class="err">{{ error }}</div>
                    <div class="label">Email</div>
                    <input v-model="creds.email" type="email" autocomplete="username" />
                    <div class="label">Password</div>
                    <input v-model="creds.password" type="password" autocomplete="current-password" />
                    <button class="btn" :disabled="busy" @click="login">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
                </div>
                <p class="muted">Installable: use “Add to Home Screen”. Works offline once loaded.</p>
            </div>
        </template>

        <!-- App -->
        <template v-else>
            <div class="bar">
                <h1>SOPHIX Field</h1>
                <span class="pill" :class="online ? 'online' : 'offline'">{{ online ? 'online' : 'offline' }}</span>
                <span v-if="pending" class="pill" style="background:#92400e">{{ pending }} queued</span>
                <button class="btn ghost" style="width:auto;padding:6px 10px" @click="logout">Exit</button>
            </div>

            <div class="content">
                <div v-if="error" class="err">{{ error }}</div>
                <div v-if="notice" class="ok">{{ notice }}</div>
                <button v-if="pending && online" class="btn alt" style="margin-bottom:12px" @click="flush">Sync {{ pending }} pending action(s)</button>

                <!-- Contractor -->
                <template v-if="tab === 'contractor'">
                    <template v-if="!job">
                        <h2 style="font-size:15px">My jobs</h2>
                        <div v-for="j in jobs" :key="j.work_order_id" class="card" @click="openJob(j.work_order_id)">
                            <div class="row" style="justify-content:space-between">
                                <strong>{{ j.type }}</strong><span class="badge">{{ j.status }}</span>
                            </div>
                            <div class="muted">{{ j.work_order_id }}</div>
                            <div class="muted">Region: {{ j.tech_region_id || '—' }}</div>
                        </div>
                        <p v-if="!jobs.length" class="muted">No assigned jobs.</p>
                    </template>
                    <template v-else>
                        <button class="btn ghost" style="margin-bottom:12px" @click="job=null">← Back to jobs</button>
                        <div class="card">
                            <div class="row" style="justify-content:space-between">
                                <strong>{{ job.type }}</strong><span class="badge">{{ job.status }}</span>
                            </div>
                            <div class="muted">{{ job.work_order_id }}</div>
                            <div class="muted">Account: {{ job.account_id || '—' }}</div>
                        </div>
                        <button v-if="job.status==='PENDING'||job.status==='ASSIGNED'" class="btn" style="margin-bottom:8px" @click="jobAction('assign',{assigned_technician_id:user.uid})">Claim</button>
                        <button v-if="job.status==='ASSIGNED'" class="btn" style="margin-bottom:8px" @click="jobAction('start')">Start job</button>
                        <button v-if="job.status==='IN_PROGRESS'" class="btn alt" @click="jobAction('finalize',{resolution_code:'INSTALL_OK',findings:{capturedOffline:!online}})">Finalize (capture evidence)</button>
                    </template>
                </template>

                <!-- Sales -->
                <template v-else>
                    <h2 style="font-size:15px">Quick capture</h2>
                    <div class="card">
                        <div class="label">Prospect name</div>
                        <input v-model="sale.name" placeholder="Full name" />
                        <div class="label">MSISDN</div>
                        <input v-model="sale.primary_msisdn" placeholder="+2547..." />
                        <div class="label">Serviceability (HomePass)</div>
                        <input :value="sale.homepass_id" placeholder="search address" @input="checkServiceability($event.target.value)" @change="sale.homepass_id=$event.target.value" />
                        <div v-for="h in serviceability" :key="h.id" class="muted" @click="sale.homepass_id=h.id">• {{ h.address }} ({{ h.id }})</div>
                        <div class="label">Package</div>
                        <input v-model="sale.package_ref" />
                        <button class="btn" @click="captureOrder">Capture order</button>
                    </div>
                    <p class="muted">Leads/orders captured offline sync automatically.</p>
                </template>
            </div>

            <div class="tabbar">
                <button :class="{active: tab==='contractor'}" @click="tab='contractor'; job=null; refresh()" v-if="can('workorder.execute') || can('workorder.read')">Jobs</button>
                <button :class="{active: tab==='sales'}" @click="tab='sales'" v-if="can('customer.create') || can('fulfillment.read')">Sales</button>
            </div>
        </template>
    </div>
</template>

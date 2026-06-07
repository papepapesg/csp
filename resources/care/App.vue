<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

// FE-APP-04 SOPHIX Care — customer self-care PWA. Token auth; reads/writes are
// scoped server-side to the authenticated customer (selfcare.access).
const api = axios.create({ baseURL: '/api' });
const token = ref(localStorage.getItem('care_token') || '');
const me = ref(null);
const view = ref('login');
const tab = ref('overview');
const error = ref(null);
const notice = ref(null);
const busy = ref(false);

const creds = ref({ email: '', password: '' });
const subscriptions = ref([]);
const invoices = ref([]);
const tickets = ref([]);
const pay = ref({ account_id: '', paid_amount: '' });
const newTicket = ref({ subject: '', description: '', priority: 'NORMAL' });

if (token.value) api.defaults.headers.common.Authorization = `Bearer ${token.value}`;

async function login() {
    busy.value = true; error.value = null;
    try {
        const { data } = await api.post('/auth/token', { ...creds.value, device: 'care' });
        token.value = data.token; localStorage.setItem('care_token', token.value);
        api.defaults.headers.common.Authorization = `Bearer ${token.value}`;
        view.value = 'home'; await load();
    } catch (e) { error.value = e.response?.data?.message ?? 'Login failed'; }
    finally { busy.value = false; }
}
function logout() { localStorage.removeItem('care_token'); token.value = ''; me.value = null; view.value = 'login'; }

async function load() {
    try {
        me.value = (await api.get('/selfcare/me')).data;
        subscriptions.value = (await api.get('/selfcare/subscriptions')).data.items ?? [];
        invoices.value = (await api.get('/selfcare/invoices')).data.items ?? [];
        tickets.value = (await api.get('/selfcare/tickets')).data.items ?? [];
        if (me.value.accounts?.length) pay.value.account_id = me.value.accounts[0].account_id;
    } catch (e) { error.value = e.response?.data?.message ?? 'Could not load your profile'; }
}

async function submitPayment() {
    error.value = null; notice.value = null;
    try {
        await api.post('/selfcare/payments', { ...pay.value, method: 'CARD' }, { headers: { 'Idempotency-Key': crypto.randomUUID() } });
        notice.value = 'Payment received. Thank you!'; pay.value.paid_amount = ''; await load();
    } catch (e) { error.value = e.response?.data?.message ?? 'Payment failed'; }
}

async function submitTicket() {
    error.value = null; notice.value = null;
    try {
        await api.post('/selfcare/tickets', { ...newTicket.value }, { headers: { 'Idempotency-Key': crypto.randomUUID() } });
        notice.value = 'Your request has been logged.'; newTicket.value = { subject: '', description: '', priority: 'NORMAL' }; await load();
    } catch (e) { error.value = e.response?.data?.message ?? 'Could not submit'; }
}

onMounted(() => { if (token.value) { view.value = 'home'; load(); } });
</script>

<template>
  <main class="wrap">
    <header class="bar"><b>SOPHIX Care</b><button v-if="view==='home'" class="link" @click="logout">Sign out</button></header>
    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="notice" class="ok">{{ notice }}</p>

    <section v-if="view==='login'" class="card">
      <h2>Sign in</h2>
      <input v-model="creds.email" placeholder="Email" autocomplete="username" />
      <input v-model="creds.password" type="password" placeholder="Password" autocomplete="current-password" />
      <button :disabled="busy" @click="login">{{ busy ? '…' : 'Sign in' }}</button>
    </section>

    <template v-else>
      <nav class="tabs">
        <button :class="{on: tab==='overview'}" @click="tab='overview'">Overview</button>
        <button :class="{on: tab==='invoices'}" @click="tab='invoices'">Invoices</button>
        <button :class="{on: tab==='support'}" @click="tab='support'">Support</button>
      </nav>

      <section v-if="tab==='overview'" class="card">
        <h2>My subscriptions</h2>
        <div v-for="s in subscriptions" :key="s.subscription_id" class="row">
          <span>{{ s.package_ref }}</span><span class="pill">{{ s.status_code }}</span>
        </div>
        <p v-if="!subscriptions.length" class="muted">No subscriptions.</p>
      </section>

      <section v-if="tab==='invoices'" class="card">
        <h2>Invoices</h2>
        <div v-for="i in invoices" :key="i.invoice_id" class="row">
          <span>{{ i.invoice_number ?? i.invoice_id }}</span>
          <span class="pill">{{ i.status }} · {{ i.amount_due }}</span>
        </div>
        <h3>Make a payment</h3>
        <select v-model="pay.account_id"><option v-for="a in me?.accounts" :key="a.account_id" :value="a.account_id">{{ a.account_id }}</option></select>
        <input v-model="pay.paid_amount" type="number" placeholder="Amount" />
        <button @click="submitPayment">Pay</button>
      </section>

      <section v-if="tab==='support'" class="card">
        <h2>My requests</h2>
        <div v-for="t in tickets" :key="t.ticket_id" class="row"><span>{{ t.subject }}</span><span class="pill">{{ t.status }}</span></div>
        <h3>Raise a request</h3>
        <input v-model="newTicket.subject" placeholder="Subject" />
        <textarea v-model="newTicket.description" placeholder="Describe the issue"></textarea>
        <select v-model="newTicket.priority"><option>LOW</option><option>NORMAL</option><option>HIGH</option><option>URGENT</option></select>
        <button @click="submitTicket">Submit</button>
      </section>
    </template>
  </main>
</template>

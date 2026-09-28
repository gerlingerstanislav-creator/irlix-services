<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { UiButton } from '@irlix/ui';
import ReportingPeriodsView from './ReportingPeriodsView.vue';
import CashFlowView from './CashFlowView.vue';

const props = defineProps({
  view: { type: String, required: true },
  reportMode: { type: String, default: 'kanban' },
});

const reportCreateOpen = ref(false);
const periods = ref([]);
const clients = ref([]);
const employees = ref([]);
const loading = ref(false);
const error = ref('');
const connectionScope = ref('active');
let observer = null;

async function api(url) {
  const response = await fetch(url, { headers: { Accept: 'application/json' } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || `HTTP ${response.status}`);
  return body;
}

async function loadReporting() {
  if (props.view !== 'reports') return;
  loading.value = true;
  error.value = '';
  try {
    const [overviewPayload, peoplePayload] = await Promise.all([
      api('/api/clients/overview'),
      api('/api/employees/employees'),
    ]);
    const data = overviewPayload.data || {};
    periods.value = data.reportingPeriods || [];
    clients.value = data.clients || [];
    employees.value = peoplePayload.data || [];
  } catch (exception) {
    error.value = exception.message || String(exception);
  } finally {
    loading.value = false;
  }
}

function findConnectionButtons() {
  return [...document.querySelectorAll('.content .irlix-segmented-item')].filter(button => {
    const text = (button.textContent || '').trim();
    return text === 'Активные подключения' || text === 'Все подключения';
  });
}

function syncConnectionScopeFromDom() {
  const buttons = findConnectionButtons();
  const active = buttons.find(button => button.classList.contains('active'));
  if ((active?.textContent || '').trim() === 'Все подключения') connectionScope.value = 'all';
  else if (buttons.length) connectionScope.value = 'active';
}

async function setConnectionScope(value) {
  connectionScope.value = value;
  await nextTick();
  const label = value === 'all' ? 'Все подключения' : 'Активные подключения';
  const button = findConnectionButtons().find(item => (item.textContent || '').trim() === label);
  button?.click();
}

function startScopeObserver() {
  stopScopeObserver();
  if (props.view !== 'clients') return;
  observer = new MutationObserver(syncConnectionScopeFromDom);
  observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] });
  nextTick(syncConnectionScopeFromDom);
}
function stopScopeObserver() {
  observer?.disconnect();
  observer = null;
}

watch(() => props.view, async view => {
  if (view === 'reports') await loadReporting();
  if (view === 'clients') startScopeObserver(); else stopScopeObserver();
}, { immediate: true });

onBeforeUnmount(stopScopeObserver);
</script>

<template>
  <div v-if="view==='clients'" class="connection-scope-select">
    <select :value="connectionScope" aria-label="Подключения" @change="setConnectionScope($event.target.value)">
      <option value="active">Активные подключения</option>
      <option value="all">Все подключения</option>
    </select>
  </div>

  <Teleport to="body">
    <section v-if="view==='reports'" class="clients-page-overlay clients-page-overlay--reports irlix-ui">
      <div class="overlay-page-head">
        <div><strong>Отчётные периоды</strong><span v-if="loading">Обновление…</span></div>
        <UiButton @click="reportCreateOpen=true">＋ Новый отчётный период</UiButton>
      </div>
      <div v-if="error" class="overlay-error">{{error}} <button type="button" @click="loadReporting">Повторить</button></div>
      <ReportingPeriodsView
        v-else
        :periods="periods"
        :clients="clients"
        :employees="employees"
        :mode="reportMode"
        :create-open="reportCreateOpen"
        @update:create-open="reportCreateOpen=$event"
        @changed="loadReporting"
      />
    </section>

    <section v-if="view==='cashflow'" class="clients-page-overlay clients-page-overlay--cashflow irlix-ui">
      <CashFlowView />
    </section>
  </Teleport>
</template>

<style>
.connection-scope-select select{height:34px;min-width:190px;padding:0 30px 0 10px;border:1px solid #d8dde3;border-radius:7px;background:#fff;color:#28313a;font:inherit;font-size:12px;cursor:pointer}
body:has(.connection-scope-select) .content .irlix-segmented{display:none!important}
.clients-page-overlay{position:fixed;z-index:32;left:60px;right:0;top:58px;bottom:0;overflow:auto;background:#fff}
.clients-page-overlay--reports{padding-top:0}
.overlay-page-head{position:sticky;z-index:3;top:0;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 16px;border-bottom:1px solid #edf0f2;background:rgba(255,255,255,.96);backdrop-filter:blur(5px)}
.overlay-page-head>div{display:flex;align-items:center;gap:10px}.overlay-page-head strong{font-size:14px}.overlay-page-head span{font-size:11px;color:#7a838d}.overlay-error{margin:14px 16px;padding:10px 12px;border:1px solid #efc4c4;border-radius:8px;background:#fff5f5;color:#b42318;font-size:12px}.overlay-error button{border:0;background:transparent;color:#078d6c;cursor:pointer}
body:has(.clients-page-overlay--reports) .topbar-actions{visibility:hidden}
@media(max-width:720px){.clients-page-overlay{left:0;top:96px}.connection-scope-select select{min-width:160px}}
</style>

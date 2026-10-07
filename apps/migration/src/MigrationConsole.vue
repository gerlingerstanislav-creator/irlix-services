<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { UiAppShell, UiBadge, UiButton, UiDrawer, UiIcon, UiSearchSelect, UiTreeToggle } from '@irlix/ui';
import { active, displayRun, label, messages, modeLabel, orderedModules, percent, ready, shownRuns, titles } from './migration-console-model.js';
import './migration-console.css';
import { navigateMigration, migrationNavigation } from './navigation.js';
const props = defineProps({ auth: {type:Object,required:true} });
const modules = ref([]), consoleState = ref({history:[],snapshots:{}}), details = reactive({});
const scope = ref(new URLSearchParams(location.search).get('service') || 'all');
const selectedHistory = ref(''), drawer = ref(''), connectionService = ref(''), error = ref(''), notice = ref('');
const connectionError = ref(''), connectionNotice = ref('');
const pending = ref(false), offline = ref(false), initial = ref(true), refreshed = ref('');
const form = reactive({host:'',port:5432,database:'',username:'',password:'',sslmode:'disable',readonly_acknowledged:false});
const rollbackId = ref(''), confirmation = ref(''), collapsed = reactive({}), filter = ref('all'), tableFilter = ref(null);
const autoScroll = ref(true), logBody = ref(null);
let timer, stopped = false, polling = false;
const sorted = computed(() => orderedModules(modules.value));
const implemented = computed(() => sorted.value.filter(m => m.status === 'implemented'));
const planned = computed(() => sorted.value.filter(m => m.status !== 'implemented'));
const selected = computed(() => modules.value.find(m => m.key === scope.value));
const current = computed(() => consoleState.value.operation);
const operation = computed(() => {
  if (selectedHistory.value) return consoleState.value.history.find(h => h.id === selectedHistory.value);
  const op = current.value;
  const lastBatchRun = Math.max(0, ...(op?.runs || []).map(r => Number(r.id)));
  if (op?.action === 'migrate' && !active(op) && modules.value.some(m => Number(m.latest_run?.id) > lastBatchRun)) return null;
  return op;
});
const busy = computed(() => pending.value || offline.value || active(current.value) || modules.value.some(m => active(m.active_run)));
const visible = computed(() => scope.value === 'all' ? implemented.value : selected.value ? [selected.value] : []);
const rows = computed(() => visible.value.map(m => ({module:m, runs:shownRuns(m, operation.value, details), run:displayRun(shownRuns(m,operation.value,details))})));
const canStart = computed(() => !initial.value && !busy.value && visible.value.length > 0 && visible.value.every(ready));
const snapshotOptions = computed(() => (consoleState.value.snapshots?.[scope.value] || []).filter(s => !s.restored).map(s => ({value:s.id,label:`${date(s.created_at)} · #${s.id}`})));
const allMessages = computed(() => rows.value.flatMap(r => messages(r.module,r.runs)).sort((a,b) => String(a.created_at).localeCompare(String(b.created_at)) || a.id-b.id));
const filteredMessages = computed(() => allMessages.value.filter(m => (filter.value === 'all' || m.kind === filter.value) && (!tableFilter.value || (m.service === tableFilter.value.service && (!tableFilter.value.table || m.table === tableFilter.value.table)))));
const totals = computed(() => rows.value.reduce((acc,r) => { for (const k of Object.keys(acc)) acc[k] += Number(r.run?.[k] || 0); return acc; },{processed_count:0,success_count:0,conflict_count:0,warning_count:0}));
function date(value) { if (!value) return '—'; return new Date(typeof value === 'number' ? value*1000 : String(value).replace(' ','T') + (/Z$|\+\d\d:\d\d$/.test(value) ? '' : 'Z')).toLocaleString('ru-RU'); }
function tone(status) { return ['completed','checked'].includes(status) ? 'success' : ['failed','interrupted'].includes(status) ? 'danger' : ['conflicts','partial'].includes(status) ? 'warning' : ['queued','running','processing','read'].includes(status) ? 'info' : 'neutral'; }
function selectScope(value) {
  scope.value=value; tableFilter.value=null;
  const url = new URL(location.href); value==='all' ? url.searchParams.delete('service') : url.searchParams.set('service',value);
  history.replaceState({},'',url);
}
async function api(path, options={}) {
  const response = await props.auth.fetch(`/api/migration${path}`, {...options,cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json'}});
  let payload; try { payload=await response.json(); } catch { throw new Error(`Нет ответа от Migration API (${response.status})`); }
  if (!response.ok) throw new Error(payload.message || `Migration API: ${response.status}`);
  return payload.data ?? payload;
}
async function refresh() {
  if (polling || stopped) return;
  polling=true;
  try {
    const [state, control] = await Promise.all([api('/state'),api('/console/state')]);
    modules.value=state.modules; consoleState.value=control;
    if (!['all',...state.modules.map(m=>m.key)].includes(scope.value)) selectScope('all');
    const refs = new Set([...rows.value.flatMap(r=>r.runs.map(run=>run.id)),...state.modules.map(m=>m.active_run?.id).filter(Boolean)]);
    await Promise.all([...refs].map(async id => {
      if (details[id]?.fetchError || active(details[id]) || !details[id] || active(state.modules.find(m=>m.active_run?.id===id)?.active_run)) {
        try { details[id]=await api(`/console/runs/${id}`); }
        catch(e) { details[id]={id,fetchError:true,status:'failed',mode:'migrate',error:e.message,events:[{id:0,level:'error',message:e.message,created_at:new Date().toISOString()}],conflicts:[]}; }
      }
    }));
    if (offline.value) error.value='';
    offline.value=false; initial.value=false; refreshed.value=new Date().toLocaleTimeString('ru-RU');
    await nextTick(); if(autoScroll.value && logBody.value) logBody.value.scrollTop=logBody.value.scrollHeight;
  } catch (e) { offline.value=true; error.value=e.message; }
  finally { polling=false; if(!stopped) { clearTimeout(timer); timer=setTimeout(refresh,active(current.value) || busy.value ? 1000:5000); } }
}
async function action(fn, channel = 'page') {
  const actionError = channel === 'connection' ? connectionError : error;
  const actionNotice = channel === 'connection' ? connectionNotice : notice;
  if(pending.value) return;
  pending.value=true; actionError.value='';actionNotice.value='';
  try { await fn(); } catch(e) { actionError.value=e.message; }
  finally { pending.value=false; await refresh(); }
}
function openConnection(service=scope.value) {
  if(service==='all') return;
  connectionService.value=service;
  connectionError.value=''; connectionNotice.value='';
  const c=modules.value.find(m=>m.key===service)?.connection || {};
  Object.assign(form,{host:c.host||'',port:c.port||5432,database:c.database||'',username:c.username||'',password:'',sslmode:c.sslmode||'disable',readonly_acknowledged:!!c.readonly_acknowledged});
  drawer.value='connection';
}
async function connectionAction(kind) {
  if(busy.value) return;
  await action(async()=>{
    const service=connectionService.value;
    if(kind==='save') { await api(`/services/${service}/connection`,{method:'PUT',body:JSON.stringify(form)}); form.password='';connectionNotice.value='Доступ сохранён. Проверьте подключение.'; }
    if(kind==='verify') { await api(`/services/${service}/verify`,{method:'POST',body:'{}'}); connectionNotice.value='Read-only подключение проверено.'; }
    if(kind==='reachability') { await api(`/services/${service}/reachability`,{method:'POST',body:JSON.stringify({host:form.host,port:Number(form.port)})});connectionNotice.value='Сервер доступен по TCP.'; }
    if(kind==='delete') { await api(`/services/${service}/connection`,{method:'DELETE'});Object.assign(form,{host:'',database:'',username:'',password:'',readonly_acknowledged:false});connectionNotice.value='Сохранённый доступ удалён.'; }
  }, 'connection');
}
async function prepare(mode) {
  if(busy.value) return;
  await action(async()=>{await api(`/services/${connectionService.value}/runs`,{method:'POST',body:JSON.stringify({mode})}); drawer.value='';}, 'connection');
}
function openRollback() { rollbackId.value=snapshotOptions.value[0]?.value||'';confirmation.value='';drawer.value='rollback'; }
async function transfer() {
  await action(async()=>{const result=await api('/console/start',{method:'POST',body:JSON.stringify({scope:scope.value,confirm:true})});consoleState.value.operation=result.operation;selectedHistory.value='';drawer.value='';});
}
async function restore() {
  await action(async()=>{const result=await api('/console/restore',{method:'POST',body:JSON.stringify({scope:scope.value,snapshot_id:rollbackId.value,confirmation:confirmation.value})});consoleState.value.operation=result.operation;selectedHistory.value='';drawer.value='';for(const id of Object.keys(details)) delete details[id];});
}
async function loadOlder(run) {
  await action(async()=>{
    const page=await api(`/console/runs/${run.id}/conflicts?before=${run.conflict_cursor}`);
    run.conflicts=[...run.conflicts,...page.items]; run.conflict_cursor=page.cursor; run.conflicts_more=page.more;
  });
}
async function chooseHistory(item) { selectedHistory.value=item.id; drawer.value=''; await refresh(); }
function focusMessages(service,table,kind) {tableFilter.value={service,table};filter.value=kind;}
onMounted(refresh);
onBeforeUnmount(()=>{stopped=true;clearTimeout(timer);});
</script>

<template>
  <UiAppShell class="mc-root" service="migration" :current-user="auth.user" :platform-admin="true" :breadcrumbs="[{label:'Пульт переноса',href:'/migration/console/'},{label:titles[scope]||selected?.title||scope}]" :items="migrationNavigation" section="console" @update:section="navigateMigration" @logout="auth.logout()">
    <main class="mc-layout">
      <nav class="mc-queue" aria-label="Очередь переноса">
        <div class="mc-queue-title">Очередь переноса</div>
        <button class="mc-service" :class="{selected:scope==='all'}" :aria-current="scope==='all'?'page':undefined" @click="selectScope('all')"><UiIcon name="database"/><span><strong>Все сервисы</strong><small>Доступ проверен: {{ implemented.filter(ready).length }} из {{ implemented.length }}</small></span></button>
        <button v-for="(m,i) in implemented" :key="m.key" class="mc-service" :class="{selected:scope===m.key}" :aria-current="scope===m.key?'page':undefined" @click="selectScope(m.key)">
          <span class="mc-order">{{String(i+1).padStart(2,'0')}}</span><span><strong>{{titles[m.key]||m.title}}</strong><small>{{ready(m)?'Read-only проверен':m.connection?'Нужна проверка':'Доступ не настроен'}}</small><small v-if="active(current) && current.current_service===m.key">{{modeLabel(current.phase)}} · {{label(current.status)}}</small><small v-else-if="m.latest_run">{{modeLabel(m.latest_run.mode)}} · {{label(m.latest_run.status)}}</small></span><span class="mc-dot" :class="{ready:ready(m)}" :title="ready(m)?'Доступ проверен':'Доступ не готов'"/>
        </button>
        <div v-if="planned.length" class="mc-queue-title">Следующие модули</div>
        <button v-for="m in planned" :key="m.key" class="mc-service planned" :class="{selected:scope===m.key}" @click="selectScope(m.key)"><UiIcon name="database"/><span><strong>{{titles[m.key]||m.title}}</strong><small>Модуль не реализован</small></span></button>
      </nav>
      <section class="mc-main">
        <div v-if="error || offline" class="mc-alert error" role="alert">{{offline?'Связь с сервисом потеряна. Статус операции не подтверждён; повторяем проверку. ':''}}{{error}}<UiButton v-if="!offline" variant="ghost" compact @click="error=''">Скрыть</UiButton></div>
        <div v-if="notice" class="mc-alert" role="status">{{notice}}</div>
        <div v-if="initial" class="mc-empty">Загружаем состояние переноса…</div>
        <div v-else-if="selected?.status==='planned'" class="mc-empty">{{selected.description}}<p>Перенос и откат станут доступны после реализации модуля.</p></div>
        <template v-else>
          <section class="mc-progress" aria-live="polite">
            <div class="mc-progress-head"><div class="mc-actions"><UiButton :disabled="!canStart" @click="drawer='start'">{{scope==='all'?'Перенести все':'Перенести'}}</UiButton><UiButton variant="secondary" :disabled="busy || !snapshotOptions.length" @click="openRollback">Откатить</UiButton><UiButton v-if="scope!=='all'" variant="secondary" :disabled="selected?.status!=='implemented'" @click="openConnection()">Доступ к БД</UiButton><UiButton variant="secondary" @click="drawer='history'">История</UiButton></div><span v-if="operation" class="mc-operation">{{selectedHistory?'История · ':''}}Операция #{{operation.id}}</span><span class="mc-live" role="status">{{ offline ? 'Связь потеряна · повторяем проверку' : `Обновлено ${refreshed}` }}</span></div>
            <div class="mc-steps"><span :class="{current:operation?.phase==='snapshot'}">1 · Точка отката</span><span :class="{current:['inspect','dry-run'].includes(operation?.phase)}">2 · Подготовка</span><span :class="{current:operation?.phase==='migrate'}">3 · Перенос</span><span :class="{current:operation?.phase==='validate'}">4 · Проверка</span><UiBadge v-if="operation" :tone="tone(operation.status)">{{label(operation.status)}}</UiBadge></div>
            <div v-if="operation" class="mc-stage">{{titles[operation.current_service]}} · {{operation.message}}</div><div v-else class="mc-stage">Проверьте доступ к БД. При запуске автоматически создаётся новая точка отката, затем выполняются Inspect, Dry run, перенос и проверка.</div>
            <div v-if="scope==='all'" class="mc-scope-ready"><span v-for="m in implemented" :key="m.key">{{titles[m.key]}} <UiBadge :tone="ready(m)?'success':'warning'">{{ready(m)?'Доступ готов':'Настройте доступ'}}</UiBadge><UiButton compact variant="ghost" @click="openConnection(m.key)">Настроить</UiButton></span></div>
          </section>
          <div class="mc-content">
          <section class="mc-tables">
            <div class="mc-section-title">Таблицы переноса <small>Счётчики относятся к выбранному запуску. Чтение источника не считается успешным переносом.</small></div>
            <div class="mc-table-scroll"><table class="irlix-data-table mc-table"><thead><tr><th>Сервис / таблица</th><th>Состояние</th><th>Обработано / всего</th><th>Успешно</th><th>Ошибки</th><th>Предупр.</th></tr></thead><tbody>
              <template v-for="row in rows" :key="row.module.key">
                <tr class="irlix-table-group"><th><div class="mc-group-label"><UiTreeToggle :expanded="!collapsed[row.module.key]" :label="`Таблицы ${titles[row.module.key]}`" @click="collapsed[row.module.key]=!collapsed[row.module.key]"/>{{titles[row.module.key]}}</div></th><td><UiBadge :tone="tone(row.run?.status)">{{label(row.run?.status)}}</UiBadge></td><td>{{row.run?.processed_count??'—'}}</td><td>{{row.run?.success_count??'—'}}</td><td><UiButton variant="ghost" compact @click="tableFilter={service:row.module.key,table:''}; filter='error'">{{row.run?.conflict_count??0}}</UiButton></td><td><UiButton variant="ghost" compact @click="focusMessages(row.module.key,'','warning')">{{row.run?.warning_count??0}}</UiButton></td></tr>
                <template v-if="!collapsed[row.module.key]">
                  <tr v-for="t in row.run?.tables||[]" :key="t.table_name" :class="{highlight:tableFilter?.service===row.module.key&&tableFilter?.table===t.table_name}"><td class="mc-table-name">{{t.table_name}}</td><td><div class="mc-table-state"><UiBadge :tone="tone(t.state)">{{label(t.state)}}</UiBadge><progress v-if="row.run.mode==='migrate' && ['processing','completed','conflicts','partial'].includes(t.state) && percent(t)!==null" :value="percent(t)" max="100" :aria-label="`${t.table_name}: ${percent(t)}%`"/></div></td><td>{{t.processed_count??0}} / {{t.total??'—'}}<small v-if="t.read_count">Прочитано: {{t.read_count}}</small></td><td>{{t.success_count}}</td><td><UiButton variant="ghost" compact @click="focusMessages(row.module.key,t.table_name,'error')"><span :class="{danger:Number(t.error_count)}">{{t.error_count}}</span></UiButton></td><td><UiButton variant="ghost" compact @click="focusMessages(row.module.key,t.table_name,'warning')"><span :class="{warning:Number(t.warning_count)}">{{t.warning_count}}</span></UiButton></td></tr>
                  <tr v-if="!row.run?.tables?.length"><td colspan="6" class="mc-empty">{{row.run ? 'Ждём отчёт по таблицам. Для старых запусков детализация может отсутствовать.' : 'Перенос ещё не запущен.'}}</td></tr>
                </template>
              </template>
            </tbody></table></div>
            <footer class="mc-totals"><span>Обработано <b>{{totals.processed_count}}</b></span><span>Успешно <b>{{totals.success_count}}</b></span><span>Ошибки <b class="danger">{{totals.conflict_count}}</b></span><span>Предупреждения <b class="warning">{{totals.warning_count}}</b></span></footer>
          </section>
          <section class="mc-log"><header class="mc-log-head"><strong>Журнал переноса</strong><div class="mc-log-filters" role="group" aria-label="Фильтр журнала"><UiButton v-for="item in [{value:'all',label:'Все'},{value:'error',label:'Ошибки'},{value:'warning',label:'Предупреждения'}]" :key="item.value" compact :variant="filter===item.value?'secondary':'ghost'" :aria-pressed="filter===item.value" @click="filter=item.value">{{item.label}}</UiButton></div><label><input v-model="autoScroll" type="checkbox"/> Автопрокрутка</label></header><div v-if="tableFilter" class="mc-log-filter">{{titles[tableFilter.service]}} / {{tableFilter.table}} <UiButton compact variant="ghost" @click="tableFilter=null">Сбросить</UiButton></div><div ref="logBody" class="mc-log-scroll"><table class="irlix-data-table"><thead><tr><th>Время</th><th>Сервис / таблица</th><th>Сообщение</th></tr></thead><tbody><tr v-for="msg in filteredMessages" :key="msg.key" :class="msg.kind"><td>{{date(msg.created_at)}}</td><td>{{titles[msg.service]}}<small>{{msg.table||modeLabel(msg.mode)}} · #{{msg.run_id}}</small></td><td>{{msg.message}}<small v-if="msg.code">{{msg.code}} · legacy ID: {{msg.legacy_id||'—'}}</small></td></tr><tr v-if="!filteredMessages.length"><td colspan="3" class="mc-empty">Сообщений пока нет.</td></tr></tbody></table><div v-for="row in rows" :key="row.module.key"><div v-for="run in row.runs.filter(r=>r.conflicts_more)" :key="run.id" class="mc-log-more">Загружено {{run.conflicts?.length}} сообщений запуска #{{run.id}}. <UiButton compact variant="ghost" @click="loadOlder(run)">Загрузить ещё</UiButton></div></div></div></section>
          </div>
        </template>
      </section>
    </main>
    <UiDrawer :open="drawer==='connection'" :title="`Подключение · ${titles[connectionService]}`" width="420px" @close="drawer=''"><div class="irlix-ui">
      <div v-if="connectionError" class="mc-alert error" role="alert">{{connectionError}}</div><div v-if="connectionNotice" class="mc-alert" role="status">{{connectionNotice}}</div>
      <UiBadge :tone="ready(modules.find(m=>m.key===connectionService))?'success':'warning'">{{ready(modules.find(m=>m.key===connectionService))?'Read-only проверен':'Нужна проверка'}}</UiBadge>
      <form class="mc-form" @submit.prevent="connectionAction('save')"><fieldset :disabled="busy"><label class="irlix-field"><span>Хост</span><input v-model="form.host" autocomplete="off" required/></label><div class="mc-form-pair"><label class="irlix-field"><span>Порт</span><input v-model.number="form.port" type="number" min="1" max="65535" required/></label><label class="irlix-field"><span>База данных</span><input v-model="form.database" required/></label></div><label class="irlix-field"><span>Пользователь</span><input v-model="form.username" autocomplete="off" required/></label><label class="irlix-field"><span>Пароль</span><input v-model="form.password" type="password" autocomplete="new-password" placeholder="Пусто — сохранить текущий пароль"/></label><label class="irlix-field"><span>SSL</span><select v-model="form.sslmode"><option v-for="s in ['disable','allow','prefer','require','verify-ca','verify-full']" :key="s">{{s}}</option></select></label><label><input v-model="form.readonly_acknowledged" type="checkbox"/> Учётная запись только для чтения</label><div class="mc-actions"><UiButton type="submit" :disabled="busy">Сохранить</UiButton><UiButton type="button" variant="secondary" :disabled="busy" @click="connectionAction('verify')">Проверить подключение</UiButton><UiButton type="button" variant="ghost" :disabled="busy" @click="connectionAction('reachability')">Проверить сервер</UiButton></div></fieldset></form>
      <p class="mc-help">Полная проверка использует сохранённые параметры. Сначала сохраните изменения. Пароль не возвращается в браузер.</p><p v-if="busy" class="mc-help">Настройки заблокированы на время операции.</p><div class="mc-actions"><UiButton variant="secondary" :disabled="busy||!ready(modules.find(m=>m.key===connectionService))" @click="prepare('inspect')">Inspect</UiButton><UiButton variant="secondary" :disabled="busy||!ready(modules.find(m=>m.key===connectionService))" @click="prepare('dry-run')">Dry run</UiButton><UiButton variant="danger" :disabled="busy" @click="drawer='delete-connection'">Удалить доступ</UiButton></div>
    </div></UiDrawer>
    <UiDrawer :open="drawer==='start'" title="Запуск переноса" width="440px" @close="drawer=''"><div class="irlix-ui">
      <p>Перенести данные: <strong>{{titles[scope]}}</strong>?</p><p>Будет создана и проверена новая точка отката. Затем последовательно выполняются Inspect, Dry run, перенос и проверка результата.</p><p v-if="scope==='all'">Очередь: {{implemented.map(m=>titles[m.key]).join(' → ')}}. Нереализованные модули в запуск не входят.</p><p class="mc-help">Ошибка останавливает очередь. Старые базы остаются доступны только для чтения.</p><div v-if="error" class="mc-alert error">{{error}}</div><div class="mc-actions"><UiButton :disabled="!canStart" @click="transfer">Создать точку и перенести</UiButton><UiButton variant="secondary" @click="drawer=''">Отмена</UiButton></div>
    </div></UiDrawer>
    <UiDrawer :open="drawer==='rollback'" :title="`Откат · ${titles[scope]}`" width="440px" @close="drawer=''"><div class="irlix-ui">
      <label class="irlix-field"><span>Точка отката</span><UiSearchSelect v-model="rollbackId" :options="snapshotOptions" :disabled="busy" :clearable="false"/></label><p>Будет восстановлена {{scope==='all'?'единая точка Сотрудников и Отпусков':'схема выбранного сервиса'}} и служебная информация миграции.</p><p class="mc-help">Откат отдельного сервиса блокируется, если после точки переносился другой сервис. Для отката всей очереди выбирайте «Все сервисы».</p><label class="irlix-field"><span>Введите RESTORE {{scope.toUpperCase()}}</span><input v-model="confirmation" :disabled="busy" autocomplete="off"/></label><div v-if="error" class="mc-alert error">{{error}}</div><div class="mc-actions"><UiButton variant="danger" :disabled="busy||!rollbackId||confirmation!==`RESTORE ${scope.toUpperCase()}`" @click="restore">Откатить</UiButton><UiButton variant="secondary" @click="drawer=''">Отмена</UiButton></div>
    </div></UiDrawer>
    <UiDrawer :open="drawer==='history'" title="История запусков пульта" width="560px" @close="drawer=''"><div class="irlix-ui">
      <UiButton variant="ghost" @click="selectedHistory='';drawer='';refresh()">Текущая операция</UiButton><div v-for="h in consoleState.history" :key="h.id" class="mc-history"><div><strong>{{titles[h.scope]}} · {{h.action==='restore'?'Откат':'Перенос'}}</strong><small>{{date(h.started_at)}} · #{{h.id}}</small><small>{{h.message}}</small></div><UiBadge :tone="tone(h.status)">{{label(h.status)}}</UiBadge><UiButton variant="secondary" compact @click="chooseHistory(h)">Открыть</UiButton></div><p v-if="!consoleState.history.length">Запусков пока нет.</p>
    </div></UiDrawer>
    <UiDrawer :open="drawer==='delete-connection'" title="Удаление доступа" width="420px" @close="drawer=''"><div class="irlix-ui">
      <p>Удалить сохранённый доступ к БД {{titles[connectionService]}}?</p><div class="mc-actions"><UiButton variant="danger" :disabled="busy" @click="connectionAction('delete');drawer='connection'">Удалить</UiButton><UiButton variant="secondary" @click="drawer='connection'">Отмена</UiButton></div>
    </div></UiDrawer>
  </UiAppShell>
</template>

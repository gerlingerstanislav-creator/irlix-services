<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { UiAppShell, UiBadge, UiButton, UiDrawer, UiIcon, UiSearchSelect, UiTabs, UiTreeToggle } from '@irlix/ui';
import { operationDiagnostics, restoreStage, databaseOutcome, restoreMaintenance, active, displayRun, historyForScope, label, messages, modeLabel, orderedModules, percent, ready, shownRuns, tableStatus, titles } from './migration-console-model.js';
import './migration-console.css';
import backupIcon from './assets/backup.svg?no-inline';
import { navigateMigration, migrationNavigation } from './navigation.js';
const props = defineProps({ auth: {type:Object,required:true} });
const modules = ref([]), consoleState = ref({history:[],snapshots:{}}), details = reactive({});
const scope = ref(new URLSearchParams(location.search).get('service') || 'all');
const selectedHistory = ref(''), drawer = ref(''), connectionService = ref(''), error = ref(''), notice = ref('');
const connectionError = ref(''), connectionNotice = ref('');
const pending = ref(false), offline = ref(false), initial = ref(true), offlineStatus = ref(null);
const form = reactive({host:'',port:5432,database:'',username:'',password:'',sslmode:'disable',readonly_acknowledged:false});
const rollbackId = ref(''), confirmation = ref(''), collapsed = reactive({}), filter = ref('all'), tableFilter = ref(null);
const rightPanel = ref('summary');
const autoScroll = ref(true), logBody = ref(null), contentBody = ref(null), split = ref(50);
const backupScope = ref('all'), backupTarget = ref(null), backupError = ref(''), backupNotice = ref('');
const legacyBackups = reactive({}), backupsLoading = ref(false);
const backupOperationId = ref('');
const conflictDetails = ref(null), conflictError = ref(''), conflictLoading = ref(false), conflictId = ref(null);
function hasEmployeeDetails(msg) { return msg.conflict_id && msg.service==='employees' && msg.entity_type==='employment'; }
async function openEmployeeDetails(msg) {
  conflictId.value=msg.conflict_id; conflictDetails.value=null; conflictError.value=''; conflictLoading.value=true; drawer.value='employee-details';
  const id=msg.conflict_id;
  try { const result=await api(`/console/conflicts/${id}/details`); if(conflictId.value===id) conflictDetails.value=result; }
  catch(e) { if(conflictId.value===id) conflictError.value=e.message; }
  finally { if(conflictId.value===id) conflictLoading.value=false; }
}
const userMapping = reactive({version:0,conflict:null,login:'',employee:null,pending:false,error:'',notice:''});
function canMapUser(msg) {return !selectedHistory.value && ['vacations','clients'].includes(msg.service) && msg.entity_type==='users' && msg.kind==='error' && !!msg.context?.source?.email;}
function openUserMapping(msg) {userMapping.version++;Object.assign(userMapping,{conflict:msg,login:'',employee:null,pending:false,error:'',notice:''});drawer.value='user-mapping';}
async function checkUserMapping() {
  if(userMapping.pending) return;
  const id=userMapping.conflict.conflict_id, login=userMapping.login, version=userMapping.version;
  userMapping.pending=true;userMapping.error='';userMapping.employee=null;userMapping.notice='';
  try {const employee=await api(`/console/conflicts/${id}/employee-match?login=${encodeURIComponent(login)}`);if(userMapping.version===version && userMapping.conflict?.conflict_id===id && userMapping.login===login) {userMapping.employee=employee;userMapping.conflict={...userMapping.conflict,legacy_id:String(employee.source.id),context:{source:employee.source}};}}
  catch(e) {if(userMapping.version===version)userMapping.error=e.message;}
  finally {if(userMapping.version===version)userMapping.pending=false;}
}
async function saveUserMapping() {
  if(userMapping.pending || busy.value || !userMapping.employee) return;
  const version=userMapping.version;
  userMapping.pending=true;userMapping.error='';
  try {const result=await api(`/console/conflicts/${userMapping.conflict.conflict_id}/employee-map`,{method:'POST',body:JSON.stringify({login:userMapping.login,employee_id:userMapping.employee.id,source_fingerprint:userMapping.employee.source_fingerprint,confirmation:'MAP USER '+userMapping.conflict.legacy_id})});if(userMapping.version===version){userMapping.notice=result.message;userMapping.employee=null;}}
  catch(e) {if(userMapping.version===version)userMapping.error=e.message;}
  finally {if(userMapping.version===version)userMapping.pending=false;}
}
let resizeCleanup;
const backupItems = computed(() => [
  ...(consoleState.value.snapshots?.[backupScope.value] || []).map(item => ({...item, source:'console'})),
  ...(legacyBackups[backupScope.value]?.snapshots || []).map(item => ({...item, source:'legacy', scope:backupScope.value})),
].sort((a,b) => String(b.created_at).localeCompare(String(a.created_at))));
const snapshotBusy = computed(() => ['queued','running'].includes(consoleState.value.snapshot_operation?.state));
const splitStyle = computed(() => ({'--mc-left':`${split.value}fr`,'--mc-right':`${100-split.value}fr`}));
function setSplit(value) { split.value=Math.max(20,Math.min(80,value)); }
function startResize(event) {
  if(event.button!==0 || window.innerWidth<=720 || !contentBody.value) return;
  event.preventDefault(); resizeCleanup?.();
  const element=event.currentTarget;
  element.setPointerCapture(event.pointerId);
  const move=e=>{const rect=contentBody.value.getBoundingClientRect();setSplit((e.clientX-rect.left)/rect.width*100);};
  const end=()=>{element.removeEventListener('pointermove',move);element.removeEventListener('pointerup',end);element.removeEventListener('lostpointercapture',end);if(element.hasPointerCapture(event.pointerId))element.releasePointerCapture(event.pointerId);contentBody.value?.classList.remove('mc-resizing');resizeCleanup=null;};
  element.addEventListener('pointermove',move);element.addEventListener('pointerup',end);element.addEventListener('lostpointercapture',end);
  contentBody.value.classList.add('mc-resizing');resizeCleanup=end;
}
function resizeKey(event) {
  const values={ArrowLeft:split.value-2,ArrowRight:split.value+2,Home:20,End:80};
  if(event.key in values) {event.preventDefault();setSplit(values[event.key]);}
}
async function refreshBackups(service=backupScope.value) {
  if(!['employees','vacations'].includes(service)) return;
  try {legacyBackups[service]=await api(service==='employees'?'/snapshots':`/${service}/snapshots`);}
  catch(e) {backupError.value=e.message;}
}
async function openBackups(service=scope.value) {
  backupScope.value=service; backupError.value='';backupNotice.value='';drawer.value='backups';
  backupsLoading.value=true; await refreshBackups(service);backupsLoading.value=false;
}
function chooseBackup(item, action) {
  backupTarget.value={...item};confirmation.value='';backupError.value='';backupNotice.value='';drawer.value=action+'-backup';
}
async function createBackup() {
  if(busy.value || backupsLoading.value) return;
  await action(async()=>{
    const result=await api('/console/snapshot',{method:'POST',body:JSON.stringify({scope:backupScope.value})});
    consoleState.value.operation=result.operation;
    backupOperationId.value=result.operation.id;
    backupNotice.value='Создаём и проверяем точку отката…';
  }, 'backup');
}
async function backupAction(kind) {
  if(busy.value || !backupTarget.value) return;
  const item=backupTarget.value;
  await action(async()=>{
    if(kind==='delete') {
      await api(item.source==='console'?`/console/snapshots/${item.scope}/${item.id}`:`${item.scope==='employees'?'':'/'+item.scope}/snapshots/${item.id}`,{method:'DELETE'});
      backupNotice.value=`Бэкап #${item.id} удалён.`;drawer.value='backups';await refreshBackups();
    } else {
      const result=await api(item.source==='console'?'/console/restore':`${item.scope==='employees'?'':'/'+item.scope}/snapshots/${item.id}/restore`,{method:'POST',body:JSON.stringify({scope:item.scope,snapshot_id:item.id,confirmation:confirmation.value})});
      if(item.source==='console') consoleState.value.operation=result.operation;
      else consoleState.value.snapshot_operation=result.operation;
      selectedHistory.value='';drawer.value='';for(const id of Object.keys(details))delete details[id];
    }
  }, 'backup');
}
const hoveredAction = ref(''), focusedAction = ref('');
function actionVariant(key) {return [hoveredAction.value,focusedAction.value].includes(key)?'secondary':'ghost';}
let timer, stopped = false, polling = false;
const sorted = computed(() => orderedModules(modules.value));
const implemented = computed(() => sorted.value.filter(m => m.status === 'implemented'));
const planned = computed(() => sorted.value.filter(m => m.status !== 'implemented'));
const selected = computed(() => modules.value.find(m => m.key === scope.value));
const serviceHistory = computed(() => historyForScope(consoleState.value.history,scope.value));
const current = computed(() => consoleState.value.operation);
const operation = computed(() => {
  if (selectedHistory.value) return consoleState.value.history.find(h => h.id === selectedHistory.value);
  const op = current.value;
  const lastBatchRun = Math.max(0, ...(op?.runs || []).map(r => Number(r.id)));
  if (op?.action === 'migrate' && !active(op) && modules.value.some(m => Number(m.latest_run?.id) > lastBatchRun)) return null;
  return op;
});
const diagnosticReports = computed(() => operationDiagnostics(operation.value, consoleState.value.snapshot_operation, scope.value, !!selectedHistory.value));
function downloadDiagnostic(report) {
  const blob=new Blob([JSON.stringify(report,null,2)],{type:'application/json'});
  const url=URL.createObjectURL(blob), link=document.createElement('a');
  link.href=url;link.download=`migration-diagnostic-${report.diagnostic_id || report.operation_id}.json`;
  link.click();URL.revokeObjectURL(url);
}
const checkpointActive = computed(() =>
  (active(current.value) && (current.value.action==='snapshot' || current.value.phase==='snapshot' || (current.value.action==='migrate' && current.value.phase==='queued' && !current.value.runs?.length)))
  || (snapshotBusy.value && consoleState.value.snapshot_operation?.action==='snapshot'));
const checkpointPause = computed(() => offline.value && [502,503].includes(offlineStatus.value) && checkpointActive.value);
const checkpointHelp = 'Для согласованного бэкапа API временно приостанавливается. Ответы 502/503 в этот период ожидаемы; связь восстановится автоматически после запуска API.';
const checkpointPauseMessage = computed(() => `Создание точки отката: API временно недоступен (${offlineStatus.value}). Это ожидаемый этап бэкапа. Проверяем связь автоматически; окончательный результат покажем после её восстановления.`);
const busy = computed(() => pending.value || offline.value || snapshotBusy.value || active(current.value) || modules.value.some(m => active(m.active_run)));
const visible = computed(() => scope.value === 'all' ? implemented.value : selected.value ? [selected.value] : []);
const rows = computed(() => visible.value.map(m => ({module:m, runs:shownRuns(m, operation.value, details), run:displayRun(shownRuns(m,operation.value,details))})));
const failedRuns = computed(() => rows.value.flatMap(r=>r.runs.map(run=>({...run,service:r.module.key}))).filter(run=>['failed','conflicts','interrupted'].includes(run.status) || Number(run.conflict_count)>0));
const summaryStatus = computed(() => active(operation.value) || rows.value.some(row=>row.runs.some(active)) ? 'Перенос выполняется' : ['failed','interrupted'].includes(operation.value?.status) ? 'Операция не завершилась успешно' : !operation.value && !rows.value.some(row=>row.runs.length) ? 'Перенос ещё не запущен' : 'Выбранные этапы завершены без ошибок');
const failedView = computed(() => JSON.stringify([selectedHistory.value,scope.value,failedRuns.value.map(r=>r.id)]));
watch(failedView, () => {if(failedRuns.value.length) {filter.value='error';tableFilter.value=null;autoScroll.value=false;} });
const canStart = computed(() => !initial.value && !busy.value && visible.value.length > 0 && visible.value.every(ready));
const snapshotOptions = computed(() => (consoleState.value.snapshots?.[scope.value] || []).filter(s => s.compatible!==false).map(s => ({value:s.id,label:`${date(s.created_at)} · #${s.id}`})));
const allMessages = computed(() => rows.value.flatMap(r => messages(r.module,r.runs)).sort((a,b) => String(a.created_at).localeCompare(String(b.created_at)) || a.id-b.id));
const journalPages = reactive({});
let journalVersion = 0, previousJournalKey = '';
const journalFiltered = computed(() => filter.value !== 'all' || !!tableFilter.value);
const journalTargets = computed(() => rows.value.filter(r => !tableFilter.value || r.module.key === tableFilter.value.service).flatMap(r => r.runs.filter(run=>!run.fetchError).map(run => ({module:r.module,run}))));
const journalKey = computed(() => JSON.stringify([operation.value?.id,journalFiltered.value,filter.value,tableFilter.value,journalTargets.value.map(t=>[t.module.key,t.run.id])]));
const journalCounts = computed(() => JSON.stringify(journalTargets.value.map(t=>[t.run.conflict_count,t.run.warning_count])));
const journalLoading = computed(() => journalFiltered.value && journalTargets.value.some(t=>!journalPages[t.run.id] || journalPages[t.run.id].loading));
const journalErrors = computed(() => journalFiltered.value ? journalTargets.value.filter(t=>journalPages[t.run.id]?.error).map(t=>({id:t.run.id,message:journalPages[t.run.id].error})) : []);
const journalMessages = computed(() => journalFiltered.value ? journalTargets.value.flatMap(t=>messages(t.module,[{...t.run,conflicts:journalPages[t.run.id]?.items||[]}])).sort((a,b)=>String(a.created_at).localeCompare(String(b.created_at)) || a.id-b.id) : allMessages.value);
const filteredMessages = computed(() => journalMessages.value.filter(m => (filter.value === 'all' || m.kind === filter.value) && (!tableFilter.value || (m.service === tableFilter.value.service && (!tableFilter.value.table || m.table === tableFilter.value.table)))));
const totals = computed(() => rows.value.reduce((acc,r) => { for (const k of Object.keys(acc)) acc[k] += Number(r.run?.[k] || 0); return acc; },{processed_count:0,success_count:0,ready_count:0,blocked_count:0,conflict_count:0,warning_count:0}));
function periodDay(value) { return /^\d{4}-\d{2}-\d{2}$/.test(String(value||'')) ? String(value).split('-').reverse().join('.') : (value||'без окончания'); }
function date(value) { if (!value) return '—'; return new Date(typeof value === 'number' ? value*1000 : String(value).replace(' ','T') + (/Z$|\+\d\d:\d\d$/.test(value) ? '' : 'Z')).toLocaleString('ru-RU'); }
function tone(status) { return ['completed','checked','preflight_ready'].includes(status) ? 'success' : ['failed','interrupted'].includes(status) ? 'danger' : ['conflicts','partial'].includes(status) ? 'warning' : ['queued','running','processing','read'].includes(status) ? 'info' : 'neutral'; }
function selectScope(value) {
  scope.value=value; tableFilter.value=null;
  const url = new URL(location.href); value==='all' ? url.searchParams.delete('service') : url.searchParams.set('service',value);
  history.replaceState({},'',url);
}
async function api(path, options={}) {
  const response = await props.auth.fetch(`/api/migration${path}`, {...options,cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json'}});
  let payload; try { payload=await response.json(); } catch { throw Object.assign(new Error(`Нет ответа от Migration API (${response.status})`),{status:response.status}); }
  if (!response.ok) throw Object.assign(new Error(payload.message || `Migration API: ${response.status}`),{status:response.status});
  return payload.data ?? payload;
}
async function refresh() {
  if (polling || stopped) return;
  polling=true;
  try {
    const control = await api('/console/state');
    consoleState.value=control;
    if (restoreMaintenance(control)) {
      if (offline.value) error.value='';
      offline.value=false; offlineStatus.value=null; initial.value=false;
      return;
    }
    const state = await api('/state');
    modules.value=state.modules;
    if(control.operation?.id===backupOperationId.value) {
      if(control.operation.status==='completed') {backupNotice.value='Точка отката создана и проверена.';backupOperationId.value='';}
      if(control.operation.status==='failed') {backupNotice.value='';backupError.value=control.operation.message;backupOperationId.value='';}
    }
    if(drawer.value==='backups') await refreshBackups();
    if (!['all',...state.modules.map(m=>m.key)].includes(scope.value)) selectScope('all');
    const refs = new Set([...rows.value.flatMap(r=>r.runs.map(run=>run.id)),...state.modules.map(m=>m.active_run?.id).filter(Boolean)]);
    const reportOperationId=String(operation.value?.id || '');
    const reportRefs=operation.value?.runs || [];
    await Promise.all([...refs].map(async id => {
      if (details[id]?.reportOperationId !== reportOperationId || details[id]?.fetchError || active(details[id]) || !details[id] || active(state.modules.find(m=>m.active_run?.id===id)?.active_run)) {
        try { const report=await api(`/console/runs/${id}${reportOperationId ? '?operation_id='+encodeURIComponent(reportOperationId) : ''}`); if(String(operation.value?.id || '')===reportOperationId) details[id]={...report,reportOperationId}; }
        catch(e) { if(String(operation.value?.id || '')===reportOperationId) details[id]={...(reportRefs.find(r=>r.id===id)||{}),id,reportOperationId,fetchError:true,fetchStatus:e.status,status:'failed',error:e.message,events:[{id:0,level:'error',message:e.message,created_at:new Date().toISOString()}],conflicts:[]}; }
      }
    }));
    if (offline.value) error.value='';
    offline.value=false; offlineStatus.value=null; initial.value=false;
    await nextTick(); if(autoScroll.value && logBody.value) logBody.value.scrollTop=logBody.value.scrollHeight;
  } catch (e) { offline.value=true; offlineStatus.value=e.status??null; error.value=e.message; }
  finally { polling=false; if(!stopped) { clearTimeout(timer); timer=setTimeout(refresh,active(current.value) || busy.value ? 1000:5000); } }
}
async function action(fn, channel = 'page') {
  const actionError = channel === 'connection' ? connectionError : channel === 'backup' ? backupError : error;
  const actionNotice = channel === 'connection' ? connectionNotice : channel === 'backup' ? backupNotice : notice;
  if(pending.value) return;
  pending.value=true; actionError.value='';actionNotice.value='';
  try { await fn(); } catch(e) { actionError.value=e.message; }
  finally { pending.value=false; await refresh(); }
}
function openConnection(service=scope.value) {
  if(service==='all') {drawer.value='connections';return;}
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
function conflictPath(run, before) {
  const query = new URLSearchParams();
  if (operation.value) query.set('operation_id',operation.value.id);
  if (before) query.set('before',before);
  if (journalFiltered.value) {
    if (filter.value !== 'all') query.set('severity',filter.value);
    if (tableFilter.value?.table) query.set('table',tableFilter.value.table);
  }
  return `/console/runs/${run.id}/conflicts?${query}`;
}
async function refreshJournal() {
  const version=++journalVersion;
  const reset=previousJournalKey!==journalKey.value;
  previousJournalKey=journalKey.value;
  if(reset) for(const id of Object.keys(journalPages)) delete journalPages[id];
  if(!journalFiltered.value) return;
  await Promise.all(journalTargets.value.map(async ({run})=>{
    const existing=journalPages[run.id];
    if(!existing) journalPages[run.id]={items:[],cursor:null,more:false,total:null};
    const page=journalPages[run.id];
    page.loading=true;page.moreLoading=false;page.error='';
    try {
      const result=await api(conflictPath(run));
      if(stopped || version!==journalVersion) return;
      const byId=new Map([...(existing?.items||[]),...result.items].map(item=>[item.id,item]));
      page.items=[...byId.values()].sort((a,b)=>b.id-a.id);
      if(!page.loaded || page.total!==result.total) {page.cursor=result.cursor;page.more=result.more;page.loaded=true;}
      page.total=result.total;
    } catch(e) {if(version===journalVersion && !stopped) page.error=e.message;}
    finally {if(version===journalVersion && !stopped) page.loading=false;}
  }));
}
async function loadOlder(run) {
  const filtered=journalFiltered.value, version=journalVersion;
  const page=filtered ? journalPages[run.id] : run;
  if(!page || page.moreLoading || page.loading) return;
  page.moreLoading=true;
  if(filtered) page.error='';else run.journalError='';
  try {
    const result=await api(conflictPath(run,filtered?page.cursor:run.conflict_cursor));
    if(stopped || version!==journalVersion) return;
    if(filtered) {
      page.items=[...new Map([...page.items,...result.items].map(item=>[item.id,item])).values()];
      page.cursor=result.cursor;page.more=result.more;page.total=result.total;
    } else {
      run.conflicts=[...new Map([...(run.conflicts||[]),...result.items].map(item=>[item.id,item])).values()];
      run.conflict_cursor=result.cursor;run.conflicts_more=result.more;
    }
  } catch(e) {if(version===journalVersion && !stopped) {if(filtered) page.error=e.message;else run.journalError=e.message;}}
  finally {page.moreLoading=false;}
}
const journalPagination = computed(() => journalTargets.value.map(({run})=>({run,page:journalFiltered.value?journalPages[run.id]:{items:run.conflicts,more:run.conflicts_more,total:null,moreLoading:run.moreLoading,error:run.journalError}})).filter(t=>t.page));
watch([journalKey,journalCounts],refreshJournal,{immediate:true});
async function chooseHistory(item) { selectedHistory.value=item.id; if(scope.value==='all') selectScope(item.scope); else tableFilter.value=null; filter.value='all'; autoScroll.value=false; drawer.value=''; await refresh(); }
function focusMessages(service,table,kind) {rightPanel.value='journal';tableFilter.value={service,table};filter.value=kind;}
onMounted(refresh);
onBeforeUnmount(()=>{stopped=true;clearTimeout(timer);resizeCleanup?.();});
</script>

<template>
  <UiAppShell class="mc-root" service="migration" :current-user="auth.user" :platform-admin="true" :breadcrumbs="[{label:'Пульт переноса',href:'/migration/'},{label:titles[scope]||selected?.title||scope}]" :items="migrationNavigation" section="console" @update:section="navigateMigration" @logout="auth.logout()">
    <main class="mc-layout">
      <nav class="mc-queue" aria-label="Очередь переноса">
        <div class="mc-service-item mc-service-all" :class="{selected:scope==='all'}">
          <button class="mc-service" :aria-current="scope==='all'?'page':undefined" @click="selectScope('all')"><UiIcon name="database"/><span><strong>Все сервисы</strong><small>Доступ проверен: {{implemented.filter(ready).length}} из {{implemented.length}}</small></span></button>
          <div class="mc-service-settings"><UiButton compact :variant="actionVariant('settings:all')" @mouseenter="hoveredAction='settings:all'" @mouseleave="hoveredAction=''" @focus="focusedAction='settings:all'" @blur="focusedAction=''" aria-label="Настройки БД · Все сервисы" title="Настройки БД" @click="openConnection('all')"><UiIcon name="settings"/></UiButton></div>
          <div class="mc-service-backups"><UiButton compact :variant="actionVariant('backups:all')" @mouseenter="hoveredAction='backups:all'" @mouseleave="hoveredAction=''" @focus="focusedAction='backups:all'" @blur="focusedAction=''" title="Бэкапы" aria-label="Бэкапы · Все сервисы" @click="openBackups('all')"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><use :href="`${backupIcon}#backup`"/></svg></UiButton></div>
        </div>
        <div v-for="(m,i) in sorted" :key="m.key" class="mc-service-item" :class="{selected:scope===m.key,planned:m.status!=='implemented'}">
          <button class="mc-service" :aria-current="scope===m.key?'page':undefined" @click="selectScope(m.key)"><span class="mc-order">{{String(i+1).padStart(2,'0')}}</span><span><strong>{{titles[m.key]||m.title}}</strong><small>{{m.status!=='implemented'?'Модуль не реализован':ready(m)?'Read-only проверен':m.connection?'Нужна проверка':'Доступ не настроен'}}</small><small v-if="active(current)&&current.current_service===m.key">{{modeLabel(current.phase)}} · {{label(current.status)}}</small><small v-else-if="m.latest_run">{{modeLabel(m.latest_run.mode)}} · {{label(m.latest_run.status)}}</small></span></button>
          <div class="mc-service-settings"><UiButton compact :variant="actionVariant(`settings:${m.key}`)" @mouseenter="hoveredAction=`settings:${m.key}`" @mouseleave="hoveredAction=''" @focus="focusedAction=`settings:${m.key}`" @blur="focusedAction=''" :disabled="m.status!=='implemented'" :aria-label="`Настройки БД · ${titles[m.key]||m.title}`" :title="m.status==='implemented'?'Настройки БД':'Модуль не реализован'" @click="openConnection(m.key)"><UiIcon name="settings"/></UiButton></div>
          <div class="mc-service-backups"><UiButton compact :variant="actionVariant(`backups:${m.key}`)" @mouseenter="hoveredAction=`backups:${m.key}`" @mouseleave="hoveredAction=''" @focus="focusedAction=`backups:${m.key}`" @blur="focusedAction=''" :title="m.status==='implemented'?'Бэкапы':'Модуль не реализован'" :disabled="m.status!=='implemented'" :aria-label="`Бэкапы · ${titles[m.key]||m.title}`" @click="openBackups(m.key)"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><use :href="`${backupIcon}#backup`"/></svg></UiButton></div>
        </div>
      </nav>
      <section class="mc-main">
        <div v-if="checkpointPause" class="mc-alert" role="status">{{checkpointPauseMessage}}</div>
        <div v-else-if="error || offline" class="mc-alert error" role="alert">{{offline?'Связь с сервисом потеряна. Статус операции не подтверждён; повторяем проверку. ':''}}{{error}}<UiButton v-if="!offline" variant="ghost" compact @click="error=''">Скрыть</UiButton></div>
        <div v-if="notice" class="mc-alert" role="status">{{notice}}</div>
        <div v-if="initial" class="mc-empty">Загружаем состояние переноса…</div>
        <div v-else-if="selected?.status==='planned'" class="mc-empty">{{selected.description}}<p>Перенос и откат станут доступны после реализации модуля.</p></div>
        <template v-else>
          <section class="mc-progress" aria-live="polite">
            <div class="mc-progress-copy">
            <div class="mc-steps"><span :class="{current:operation?.phase==='snapshot'}">1 · Точка отката</span><span :class="{current:['inspect','dry-run'].includes(operation?.phase)}">2 · Подготовка</span><span :class="{current:operation?.phase==='migrate'}">3 · Перенос</span><span :class="{current:operation?.phase==='validate'}">4 · Проверка</span><UiBadge v-if="operation" :tone="tone(operation.status)">{{label(operation.status)}}</UiBadge></div>
            <div v-if="operation" class="mc-stage">{{titles[operation.current_service]}} · {{operation.message}}</div><div v-else class="mc-stage">Запуск: новая точка отката → Inspect → Dry run → перенос → проверка.</div>
            <div v-if="snapshotBusy||consoleState.snapshot_operation?.state==='failed'" class="mc-stage">Операция с бэкапом · {{label(consoleState.snapshot_operation.state)}} · {{consoleState.snapshot_operation.message||'Ожидаем завершения'}}</div>
            <div v-if="operation" class="mc-progress-meta"><span class="mc-operation">{{selectedHistory?'История · ':''}}Операция #{{operation.id}}</span></div>
            </div><div class="mc-actions"><UiButton :disabled="!canStart" @click="drawer='start'">{{scope==='all'?'Перенести все':'Перенести'}}</UiButton><UiButton variant="secondary" :disabled="busy || !snapshotOptions.length" @click="openRollback">Откатить</UiButton><UiButton v-if="scope!=='all'" variant="secondary" :disabled="selected?.status!=='implemented'" @click="openConnection()">Доступ к БД</UiButton><UiButton variant="secondary" @click="drawer='history'">История</UiButton></div>
          </section>

          <section v-for="report in diagnosticReports" :key="report.diagnostic_id || report.operation_id" class="mc-alert error" role="alert" aria-label="Диагностика операции">
            <strong>{{report.action==='restore'?'Ошибка отката':'Ошибка операции'}} · {{titles[report.scope]}} · #{{report.operation_id}}</strong>
            <p>Этап: {{restoreStage(report.failed_stage || report.stage)}}. Код: {{report.error_code || 'Диагностика недоступна для старой операции'}}.</p>
            <p v-if="report.reason">{{report.reason}}</p>
            <p>{{databaseOutcome(report.database_outcome)}}<template v-if="report.database_committed"> · Журнал: {{report.metadata_complete?'восстановлен':'не восстановлен'}} · Схема: {{report.schemas_upgraded?'обновлена':'обновление не подтверждено'}}</template></p>
            <p v-if="report.snapshot_id">Точка отката #{{report.snapshot_id}}</p>
            <p v-if="report.diagnostic_id">ID диагностики: <code>{{report.diagnostic_id}}</code> · {{report.log_saved?'Полный лог сохранён на сервере':'Сохранение полного лога не подтверждено'}}</p>
            <p v-else>Подробности прежнего сбоя не записаны. Новая попытка получит отдельный отчёт диагностики.</p>
            <UiButton compact variant="secondary" @click="downloadDiagnostic(report)">Скачать диагностику</UiButton>
          </section>

          <div ref="contentBody" class="mc-content" :style="splitStyle">
          <section class="mc-tables">
            <header class="mc-section-title"><strong>Таблицы переноса</strong> <small title="Счётчики относятся к выбранному запуску. Чтение источника не считается успешным переносом.">Счётчики относятся к выбранному запуску. Чтение источника не считается успешным переносом.</small></header>
            <div class="mc-table-body"><div class="mc-table-scroll"><table class="irlix-data-table mc-table"><thead><tr><th>Сервис / таблица</th><th>Состояние</th><th>Обработано / всего</th><th>Успешно / Ошибки / Предупр.</th></tr></thead><tbody>
              <template v-for="row in rows" :key="row.module.key">
                <tr class="irlix-table-group"><th><div class="mc-group-label"><UiTreeToggle :expanded="!collapsed[row.module.key]" :label="`Таблицы ${titles[row.module.key]}`" @click="collapsed[row.module.key]=!collapsed[row.module.key]"/>{{titles[row.module.key]}}</div></th><td><UiBadge :tone="tone(row.run?.status)">{{label(row.run?.status)}}</UiBadge></td><td>{{row.run?.processed_count??'—'}}<small v-if="row.run?.mode==='dry-run'">Готово: {{row.run?.ready_count??0}}</small><small v-if="row.run?.blocked_count">Зависимости: {{row.run.blocked_count}}</small></td><td><div class="mc-counts"><span>{{row.run?.success_count??'—'}}</span><span>/</span><UiButton variant="ghost" compact :aria-label="`Ошибки · ${titles[row.module.key]}`" @click="focusMessages(row.module.key,'','error')">{{row.run?.conflict_count??0}}</UiButton><span>/</span><UiButton variant="ghost" compact :aria-label="`Предупреждения · ${titles[row.module.key]}`" @click="focusMessages(row.module.key,'','warning')">{{row.run?.warning_count??0}}</UiButton></div></td></tr>
                <template v-if="!collapsed[row.module.key]">
                  <tr v-for="t in row.run?.tables||[]" :key="t.table_name" :class="{highlight:tableFilter?.service===row.module.key&&tableFilter?.table===t.table_name}"><td class="mc-table-name">{{t.table_name}}</td><td><div class="mc-table-state"><UiBadge :tone="tone(tableStatus(t))">{{label(tableStatus(t))}}</UiBadge><progress v-if="row.run.mode==='migrate' && ['processing','completed','conflicts','partial'].includes(t.state) && percent(t)!==null" :value="percent(t)" max="100" :aria-label="`${t.table_name}: ${percent(t)}%`"/></div></td><td>{{t.processed_count??0}} / {{t.total??'—'}}<small v-if="t.read_count">Прочитано: {{t.read_count}}</small><small v-if="row.run.mode==='dry-run'">Готово: {{t.ready_count??0}}</small><small v-if="t.blocked_count">Зависимости: {{t.blocked_count}}</small></td><td><div class="mc-counts"><span>{{t.success_count??0}}</span><span>/</span><UiButton variant="ghost" compact :aria-label="`Ошибки · ${t.table_name}`" @click="focusMessages(row.module.key,t.table_name,'error')"><span :class="{danger:Number(t.error_count)}">{{t.error_count??0}}</span></UiButton><span>/</span><UiButton variant="ghost" compact :aria-label="`Предупреждения · ${t.table_name}`" @click="focusMessages(row.module.key,t.table_name,'warning')"><span :class="{warning:Number(t.warning_count)}">{{t.warning_count??0}}</span></UiButton></div></td></tr>
                  <tr v-if="!row.run?.tables?.length"><td colspan="4" class="mc-empty">{{row.run ? 'Ждём отчёт по таблицам. Для старых запусков детализация может отсутствовать.' : 'Перенос ещё не запущен.'}}</td></tr>
                </template>
              </template>
            </tbody></table></div>

            <footer class="mc-totals"><span>Обработано <b>{{totals.processed_count}}</b></span><span v-if="totals.ready_count">Готово к переносу <b>{{totals.ready_count}}</b></span><span>Успешно <b>{{totals.success_count}}</b></span><span>Ошибки <b class="danger">{{totals.conflict_count}}</b></span><span>Предупреждения <b class="warning">{{totals.warning_count}}</b></span></footer></div>
          </section>
          <div class="mc-divider" role="separator" tabindex="0" aria-orientation="vertical" aria-label="Ширина таблицы переноса" aria-valuemin="20" aria-valuemax="80" :aria-valuenow="Math.round(split)" @pointerdown="startResize" @keydown="resizeKey" @dblclick="setSplit(50)" title="Перетащите для изменения ширины; двойной щелчок — поровну"></div>
          <section class="mc-log"><header class="mc-log-head"><UiTabs v-model="rightPanel" :items="[{value:'summary',label:'Итоги переноса'},{value:'journal',label:'Журнал переноса'}]"/><div v-show="rightPanel==='journal'" class="mc-journal-toolbar"><div class="mc-log-filters" role="group" aria-label="Фильтр журнала"><UiButton v-for="item in [{value:'all',label:'Все'},{value:'error',label:'Ошибки'},{value:'warning',label:'Предупреждения'}]" :key="item.value" compact :variant="filter===item.value?'secondary':'ghost'" :aria-pressed="filter===item.value" @click="filter=item.value">{{item.label}}</UiButton></div><label><input v-model="autoScroll" type="checkbox"/> Автопрокрутка</label></div></header>          <div v-show="rightPanel==='summary'" class="mc-summary" role="tabpanel" aria-label="Итоги переноса">            <div v-for="run in failedRuns" :key="run.id" class="mc-failure" role="status"><strong>{{titles[run.service]}} · {{modeLabel(run.mode)}}: {{run.fetchError?'отчёт недоступен':'есть ошибки'}}</strong><p v-if="run.error">{{run.error}}</p><p v-else>Этап не завершён успешно: {{run.conflict_count||0}} ошибок. Предупреждения показаны отдельно.</p><p v-for="reason in run.conflict_summary||[]" :key="reason.entity_type+reason.code+reason.message">{{reason.entity_type}} · {{reason.count}}: {{reason.message}}</p><UiButton v-if="!run.fetchError" compact variant="ghost" @click="focusMessages(run.service,'','error')">Показать ошибки</UiButton></div><div v-if="!failedRuns.length" class="mc-summary-state"><strong>{{summaryStatus}}</strong><p>{{operation?.message||'Результаты появятся после запуска этапов переноса.'}}</p></div><div v-for="row in rows.filter(item=>item.run?.warning_summary?.length)" :key="'warnings-'+row.module.key" class="mc-failure"><strong>{{titles[row.module.key]}} · Сохранено только в metadata / предупреждения</strong><p v-for="item in row.run.warning_summary" :key="item.entity_type+item.code+item.message">{{item.entity_type}} · {{item.count}}: {{item.message}}</p><UiButton compact variant="ghost" @click="focusMessages(row.module.key,'','warning')">Показать предупреждения</UiButton></div><div class="mc-summary-counts"><span v-if="totals.ready_count">Готово к переносу: <b>{{totals.ready_count}}</b></span><span v-if="totals.blocked_count">Ошибки зависимостей: <b>{{totals.blocked_count}}</b></span><span>Успешно: <b>{{totals.success_count}}</b></span><span>Ошибки: <b>{{totals.conflict_count}}</b></span><span>Предупреждения: <b>{{totals.warning_count}}</b></span></div></div><div v-show="rightPanel==='journal'" class="mc-log-body" role="tabpanel" aria-label="Журнал переноса"><div v-if="tableFilter" class="mc-log-filter">{{titles[tableFilter.service]}} / {{tableFilter.table}} <UiButton compact variant="ghost" @click="tableFilter=null">Сбросить</UiButton></div><div ref="logBody" class="mc-log-scroll"><table class="irlix-data-table"><thead><tr><th>Время</th><th>Сервис / таблица</th><th>Сообщение</th></tr></thead><tbody><tr v-for="msg in filteredMessages" :key="msg.key" :class="msg.kind"><td>{{date(msg.created_at)}}</td><td>{{titles[msg.service]}}<small>{{msg.table||modeLabel(msg.mode)}} · #{{msg.run_id}}</small></td><td>{{msg.message}}<small v-if="msg.code">{{msg.code}} · legacy ID: {{msg.legacy_id||'—'}}</small><small v-if="msg.context?.source">{{msg.context.source.email||''}} · {{msg.table==='reporting_periods'?'ID записи':'user ID'}}: {{msg.context.source.user_id??msg.context.source.id??'—'}}<template v-if="msg.context.source.from||msg.context.source.to"> · {{periodDay(msg.context.source.from)}} — {{periodDay(msg.context.source.to)}} · тип: {{msg.context.source.type||'—'}}</template></small><small v-if="msg.context?.client_login">Сопоставление Clients: {{msg.context.client_login}} · Vacations: {{msg.context.vacation_login}}</small><small v-if="msg.context?.result_titles">Результаты: {{msg.context.result_titles.join('; ')}}</small><small v-if="msg.context?.memberable_type">Тип участника: {{msg.context.memberable_type}} · ID {{msg.context.memberable_id}}</small><small v-if="msg.context?.overlapping_period">Пересечение: ID {{msg.context.period.legacy_id}} ({{periodDay(msg.context.period.from)}} — {{periodDay(msg.context.period.to)}}) и ID {{msg.context.overlapping_period.legacy_id}} ({{periodDay(msg.context.overlapping_period.from)}} — {{periodDay(msg.context.overlapping_period.to)}})</small><small v-if="msg.context?.imported_employee_id">Импортированный сотрудник: {{msg.context.imported_employee_id}} · найденный: {{msg.context.resolved_employee_id}}</small><small v-if="msg.context?.field">Поле: {{msg.context.field}}</small><small v-if="msg.context?.dependency">Зависимость: {{msg.context.dependency.table}} · ID {{msg.context.dependency.legacy_id}} · {{msg.context.dependency.cause}}</small><small v-if="msg.context?.employee">{{msg.context.employee.full_name||msg.context.employee.legacy_id}} · {{msg.context.employee.login||'логин не указан'}}</small><UiButton v-if="canMapUser(msg)" variant="ghost" compact @click="openUserMapping(msg)">Сопоставить сотрудника</UiButton><details v-if="msg.context?.attempt_fields"><summary>Исходные поля попытки · ID {{msg.legacy_id}}</summary><table class="irlix-data-table"><thead><tr><th>Поле</th><th>Заполнено</th><th>Значение</th></tr></thead><tbody><tr v-for="(field,name) in msg.context.attempt_fields" :key="name"><td>{{name}}</td><td>{{field.filled?'Да':'Нет'}}</td><td>{{field.value}}</td></tr></tbody></table><p>Связанных результатов: {{msg.context.result_count}}. {{(msg.context.result_titles||[]).join('; ')||'Результатов нет'}}</p></details><small v-if="msg.context?.corrected_period">Коррекция: {{periodDay(msg.context.source_period.from)}} — {{periodDay(msg.context.source_period.to)}} → {{periodDay(msg.context.corrected_period.from)}} — {{periodDay(msg.context.corrected_period.to)}}</small><details v-if="msg.context?.period_records"><summary>Сравнить исходные периоды · клиент ID {{msg.context.legacy_client_id}}</summary><section v-for="record in msg.context.period_records" :key="record.legacy_id"><strong>Период ID {{record.legacy_id}}</strong><p>Связанных ставок: {{record.rate_link_count??'неизвестно'}}</p><table class="irlix-data-table"><thead><tr><th>Поле</th><th>Заполнено</th><th>Значение</th></tr></thead><tbody><tr v-for="(field,name) in record.fields" :key="name"><td>{{name}}</td><td>{{field.filled?'Да':'Нет'}}</td><td>{{['from','to'].includes(name)&&field.filled?periodDay(field.value):field.value}}</td></tr></tbody></table></section></details><UiButton v-if="hasEmployeeDetails(msg)" variant="ghost" compact :aria-label="`Сотрудник и периоды · ошибка ${msg.conflict_id}`" @click="openEmployeeDetails(msg)">Сотрудник и периоды</UiButton></td></tr><tr v-if="journalLoading"><td colspan="3" class="mc-empty" role="status">Загружаем сообщения по выбранному фильтру…</td></tr><tr v-if="!filteredMessages.length && !journalLoading && !journalErrors.length"><td colspan="3" class="mc-empty">{{failedRuns.some(run=>run.fetchError)?'Подробный журнал недоступен. Причина указана во вкладке «Итоги переноса».':journalFiltered?'По выбранному фильтру сообщений не найдено.':'В загруженной части журнала сообщений нет.'}}</td></tr></tbody></table><div v-for="entry in journalPagination" :key="entry.run.id">
            <div v-if="entry.page.error" class="mc-alert error" role="alert">Не удалось загрузить журнал #{{entry.run.id}}: {{entry.page.error}} <UiButton compact variant="ghost" @click="journalFiltered?refreshJournal():loadOlder(entry.run)">Повторить</UiButton></div>
            <div v-if="journalFiltered && entry.page.total!==null || entry.page.more" class="mc-log-more">{{journalFiltered?'По фильтру загружено':'Загружено'}} {{entry.page.items?.length||0}}{{entry.page.total!==null?' из '+entry.page.total:''}} сообщений запуска #{{entry.run.id}}. <UiButton v-if="entry.page.more" compact variant="ghost" :disabled="entry.page.loading||entry.page.moreLoading" @click="loadOlder(entry.run)">{{entry.page.moreLoading?'Загружаем…':'Загрузить ещё'}}</UiButton></div>
          </div></div></div></section>
          </div>
        </template>
      </section>
    </main>
    <UiDrawer :open="drawer==='user-mapping'" title="Сопоставление сотрудника" width="540px" @close="drawer=''"><div class="irlix-ui">
      <p>Пользователь старой БД: {{userMapping.conflict?.context?.source?.email}} · ID {{userMapping.conflict?.legacy_id}}</p>
      <p class="mc-help">Введите точный текущий логин сотрудника. Сохраняется соответствие старого и текущего логинов для этой исходной БД. Оно сохраняется после отката; ID сотрудника определяется заново при каждом переносе. Логин и пароль сотрудника не изменяются.</p>
      <div class="mc-form"><fieldset :disabled="userMapping.pending||busy"><label class="irlix-field"><span>Текущий логин в Employees</span><input v-model="userMapping.login" @input="userMapping.employee=null;userMapping.notice=''"/></label><UiButton variant="secondary" :disabled="!userMapping.login.trim()" @click="checkUserMapping">Проверить сотрудника</UiButton></fieldset></div>
      <p v-if="userMapping.pending" role="status">Проверяем сопоставление…</p><p v-if="userMapping.employee"><strong>{{userMapping.employee.full_name}}</strong><br/>{{userMapping.employee.login}} · ID {{userMapping.employee.id}}</p>
      <p v-if="userMapping.employee" class="mc-help">Подтвердите, что это тот же человек. Затем повторите Dry run.</p>
      <UiButton v-if="userMapping.employee" :disabled="busy||userMapping.pending" @click="saveUserMapping">Подтвердить сопоставление</UiButton>
      <p v-if="userMapping.notice" role="status">{{userMapping.notice}}</p><div v-if="userMapping.error" class="mc-alert error" role="alert">{{userMapping.error}}</div>
    </div></UiDrawer>
    <UiDrawer :open="drawer==='employee-details'" title="Сотрудник и конфликтующие периоды" width="720px" @close="drawer=''"><div class="irlix-ui">
      <p v-if="conflictLoading" role="status">Читаем подробности…</p><div v-if="conflictError" class="mc-alert error" role="alert">{{conflictError}}</div>
      <template v-if="conflictDetails">
        <p class="mc-help">{{conflictDetails.basis==='recorded'?'Данные сохранены при возникновении ошибки.':'Текущие данные БД: они могли измениться после запуска. Это не снимок на момент ошибки.'}} · {{date(conflictDetails.details.observed_at)}}</p>
        <p><strong>{{conflictDetails.details.employee.full_name||'ФИО недоступно'}}</strong><br/>Логин: {{conflictDetails.details.employee.login||'—'}}<br/>ID в старой БД: {{conflictDetails.details.employee.legacy_id}}<br/>ID в новой БД: {{conflictDetails.details.employee.target_id||'нет сопоставления'}}</p>
        <div class="mc-table-scroll"><table class="irlix-data-table"><thead><tr><th>Период</th><th>ID</th><th>Тип сотрудничества</th><th>Начало</th><th>Окончание</th></tr></thead><tbody>
          <tr><td>Исходный</td><td>{{conflictDetails.details.source_period.id}}</td><td>{{conflictDetails.details.source_period.cooperation_type}}</td><td>{{conflictDetails.details.source_period.started_at||'—'}}</td><td>{{conflictDetails.details.source_period.ended_at||'не закрыт'}}</td></tr>
          <tr v-for="period in conflictDetails.details.target_open_periods" :key="period.id"><td>Незакрытый в новой БД</td><td>{{period.id}}</td><td>{{period.cooperation_type}}</td><td>{{period.started_at}}</td><td>не закрыт</td></tr>
        </tbody></table></div>
        <p v-if="!conflictDetails.details.target_open_periods.length">Незакрытых периодов в новой БД не найдено.</p>
        <p v-if="'effective_end_date' in conflictDetails.details && conflictDetails.details.effective_end_date!==conflictDetails.details.source_period.ended_at" class="mc-help">Исходная дата окончания распознана как дата-заглушка. При переносе период считается незакрытым.</p>
        <p class="mc-help">Просмотр подробностей не повторяет перенос и не исправляет конфликт.</p>
      </template>
    </div></UiDrawer>
    <UiDrawer :open="drawer==='connections'" title="Настройки БД · Все сервисы" width="420px" @close="drawer=''"><div class="irlix-ui"><div v-for="m in implemented" :key="m.key" class="mc-history"><strong>{{titles[m.key]}}</strong><UiButton variant="secondary" @click="openConnection(m.key)">Настроить</UiButton></div></div></UiDrawer>
    <UiDrawer :open="drawer==='backups'" :title="`Бэкапы · ${titles[backupScope]}`" width="640px" @close="drawer=''"><div class="irlix-ui">
      <p class="mc-help">Бэкап сохраняет целевые данные выбранного сервиса и метаданные миграции. Общие бэкапы находятся в разделе «Все сервисы».</p>
      <div v-if="backupError" class="mc-alert error" role="alert">{{backupError}}</div><div v-if="backupNotice" class="mc-alert" role="status">{{backupNotice}}</div>
      <p class="mc-help">{{checkpointHelp}}</p>
      <div v-if="checkpointPause" class="mc-alert" role="status">{{checkpointPauseMessage}}</div>
      <div class="mc-actions"><UiButton :disabled="busy||backupsLoading" @click="createBackup">Создать точку отката</UiButton></div>
      <p v-if="backupsLoading">Загружаем бэкапы…</p><p v-else-if="!backupItems.length">Бэкапов пока нет.</p>
      <div v-for="item in backupItems" :key="`${item.source}:${item.id}`" class="mc-history mc-backup"><div><strong>{{date(item.created_at)}} · #{{item.id}}</strong><small>{{item.source==='console'?'Пульт переноса':'Прежний интерфейс'}} · {{item.compatible===false?'Старый состав сервисов — создайте новую точку':item.restored?'Восстанавливался · доступен повторно':'Готов к восстановлению'}}</small></div><div class="mc-actions"><UiButton compact variant="secondary" :disabled="busy||item.compatible===false||backupsLoading" @click="chooseBackup(item,'restore')">Вернуться</UiButton><UiButton compact variant="danger" :disabled="busy||backupsLoading" @click="chooseBackup(item,'delete')">Удалить</UiButton></div></div>
      <p v-if="busy" class="mc-help">Действия с бэкапами заблокированы до завершения текущей операции.</p>
    </div></UiDrawer>
    <UiDrawer :open="['restore-backup','delete-backup'].includes(drawer)" :title="drawer==='delete-backup'?'Удаление бэкапа':'Возврат к бэкапу'" width="480px" @close="drawer='backups'"><div v-if="backupTarget" class="irlix-ui">
      <p>{{titles[backupTarget.scope]}} · #{{backupTarget.id}} · {{date(backupTarget.created_at)}}</p>
      <p v-if="drawer==='delete-backup'">Бэкап будет удалён с сервера. После удаления вернуться к нему будет невозможно.</p>
      <template v-else><p>Целевые данные и метаданные миграции будут восстановлены на дату бэкапа. Изменения после этой даты будут потеряны. Источник legacy остаётся без изменений.</p><p class="mc-help">Откат отдельного сервиса запрещён, если после бэкапа переносился другой сервис.</p><label class="irlix-field"><span>Введите RESTORE {{backupTarget.scope.toUpperCase()}}</span><input v-model="confirmation" :disabled="busy" autocomplete="off"/></label></template>
      <div v-if="backupError" class="mc-alert error" role="alert">{{backupError}}</div><div class="mc-actions"><UiButton variant="danger" :disabled="busy||(drawer==='restore-backup'&&confirmation!==`RESTORE ${backupTarget.scope.toUpperCase()}`)" @click="backupAction(drawer==='delete-backup'?'delete':'restore')">{{drawer==='delete-backup'?'Удалить бэкап':'Восстановить бэкап'}}</UiButton><UiButton variant="secondary" :disabled="pending" @click="drawer='backups'">Отмена</UiButton></div>
    </div></UiDrawer>
    <UiDrawer :open="drawer==='connection'" :title="`Подключение · ${titles[connectionService]}`" width="420px" @close="drawer=''"><div class="irlix-ui">
      <div v-if="connectionError" class="mc-alert error" role="alert">{{connectionError}}</div><div v-if="connectionNotice" class="mc-alert" role="status">{{connectionNotice}}</div>
      <UiBadge :tone="ready(modules.find(m=>m.key===connectionService))?'success':'warning'">{{ready(modules.find(m=>m.key===connectionService))?'Read-only проверен':'Нужна проверка'}}</UiBadge>
      <form class="mc-form" @submit.prevent="connectionAction('save')"><fieldset :disabled="busy"><label class="irlix-field"><span>Хост</span><input v-model="form.host" autocomplete="off" required/></label><div class="mc-form-pair"><label class="irlix-field"><span>Порт</span><input v-model.number="form.port" type="number" min="1" max="65535" required/></label><label class="irlix-field"><span>База данных</span><input v-model="form.database" required/></label></div><label class="irlix-field"><span>Пользователь</span><input v-model="form.username" autocomplete="off" required/></label><label class="irlix-field"><span>Пароль</span><input v-model="form.password" type="password" autocomplete="new-password" placeholder="Пусто — сохранить текущий пароль"/></label><label class="irlix-field"><span>SSL</span><select v-model="form.sslmode"><option v-for="s in ['disable','allow','prefer','require','verify-ca','verify-full']" :key="s">{{s}}</option></select></label><label><input v-model="form.readonly_acknowledged" type="checkbox"/> Учётная запись только для чтения</label><div class="mc-actions"><UiButton type="submit" :disabled="busy">Сохранить</UiButton><UiButton type="button" variant="secondary" :disabled="busy" @click="connectionAction('verify')">Проверить подключение</UiButton><UiButton type="button" variant="ghost" :disabled="busy" @click="connectionAction('reachability')">Проверить сервер</UiButton></div></fieldset></form>
      <p class="mc-help">Полная проверка использует сохранённые параметры. Сначала сохраните изменения. Пароль не возвращается в браузер.</p><p v-if="busy" class="mc-help">Настройки заблокированы на время операции.</p><div class="mc-actions"><UiButton variant="secondary" :disabled="busy||!ready(modules.find(m=>m.key===connectionService))" @click="prepare('inspect')">Inspect</UiButton><UiButton variant="secondary" :disabled="busy||!ready(modules.find(m=>m.key===connectionService))" @click="prepare('dry-run')">Dry run</UiButton><UiButton variant="danger" :disabled="busy" @click="drawer='delete-connection'">Удалить доступ</UiButton></div>
    </div></UiDrawer>
    <UiDrawer :open="drawer==='start'" title="Запуск переноса" width="440px" @close="drawer=''"><div class="irlix-ui">
      <p>Перенести данные: <strong>{{titles[scope]}}</strong>?</p><p>Будет создана и проверена новая точка отката. Затем последовательно выполняются Inspect, Dry run, перенос и проверка результата.</p><p class="mc-help">{{checkpointHelp}}</p><p v-if="scope==='all'">Очередь: {{implemented.map(m=>titles[m.key]).join(' → ')}}. Нереализованные модули в запуск не входят.</p><p class="mc-help">Ошибка останавливает очередь. Старые базы остаются доступны только для чтения.</p><div v-if="error" class="mc-alert error">{{error}}</div><div class="mc-actions"><UiButton :disabled="!canStart" @click="transfer">Создать точку и перенести</UiButton><UiButton variant="secondary" @click="drawer=''">Отмена</UiButton></div>
    </div></UiDrawer>
    <UiDrawer :open="drawer==='rollback'" :title="`Откат · ${titles[scope]}`" width="440px" @close="drawer=''"><div class="irlix-ui">
      <label class="irlix-field"><span>Точка отката</span><UiSearchSelect v-model="rollbackId" :options="snapshotOptions" :disabled="busy" :clearable="false"/></label><p>Будет восстановлена {{scope==='all'?'единая точка всех реализованных сервисов':'схема выбранного сервиса'}} и служебная информация миграции.</p><p class="mc-help">Откат отдельного сервиса блокируется, если после точки переносился другой сервис. Для отката всей очереди выбирайте «Все сервисы».</p><label class="irlix-field"><span>Введите RESTORE {{scope.toUpperCase()}}</span><input v-model="confirmation" :disabled="busy" autocomplete="off"/></label><div v-if="error" class="mc-alert error">{{error}}</div><div class="mc-actions"><UiButton variant="danger" :disabled="busy||!rollbackId||confirmation!==`RESTORE ${scope.toUpperCase()}`" @click="restore">Откатить</UiButton><UiButton variant="secondary" @click="drawer=''">Отмена</UiButton></div>
    </div></UiDrawer>
    <UiDrawer :open="drawer==='history'" :title="'История · '+titles[scope]" width="560px" @close="drawer=''"><div class="irlix-ui">
      <UiButton variant="ghost" @click="selectedHistory='';drawer='';refresh()">Текущая операция</UiButton><div v-for="h in serviceHistory" :key="h.id" class="mc-history"><div><strong>{{titles[h.scope]}} · {{h.action==='restore'?'Откат':h.action==='snapshot'?'Создание точки отката':'Перенос'}}</strong><small>{{date(h.started_at)}} · #{{h.id}}</small><small>{{h.message}}</small></div><UiBadge :tone="tone(h.status)">{{label(h.status)}}</UiBadge><UiButton variant="secondary" compact @click="chooseHistory(h)">Открыть</UiButton></div><p v-if="!serviceHistory.length">Запусков пока нет.</p>
    </div></UiDrawer>
    <UiDrawer :open="drawer==='delete-connection'" title="Удаление доступа" width="420px" @close="drawer=''"><div class="irlix-ui">
      <p>Удалить сохранённый доступ к БД {{titles[connectionService]}}?</p><div class="mc-actions"><UiButton variant="danger" :disabled="busy" @click="connectionAction('delete');drawer='connection'">Удалить</UiButton><UiButton variant="secondary" @click="drawer='connection'">Отмена</UiButton></div>
    </div></UiDrawer>
  </UiAppShell>
</template>

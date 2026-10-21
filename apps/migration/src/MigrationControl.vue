<script setup>
import {computed,onBeforeUnmount,onMounted,ref,watch} from 'vue';
import {UiButton,UiDrawer,UiLoader,UiPageHeader,UiSearchSelect} from '@irlix/ui';
import MigrationControlTree from './MigrationControlTree.vue';
import {
  dateText,isActive,serviceTitles,stageTitles,statusText,text,
} from './migration-control-model.js';
import './migration-control.css';

const props=defineProps({auth:{type:Object,required:true}});
defineEmits(['settings']);

const state=ref({history:[]});
const report=ref(null);
const historyId=ref('');
const drawer=ref('');
const error=ref('');
const reportError=ref('');
const notice=ref('');
const offline=ref(false);
const loading=ref(true);
const waiting=ref(false);
const pending=ref(false);
const reason=ref('');
const launchScope=ref('all');
const launchContext=ref('new');
const launchSourceId=ref('');

const selected=computed(()=>historyId.value
  ? state.value.history.find(op=>String(op.id)===historyId.value)
  : state.value.operation);
const operation=computed(()=>isActive(selected.value)
  ? selected.value
  : report.value&&String(report.value.operation?.id)===String(selected.value?.id)
    ? report.value.operation
    : selected.value);
const selectedReport=computed(()=>String(report.value?.operation?.id)===String(selected.value?.id)?report.value:null);
const services=computed(()=>[{value:'all',label:'Все сервисы'},...Object.entries(serviceTitles)
  .filter(([key])=>key!=='all').map(([value,label])=>({value,label}))]);
const historyOptions=computed(()=>[{value:'',label:'Текущая операция'},...state.value.history.map(op=>({
  value:String(op.id),
  label:'#'+op.id+' · '+dateText(op.started_at)+' · '+statusText(op.status)+' · '+(serviceTitles[op.scope]||op.scope),
}))]);
const busy=computed(()=>pending.value||offline.value||loading.value||isActive(state.value.operation)
  ||isActive(state.value.console_operation)||isActive(state.value.snapshot_operation)
  ||isActive(state.value.restore_status)||state.value.restore_status?.maintenance===true||state.value.active_runs?.length>0);
const chat=computed(()=>selectedReport.value?.chat_summary||'');

let timer,waitTimer,stopped=false,polling=false,reportSequence=0;
let reportLoadingId='';
const controllers=new Set();

async function api(path,options={}) {
  const controller=new AbortController();
  controllers.add(controller);
  const timeout=setTimeout(()=>controller.abort(),20000);
  try {
    const response=await props.auth.fetch('/api/migration'+path,{
      ...options,signal:controller.signal,cache:'no-store',
      headers:{Accept:'application/json','Content-Type':'application/json'},
    });
    let payload;
    try{payload=await response.json();}catch{throw new Error('Migration API: '+response.status);}
    if(!response.ok)throw new Error(payload.message||'Migration API: '+response.status);
    return payload.data??payload;
  } finally {
    clearTimeout(timeout);
    controllers.delete(controller);
  }
}
async function loadReport(force=false) {
  const id=String(selected.value?.id||'');
  if(!id){report.value=null;return;}
  if(reportLoadingId===id)return;
  if(!force&&String(report.value?.operation?.id)===id&&!isActive(selected.value)
    &&report.value.operation?.status===selected.value.status
    &&report.value.operation?.updated_at===selected.value.updated_at)return;
  const sequence=++reportSequence;
  reportLoadingId=id;
  reportError.value='';
  try {
    const result=await api('/control/operations/'+encodeURIComponent(id)+'/report');
    if(!stopped&&sequence===reportSequence&&id===String(selected.value?.id))report.value=result;
  } catch(e) {
    if(!stopped&&sequence===reportSequence)reportError.value=e.message;
  } finally {
    if(reportLoadingId===id)reportLoadingId='';
  }
}
async function refresh() {
  if(stopped||polling)return;
  polling=true;
  clearTimeout(waitTimer);
  waitTimer=setTimeout(()=>{if(!stopped)waiting.value=true;},900);
  try {
    state.value=await api('/control/state');
    offline.value=false;
    error.value='';
    await loadReport();
  } catch(e) {
    if(!stopped){offline.value=true;error.value=e.message;}
  } finally {
    clearTimeout(waitTimer);
    waiting.value=false;
    polling=false;
    loading.value=false;
    if(!stopped){
      clearTimeout(timer);
      timer=setTimeout(refresh,offline.value?2000:isActive(state.value.operation)?1500:5000);
    }
  }
}
watch(historyId,()=>{reportSequence++;reportLoadingId='';report.value=null;reportError.value='';launchContext.value='new';loadReport();});
async function mutate(path,body) {
  if(pending.value)return;
  pending.value=true;
  error.value='';
  notice.value='';
  try{
    const result=await api(path,{method:'POST',body:JSON.stringify(body)});
    if(result.operation)state.value={...state.value,operation:result.operation};
    historyId.value='';
    report.value=null;
    drawer.value='';
    notice.value=path.endsWith('cancel')
      ?'Запрошена остановка между этапами. Выполненные записи сохраняются.'
      :'Операция запущена.';
  }catch(e){error.value=e.message;}
  finally{pending.value=false;await refresh();}
}
function start(){if(!busy.value)mutate('/control/start',{scope:launchScope.value,confirm:true});}
function retryService(serviceKey){
 if(busy.value||!Object.hasOwn(serviceTitles,serviceKey)||serviceKey==='all')return;
 launchScope.value=serviceKey;launchContext.value='retry';
 launchSourceId.value=String(selected.value?.id||'');
 drawer.value='start';
}
function cancel(){
  if(!reason.value.trim()||pending.value||offline.value)return;
  mutate('/control/cancel',{operation_id:state.value.operation.id,reason:reason.value.trim()});
}
async function copy(){
  if(!chat.value)return;
  try{
    await navigator.clipboard.writeText(chat.value);
    notice.value='Диагностический текст скопирован.';
  }catch{drawer.value='copy';}
}
async function download(){
  if(!selected.value?.id||pending.value)return;
  const operationId=selected.value.id;
  const controller=new AbortController();
  controllers.add(controller);
  const timeout=setTimeout(()=>controller.abort(),30000);
  pending.value=true;
  try{
    const response=await props.auth.fetch('/api/migration/control/operations/'+encodeURIComponent(operationId)+'/report?download=1',{
      signal:controller.signal,headers:{Accept:'application/json'},cache:'no-store',
    });
    if(!response.ok)throw new Error('Не удалось скачать отчёт ('+response.status+')');
    const url=URL.createObjectURL(await response.blob());
    const link=document.createElement('a');
    link.href=url;
    link.download='migration-control-'+operationId+'.json';
    link.click();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
  }catch(e){error.value=e.message;}
  finally{
    clearTimeout(timeout);
    controllers.delete(controller);
    pending.value=false;
  }
}

onMounted(refresh);
onBeforeUnmount(()=>{
  stopped=true;
  reportSequence++;
  clearTimeout(timer);
  clearTimeout(waitTimer);
  for(const controller of controllers)controller.abort();
});
</script>

<template>
<main class="mctrl" aria-label="Контроль переноса">
  <UiPageHeader title="Контроль переноса">
    <template #actions>
      <div class="mctrl-actions">
        <UiButton variant="ghost" @click="$emit('settings')">Открыть пульт переноса</UiButton>
        <UiButton :disabled="busy" @click="launchScope='all';launchContext='new';launchSourceId='';drawer='start'">Новый перенос</UiButton>
        <UiButton v-if="isActive(state.operation)" variant="secondary" :disabled="pending||offline||state.operation.cancel_requested" @click="reason='';drawer='cancel'">
          {{state.operation.cancel_requested?'Остановка запрошена':'Остановить'}}
        </UiButton>
      </div>
    </template>
  </UiPageHeader>

  <div v-if="offline" class="mctrl-alert" role="alert">
    Нет связи с Migration. Показан последний полученный отчёт; актуальный статус не подтверждён. {{error}}
    <UiButton variant="ghost" compact @click="refresh">Повторить</UiButton>
  </div>
  <div v-else-if="error" class="mctrl-alert" role="alert">{{error}}</div>
  <p v-if="notice" role="status">{{notice}}</p>
  <UiLoader v-if="loading||offline||waiting" class="mctrl-connection-loader" show-label
    :label="offline?'Ожидаем восстановления Migration API… Повторяем проверку автоматически.':loading?'Загружаем состояние переноса…':'Ожидаем ответ Migration API…'"/>

  <div class="mctrl-filters">
    <label>Операция
      <UiSearchSelect v-model="historyId" :options="historyOptions" :clearable="false" aria-label="История операций"/>
    </label>
    <div class="mctrl-actions mctrl-report-actions">
      <UiButton variant="ghost" aria-label="Скопировать диагностический текст" :disabled="!chat" @click="copy">Диагностика</UiButton>
      <UiButton variant="ghost" :aria-label="selectedReport?.archive_complete===false?'Скачать доступный JSON':'Скачать полный JSON'" :disabled="!selected||pending" @click="download">JSON-отчёт</UiButton>
    </div>
  </div>
  <p v-if="operation&&isActive(operation)" role="status" aria-live="polite" class="mctrl-active-state">
    Выполняется: {{serviceTitles[operation.current_service]||text(operation.current_service)}} · {{stageTitles[operation.phase]||text(operation.phase)}}
  </p>

  <p v-if="!operation&&!loading&&!offline">Перенос ещё не запускался. Настройте подключения в пульте и начните новый перенос.</p>
  <div v-if="reportError" role="alert" class="mctrl-alert">
    Отчёт недоступен: {{reportError}}
    <UiButton variant="ghost" compact @click="loadReport(true)">Повторить загрузку</UiButton>
  </div>
  <MigrationControlTree v-if="operation" :key="String(operation.id)" :operation="operation"
    :report="selectedReport" :auth="auth" :busy="busy" :restore-history="state.restore_history||[]"
    @retry-service="retryService"/>
  <UiDrawer :open="!!drawer" :title="drawer==='start'?(launchContext==='retry'?'Запуск переноса сервиса':'Новый перенос'):drawer==='cancel'?'Остановка переноса':'Диагностический текст'" @close="drawer=''">
    <form v-if="drawer==='start'" class="mctrl-form" @submit.prevent="start">
      <strong v-if="launchContext==='retry'">{{serviceTitles[launchScope]||launchScope}}</strong>
      <p v-if="launchContext==='retry'">Будет запущен новый перенос только этого сервиса. Перед записью создаётся точка отката. Ранее записанные данные сохраняются, автоматического отката нет; история операции #{{launchSourceId}} не изменится.</p>
      <p v-else>Будет создана новая операция с контрольной точкой, проверкой и записью данных. Автоматического отката нет.</p>
      <label v-if="launchContext==='new'">Область переноса
        <UiSearchSelect v-model="launchScope" :options="services" :clearable="false" aria-label="Область запуска"/>
      </label>
      <p v-if="launchContext==='new'">Перед запуском проверьте подключения в пульте переноса.</p>
      <UiButton type="submit" :disabled="busy">{{launchContext==='retry'?'Запустить перенос':'Подтвердить и запустить'}}</UiButton>
    </form>
    <form v-if="drawer==='cancel'" class="mctrl-form" @submit.prevent="cancel">
      <p>Активный этап завершится; следующие этапы не начнутся. Выполненные записи останутся в базе. Откат выполняется отдельно через бэкапы.</p>
      <label class="irlix-field">Причина остановки<textarea v-model="reason" required maxlength="500" rows="4"/></label>
      <UiButton type="submit" :disabled="!reason.trim()||pending||offline">Остановить после этапа</UiButton>
    </form>
    <div v-if="drawer==='copy'">
      <p>Автокопирование недоступно. Выделите диагностический текст и скопируйте вручную.</p>
      <textarea class="mctrl-copy" readonly :value="chat" aria-label="Диагностический текст" @focus="$event.target.select()"/>
    </div>
  </UiDrawer>
</main>
</template>

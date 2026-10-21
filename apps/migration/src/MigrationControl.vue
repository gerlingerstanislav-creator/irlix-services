<script setup>
import {computed,onBeforeUnmount,onMounted,ref,watch} from 'vue';
import {UiButton,UiDrawer,UiPageHeader,UiSearchSelect} from '@irlix/ui';
import MigrationControlTree from './MigrationControlTree.vue';
import {
  causeGroups,dateText,isActive,reportRuns,serviceEvidenceRows,serviceTitles,stageRows,stageTitles,
  statusText,tableEvidenceRows,text,tone,
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

let timer,stopped=false,polling=false,reportSequence=0;
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
  try {
    state.value=await api('/control/state');
    offline.value=false;
    error.value='';
    await loadReport();
  } catch(e) {
    if(!stopped){offline.value=true;error.value=e.message;}
  } finally {
    polling=false;
    loading.value=false;
    if(!stopped){
      clearTimeout(timer);
      timer=setTimeout(refresh,isActive(state.value.operation)?1500:5000);
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
  for(const controller of controllers)controller.abort();
});
</script>

<template>
<main class="mctrl" aria-label="Контроль переноса">
  <UiPageHeader title="Контроль переноса" description="Доказательства по сервисам, сущностям и этапам переноса без подмены неизвестного состояния нулём.">
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

  <div class="mctrl-filters">
    <label>Операция
      <UiSearchSelect v-model="historyId" :options="historyOptions" :clearable="false" aria-label="История операций"/>
    </label>
    <div class="mctrl-actions">
      <UiButton variant="secondary" :disabled="!chat" @click="copy">Скопировать диагностический текст</UiButton>
      <UiButton variant="secondary" :disabled="!selected||pending" @click="download">
        {{selectedReport?.archive_complete===false?'Скачать доступный JSON':'Скачать полный JSON'}}
      </UiButton>
    </div>
  </div>
  <p class="mctrl-muted">Диагностический текст и JSON относятся к выбранной операции. При смене версии дерево и окна показывают только её данные.</p>
  <p role="status" aria-live="polite" class="mctrl-muted">
    {{operation?statusText(operation.status)+' · '+(stageTitles[operation.phase]||text(operation.phase)):'Операция не выбрана'}}
  </p>
  <p v-if="loading" role="status">Загружаем состояние переноса…</p>

  <p v-if="!operation&&!loading&&!offline">Перенос ещё не запускался. Настройте подключения в пульте и начните новый перенос.</p>
  <div v-if="reportError" role="alert" class="mctrl-alert">
    Отчёт недоступен: {{reportError}}
    <UiButton variant="ghost" compact @click="loadReport(true)">Повторить загрузку</UiButton>
  </div>
  <MigrationControlTree v-if="operation" :key="String(operation.id)" :operation="operation"
    :report="selectedReport" :auth="auth" :busy="busy"
    @retry-service="retryService"/>
  <UiDrawer :open="!!drawer" :title="drawer==='start'?'Новый перенос':drawer==='cancel'?'Остановка переноса':'Диагностический текст'" @close="drawer=''">
    <form v-if="drawer==='start'" class="mctrl-form" @submit.prevent="start">
      <strong v-if="launchContext==='retry'">Повторный перенос: {{serviceTitles[launchScope]||launchScope}}</strong>
      <p v-if="launchContext==='retry'">Будет создана новая операция только для этого сервиса, с отдельной контрольной точкой и историей. Операция #{{launchSourceId}} останется неизменной. Автоматического отката уже записанных данных нет; перед подтверждением проверьте прошлый результат и отсутствие активного worker.</p>
      <p>Последовательно: точка отката, чтение, проверка, запись и проверка результата. Новый перенос допускается поверх частичных данных; автоматический откат не выполняется.</p>
      <label>Область переноса
        <UiSearchSelect v-model="launchScope" :options="services" :clearable="false" aria-label="Область запуска"/>
      </label>
      <p>Перед запуском проверьте подключения в старом пульте. В историю сохранятся результаты каждого этапа.</p>
      <UiButton type="submit" :disabled="busy">{{launchContext==='retry'?'Подтвердить повтор сервиса':'Подтвердить и запустить'}}</UiButton>
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

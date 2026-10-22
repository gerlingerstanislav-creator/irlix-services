<script setup>
import {computed,ref,watch} from 'vue';
import {UiBadge,UiButton,UiDrawer,UiLoader} from '@irlix/ui';
import {serviceTitles} from './migration-control-model.js';

const props=defineProps({
 open:{type:Boolean,default:false},operation:{type:Object,required:true},
 report:{type:Object,default:null},auth:{type:Object,required:true},
 initialService:{type:String,default:'timesheets'},initialTable:{type:String,default:''},
});
const emit=defineEmits(['close']);
const service=ref('timesheets'),rows=ref([]),cursor=ref(null),total=ref(null),more=ref(false);
const loading=ref(false),error=ref('');
const selected=ref(null),evidence=ref(null),evidenceError=ref(''),evidenceLoading=ref(false);
const filterTable=ref(''),filterCode=ref(''),filterId=ref('');
let listSequence=0,detailSequence=0;
const runs=computed(()=>{
 const list={};
 for(const record of props.report?.runs||[]){
  const mode=record.mode||record.run?.mode, name=record.service||record.run?.service;
  const id=record.run?.id??record.run_id??record.id;
  if(name&&id&&['dry-run','migrate'].includes(mode))list[name]=id;
 }
 return list;
});
const run=computed(()=>runs.value[service.value]||null);
function close(){listSequence++;detailSequence++;emit('close');}
function clearDetail(){selected.value=null;evidence.value=null;evidenceError.value='';detailSequence++;}
function setService(next){service.value=next;rows.value=[];cursor.value=null;more.value=false;total.value=null;clearDetail();void load(false);}
watch(()=>[props.open,props.operation?.id],()=>{
 if(!props.open){clearDetail();return;}
 service.value=props.initialService||'timesheets';rows.value=[];cursor.value=null;more.value=false;total.value=null;
 clearDetail();void load(false);
},{immediate:true});
async function load(append){
 if(!props.open)return;
 const id=run.value,operationId=String(props.operation?.id||'');
 if(!id){error.value='Запуск Dry run / Migrate этого сервиса не найден в выбранной операции.';return;}
 const current=++listSequence,controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),25000);
 loading.value=true;error.value='';
 try{
  const q=new URLSearchParams({operation_id:operationId,severity:'error'});
  if(append&&cursor.value)q.set('before',String(cursor.value));
  if(filterTable.value)q.set('table',filterTable.value);
  if(filterCode.value)q.set('code',filterCode.value);
  if(filterId.value)q.set('legacy_id',filterId.value);
  if(!filterTable.value&&props.initialTable&&service.value===props.initialService)q.set('table',props.initialTable);
  const response=await props.auth.fetch('/api/migration/console/runs/'+encodeURIComponent(id)+'/conflicts?'+q.toString(),
   {signal:controller.signal,cache:'no-store',headers:{Accept:'application/json'}});
  const body=await response.json();
  if(!response.ok)throw new Error(body.message||'HTTP '+response.status);
  if(current!==listSequence||operationId!==String(props.operation?.id))return;
  const value=body.data||body;
  rows.value=append?[...rows.value,...(value.items||[])]:value.items||[];
  cursor.value=value.cursor??null;more.value=!!value.more;total.value=Number(value.total??rows.value.length);
 }catch(e){if(current===listSequence)error.value=controller.signal.aborted?'Превышено время ожидания':e.message;}
 finally{clearTimeout(timeout);if(current===listSequence)loading.value=false;}
}
async function openDetail(item){
 selected.value=item;evidence.value=null;evidenceError.value='';
 if(service.value!=='timesheets')return;
 const current=++detailSequence,operationId=String(props.operation?.id),controller=new AbortController();
 const timeout=setTimeout(()=>controller.abort(),25000);evidenceLoading.value=true;
 try{
  const uri='/api/migration/console/runs/'+encodeURIComponent(run.value)
   +'/conflicts/'+encodeURIComponent(item.id)+'/evidence?operation_id='+encodeURIComponent(operationId);
  const response=await props.auth.fetch(uri,{signal:controller.signal,cache:'no-store',headers:{Accept:'application/json'}});
  const body=await response.json();if(!response.ok)throw new Error(body.message||'HTTP '+response.status);
  if(current===detailSequence)evidence.value=body.data||body;
 }catch(e){if(current===detailSequence)evidenceError.value=controller.signal.aborted?'Превышено время ожидания':e.message;}
 finally{clearTimeout(timeout);if(current===detailSequence)evidenceLoading.value=false;}
}
</script>

<template>
 <UiDrawer :open="open" title="Ошибки переноса · расследование" width="1040px" @close="close">
  <div class="merr-shell">
   <p>Полный журнал ошибок выбранного запуска: все записи доступны постранично, а не только примеры из архива.
     Нажми «Разобрать», чтобы сверить конкретный исходный timesheet, rate, member, сотрудника,
     подключения Clients и интервалы. Данные БД показаны <strong>на момент открытия</strong>, а не на момент старого запуска.</p>
   <div class="merr-toolbar">
    <label>Сервис
     <select :value="service" @change="setService($event.target.value)">
      <option v-for="(id,key) in runs" :key="key" :value="key">{{serviceTitles[key]||key}} · run #{{id}}</option>
     </select>
    </label>
    <strong>Ошибок: {{total===null?'…':total}}</strong>
   </div>
   <form class="merr-filters" @submit.prevent="rows=[];cursor=null;more=false;load(false)">
    <label>Таблица
      <select v-model="filterTable">
       <option value="">Все</option><option value="members">members</option>
       <option value="rates">rates</option><option value="timesheets">timesheets</option>
       <option value="users">users</option>
      </select>
    </label>
    <label>Причина
      <select v-model="filterCode">
       <option value="">Все</option><option value="SOURCE_ROW_UNRESOLVED">SOURCE_ROW_UNRESOLVED</option>
       <option value="DEPENDENCY_BLOCKED">DEPENDENCY_BLOCKED</option>
       <option value="IDENTITY_MAPPING_COLLISION">IDENTITY_MAPPING_COLLISION</option>
      </select>
    </label>
    <label>Исходный ID
     <input v-model="filterId" inputmode="numeric" pattern="[0-9]*" placeholder="Точный ID"/>
    </label>
    <UiButton variant="secondary" submit :disabled="loading">Найти ошибки</UiButton>
   </form>
   <UiLoader v-if="loading" show-label label="Читаем ошибки…"/>
   <p v-if="error" role="alert">{{error}}</p>
   <div v-for="item in rows" :key="item.id" class="merr-row">
    <div><strong>{{item.entity_type}} #{{item.legacy_id}}</strong><small>{{item.code}}</small></div>
    <span>{{item.message}}</span>
    <UiButton variant="secondary" compact :aria-label="'Разобрать запись '+item.legacy_id" @click="openDetail(item)">Разобрать</UiButton>
   </div>
   <p v-if="!loading&&!error&&total===0">Зафиксированных ошибок в этом запуске нет.</p>
   <div v-if="more" class="merr-toolbar">
    <span>Загружено {{rows.length}} из {{total}}</span>
    <UiButton variant="secondary" :disabled="loading" @click="load(true)">Следующие 200</UiButton>
   </div>
   <p v-if="!more&&total!==null&&total>0">Все {{rows.length}} записей этого запуска доступны выше.</p>
  </div>
 </UiDrawer>
 <UiDrawer :open="!!selected" :title="'Разбор '+(selected?.entity_type||'')+' #'+(selected?.legacy_id||'')" width="920px" @close="clearDetail">
  <div class="merr-shell">
   <p><strong>Ошибка:</strong> {{selected?.code}} · {{selected?.message}}</p>
   <p v-if="service!=='timesheets'">Для этого сервиса доступен сохранённый контекст ошибки; детальная live-сверка сейчас реализована для Timesheets.</p>
   <UiLoader v-if="evidenceLoading" show-label label="Читаем исходные и целевые записи…"/>
   <p v-if="evidenceError" role="alert">{{evidenceError}}</p>
   <template v-if="evidence">
    <p class="merr-disclaimer">Текущие данные источника и Clients. Если они изменились после миграции, результат может отличаться от исходной диагностики.</p>
    <div class="merr-cards">
     <section><strong>Исходный {{evidence.source_type}} #{{evidence.source_id}}</strong><pre>{{JSON.stringify(evidence.source,null,2)}}</pre></section>
     <section><strong>Ставка Rates #{{evidence.rate?.id||'не найдена'}}</strong><pre>{{JSON.stringify(evidence.rate,null,2)}}</pre></section>
     <section><strong>Участник Members #{{evidence.member?.id||'не найден'}}</strong><pre>{{JSON.stringify(evidence.member,null,2)}}</pre></section>
    </div>
    <p v-if="evidence.employee">Подтверждённый сотрудник: <strong>{{evidence.employee.employee_name}}</strong> · Employees #{{evidence.employee.employee_id}} · исходный проект: {{evidence.employee.project}}</p>
    <div v-for="(check,index) in evidence.checks" :key="index" class="merr-check">
     <UiBadge :tone="check.ok?'success':'danger'">{{check.ok?'Совпадает':'Не совпадает'}}</UiBadge>
     <span>{{check.name}}: {{check.detail}}</span>
    </div>
    <p>Связь members: {{evidence.mapped_member_id||'не сохранена'}} · override: {{evidence.override_member_id||'нет'}} · ставка Clients: {{evidence.mapped_term_id||'не сохранена'}}.</p>
    <strong>Кандидаты Clients и периоды member_terms</strong>
    <p v-if="!evidence.candidates.length">Подключений не найдено или сотрудник не подтверждён.</p>
    <section v-for="candidate in evidence.candidates" :key="candidate.target_id" class="merr-candidate">
     <strong>{{candidate.client_name}} / {{candidate.project_name}} · project #{{candidate.project_id}} · member #{{candidate.target_id}}</strong>
     <small>{{candidate.period_matches?'Все исходные ставки совпадают':'Не все ставки совпадают'}} · {{candidate.matched_rates}} из {{candidate.rate_count}}</small>
     <p v-for="term in candidate.terms||[]" :key="term.id">
      Term #{{term.id}}: {{term.valid_from}} — {{term.valid_to||'открытый период'}}, {{term.hours_per_day}} ч/день
      <span v-if="candidate.day_matches?.length">· дата записи {{candidate.day_matches.find(x=>x.term_id===term.id)?.ok?'попадает':'не попадает'}} в период</span>
     </p>
    </section>
    <div class="merr-disclaimer"><strong>Что нужно решить:</strong> {{evidence.action}}</div>
   </template>
   <details v-if="selected?.context"><summary>Сохранённый контекст ошибки</summary><pre>{{JSON.stringify(selected.context,null,2)}}</pre></details>
  </div>
 </UiDrawer>
</template>

<style scoped>
.merr-shell{display:flex;flex-direction:column;gap:10px;min-width:0}
.merr-shell>p{margin:2px 0;line-height:1.45}
.merr-toolbar{display:flex;align-items:end;justify-content:space-between;gap:12px;flex-wrap:wrap}
.merr-toolbar label{display:flex;flex-direction:column;gap:4px;min-width:230px}
.merr-filters{display:flex;align-items:end;gap:10px;flex-wrap:wrap}
.merr-filters label{display:flex;flex-direction:column;gap:4px;flex:1 1 150px;min-width:0}
.merr-filters input,.merr-filters select{padding:8px;border:1px solid var(--irlix-color-border);border-radius:6px;background:var(--irlix-color-surface);color:var(--irlix-color-text)}
.merr-toolbar select{padding:8px;border:1px solid var(--irlix-color-border);border-radius:6px;color:var(--irlix-color-text);background:var(--irlix-color-surface)}
.merr-row{display:grid;grid-template-columns:minmax(150px,1fr) minmax(250px,3fr) auto;gap:14px;align-items:center;
 border-bottom:1px solid var(--irlix-color-border);padding:12px 2px;min-width:0;overflow-wrap:anywhere}
.merr-row small,.merr-candidate small{display:block;color:var(--irlix-color-text-muted);margin-top:3px}
.merr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;min-width:0}
.merr-cards section,.merr-candidate{border:1px solid var(--irlix-color-border);padding:10px;border-radius:8px;min-width:0}
.merr-shell pre{overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.5 monospace}
.merr-check{display:flex;gap:10px;align-items:center}
.merr-disclaimer{padding:10px;border:1px solid var(--irlix-color-border);border-left:3px solid var(--irlix-color-warning,#eab308);border-radius:5px}
.merr-candidate{display:flex;flex-direction:column;gap:3px}
.merr-candidate p{margin:3px 0}
@media(max-width:700px){.merr-row{grid-template-columns:minmax(0,1fr)}}
</style>

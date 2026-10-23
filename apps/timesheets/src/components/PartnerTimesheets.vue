<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { UiButton, UiFilterBar } from '@irlix/ui';
import { api } from '../api';

const props=defineProps({month:{type:String,required:true}});
const loading=ref(false),saving=ref(''),error=ref(''),data=ref({members:[],entries:[],confirmations:[],final_approvals:[],locked_reporting_periods:[]});
const memberId=ref('');
const drafts=ref({});
const search=ref('');
const members=computed(()=>[...new Map((data.value.members||[]).map(m=>[m.client_member_id,m])).values()]
  .filter(m=>(m.client_name+' '+m.specialist_name).toLocaleLowerCase('ru').includes(search.value.toLocaleLowerCase('ru')))
  .sort((a,b)=>(a.client_name+a.specialist_name).localeCompare(b.client_name+b.specialist_name,'ru')));
const selected=computed(()=>members.value.find(m=>String(m.client_member_id)===String(memberId.value)));
const days=computed(()=>{
 const [y,m]=props.month.split('-').map(Number);
 return Array.from({length:new Date(y,m,0).getDate()},(_,i)=>props.month+'-'+String(i+1).padStart(2,'0'));
});
const monthApproved=computed(()=>data.value.final_approvals?.some(a=>Number(a.client_member_id)===Number(memberId.value)));
const readEntry=d=>data.value.entries?.find(e=>Number(e.client_member_id)===Number(memberId.value)&&e.work_date===d);
const confirmed=d=>data.value.confirmations?.some(e=>Number(e.client_member_id)===Number(memberId.value)&&e.work_date===d);
const active=d=>data.value.members?.some(m=>Number(m.client_member_id)===Number(memberId.value)&&m.valid_from<=d&&(!m.valid_to||m.valid_to>=d));
const locked=d=>data.value.locked_reporting_periods?.some(p=>Number(p.client_id)===Number(selected.value?.client_id)&&p.period_start<=d&&p.period_end>=d);
const total=computed(()=>days.value.reduce((v,d)=>v+Number(readEntry(d)?.hours||0),0));
async function load(){
 loading.value=true;error.value='';
 try{
  data.value=(await api('/api/timesheets/partner-hours?month='+props.month)).data;
  if(!members.value.some(m=>String(m.client_member_id)===String(memberId.value)))
    memberId.value=String(members.value[0]?.client_member_id||'');
  const next={};
  for(const e of data.value.entries||[]) next[e.client_member_id+':'+e.work_date]={hours:Number(e.hours),description:e.description||''};
  drafts.value=next;
 }catch(e){error.value=e.message;}finally{loading.value=false;}
}
function draft(d){const key=memberId.value+':'+d;return drafts.value[key]||{hours:0,description:''};}
function setDraft(d,field,value){const key=memberId.value+':'+d;drafts.value={...drafts.value,[key]:{...draft(d),[field]:value}};}
async function save(d){
 if(!selected.value||!active(d)||locked(d)||monthApproved.value||saving.value) return;
 const v=draft(d),prev=readEntry(d);
 if(!prev && Number(v.hours)===0 && !String(v.description||'').trim())return;
 if(prev&&Number(prev.hours)===Number(v.hours)&&String(prev.description||'')===String(v.description||''))return;
 saving.value=d;error.value='';
 try{
  await api('/api/timesheets/partner-hours',{method:'PUT',body:{
   client_member_id:Number(memberId.value),work_date:d,hours:Number(v.hours),description:v.description||null
  }});
  await load();
 }catch(e){error.value=e.message;}finally{saving.value='';}
}
async function confirm(d){
 saving.value=d;error.value='';
 try{
  await api('/api/timesheets/partner-hours/confirm',{method:'POST',body:{
    client_member_id:Number(memberId.value),work_date:d,confirmed:!confirmed(d)
  }});await load();
 }catch(e){error.value=e.message;}finally{saving.value='';}
}
async function finalApproval(){
 saving.value='final';error.value='';
 try{
  await api('/api/timesheets/partner-hours/final-approval',{method:'POST',body:{
    client_member_id:Number(memberId.value),month:props.month,approved:!monthApproved.value
  }});await load();
 }catch(e){error.value=e.message;}finally{saving.value='';}
}
watch(()=>props.month,load);onMounted(load);
</script>
<template>
 <section class="partner-timesheets">
  <UiFilterBar><input v-model="search" type="search" placeholder="Поиск клиента / специалиста" aria-label="Поиск партнёра">
   <select v-model="memberId" aria-label="Партнёрский специалист">
    <option v-for="m in members" :key="m.client_member_id" :value="String(m.client_member_id)">{{m.client_name}} — {{m.specialist_name}}</option>
   </select>
   <UiButton @click="load" :disabled="loading">Обновить</UiButton>
  </UiFilterBar>
  <div v-if="error" class="partner-hour-error">{{error}}</div>
  <div v-if="loading">Загрузка таймшитов партнёров…</div>
  <template v-else-if="selected">
   <div class="partner-hour-summary">
    <strong>{{selected.client_name}} / {{selected.specialist_name}}</strong>
    <span>Всего {{total.toFixed(2)}} ч.</span>
    <UiButton :disabled="!!saving" @click="finalApproval">{{monthApproved?'× Снять финальное подтверждение':'✓ Финально подтвердить месяц'}}</UiButton>
   </div>
   <div class="partner-hours-scroll"><table class="partner-hour-table"><thead><tr><th>Дата</th><th>Часы</th><th>Описание работ</th><th>Подтверждение</th></tr></thead>
    <tbody><tr v-for="d in days" :key="d" :class="{inactive:!active(d),closed:locked(d)}">
      <td>{{d.slice(8)}}.{{d.slice(5,7)}}.{{d.slice(0,4)}}</td>
      <td><input type="number" min="0" max="24" step="0.25" :value="draft(d).hours" :disabled="!active(d)||locked(d)||monthApproved||!!saving" @input="setDraft(d,'hours',$event.target.value)" @blur="save(d)"></td>
      <td><input type="text" maxlength="4000" :value="draft(d).description" :disabled="!active(d)||locked(d)||monthApproved||!!saving" @input="setDraft(d,'description',$event.target.value)" @blur="save(d)"></td>
      <td><UiButton compact :disabled="!active(d)||locked(d)||monthApproved||!!saving" @click="confirm(d)">{{confirmed(d)?'✓ Подтверждено':'Подтвердить'}}</UiButton></td>
     </tr></tbody></table></div>
  </template>
  <div v-else class="partner-hour-empty">Нет доступных подключений партнёров в выбранном месяце.</div>
 </section>
</template>
<style scoped>
.partner-timesheets{padding:12px;min-height:420px}.partner-timesheets input,.partner-timesheets select{padding:7px;border:1px solid var(--irlix-color-border);border-radius:5px;background:var(--irlix-color-surface);color:inherit}.partner-hour-summary{display:flex;align-items:center;gap:14px;padding:14px 0}.partner-hour-summary span{margin-left:auto}.partner-hours-scroll{overflow:auto;max-height:calc(100vh - 260px)}.partner-hour-table{width:100%;border-collapse:collapse}.partner-hour-table th{position:sticky;top:0;background:var(--irlix-color-surface-muted)}.partner-hour-table th,.partner-hour-table td{padding:5px 10px;border-bottom:1px solid var(--irlix-color-border);text-align:left}.partner-hour-table td:nth-child(2){width:100px}.partner-hour-table td:nth-child(2) input{width:80px}.partner-hour-table td:nth-child(3) input{width:100%}.partner-hour-table .inactive{opacity:.45}.partner-hour-table .closed{opacity:.65}.partner-hour-error{padding:12px;color:#c23b3b}
</style>

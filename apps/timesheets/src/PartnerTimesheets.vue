<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { UiButton, UiFilterBar, UiSearchSelect, UiRegistryTable } from '@irlix/ui';
import { api } from './api';

const props = defineProps({ month: { type: String, required: true }, canManage: { type: Boolean, default: false } });
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const snapshot = ref({ members: [], entries: [], confirmations: [], approvals: [], exceptions: [], locked_periods: [] });
const memberId = ref('');
const filter = ref('');
const drafts = reactive({});
const monthDates = computed(() => {
  if(!/^\d{4}-(0[1-9]|1[0-2])$/.test(props.month))return [];
  const year = Number(props.month.slice(0,4)), monthIndex = Number(props.month.slice(5,7));
  const days = new Date(year,monthIndex,0).getDate();
  return Array.from({length:days},(_,index)=>props.month+'-'+String(index+1).padStart(2,'0'));
});
const members = computed(() => (snapshot.value.members || []).filter(item => 
  !filter.value || [item.client_name,item.specialist_name].some(s=>String(s||'').toLocaleLowerCase('ru').includes(filter.value.toLocaleLowerCase('ru')))
));
const memberOptions = computed(() => members.value.map(item => ({
  value:String(item.client_member_id), label:`${item.client_name} · ${item.specialist_name}`
})));
const selected = computed(() => members.value.find(item=>String(item.client_member_id)===String(memberId.value)) || null);
const entryMap = computed(() => new Map((snapshot.value.entries||[])
  .filter(x=>String(x.client_member_id)===String(memberId.value)).map(x=>[x.work_date,x])));
const confirmations = computed(()=>new Set((snapshot.value.confirmations||[])
  .filter(x=>String(x.client_member_id)===String(memberId.value)).map(x=>x.work_date)));
const monthApproved = computed(()=> (snapshot.value.approvals||[]).some(x=>String(x.client_member_id)===String(memberId.value)));
const exceptions = computed(()=>new Set((snapshot.value.exceptions||[])
  .filter(x=>String(x.client_member_id)===String(memberId.value)).map(x=>x.work_date)));
const lockedDates = computed(()=>new Set(monthDates.value.filter(date=>(snapshot.value.locked_periods||[])
  .some(p=>Number(p.client_id)===Number(selected.value?.client_id)
    && p.period_start<=date && p.period_end>=date))));
const active = date => (selected.value?.terms||[]).filter(term=>term.valid_from<=date && (!term.valid_to||term.valid_to>=date)).length===1;
const readonly = date => !props.canManage || !active(date) || lockedDates.value.has(date)
  || (monthApproved.value && !exceptions.value.has(date));
const rowHours = date => Number(drafts[date]?.hours ?? entryMap.value.get(date)?.hours ?? 0);
const rowDescription = date => String(drafts[date]?.description ?? entryMap.value.get(date)?.description ?? '');
const monthTotal = computed(()=>monthDates.value.reduce((sum,date)=>sum+rowHours(date),0));
function updateDraft(date,key,value){if(!drafts[date])drafts[date]={hours:String(entryMap.value.get(date)?.hours ?? '0'),description:String(entryMap.value.get(date)?.description ?? '')};drafts[date][key]=value;}
async function load(){
  if(!/^\d{4}-\d{2}$/.test(props.month))return;
  loading.value=true;error.value='';
  try {
    const response=await api('/api/timesheets/partners?month='+encodeURIComponent(props.month));
    snapshot.value=response.data||{members:[],entries:[],confirmations:[],approvals:[],exceptions:[],locked_periods:[]};
    const available=(snapshot.value.members||[]).map(m=>String(m.client_member_id));
    if(!available.includes(memberId.value))memberId.value=available[0]||'';
    Object.keys(drafts).forEach(key=>delete drafts[key]);
  }catch(e){error.value=e.message||String(e)}finally{loading.value=false;}
}
async function submit(url,body){
  saving.value=true;error.value='';notice.value='';
  try{
    await api(url,{method:url.endsWith('/entries')?'PUT':'POST',body});
    notice.value='Изменения сохранены';
    await load();
  }catch(e){error.value=e.message||String(e)}
  finally{saving.value=false;}
}
function save(date){const row=drafts[date];return submit('/api/timesheets/partners/entries',{
  client_member_id:Number(memberId.value),work_date:date,
  hours:row?.hours??entryMap.value.get(date)?.hours??0,
  description:row?.description??entryMap.value.get(date)?.description??null,
});}
function confirm(date){return submit('/api/timesheets/partners/confirm',{
  client_member_id:Number(memberId.value),work_date:date,
});}
function finalize(approved){return submit('/api/timesheets/partners/final-approval',{
  client_member_id:Number(memberId.value),month:props.month,approved,
});}
watch(()=>props.month,load,{immediate:true});
watch(memberId,()=>Object.keys(drafts).forEach(key=>delete drafts[key]));
</script>

<template>
  <div class="partner-timesheets">
    <div class="partner-timesheets__filters">
      <UiFilterBar>
        <input v-model="filter" type="search" class="registry-search" placeholder="Поиск по клиенту или специалисту" aria-label="Поиск партнёрского подключения"/>
        <UiSearchSelect v-model="memberId" :options="memberOptions" :clearable="false" placeholder="Партнёрский специалист" search-placeholder="Найти специалиста"/>
      </UiFilterBar>
      <span v-if="selected" class="partner-timesheets__total">Итого за месяц: {{ monthTotal.toFixed(2) }} ч</span>
    </div>
    <p v-if="loading" role="status">Загрузка партнёрских таймшитов…</p>
    <p v-else-if="error" role="alert" class="partner-timesheets__error">{{ error }}</p>
    <p v-if="notice" role="status">{{ notice }}</p>
    <template v-if="!loading && selected">
      <div class="partner-timesheets__approval">
        <span>Финальное подтверждение клиента: {{ monthApproved?'подтверждено':'не подтверждено' }}</span>
        <UiButton v-if="canManage" compact :disabled="saving || monthApproved" @click="finalize(true)">✓ Подтвердить месяц</UiButton>
        <UiButton v-if="canManage" compact variant="secondary" :disabled="saving || !monthApproved" @click="finalize(false)">× Снять подтверждение</UiButton>
      </div>
      <div class="partner-timesheets__scroll">
        <UiRegistryTable :columns="['130px','105px','1fr','145px','155px']" min-width="760px" density="compact"
          table-class="irlix-data-table" aria-label="Таймшиты партнёрского специалиста">
          <template #head><tr><th>Дата</th><th>Часы</th><th>Описание работы</th><th>Подтверждение</th><th>Действия</th></tr></template>
          <tr v-for="date in monthDates" :key="date">
            <td>{{ date.slice(8) }}.{{ date.slice(5,7) }}.{{ date.slice(0,4) }}</td>
            <td><input class="partner-timesheets__hours" type="number" min="0" max="24" step="0.25"
              :value="rowHours(date)" :disabled="readonly(date) || saving"
              :aria-label="'Часы '+date" @input="updateDraft(date,'hours',$event.target.value)" /></td>
            <td><input class="partner-timesheets__description" type="text" maxlength="4000"
              :value="rowDescription(date)" :disabled="readonly(date) || saving"
              :aria-label="'Описание '+date" @input="updateDraft(date,'description',$event.target.value)"/></td>
            <td>{{ !active(date)?'Нет условий':lockedDates.has(date)?'Отправлено клиенту':confirmations.has(date)?'Подтверждено':'Не подтверждено' }}</td>
            <td class="partner-timesheets__actions">
              <button type="button" :disabled="readonly(date)||saving" @click="save(date)">Сохранить</button>
              <button type="button" :disabled="readonly(date)||saving||confirmations.has(date)" @click="confirm(date)">✓ День</button>
            </td>
          </tr>
        </UiRegistryTable>
      </div>
    </template>
    <p v-else-if="!loading && !error" class="partner-timesheets__empty">
      Нет партнёрских подключений, доступных для управления. Добавьте специалиста к клиенту в Clients.
    </p>
  </div>
</template>

<style scoped>
.partner-timesheets { display:flex;flex-direction:column; min-height:0;height:100%;padding:10px 14px;gap:14px; }
.partner-timesheets__filters,.partner-timesheets__approval { display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap; }
.partner-timesheets__approval {border-bottom:1px solid var(--irlix-border-color,#dedede);padding:8px 0 12px;}
.partner-timesheets__total {font-weight:600;}
.partner-timesheets__scroll {overflow:auto;flex:1;min-height:0;}
.partner-timesheets__hours {width:75px;}.partner-timesheets__description {width:100%;box-sizing:border-box;min-width:140px;}
.partner-timesheets__hours,.partner-timesheets__description {font:inherit;padding:6px;border:1px solid var(--irlix-border-color,#ddd);border-radius:5px;background:transparent;color:inherit;}
.partner-timesheets__actions {white-space:nowrap;display:flex;gap:7px;}
.partner-timesheets__actions button {border:0;background:transparent;color:inherit;cursor:pointer;text-decoration:underline;}
.partner-timesheets__actions button:disabled {opacity:.4;cursor:not-allowed;}
.partner-timesheets__error {color:#b42318;}.partner-timesheets__empty {padding:16px;color:var(--irlix-text-muted,#888);}
</style>

<script setup>
import { computed,onMounted,ref,watch } from 'vue';
import { UiButton,UiFilterBar,UiPanel,UiSearchSelect } from '@irlix/ui';
import { api } from '../api';
import { formatDate,typeLabels } from '../constants';
import AbsenceActions from '../components/AbsenceActions.vue';
import AbsenceCreateModal from '../components/AbsenceCreateModal.vue';
import AbsenceTable from '../components/AbsenceTable.vue';

const props=defineProps({departments:{type:Array,default:()=>[]},employees:{type:Array,default:()=>[]},canCreateForEmployee:{type:Boolean,default:false},refreshToken:{type:Number,default:0}});
const emit=defineEmits(['action','error','changed']);
const now=new Date();
const month=ref(`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`),departmentId=ref(''),view=ref('calendar'),items=ref([]),loading=ref(false),showCreate=ref(false);
const departmentOptions=computed(()=>props.departments.map(d=>({value:d.id,label:d.name})));
const days=computed(()=>{const[y,m]=month.value.split('-').map(Number);return Array.from({length:new Date(y,m,0).getDate()},(_,i)=>i+1)});
const monthRange=computed(()=>{const[y,m]=month.value.split('-').map(Number);const end=new Date(y,m,0).getDate();return{from:`${month.value}-01`,to:`${month.value}-${String(end).padStart(2,'0')}`}});
const grouped=computed(()=>{const map=new Map();for(const a of items.value){const key=Number(a.employee_id);if(!map.has(key))map.set(key,{employee_id:key,employee_name:a.employee_name,department_name:a.department_name,absences:[]});map.get(key).absences.push(a)}return[...map.values()].sort((a,b)=>String(a.employee_name||'').localeCompare(String(b.employee_name||''),'ru'))});
const load=async()=>{loading.value=true;try{const params=new URLSearchParams({from:monthRange.value.from,to:monthRange.value.to});if(departmentId.value)params.set('department_id',departmentId.value);const p=await api(`/api/vacations/registry?${params}`);items.value=p.data||[]}catch(e){emit('error',e.message)}finally{loading.value=false}};
const created=async()=>{showCreate.value=false;await load();emit('changed')};
const activeOnDay=(a,day)=>{const date=`${month.value}-${String(day).padStart(2,'0')}`;return a.starts_on<=date&&(!a.ends_on||a.ends_on>=date)};
const dayItems=(g,d)=>g.absences.filter(a=>activeOnDay(a,d));
onMounted(load);
watch(()=>props.refreshToken,load);
watch([month,departmentId],load);
</script>

<template>
  <Teleport to="#vacations-topbar-actions">
    <UiButton v-if="canCreateForEmployee" compact @click="showCreate=true">+ Отсутствие сотруднику</UiButton>
  </Teleport>

  <UiPanel class="vacations-page-panel">
    <UiFilterBar class="vacations-filterbar department-filterbar">
      <input v-model="month" type="month" aria-label="Месяц"/>
      <UiSearchSelect v-model="departmentId" class="filter-department" :options="departmentOptions" placeholder="Все подразделения" search-placeholder="Поиск подразделения"/>
      <div class="irlix-segmented">
        <button type="button" class="irlix-segmented-item" :class="{active:view==='calendar'}" @click="view='calendar'">Календарь</button>
        <button type="button" class="irlix-segmented-item" :class="{active:view==='list'}" @click="view='list'">Список</button>
      </div>
    </UiFilterBar>

    <div v-if="loading" class="empty">Загрузка отсутствий подразделения…</div>
    <div v-else-if="!items.length" class="empty"><strong>Нет отсутствий за выбранный период</strong><span>Измените месяц или подразделение.</span></div>
    <AbsenceTable v-else-if="view==='list'" :items="items" show-employee show-progress @action="emit('action',$event)"/>
    <div v-else class="calendar-wrap">
      <table class="absence-calendar">
        <thead><tr><th class="calendar-person">Сотрудник</th><th v-for="day in days" :key="day">{{ day }}</th></tr></thead>
        <tbody><tr v-for="group in grouped" :key="group.employee_id"><td class="calendar-person"><strong>{{ group.employee_name||`#${group.employee_id}` }}</strong><small>{{ group.department_name||'—' }}</small></td><td v-for="day in days" :key="day" :class="{occupied:dayItems(group,day).length}"><div v-for="absence in dayItems(group,day)" :key="absence.id" class="calendar-absence" :title="`${typeLabels[absence.type]||absence.type}: ${formatDate(absence.starts_on)} — ${formatDate(absence.ends_on)}`" @click="emit('action',{action:'view',item:absence})"><span></span><AbsenceActions v-if="day===Number(String(absence.starts_on).slice(-2))||day===1" :actions="absence.available_actions||[]" @action="action=>emit('action',{action,item:absence})"/></div></td></tr></tbody>
      </table>
    </div>
  </UiPanel>

  <AbsenceCreateModal :open="showCreate" :employees="employees" for-employee title="Запланировать отсутствие сотруднику" eyebrow="ОТСУТСТВИЕ СОТРУДНИКА" @close="showCreate=false" @changed="created"/>
</template>
<script setup>
import { computed,onMounted,ref,watch } from 'vue';
import { UiButton,UiFilterBar,UiPanel,UiSearchSelect,UiViewSelect } from '@irlix/ui';
import { api } from '../api';
import { formatDate,typeLabels } from '../constants';
import AbsenceCreateModal from '../components/AbsenceCreateModal.vue';
import AbsenceTable from '../components/AbsenceTable.vue';

const props=defineProps({year:{type:Number,required:true},departments:{type:Array,default:()=>[]},employees:{type:Array,default:()=>[]},canCreateForEmployee:{type:Boolean,default:false},refreshToken:{type:Number,default:0}});
const emit=defineEmits(['action','error','changed']);
const currentMonth=new Date().getMonth()+1;
const activeMonth=ref(currentMonth),departmentId=ref(''),view=ref('calendar'),items=ref([]),loading=ref(false),showCreate=ref(false);

const buildDepartmentOptions=(departments)=>{
  const byParent=new Map();
  const ids=new Set(departments.map(item=>String(item.id)));
  for(const item of departments){
    const parent=item.parent_id!==null&&item.parent_id!==undefined&&ids.has(String(item.parent_id))?String(item.parent_id):'root';
    if(!byParent.has(parent))byParent.set(parent,[]);
    byParent.get(parent).push(item);
  }
  for(const children of byParent.values())children.sort((a,b)=>String(a.name||'').localeCompare(String(b.name||''),'ru'));
  const result=[];
  const walk=(parent,depth)=>{
    for(const item of byParent.get(parent)||[]){
      result.push({value:item.id,label:item.name,depth});
      walk(String(item.id),depth+1);
    }
  };
  walk('root',0);
  return result;
};
const departmentOptions=computed(()=>buildDepartmentOptions(props.departments));
const viewOptions=[{value:'calendar',label:'Календарь'},{value:'list',label:'Список'}];
const yearValue=computed(()=>Number(props.year));
const yearRange=computed(()=>({from:`${yearValue.value}-01-01`,to:`${yearValue.value}-12-31`}));
const monthBounds=(monthIndex)=>{const mm=String(monthIndex).padStart(2,'0');const last=new Date(yearValue.value,monthIndex,0).getDate();return{from:`${yearValue.value}-${mm}-01`,to:`${yearValue.value}-${mm}-${String(last).padStart(2,'0')}`}};
const monthKey=computed(()=>`${yearValue.value}-${String(activeMonth.value).padStart(2,'0')}`);
const days=computed(()=>Array.from({length:new Date(yearValue.value,activeMonth.value,0).getDate()},(_,i)=>i+1));
const overlaps=(item,from,to)=>item.starts_on<=to&&(!item.ends_on||item.ends_on>=from);
const visibleItems=computed(()=>{const b=monthBounds(activeMonth.value);return items.value.filter(item=>overlaps(item,b.from,b.to))});
const grouped=computed(()=>{const map=new Map();for(const a of visibleItems.value){const key=Number(a.employee_id);if(!map.has(key))map.set(key,{employee_id:key,employee_name:a.employee_name,department_name:a.department_name,absences:[]});map.get(key).absences.push(a)}return[...map.values()].sort((a,b)=>String(a.employee_name||'').localeCompare(String(b.employee_name||''),'ru'))});
const departmentEmployeeCount=computed(()=>props.employees.filter(e=>!departmentId.value||String(e.department_id??'')===String(departmentId.value)).length);
const parseDate=(value)=>{const[y,m,d]=String(value).slice(0,10).split('-').map(Number);return new Date(Date.UTC(y,m-1,d))};
const workingDaysBetween=(from,to)=>{let cursor=parseDate(from),end=parseDate(to),count=0;while(cursor<=end){const day=cursor.getUTCDay();if(day!==0&&day!==6)count++;cursor=new Date(cursor.getTime()+86400000)}return count};
const absenceHoursInMonth=(item,monthIndex)=>{if(['rejected','cancelled'].includes(item.status))return 0;const b=monthBounds(monthIndex);if(!overlaps(item,b.from,b.to))return 0;const from=item.starts_on>b.from?item.starts_on:b.from;const endValue=item.ends_on||b.to;const to=endValue<b.to?endValue:b.to;return workingDaysBetween(from,to)*8};
const workingDaysInMonth=(monthIndex)=>{const b=monthBounds(monthIndex);return workingDaysBetween(b.from,b.to)};
const monthCards=computed(()=>Array.from({length:12},(_,index)=>{const month=index+1,totalHours=departmentEmployeeCount.value*workingDaysInMonth(month)*8,absenceHours=items.value.reduce((sum,item)=>sum+absenceHoursInMonth(item,month),0);return{month,label:`${String(month).padStart(2,'0')}.${yearValue.value}`,absenceHours,totalHours,percent:totalHours>0?Math.round(absenceHours/totalHours*100):0}}));
const load=async()=>{loading.value=true;try{const params=new URLSearchParams({from:yearRange.value.from,to:yearRange.value.to});if(departmentId.value)params.set('department_id',departmentId.value);const p=await api(`/api/vacations/registry?${params}`);items.value=p.data||[]}catch(e){emit('error',e.message)}finally{loading.value=false}};
const created=async()=>{showCreate.value=false;await load();emit('changed')};
const dayDate=day=>`${monthKey.value}-${String(day).padStart(2,'0')}`;
const activeOnDay=(a,day)=>{const date=dayDate(day);return a.starts_on<=date&&(!a.ends_on||a.ends_on>=date)};
const dayItems=(g,d)=>g.absences.filter(a=>activeOnDay(a,d));
const isVisualStart=(a,day)=>day===1||a.starts_on===dayDate(day);
const isVisualEnd=(a,day)=>day===days.value.length||a.ends_on===dayDate(day);
const absenceTone=a=>({paid_vacation:'vacation',sick_leave:'medical',maternity_leave:'medical',day_off:'neutral',unpaid_vacation:'neutral'}[a.type]||'neutral');
onMounted(load);
watch(()=>props.refreshToken,load);
watch([()=>props.year,departmentId],load);
</script>

<template>
  <Teleport to="#vacations-breadcrumb-extra">
    <span class="irlix-breadcrumbs__separator" aria-hidden="true">—</span>
    <UiViewSelect v-model="view" :options="viewOptions" aria-label="Режим отображения"/>
  </Teleport>
  <Teleport to="#vacations-topbar-actions"><UiButton v-if="canCreateForEmployee" compact @click="showCreate=true">+ Отсутствие сотруднику</UiButton></Teleport>

  <UiPanel class="vacations-page-panel">
    <UiFilterBar class="vacations-filterbar department-filterbar">
      <UiSearchSelect v-model="departmentId" class="filter-department" :options="departmentOptions" placeholder="Все подразделения" search-placeholder="Поиск подразделения"/>
    </UiFilterBar>
    <div class="month-cards" aria-label="Фильтр по месяцам">
      <button v-for="card in monthCards" :key="card.month" type="button" class="month-card" :class="{active:activeMonth===card.month}" @click="activeMonth=card.month">
        <span>{{ card.label }}</span><strong>{{ card.percent }}%</strong>
        <small><b>Отсутствия:</b><em>{{ card.absenceHours }}ч</em></small>
        <small><b>Всего:</b><em>{{ card.totalHours }}ч</em></small>
      </button>
    </div>
    <div v-if="loading" class="empty">Загрузка отсутствий подразделения…</div>
    <div v-else-if="!visibleItems.length" class="empty"><strong>Нет отсутствий за выбранный период</strong><span>Измените месяц или подразделение.</span></div>
    <AbsenceTable v-else-if="view==='list'" :items="visibleItems" show-employee show-progress @action="emit('action',$event)"/>
    <div v-else class="calendar-wrap"><table class="absence-calendar"><thead><tr><th class="calendar-person">Сотрудник</th><th v-for="day in days" :key="day">{{ day }}</th></tr></thead><tbody><tr v-for="group in grouped" :key="group.employee_id"><td class="calendar-person"><strong>{{ group.employee_name||`#${group.employee_id}` }}</strong><small>{{ group.department_name||'—' }}</small></td><td v-for="day in days" :key="day"><div v-for="absence in dayItems(group,day)" :key="absence.id" class="calendar-absence" :class="[`calendar-absence--${absenceTone(absence)}`,{'calendar-absence--start':isVisualStart(absence,day),'calendar-absence--end':isVisualEnd(absence,day)}]" :title="`${typeLabels[absence.type]||absence.type}: ${formatDate(absence.starts_on)} — ${formatDate(absence.ends_on)}`" @click="emit('action',{action:'view',item:absence})"><span></span></div></td></tr></tbody></table></div>
  </UiPanel>

  <AbsenceCreateModal :open="showCreate" :employees="employees" for-employee title="Запланировать отсутствие сотруднику" eyebrow="ОТСУТСТВИЕ СОТРУДНИКА" @close="showCreate=false" @changed="created"/>
</template>

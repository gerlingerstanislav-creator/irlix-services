<script setup>
import { computed,onMounted,ref,watch } from 'vue';
import { UiButton,UiFilterBar,UiPanel,UiSearchSelect,UiViewSelect, UiLoader } from '@irlix/ui';
import { api } from '../api';
import { visibleDepartmentEmployees, departmentAbsenceGroups, departmentAbsenceListItems, monthEmploymentHours } from '../departmentAbsenceRoster';
import { formatDate,typeLabels } from '../constants';
import AbsenceCreateModal from '../components/AbsenceCreateModal.vue';
import AbsenceTable from '../components/AbsenceTable.vue';

const props=defineProps({year:{type:Number,required:true},departments:{type:Array,default:()=>[]},employees:{type:Array,default:()=>[]},canCreateForEmployee:{type:Boolean,default:false},refreshToken:{type:Number,default:0}});
const emit=defineEmits(['action','error','changed']);
const currentMonth=new Date().getMonth()+1;
const activeMonth=ref(currentMonth),departmentIds=ref([]),search=ref(''),view=ref('calendar'),items=ref([]),historicalEmployees=ref([]),historicalDepartments=ref([]),productionCalendar=ref({}),loading=ref(false),showCreate=ref(false),hoveredAbsenceId=ref(null);

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
const departmentOptions=computed(()=>buildDepartmentOptions(historicalDepartments.value));
watch(departmentOptions,(options)=>{
  const allowed=new Set(options.map(option=>String(option.value)));
  // With exactly one authorized department there is nothing to choose or clear.
  const next=options.length===1?[options[0].value]:departmentIds.value.filter(id=>allowed.has(String(id)));
  if(next.length!==departmentIds.value.length||next.some((id,i)=>String(id)!==String(departmentIds.value[i])))departmentIds.value=next;
},{immediate:true});
const departmentSelection=computed({
  get:()=>departmentOptions.value.length===1?(departmentIds.value[0]??''):departmentIds.value,
  set:(value)=>{departmentIds.value=Array.isArray(value)?value:(value===''||value==null?[]:[value])},
});
const viewOptions=[{value:'calendar',label:'Календарь'},{value:'list',label:'Список'}];
const yearValue=computed(()=>Number(props.year));
const yearRange=computed(()=>({from:`${yearValue.value}-01-01`,to:`${yearValue.value}-12-31`}));
const monthBounds=(monthIndex)=>{const mm=String(monthIndex).padStart(2,'0');const last=new Date(yearValue.value,monthIndex,0).getDate();return{from:`${yearValue.value}-${mm}-01`,to:`${yearValue.value}-${mm}-${String(last).padStart(2,'0')}`}};
const monthKey=computed(()=>`${yearValue.value}-${String(activeMonth.value).padStart(2,'0')}`);
const days=computed(()=>Array.from({length:new Date(yearValue.value,activeMonth.value,0).getDate()},(_,i)=>i+1));
const overlaps=(item,from,to)=>item.starts_on<=to&&(!item.ends_on||item.ends_on>=from);
const yearRoster=computed(()=>visibleDepartmentEmployees(historicalEmployees.value,departmentIds.value,search.value,yearRange.value.from,yearRange.value.to));
const roster=computed(()=>{const b=monthBounds(activeMonth.value);return visibleDepartmentEmployees(historicalEmployees.value,departmentIds.value,search.value,b.from,b.to)});
const rosterIds=computed(()=>new Set(yearRoster.value.map(employee=>Number(employee.id))));
const matchingItems=computed(()=>items.value.filter(item=>rosterIds.value.has(Number(item.employee_id))));
const visibleItems=computed(()=>{
  const b=monthBounds(activeMonth.value), inMonth=new Map(roster.value.map(person=>[Number(person.id),person]));
  return matchingItems.value.filter(item=>{
    const person=inMonth.get(Number(item.employee_id));
    return person&&overlaps(item,b.from,b.to)&&person.employment_segments.some(segment=>
      overlaps(item,segment.from,segment.to));
  });
});
const grouped=computed(()=>{const b=monthBounds(activeMonth.value);return departmentAbsenceGroups(roster.value,visibleItems.value,b.from,b.to)});
const listItems=computed(()=>departmentAbsenceListItems(grouped.value));
const parseDate=(value)=>{const[y,m,d]=String(value).slice(0,10).split('-').map(Number);return new Date(Date.UTC(y,m-1,d))};
const isWorkingDate=(value)=>{const key=String(value).slice(0,10),info=productionCalendar.value[key];if(info)return Boolean(info.is_working);const day=parseDate(key).getUTCDay();return day!==0&&day!==6};
const workingDaysBetween=(from,to)=>{let cursor=parseDate(from),end=parseDate(to),count=0;while(cursor<=end){const key=cursor.toISOString().slice(0,10);if(isWorkingDate(key))count++;cursor=new Date(cursor.getTime()+86400000)}return count};
const monthCards=computed(()=>Array.from({length:12},(_,index)=>{
  const month=index+1,b=monthBounds(month),staff=visibleDepartmentEmployees(historicalEmployees.value,departmentIds.value,search.value,b.from,b.to);
  const {absenceHours,totalHours}=monthEmploymentHours(staff,matchingItems.value,b.from,b.to,isWorkingDate);
  return{month,label:`${String(month).padStart(2,'0')}.${yearValue.value}`,absenceHours,totalHours,percent:totalHours>0?Math.round(absenceHours/totalHours*100):0};
}));
const loadProductionCalendar=async()=>{const year=yearValue.value;try{const p=await api(`/api/vacations/production-calendar?year=${year}`);if(year===yearValue.value)productionCalendar.value=p.data?.days||{}}catch(e){if(year===yearValue.value){productionCalendar.value={};emit('error',e.message)}}};
let lastRosterRequest=0;
const load=async()=>{
  const requestId=++lastRosterRequest;
  loading.value=true;
  try{
    const year=yearValue.value;
    const params=new URLSearchParams({from:yearRange.value.from,to:yearRange.value.to,historical_year:String(year)});
    const [directory,absenceRegistry]=await Promise.all([
      api(`/api/vacations/department-roster?year=${year}`),
      api(`/api/vacations/registry?${params}`),
    ]);
    if(requestId!==lastRosterRequest||year!==yearValue.value)return;
    historicalEmployees.value=directory.data?.employees||[];
    historicalDepartments.value=directory.data?.departments||[];
    items.value=absenceRegistry.data||[];
  }catch(e){
    if(requestId===lastRosterRequest){historicalEmployees.value=[];historicalDepartments.value=[];items.value=[];emit('error',e.message)}
  }finally{if(requestId===lastRosterRequest)loading.value=false}
};
const created=async()=>{showCreate.value=false;await load();emit('changed')};
const isNonWorkingDay=day=>{const key=dayDate(day),info=productionCalendar.value[key];if(info)return !info.is_working;const weekday=parseDate(key).getUTCDay();return weekday===0||weekday===6};
const dayTitle=day=>{const info=productionCalendar.value[dayDate(day)];if(!info)return isNonWorkingDay(day)?'Выходной день':'';if(info.is_holiday)return 'Праздничный день';if(info.is_day_off)return 'Выходной день';if(info.is_short)return 'Сокращённый рабочий день';return ''};
const dayDate=day=>`${monthKey.value}-${String(day).padStart(2,'0')}`;
const activeOnDay=(a,day)=>{const date=dayDate(day);return a.starts_on<=date&&(!a.ends_on||a.ends_on>=date)};
const dayItems=(g,d)=>g.absences.filter(a=>activeOnDay(a,d)&&g.employment_segments.some(segment=>segment.from<=dayDate(d)&&segment.to>=dayDate(d)));
const isVisualStart=(g,a,day)=>day===1||a.starts_on===dayDate(day)||!g.employment_segments.some(segment=>segment.from<=dayDate(day-1)&&segment.to>=dayDate(day-1));
const isVisualEnd=(g,a,day)=>day===days.value.length||a.ends_on===dayDate(day)||!g.employment_segments.some(segment=>segment.from<=dayDate(day+1)&&segment.to>=dayDate(day+1));
const shortName=(name)=>{const parts=String(name||'').trim().split(/\s+/);return parts.length>2?parts.slice(0,2).join(' '):String(name||'')};
const absenceTone=a=>({paid_vacation:'vacation',sick_leave:'medical',maternity_leave:'medical',day_off:'neutral',unpaid_vacation:'neutral'}[a.type]||'neutral');
const clearHoveredAbsence=id=>{if(hoveredAbsenceId.value===id)hoveredAbsenceId.value=null;};
onMounted(()=>{load();loadProductionCalendar()});
watch(()=>props.refreshToken,load);
watch(()=>props.year,()=>{load();loadProductionCalendar()});

</script>

<template>
  <Teleport to="#vacations-breadcrumb-extra">
    <span class="irlix-breadcrumbs__separator" aria-hidden="true">—</span>
    <UiViewSelect v-model="view" :options="viewOptions" aria-label="Режим отображения"/>
  </Teleport>
  <Teleport to="#vacations-topbar-actions"><UiButton v-if="canCreateForEmployee" compact @click="showCreate=true">+ Отсутствие сотруднику</UiButton></Teleport>

  <UiPanel class="vacations-page-panel">
    <UiFilterBar class="vacations-filterbar department-filterbar">
      <input v-model="search" type="search" placeholder="Поиск по ФИО" aria-label="Поиск по ФИО"/>
      <UiSearchSelect v-model="departmentSelection" class="filter-department" :options="departmentOptions"
        :disabled="departmentOptions.length<=1" :clearable="departmentOptions.length>1"
        :multiple="departmentOptions.length>1" placeholder="Все подразделения" search-placeholder="Поиск подразделения" aria-label="Подразделения"/>
    </UiFilterBar>
    <div class="month-cards" aria-label="Фильтр по месяцам">
      <button v-for="card in monthCards" :key="card.month" type="button" class="month-card" :class="{active:activeMonth===card.month}" @click="activeMonth=card.month">
        <span>{{ card.label }}</span><strong>{{ card.percent }}%</strong>
        <small class="month-card-hours" :title="`Отсутствия: ${card.absenceHours} ч.; всего: ${card.totalHours} ч.`">{{ card.absenceHours }} / {{ card.totalHours }} ч.</small>
      </button>
    </div>
    <UiLoader v-if="loading" page label="Загрузка отсутствий подразделения" />
    <div v-else-if="!grouped.length" class="empty"><strong>Нет сотрудников, работавших в выбранном месяце</strong><span>Измените месяц, подразделение или поисковый запрос.</span></div>
    <AbsenceTable v-else-if="view==='list'" :items="listItems" show-employee show-progress @action="emit('action',$event)"/>
    <div v-else class="calendar-wrap"><table class="absence-calendar"><thead><tr><th class="calendar-person">Сотрудник</th><th v-for="day in days" :key="day" :class="{'calendar-non-working':isNonWorkingDay(day)}" :title="dayTitle(day)">{{ day }}</th></tr></thead><tbody><tr v-for="group in grouped" :key="group.employee_id"><td class="calendar-person"><strong>{{ shortName(group.employee_name)||`#${group.employee_id}` }}</strong><small>{{ group.department_name||'—' }}</small></td><td v-for="day in days" :key="day" :class="{'calendar-non-working':isNonWorkingDay(day)}" :title="dayTitle(day)"><div v-for="absence in dayItems(group,day)" :key="absence.id" class="calendar-absence" :class="[`calendar-absence--${absenceTone(absence)}`,{'calendar-absence--start':isVisualStart(group,absence,day),'calendar-absence--end':isVisualEnd(group,absence,day),'calendar-absence--hovered':hoveredAbsenceId===absence.id}]" @mouseenter="hoveredAbsenceId=absence.id" @mouseleave="clearHoveredAbsence(absence.id)" :title="`${typeLabels[absence.type]||absence.type}: ${formatDate(absence.starts_on)} — ${formatDate(absence.ends_on)}`" @click="emit('action',{action:'view',item:absence})"><span></span></div></td></tr></tbody></table></div>
  </UiPanel>

  <AbsenceCreateModal :open="showCreate" :employees="employees" for-employee title="Запланировать отсутствие сотруднику" eyebrow="ОТСУТСТВИЕ СОТРУДНИКА" @close="showCreate=false" @changed="created"/>
</template>

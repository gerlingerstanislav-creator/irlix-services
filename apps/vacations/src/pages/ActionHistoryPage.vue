<script setup>
import { computed,onMounted,ref,watch } from 'vue';
import { UiButton,UiFilterBar,UiPanel,UiSearchSelect } from '@irlix/ui';
import { api } from '../api';
import { auditLabels,formatDate,formatDateTime,typeLabels } from '../constants';
import AbsenceActions from '../components/AbsenceActions.vue';

const props=defineProps({year:{type:Number,required:true},employees:{type:Array,default:()=>[]},refreshToken:{type:Number,default:0}});
const emit=defineEmits(['action','error']);
const items=ref([]),loading=ref(false),filters=ref({employee_id:'',event:'',from:'',to:''});
const employeeOptions=computed(()=>{
  const grouped=new Map();
  for(const employee of props.employees){
    const group=employee.department_name||'Без подразделения';
    if(!grouped.has(group))grouped.set(group,[]);
    grouped.get(group).push(employee);
  }
  const options=[];
  for(const [group,employees] of [...grouped.entries()].sort((a,b)=>a[0].localeCompare(b[0],'ru'))){
    options.push({kind:'group',value:`group-${group}`,label:group,depth:0});
    for(const employee of employees.sort((a,b)=>String(a.full_name||'').localeCompare(String(b.full_name||''),'ru')))options.push({value:employee.id,label:employee.full_name||`#${employee.id}`,depth:1});
  }
  return options;
});
const eventOptions=computed(()=>Object.entries(auditLabels).map(([value,label])=>({value,label})));
const load=async()=>{
  loading.value=true;
  try{
    const params=new URLSearchParams();
    params.set('from',filters.value.from||`${props.year}-01-01`);
    params.set('to',filters.value.to||`${props.year}-12-31`);
    if(filters.value.employee_id)params.set('employee_id',filters.value.employee_id);
    if(filters.value.event)params.set('event',filters.value.event);
    const payload=await api(`/api/vacations/history?${params}`);
    items.value=payload.data||[];
  }catch(error){emit('error',error.message)}
  finally{loading.value=false}
};
const reset=()=>{filters.value={employee_id:'',event:'',from:'',to:''};load()};
onMounted(load);
watch(()=>props.refreshToken,load);
watch(()=>props.year,()=>{filters.value.from='';filters.value.to='';load()});
</script>

<template>
  <UiPanel class="vacations-page-panel">
    <UiFilterBar class="vacations-filterbar history-filterbar">
      <UiSearchSelect v-if="employees.length" v-model="filters.employee_id" class="filter-employee" :options="employeeOptions" placeholder="Все сотрудники" search-placeholder="Поиск сотрудника"/>
      <UiSearchSelect v-model="filters.event" class="filter-event" :options="eventOptions" placeholder="Все действия" search-placeholder="Поиск действия"/>
      <input v-model="filters.from" type="date" aria-label="С"/>
      <input v-model="filters.to" type="date" aria-label="По"/>
      <UiButton compact @click="load">Применить</UiButton>
      <UiButton compact variant="secondary" @click="reset">Сбросить</UiButton>
    </UiFilterBar>

    <div v-if="loading" class="empty">Загрузка истории…</div>
    <div v-else-if="!items.length" class="empty"><strong>История пуста</strong><span>Для выбранных условий действий не найдено.</span></div>
    <div v-else class="table-wrap">
      <table class="irlix-data-table history-table">
        <thead><tr><th>Дата</th><th>Сотрудник</th><th>Отсутствие</th><th>Действие</th><th>Автор</th><th></th></tr></thead>
        <tbody><tr v-for="item in items" :key="item.id" class="clickable-row" @click="emit('action',{action:'view',item:{id:item.absence_id}})">
          <td>{{ formatDateTime(item.created_at) }}</td>
          <td><strong>{{ item.employee_name||`#${item.employee_id}` }}</strong><small>{{ item.department_name||'—' }}</small></td>
          <td><strong>{{ typeLabels[item.type]||item.type }}</strong><small>{{ formatDate(item.starts_on) }} — {{ formatDate(item.ends_on) }}</small></td>
          <td>{{ auditLabels[item.event]||item.event }}</td>
          <td>{{ item.actor_name||item.actor_subject||'Система' }}</td>
          <td class="actions-cell" @click.stop><AbsenceActions :actions="['view','history']" @action="action=>emit('action',{action,item:{id:item.absence_id}})"/></td>
        </tr></tbody>
      </table>
    </div>
  </UiPanel>
</template>
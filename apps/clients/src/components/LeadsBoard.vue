<script setup>
import { computed, ref } from 'vue';
import { UiBadge, UiFilterBar, UiSearchSelect } from '@irlix/ui';

const props=defineProps({leads:{type:Array,default:()=>[]},employees:{type:Array,default:()=>[]},statuses:{type:Array,default:()=>[]}});
const emit=defineEmits(['open']);
const query=ref(''),responsible=ref('');
const employeeMap=computed(()=>new Map(props.employees.map(item=>[Number(item.id),item.full_name||`#${item.id}`])));
const employeeName=id=>employeeMap.value.get(Number(id))||(id?`#${id}`:'—');
const employeeOptions=computed(()=>props.employees.map(item=>({value:String(item.id),label:item.full_name||`#${item.id}`})).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const visible=computed(()=>props.leads.filter(lead=>{
  const needle=query.value.trim().toLocaleLowerCase('ru');
  return (!needle||[lead.name,lead.source,employeeName(lead.responsible_employee_id)].join(' ').toLocaleLowerCase('ru').includes(needle))
    &&(!responsible.value||String(lead.responsible_employee_id)===String(responsible.value));
}));
const tone=status=>status.includes('Отказ')?'danger':status.includes('Успех')?'success':'info';
</script>

<template>
  <section class="leads-board-view">
    <UiFilterBar><input v-model="query" class="registry-search" type="search" placeholder="Поиск лида"><UiSearchSelect v-model="responsible" :options="employeeOptions" placeholder="Ответственные" search-placeholder="Поиск ответственного"/></UiFilterBar>
    <div class="leads-kanban">
      <section v-for="status in statuses" :key="status" class="lead-column">
        <header><span>{{status}}</span><b>{{visible.filter(item=>item.status===status).length}}</b></header>
        <button v-for="lead in visible.filter(item=>item.status===status)" :key="lead.id" type="button" class="lead-card" @click="emit('open',lead)">
          <strong>{{lead.name}}</strong>
          <dl><div><dt>Источник</dt><dd>{{lead.source||'—'}}</dd></div><div><dt>Ответственный</dt><dd>{{employeeName(lead.responsible_employee_id)}}</dd></div><div><dt>Запросы</dt><dd>{{lead.request_count||0}}</dd></div></dl>
          <UiBadge :tone="tone(lead.status)">{{lead.status}}</UiBadge>
        </button>
      </section>
    </div>
  </section>
</template>

<style scoped>
.leads-board-view{min-width:0}.leads-kanban{display:grid;grid-template-columns:repeat(8,minmax(220px,1fr));gap:10px;overflow:auto;padding:12px 16px}.lead-column{min-height:560px;padding:10px;border-radius:10px;background:#f4f6f7}.lead-column>header{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px;color:#49535d;font-size:12px;font-weight:600}.lead-column>header b{display:grid;place-items:center;min-width:22px;height:22px;border-radius:6px;background:#e4e8eb;color:#68727c;font-size:11px}.lead-card{display:grid;width:100%;gap:10px;margin-bottom:8px;padding:12px;border:0;border-radius:10px;background:#fff;box-shadow:0 1px 4px rgba(25,35,45,.09);color:#2b333b;text-align:left;cursor:pointer}.lead-card:hover{box-shadow:0 4px 14px rgba(25,35,45,.12)}.lead-card>strong{font-size:14px}.lead-card dl{display:grid;gap:6px;margin:0}.lead-card dl div{display:flex;justify-content:space-between;gap:8px}.lead-card dt{color:#8a929b;font-size:10px}.lead-card dd{margin:0;overflow:hidden;color:#56606a;font-size:11px;text-align:right;text-overflow:ellipsis;white-space:nowrap}.lead-card :deep(.irlix-badge),.lead-card :deep(.ui-badge){justify-self:start}
</style>

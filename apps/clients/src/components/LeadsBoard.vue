<script setup>
import { computed, ref } from 'vue';
import { UiBadge, UiFilterBar, UiSearchSelect } from '@irlix/ui';

const props=defineProps({leads:{type:Array,default:()=>[]},employees:{type:Array,default:()=>[]},statuses:{type:Array,default:()=>[]},canManage:Boolean});
const emit=defineEmits(['open','move']);
const query=ref(''),responsible=ref(''),draggedId=ref(null),dropStatus=ref('');
const employeeMap=computed(()=>new Map(props.employees.map(item=>[Number(item.id),item.full_name||`#${item.id}`])));
const employeeName=id=>employeeMap.value.get(Number(id))||(id?`#${id}`:'—');
const employeeOptions=computed(()=>props.employees.map(item=>({value:String(item.id),label:item.full_name||`#${item.id}`})).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const visible=computed(()=>props.leads.filter(lead=>{
  const needle=query.value.trim().toLocaleLowerCase('ru');
  return (!needle||[lead.name,lead.source,employeeName(lead.responsible_employee_id)].join(' ').toLocaleLowerCase('ru').includes(needle))
    &&(!responsible.value||String(lead.responsible_employee_id)===String(responsible.value));
}));
const tone=status=>status.includes('Отказ')?'danger':status.includes('Успех')?'success':'info';
function dragStart(event,lead){if(!props.canManage){event.preventDefault();return;}draggedId.value=Number(lead.id);event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',String(lead.id));}
function dragEnd(){draggedId.value=null;dropStatus.value='';}
function dragOver(event,status){if(!props.canManage)return;event.preventDefault();event.dataTransfer.dropEffect='move';dropStatus.value=status;}
function drop(event,status){if(!props.canManage)return;event.preventDefault();const id=Number(event.dataTransfer.getData('text/plain')||draggedId.value);const lead=props.leads.find(item=>Number(item.id)===id);dragEnd();if(lead&&lead.status!==status)emit('move',{lead,status});}
</script>

<template>
  <section class="leads-board-view">
    <UiFilterBar><input v-model="query" class="registry-search" type="search" placeholder="Поиск лида"><UiSearchSelect v-model="responsible" :options="employeeOptions" placeholder="Ответственные" search-placeholder="Поиск ответственного"/></UiFilterBar>
    <div class="leads-kanban">
      <section v-for="status in statuses" :key="status" class="lead-column" :class="{'lead-column--drop':dropStatus===status}" @dragover="dragOver($event,status)" @dragleave.self="dropStatus=''" @drop="drop($event,status)">
        <header><span>{{status}}</span><b>{{visible.filter(item=>item.status===status).length}}</b></header>
        <button v-for="lead in visible.filter(item=>item.status===status)" :key="lead.id" type="button" class="lead-card" :class="{'lead-card--dragging':draggedId===Number(lead.id)}" :draggable="canManage" @dragstart="dragStart($event,lead)" @dragend="dragEnd" @click="emit('open',lead)">
          <strong>{{lead.name}}</strong>
          <dl><div><dt>Источник</dt><dd>{{lead.source||'—'}}</dd></div><div><dt>Ответственный</dt><dd>{{employeeName(lead.responsible_employee_id)}}</dd></div><div><dt>Запросы</dt><dd>{{lead.request_count||0}}</dd></div></dl>
          <UiBadge :tone="tone(lead.status)">{{lead.status}}</UiBadge>
        </button>
      </section>
    </div>
  </section>
</template>

<style scoped>
.leads-board-view{min-width:0}.leads-kanban{display:grid;grid-template-columns:repeat(8,minmax(220px,1fr));gap:10px;overflow:auto;padding:12px 16px}.lead-column{min-height:560px;padding:10px;border-radius:10px;background:var(--irlix-color-surface-muted);transition:background .14s ease,box-shadow .14s ease}.lead-column--drop{background:var(--irlix-color-surface-muted);box-shadow:inset 0 0 0 2px var(--irlix-color-shadow-base)}.lead-column>header{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px;color:var(--irlix-color-text);font-size:var(--irlix-font-size-caption);font-weight:600}.lead-column>header b{display:grid;place-items:center;min-width:22px;height:22px;border-radius:6px;background:var(--irlix-color-surface-muted);color:var(--irlix-color-text);font-size:var(--irlix-font-size-caption)}.lead-card{display:grid;width:100%;gap:10px;margin-bottom:8px;padding:12px;border:0;border-radius:10px;background:var(--irlix-color-surface);box-shadow:0 1px 4px color-mix(in srgb, var(--irlix-color-shadow-base) 9%, transparent);color:var(--irlix-color-text);text-align:left;cursor:pointer}.lead-card[draggable=true]{cursor:grab}.lead-card--dragging{opacity:.42}.lead-card:hover{box-shadow:0 4px 14px color-mix(in srgb, var(--irlix-color-shadow-base) 12%, transparent)}.lead-card>strong{font-size:var(--irlix-font-size-body)}.lead-card dl{display:grid;gap:6px;margin:0}.lead-card dl div{display:flex;justify-content:space-between;gap:8px}.lead-card dt{color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}.lead-card dd{margin:0;overflow:hidden;color:var(--irlix-color-text);font-size:var(--irlix-font-size-caption);text-align:right;text-overflow:ellipsis;white-space:nowrap}.lead-card:deep(.irlix-badge),.lead-card :deep(.ui-badge){justify-self:start}
</style>

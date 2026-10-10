<script setup>
import { computed, ref, watch } from 'vue';
import { UiFilterBar, UiSearchSelect, UiKanbanBoard } from '@irlix/ui';

const props=defineProps({leads:{type:Array,default:()=>[]},employees:{type:Array,default:()=>[]},statuses:{type:Array,default:()=>[]},canManage:Boolean});
const emit=defineEmits(['open','move']);
const leadColumns = ref(null);
watch(() => props.statuses, statuses => { if (leadColumns.value === null && statuses.length) leadColumns.value = statuses.filter(status => !status.startsWith('Сделка закрыта')); }, { immediate: true });
const visibleLeadColumns = computed(() => props.statuses.filter(status => (leadColumns.value || []).includes(status)));
const query=ref(''),responsible=ref(''),draggedId=ref(null),dropStatus=ref('');
const employeeMap=computed(()=>new Map(props.employees.map(item=>[Number(item.id),item.full_name||`#${item.id}`])));
const employeeName=id=>employeeMap.value.get(Number(id))||(id?`#${id}`:'—');
const employeeOptions=computed(()=>props.employees.map(item=>({value:String(item.id),label:item.full_name||`#${item.id}`})).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const visible=computed(()=>props.leads.filter(lead=>{
  const needle=query.value.trim().toLocaleLowerCase('ru');
  return (!needle||[lead.name,lead.source,employeeName(lead.responsible_employee_id)].join(' ').toLocaleLowerCase('ru').includes(needle))
    &&(!responsible.value||String(lead.responsible_employee_id)===String(responsible.value));
}));
function dragStart(event,lead){if(!props.canManage){event.preventDefault();return;}draggedId.value=Number(lead.id);event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',String(lead.id));}
function dragEnd(){draggedId.value=null;dropStatus.value='';}
function dragOver(event,status){if(!props.canManage)return;event.preventDefault();event.dataTransfer.dropEffect='move';dropStatus.value=status;}
function drop(event,status){if(!props.canManage)return;event.preventDefault();const id=Number(event.dataTransfer.getData('text/plain')||draggedId.value);const lead=props.leads.find(item=>Number(item.id)===id);dragEnd();if(lead&&lead.status!==status)emit('move',{lead,status});}
</script>

<template>
  <section class="leads-board-view">
    <UiFilterBar class="irlix-kanban-filters"><input v-model="query" class="registry-search" type="search" placeholder="Поиск лида"><UiSearchSelect v-model="responsible" :options="employeeOptions" placeholder="Ответственные" search-placeholder="Поиск ответственного"/><UiSearchSelect v-model="leadColumns" :options="statuses" placeholder="Столбцы" multiple/></UiFilterBar>
    <UiKanbanBoard class="leads-kanban" :columns="visibleLeadColumns" :items="visible" :active-target="dropStatus" label="Канбан лидов" @dragover="dragOver" @drop="drop" @dragleave="dropStatus=''">
      <template #cards="{items}">
        <button v-for="lead in items" :key="lead.id" type="button" class="irlix-kanban-card lead-card" :class="{'irlix-kanban-card--dragging':draggedId===Number(lead.id)}" :draggable="canManage" @dragstart="dragStart($event,lead)" @dragend="dragEnd" @click="emit('open',lead)">
          <strong>{{lead.name}}</strong>
          <dl><div><dt>Источник</dt><dd>{{lead.source||'—'}}</dd></div><div><dt>Ответственный</dt><dd>{{employeeName(lead.responsible_employee_id)}}</dd></div><div><dt>Запросы</dt><dd>{{lead.request_count||0}}</dd></div></dl>
        </button>
      </template>
    </UiKanbanBoard>
  </section>
</template>

<style scoped>
.leads-board-view{min-width:0}
.lead-card dl{display:grid;gap:8px;margin:0;min-width:0}
.lead-card dl div{display:grid;grid-template-columns:minmax(0,1fr);gap:2px;min-width:0}
.lead-card dt{color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}
.lead-card dd{margin:0;min-width:0;white-space:normal;overflow-wrap:anywhere;color:var(--irlix-color-text);font-size:var(--irlix-font-size-caption);line-height:1.35}
</style>

<script setup>
import { UiRegistryTable, UiTooltip } from '@irlix/ui';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AbsenceActions from './AbsenceActions.vue';
import { formatDateNumeric, statusLabels, typeLabels } from '../constants';
import { absenceRegistryColumns, absenceRegistryMinWidth, absenceTypeTone, formatAbsenceDays } from '../absenceRegistryLayout';

const props=defineProps({
  items:{type:Array,default:()=>[]},
  showEmployee:{type:Boolean,default:false},
  showStage:{type:Boolean,default:false},
  showProgress:{type:Boolean,default:false},
  centerColumns:{type:Boolean,default:false},
  showDocuments:{type:Boolean,default:true},
  showActions:{type:Boolean,default:true},
  hiddenActions:{type:Array,default:()=>[]},
});
const emit=defineEmits(['action']);
const typeRuler=ref(null),statusRuler=ref(null);
const typeBadgeWidth=ref(0),statusBadgeWidth=ref(0);
const displayedItems=computed(()=>props.items.filter(item=>!item.is_employee_without_absences));
const visibleTypeLabels=computed(()=>[...new Set(displayedItems.value.map(item=>typeLabels[item.type]||item.type).filter(Boolean))]);
const visibleStatusLabels=computed(()=>[...new Set(displayedItems.value.map(item=>statusLabels[item.absence_status||item.status]||item.absence_status||item.status).filter(Boolean))]);
const measureBadges=()=>{
  typeBadgeWidth.value=Math.ceil(typeRuler.value?.getBoundingClientRect().width||0);
  statusBadgeWidth.value=Math.ceil(statusRuler.value?.getBoundingClientRect().width||0);
};
let badgeObserver;
onMounted(()=>{
  if(!props.centerColumns)return;
  measureBadges();
  if(typeof ResizeObserver!=='undefined'){
    badgeObserver=new ResizeObserver(measureBadges);
    if(typeRuler.value)badgeObserver.observe(typeRuler.value);
    if(statusRuler.value)badgeObserver.observe(statusRuler.value);
  }
});
onBeforeUnmount(()=>badgeObserver?.disconnect());
const badgeStyles=computed(()=>props.centerColumns?{
  '--absence-type-badge-width':typeBadgeWidth.value?`${typeBadgeWidth.value}px`:'max-content',
  '--absence-status-badge-width':statusBadgeWidth.value?`${statusBadgeWidth.value}px`:'max-content',
}:{});
const registryColumns = computed(() => absenceRegistryColumns({
  showEmployee:props.showEmployee,
  showProgress:props.showProgress,
  showStage:props.showStage,
  showDocuments:props.showDocuments,
  showActions:props.showActions,
  centerColumns:props.centerColumns,
  typeBadgeWidth:typeBadgeWidth.value,
  statusBadgeWidth:statusBadgeWidth.value,
}));
const registryMinWidth = computed(() => absenceRegistryMinWidth(registryColumns.value, {centerColumns:props.centerColumns}));


const actionsOf=(item)=>(item.available_actions||[]).filter((action)=>!props.hiddenActions.includes(action));
const absenceId=(item)=>item.absence_id||item.id;
const shortName=(name)=>{const parts=String(name||'').trim().split(/\s+/);return parts.length>2?parts.slice(0,2).join(' '):String(name||'')};
const statusTone=(item)=>{const status=item.absence_status||item.status;return ['confirmed'].includes(status)?'success':['rejected','cancelled'].includes(status)?'danger':['planned','draft'].includes(status)?'info':'warning'};
const stageLabel=(task)=>({
  hr_review:'Первичная проверка',
  account_manager_review:'Согласование с аккаунт-менеджером',
  manager_review:'Согласование с руководителем',
  hr_final_review:'Предоставление',
}[task.stage]||statusLabels[task.stage]||task.stage||'Этап');
const taskClass=(task)=>task.status==='approved'?'done':task.status==='pending'?'current':'waiting';
const taskStatus=(task)=>task.status==='approved'?'Согласовано':task.status==='pending'?'Текущий этап':'Ожидает этапа';
const taskTooltip=(task)=>[
  stageLabel(task),
  task.approver_name||'Согласующий не назначен',
  taskStatus(task),
].join('\n');

const iconPaths={
  hr_review:[
    'M8 2.1a2.15 2.15 0 1 1 0 4.3 2.15 2.15 0 0 1 0-4.3Z',
    'M3.9 13.4c.25-2.25 1.85-3.65 4.1-3.65 1 0 1.86.28 2.55.78',
    'm10.7 11.65 1.15 1.15 2.15-2.35',
  ],
  approval:[
    'M4 1.9h5.3L12.4 5v9.1H4Z',
    'M9.3 1.9V5h3.1',
    'M6 7.25h4.4M6 9.45h4.4M6 11.65h3.2',
  ],
  hr_final_review:[
    'M4.2 2.2h7.6v11.6H4.2Z',
    'M6.1 1.2h3.8v2H6.1Z',
    'm6 9.3 1.35 1.35 2.75-3',
  ],
};
const iconPath=(task)=>{
  if(task.stage==='hr_review')return iconPaths.hr_review;
  if(task.stage==='hr_final_review')return iconPaths.hr_final_review;
  return iconPaths.approval;
};
</script>

<template>
  <div class="table-wrap vacations-table-wrap" :class="{ 'vacations-table-wrap--centered': centerColumns }" :style="badgeStyles">
    <div v-if="centerColumns" class="vacations-badge-rulers" aria-hidden="true">
      <div ref="typeRuler" class="vacations-badge-ruler">
        <span v-for="label in visibleTypeLabels" :key="label">{{ label }}</span>
      </div>
      <div ref="statusRuler" class="vacations-badge-ruler">
        <span v-for="label in visibleStatusLabels" :key="label">{{ label }}</span>
      </div>
    </div>
    <UiRegistryTable class="shared-registry-content" table-class="irlix-data-table vacations-table" :columns="registryColumns" :min-width="registryMinWidth" aria-label="Отсутствия" density="compact">
      <template #head>
        <tr>
          <th v-if="showEmployee" class="employee-column">Сотрудник</th>
          <th class="period-column">Период</th>
          <th class="type-column">Тип</th>
          <th v-if="centerColumns" class="registry-spacer-column" aria-hidden="true"></th>
          <th class="status-column">Статус</th>
          <th v-if="showProgress" class="progress-column">Этапы</th>
          <th v-if="showStage" class="stage-column">Этап</th>
          <th v-if="showDocuments" class="documents-column">Документы</th>
          <th v-if="showActions" class="actions-cell actions-column"></th>
        </tr>
      </template>
      
        <tr v-for="item in items"
          :key="item.is_employee_without_absences ? `employee-${item.employee_id}` : `${absenceId(item)}-${item.id}`"
          :class="{ 'clickable-row': !item.is_employee_without_absences }"
          @click="!item.is_employee_without_absences && emit('action',{action:'view',item})">
          <td v-if="showEmployee" class="employee-column"><strong>{{ shortName(item.employee_name)||`#${item.employee_id}` }}</strong><small>{{ item.department_name||'—' }}</small></td>
          <template v-if="item.is_employee_without_absences">
            <td :colspan="registryColumns.length-(showEmployee ? 1 : 0)" class="empty-absence-label">—</td>
          </template>
          <template v-else>
          <td class="period-column"><span class="period-text"><span>{{ formatDateNumeric(item.starts_on) }} — {{ formatDateNumeric(item.ends_on) }}</span><span v-if="formatAbsenceDays(item.calendar_days)" class="period-duration">{{ formatAbsenceDays(item.calendar_days) }}</span></span></td>
          <td class="type-column"><span class="vacation-type-badge" :class="`vacation-type-badge--${absenceTypeTone(item.type)}`">{{ typeLabels[item.type]||item.type }}</span></td>
          <td v-if="centerColumns" class="registry-spacer-column" aria-hidden="true"></td>
          <td class="status-column">
            <span class="status-text vacation-status-badge" :class="`vacation-status-badge--${statusTone(item)}`" :title="statusLabels[item.absence_status||item.status]||item.absence_status||item.status">{{ statusLabels[item.absence_status||item.status]||item.absence_status||item.status }}</span>
          </td>
          <td v-if="showProgress" class="progress-column" @click.stop>
            <div v-if="item.approval_progress?.length" class="approval-progress icon-progress">
              <template v-for="(task,index) in item.approval_progress" :key="task.id">
                <span v-if="index" class="approval-connector" aria-hidden="true"></span>
                <UiTooltip :aria-label="taskTooltip(task)">
                  <span class="approval-step approval-icon" :class="taskClass(task)" aria-hidden="true">
                    <svg viewBox="0 0 16 16" aria-hidden="true">
                      <path v-for="(path,pathIndex) in iconPath(task)" :key="pathIndex" :d="path"/>
                    </svg>
                  </span>
                  <template #content>
                    <div class="approval-tooltip-content">
                      <strong>{{ stageLabel(task) }}</strong>
                      <span>{{ task.approver_name||'Согласующий не назначен' }}</span>
                      <span>{{ taskStatus(task) }}</span>
                    </div>
                  </template>
                </UiTooltip>
              </template>
            </div>
          </td>
          <td v-if="showStage" class="stage-column">{{ stageLabel({stage:item.stage}) }}</td>
          <td v-if="showDocuments" class="documents-column" @click.stop>
            <button v-if="Number(item.attachment_count||0)>0" type="button" class="document-link" @click="emit('action',{action:'view_attachments',item})">
              {{ Number(item.attachment_count)===1?'Открыть':`${item.attachment_count} файла` }}
            </button>
            <span v-else>—</span>
          </td>
          <td v-if="showActions" class="actions-cell actions-column" @click.stop>
            <AbsenceActions v-if="actionsOf(item).length" :actions="actionsOf(item)" @action="action=>emit('action',{action,item})"/>
          </td>
          </template>
        </tr>
      
    </UiRegistryTable>
  </div>
</template>
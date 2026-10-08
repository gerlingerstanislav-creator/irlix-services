<script setup>
import AbsenceActions from './AbsenceActions.vue';
import { formatDate, statusLabels, typeLabels } from '../constants';

const props=defineProps({
  items:{type:Array,default:()=>[]},
  showEmployee:{type:Boolean,default:false},
  showStage:{type:Boolean,default:false},
  showProgress:{type:Boolean,default:false},
  showDocuments:{type:Boolean,default:true},
  showActions:{type:Boolean,default:true},
  hiddenActions:{type:Array,default:()=>[]},
});
const emit=defineEmits(['action']);

const actionsOf=(item)=>(item.available_actions||[]).filter((action)=>!props.hiddenActions.includes(action));
const absenceId=(item)=>item.absence_id||item.id;
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
  <div class="table-wrap vacations-table-wrap">
    <table class="irlix-data-table vacations-table">
      <thead>
        <tr>
          <th v-if="showEmployee" class="employee-column">Сотрудник</th>
          <th class="period-column">Период</th>
          <th class="days-column">Дни</th>
          <th class="type-column">Тип</th>
          <th class="status-column">Статус</th>
          <th v-if="showStage" class="stage-column">Этап</th>
          <th v-if="showDocuments" class="documents-column">Документы</th>
          <th v-if="showActions" class="actions-cell actions-column"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in items" :key="`${absenceId(item)}-${item.id}`" class="clickable-row" @click="emit('action',{action:'view',item})">
          <td v-if="showEmployee" class="employee-column"><strong>{{ item.employee_name||`#${item.employee_id}` }}</strong><small>{{ item.department_name||'—' }}</small></td>
          <td class="period-column"><span class="period-text">{{ formatDate(item.starts_on) }} — {{ formatDate(item.ends_on) }}</span></td>
          <td class="days-column">{{ item.calendar_days??'—' }}</td>
          <td class="type-column">{{ typeLabels[item.type]||item.type }}</td>
          <td class="status-column">
            <div class="status-progress-cell">
              <span class="status-text">{{ statusLabels[item.absence_status||item.status]||item.absence_status||item.status }}</span>
              <div v-if="showProgress&&item.approval_progress?.length" class="approval-progress icon-progress">
                <template v-for="(task,index) in item.approval_progress" :key="task.id">
                  <span v-if="index" class="approval-connector"></span>
                  <span class="approval-step approval-icon" :class="taskClass(task)" :data-tooltip="taskTooltip(task)" tabindex="0">
                    <svg viewBox="0 0 16 16" aria-hidden="true">
                      <path v-for="(path,index) in iconPath(task)" :key="index" :d="path"/>
                    </svg>
                  </span>
                </template>
              </div>
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
        </tr>
      </tbody>
    </table>
  </div>
</template>
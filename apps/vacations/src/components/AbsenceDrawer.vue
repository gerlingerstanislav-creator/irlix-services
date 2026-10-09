<script setup>
import { computed, ref, watch } from 'vue';
import { UiButton } from '@irlix/ui';
import AbsenceActions from './AbsenceActions.vue';
import { api, downloadFile } from '../api';
import { formatDateTime, statusLabels, typeLabels } from '../constants';

const props = defineProps({ open: Boolean, loading: Boolean, detail: { type: Object, default: null } });
const emit = defineEmits(['close', 'action', 'changed', 'error']);
const attachments = ref([]); const documentsLoading = ref(false); const uploading = ref(false); const fileInput = ref(null);
const absence = computed(() => props.detail?.absence || null);
const actions = computed(() => absence.value?.available_actions || []);
const topActions = computed(() => actions.value.filter((action) => !['view','history','view_attachments','approve','provide','submit'].includes(action)));
const canReadDocuments = computed(() => actions.value.includes('view_attachments') || actions.value.includes('upload_attachment'));
const canUpload = computed(() => actions.value.includes('upload_attachment'));
const history = computed(() => props.detail?.history || []);
const rejectionNotes = computed(() => history.value.filter(event => event.reason === 'rejected_to_planned' && event.rejection_comment));
const stageLabel = (stage) => ({hr_review:'Первичная проверка',account_manager_review:'Согласование с аккаунт-менеджером',manager_review:'Согласование с руководителем',hr_final_review:'Предоставление'}[stage] || statusLabels[stage] || stage || 'Этап согласования');
const approvalStatusLabel = (step) => step.status === 'approved' ? 'Согласовано' : step.status === 'pending' ? 'Ожидает действия' : step.stage === 'employee_review' && !['planned','employee_review'].includes(absence.value?.status) ? 'Одобрение не зафиксировано' : step.stage === 'employee_review' && absence.value?.status === 'planned' ? 'Ожидает отправки' : 'Ожидает этапа';
const isCurrentApproval = (step) => Number(step.id) === Number(absence.value?.pending_approval_id || 0) && step.status === 'pending';
const approvalAction = (step) => step.stage === 'hr_final_review' && actions.value.includes('provide') ? 'provide' : 'approve';
const approvalActionLabel = (step) => approvalAction(step) === 'provide' ? 'Предоставить отпуск' : 'Согласовать';
const triggerApproval = (step) => emit('action',{action:approvalAction(step),item:{...absence.value,pending_approval_id:step.id}});
const triggerSubmit = () => emit('action',{action:'submit',item:absence.value});
const dateOnly = (value) => { if (!value) return '—'; const [y,m,d]=String(value).slice(0,10).split('-'); return `${Number(d)}.${m}.${y}`; };
const period = computed(() => `${dateOnly(absence.value?.starts_on)} - ${dateOnly(absence.value?.ends_on)} (${absence.value?.entitlement_days ?? absence.value?.calendar_days ?? '—'}дн.)`);
const eventDate = (predicate) => { const event=[...history.value].reverse().find(predicate); return event?.created_at ? formatDateTime(event.created_at) : ''; };
const plannedDate = computed(() => eventDate((e) => !e.from_status && ['planned','draft'].includes(e.to_status)) || eventDate((e) => ['planned','draft'].includes(e.to_status)));
const submittedDate = computed(() => eventDate((e) => ['submitted','pending_hr','hr_review'].includes(e.to_status)));
const approvalDate = (step) => step.acted_at ? formatDateTime(step.acted_at) : step.stage === 'employee_review' ? '' : step.approved_at ? formatDateTime(step.approved_at) : step.completed_at ? formatDateTime(step.completed_at) : step.status === 'approved' ? eventDate((e) => e.to_status === step.stage || e.from_status === step.stage) : '';
const timeline = computed(() => {
  const rows=[];
  if (plannedDate.value || ['planned','draft'].includes(absence.value?.status)) rows.push({key:'planned',label:'Запланировано',status:'approved',date:plannedDate.value});
  const canSubmit = actions.value.includes('submit');
  if (canSubmit || submittedDate.value || !['planned','draft'].includes(absence.value?.status)) rows.push({key:'submitted',label:'Отправка на согласование',status:submittedDate.value || !['planned','draft'].includes(absence.value?.status) ? 'approved' : 'pending',date:submittedDate.value,action:canSubmit?'submit':null});
  for (const step of props.detail?.approvals || []) rows.push({key:`approval-${step.id}`,label:stageLabel(step.stage),status:step.status,sub:`${step.approver_name || 'Согласующий не назначен'} · ${approvalStatusLabel(step)}`,date:approvalDate(step),step});
  return rows;
});
const loadAttachments=async()=>{if(!absence.value||!canReadDocuments.value){attachments.value=[];return;}documentsLoading.value=true;try{const payload=await api(`/api/vacations/absences/${absence.value.id}/attachments`);attachments.value=payload.data||[];}catch(error){emit('error',error.message);}finally{documentsLoading.value=false;}};
watch(()=>[props.open,absence.value?.id],([isOpen])=>{if(isOpen)loadAttachments();},{immediate:true});
const upload=async()=>{const file=fileInput.value?.files?.[0];if(!file||!absence.value)return;uploading.value=true;try{const form=new FormData();form.append('file',file);form.append('kind','application');await api(`/api/vacations/absences/${absence.value.id}/attachments`,{method:'POST',body:form});fileInput.value.value='';await loadAttachments();emit('changed');}catch(error){emit('error',error.message);}finally{uploading.value=false;}};
const download=async(item)=>{try{await downloadFile(`/api/vacations/absences/${absence.value.id}/attachments/${item.id}/download`,item.original_name||'document');}catch(error){emit('error',error.message);}};
const remove=async(item)=>{if(!confirm(`Удалить документ «${item.original_name}»?`))return;try{await api(`/api/vacations/absences/${absence.value.id}/attachments/${item.id}`,{method:'DELETE'});await loadAttachments();emit('changed');}catch(error){emit('error',error.message);}};
</script>
<template>
<div v-if="open" class="drawer-overlay" @click.self="emit('close')"><aside class="absence-drawer compact-absence-drawer">
<div class="drawer-head compact-drawer-head"><div><h2>{{ absence ? typeLabels[absence.type] || absence.type : 'Загрузка…' }}</h2><p v-if="absence">{{ absence.employee_name || 'Сотрудник' }} · {{ absence.department_name || 'Без подразделения' }}</p></div><div class="drawer-head-actions"><AbsenceActions v-if="absence&&topActions.length" :actions="topActions" @action="action=>emit('action',{action,item:absence})"/><button class="close" type="button" aria-label="Закрыть" @click="emit('close')">×</button></div></div>
<div v-if="loading" class="empty compact">Загрузка карточки…</div><template v-else-if="absence">
<section class="compact-fields"><div class="compact-field-row"><span>Период</span><strong>{{ period }}</strong></div><div class="compact-field-row"><span>Статус</span><strong>{{ statusLabels[absence.status] || absence.status }}</strong></div><div class="compact-field-row compact-field-row-top compact-documents-field"><span>Документы</span><div class="compact-documents-value"><div v-if="!canReadDocuments" class="compact-muted">{{ Number(absence.attachment_count||0)?`${absence.attachment_count} файл(а)`:'—' }}</div><div v-else-if="documentsLoading" class="compact-muted">Загрузка…</div><div v-else-if="!attachments.length" class="compact-muted">—</div><div v-else class="compact-document-list"><div v-for="item in attachments" :key="item.id" class="compact-document-row"><button type="button" class="compact-document-name" @click="download(item)">{{ item.original_name || 'Скачать файл' }}</button><small>{{ Math.ceil(Number(item.size_bytes||0)/1024) }} КБ · {{ formatDateTime(item.created_at) }}</small><button v-if="canUpload" type="button" class="danger-text compact-document-remove" @click="remove(item)">Удалить</button></div></div><div v-if="canUpload" class="compact-upload-row"><input ref="fileInput" type="file" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx"/><UiButton compact :disabled="uploading" @click="upload">{{ uploading?'Загрузка…':'Загрузить' }}</UiButton></div></div></div><div class="compact-field-row compact-field-row-top"><span>Комментарий</span><strong>{{ absence.comment || '—' }}</strong></div>
<div v-for="(event,index) in rejectionNotes" :key="'rejection-'+index" class="compact-field-row compact-field-row-top"><span>Причина отклонения</span><strong>{{ event.rejection_comment }} · {{ formatDateTime(event.created_at) }}</strong></div></section>
<section class="drawer-section compact-history-section"><div class="section-title compact-section-title"><h3>История</h3></div><div v-if="!timeline.length" class="compact-muted">История пока пуста.</div><div v-else class="compact-history-list"><div v-for="row in timeline" :key="row.key" class="compact-history-row approval-history-row" :class="row.status"><div class="compact-history-marker"></div><div class="compact-history-content"><strong>{{ row.label }}</strong><small v-if="row.sub">{{ row.sub }}</small></div><div class="compact-history-action"><UiButton v-if="row.action==='submit'" compact @click="triggerSubmit">Отправить</UiButton><UiButton v-else-if="row.step&&isCurrentApproval(row.step)" compact @click="triggerApproval(row.step)">{{ approvalActionLabel(row.step) }}</UiButton><time v-else-if="row.date">{{ row.date }}</time></div></div></div></section>
</template></aside></div>
</template>
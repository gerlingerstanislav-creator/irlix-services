<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { UiAppTopbar, UiButton, UiPeriodPicker } from '@irlix/ui';
import { api } from './api';
import { typeLabels } from './constants';
import AppSidebar from './components/AppSidebar.vue';
import AbsenceDrawer from './components/AbsenceDrawer.vue';
import MyAbsencesPage from './pages/MyAbsencesPage.vue';
import DepartmentAbsencesPage from './pages/DepartmentAbsencesPage.vue';
import ManageAbsencesPage from './pages/ManageAbsencesPage.vue';
import ActionHistoryPage from './pages/ActionHistoryPage.vue';

const routePaths={mine:'/vacations/',department:'/vacations/department/',manage:'/vacations/manage/',history:'/vacations/history/'};
const sectionFromPath=(pathname)=>{
  const normalized=`/${String(pathname||'').replace(/^\/+|\/+$/g,'')}/`;
  if(normalized==='/vacations/department/')return 'department';
  if(normalized==='/vacations/manage/')return 'manage';
  if(normalized==='/vacations/history/')return 'history';
  return 'mine';
};

const section=ref(sectionFromPath(window.location.pathname));
const year=ref(new Date().getFullYear());
const workspace=ref(null),loadingWorkspace=ref(true),error=ref(''),success=ref(''),refreshToken=ref(0),drawerOpen=ref(false),drawerLoading=ref(false),detail=ref(null),showEdit=ref(false),editSaving=ref(false),editForm=ref({id:null,type:'paid_vacation',starts_on:'',ends_on:'',comment:''});
const roles=computed(()=>workspace.value?.access?.roles||[]);
const isAdmin=computed(()=>roles.value.some(role=>['platform-admin','platform-tester'].includes(role)));
const isPersonnelOfficer=computed(()=>isAdmin.value||roles.value.includes('personnel-officer'));
const isHr=computed(()=>isAdmin.value||roles.value.includes('hr'));
const isManager=computed(()=>isAdmin.value||roles.value.includes('manager'));
const isElevated=computed(()=>isPersonnelOfficer.value||isHr.value||isManager.value);
const menuItems=computed(()=>[
  {id:'mine',icon:'calendar',label:'Отсутствия',visible:true},
  {id:'department',icon:'team',label:'Отсутствия подразделения',visible:isElevated.value},
  {id:'manage',icon:'manage',label:'Управление отсутствиями',visible:isElevated.value},
].filter(i=>i.visible));
const availableSections=computed(()=>new Set([...menuItems.value.map(item=>item.id),'history']));

const selectSection=(next,{replace=false}={})=>{
  if(!routePaths[next])return;
  if(workspace.value&&!availableSections.value.has(next))next='mine';
  section.value=next;
  const target=routePaths[next];
  if(window.location.pathname!==target){
    window.history[replace?'replaceState':'pushState']({},'',target);
  }
};
const onPopState=()=>{section.value=sectionFromPath(window.location.pathname)};

const notifyError=(message)=>{error.value=message||'Не удалось выполнить действие';success.value='';window.setTimeout(()=>{if(error.value===message)error.value='';},6000)};
const notifySuccess=(message)=>{success.value=message;error.value='';window.setTimeout(()=>{if(success.value===message)success.value='';},3500)};
const loadWorkspace=async()=>{
  loadingWorkspace.value=true;
  try{
    const payload=await api('/api/vacations/workspace');
    workspace.value=payload.data;
    if(!availableSections.value.has(section.value))selectSection('mine',{replace:true});
    else selectSection(section.value,{replace:true});
  }catch(e){notifyError(e.message)}
  finally{loadingWorkspace.value=false}
};
const absenceIdOf=(item)=>Number(item?.absence_id||item?.id||0);
const loadDetail=async(absenceId,open=true)=>{if(!absenceId)return;if(open)drawerOpen.value=true;drawerLoading.value=true;try{const payload=await api(`/api/vacations/absences/${absenceId}/workspace`);detail.value=payload.data}catch(e){notifyError(e.message);if(open)drawerOpen.value=false}finally{drawerLoading.value=false}};
const markChanged=async(message='')=>{refreshToken.value+=1;if(drawerOpen.value&&detail.value?.absence?.id)await loadDetail(detail.value.absence.id,false);if(message)notifySuccess(message)};
const openEdit=async(item)=>{const id=absenceIdOf(item);let source=item;if(!source?.type||source.absence_id){try{const payload=await api(`/api/vacations/absences/${id}/workspace`);source=payload.data.absence}catch(e){notifyError(e.message);return}}editForm.value={id,type:source.type,starts_on:String(source.starts_on||'').slice(0,10),ends_on:source.ends_on?String(source.ends_on).slice(0,10):'',comment:source.comment||''};showEdit.value=true};
const saveEdit=async()=>{editSaving.value=true;try{await api(`/api/vacations/absences/${editForm.value.id}`,{method:'PATCH',body:{type:editForm.value.type,starts_on:editForm.value.starts_on,ends_on:editForm.value.ends_on||null,comment:editForm.value.comment}});showEdit.value=false;await markChanged('Изменения сохранены')}catch(e){notifyError(e.message)}finally{editSaving.value=false}};
const rejection=ref({open:false,approvalId:null,comment:''});
const rejecting=ref(false);
const approvalIdOf=(item)=>Number(item.pending_approval_id||(item.absence_id?item.id:detail.value?.absence?.pending_approval_id)||0);
const performApproval=async(approvalId,action)=>{
  const endpoint=`/api/vacations/approvals/${approvalId}/${action}`;
  const body=action==='reject'?{comment:rejection.value.comment}:{};
  try {
    return await api(endpoint,{method:'POST',body});
  } catch(e) {
    if(!e.requiresDelegationConfirmation)throw e;
    if(!window.confirm('Вы пытаетесь провести согласование за другого сотрудника. Продолжить?'))return null;
    return api(endpoint,{method:'POST',body:{...body,delegate_confirmed:true}});
  }
};
const submitRejection=async()=>{
  const comment=rejection.value.comment.trim();
  if(!comment){notifyError('Укажите комментарий отклонения');return}
  rejecting.value=true;
  try{
    const result=await performApproval(rejection.value.approvalId,'reject');
    if(!result)return;
    rejection.value={open:false,approvalId:null,comment:''};
    await markChanged('Заявка отклонена и возвращена на начальный этап');
  }catch(e){notifyError(e.message)}finally{rejecting.value=false}
};
const handleAction=async({action,item})=>{
  const absenceId=absenceIdOf(item);
  if(!absenceId)return;
  if(['view','history','view_attachments','upload_attachment'].includes(action)){await loadDetail(absenceId);return}
  if(action==='edit'){await openEdit(item);return}
  try{
    if(action==='submit'){await api(`/api/vacations/absences/${absenceId}/submit`,{method:'POST'});await markChanged('Заявка отправлена на согласование');return}
    if(action==='withdraw'||action==='return_to_planned'){
      if(!confirm(action==='withdraw'?'Отозвать заявку? Текущие согласования будут сброшены.':'Вернуть заявку на доработку? Все текущие согласования будут сброшены.'))return;
      await api(`/api/vacations/absences/${absenceId}/return-to-planned`,{method:'POST'});
      await markChanged(action==='withdraw'?'Заявка отозвана':'Заявка возвращена на доработку');return;
    }
    if(['approve','provide','reject'].includes(action)){
      const approvalId=approvalIdOf(item);
      if(!approvalId)throw new Error('Не найдена активная задача согласования');
      if(action==='reject'){rejection.value={open:true,approvalId,comment:''};return}
      const result=await performApproval(approvalId,'approve');
      if(result)await markChanged(action==='provide'?'Отпуск предоставлен':'Этап согласован');
    }
  }catch(e){notifyError(e.message)}
};

onMounted(()=>{window.addEventListener('popstate',onPopState);loadWorkspace()});
onBeforeUnmount(()=>window.removeEventListener('popstate',onPopState));
</script>

<template>
  <div class="app-shell irlix-ui">
    <AppSidebar :section="section" :items="menuItems" @update:section="selectSection"/>
    <main class="workspace">
      <UiAppTopbar service="vacations" :section="section" :items="[...menuItems,{id:'history',label:'История действий'}]" :loading="loadingWorkspace">
        <template #breadcrumb-extra><span id="vacations-breadcrumb-extra" class="irlix-breadcrumbs__extra"></span></template>
        <template #actions>
          <div class="vacations-topbar-controls">
            <UiPeriodPicker v-model="year" mode="year"/>
            <div id="vacations-topbar-actions" class="vacations-topbar-actions"></div>
          </div>
        </template>
      </UiAppTopbar>

      <div v-if="error" class="alert floating-alert">{{ error }}</div>
      <div v-if="success" class="success floating-alert">{{ success }}</div>
      <div v-if="loadingWorkspace" class="empty page-loading">Загрузка Vacations…</div>

      <template v-else-if="workspace">
        <MyAbsencesPage v-if="section==='mine'" :year="year" :profile="workspace.employee" :refresh-token="refreshToken" @action="handleAction" @error="notifyError" @changed="markChanged()"/>
        <DepartmentAbsencesPage v-else-if="section==='department'" :year="year" :departments="workspace.departments||[]" :employees="workspace.employees||[]" :can-create-for-employee="isManager" :refresh-token="refreshToken" @action="handleAction" @error="notifyError" @changed="markChanged('Отсутствие сотрудника создано')"/>
        <ManageAbsencesPage v-else-if="section==='manage'" :year="year" :departments="workspace.departments||[]" :employees="workspace.employees||[]" :can-create-for-employee="isManager||isPersonnelOfficer" :refresh-token="refreshToken" @action="handleAction" @error="notifyError" @changed="markChanged('Отсутствие сотрудника создано')"/>
        <ActionHistoryPage v-else-if="section==='history'" :year="year" :employees="workspace.employees||[]" :refresh-token="refreshToken" @action="handleAction" @error="notifyError"/>
      </template>
    </main>

    <div v-if="rejection.open" class="overlay" @click.self="rejection.open=false">
      <form class="modal" @submit.prevent="submitRejection">
        <div class="modal-head"><h2>Отклонить отсутствие</h2><button type="button" class="close" @click="rejection.open=false">×</button></div>
        <p class="hint">Отсутствие вернётся на начальный этап, сотрудник увидит причину и сможет отправить заявку повторно.</p>
        <label class="irlix-field">Комментарий для сотрудника
          <textarea v-model="rejection.comment" rows="4" maxlength="2000" required placeholder="Укажите причину отклонения"/>
        </label>
        <div class="actions"><UiButton type="button" variant="secondary" @click="rejection.open=false">Отмена</UiButton><UiButton type="submit" :disabled="rejecting||!rejection.comment.trim()">{{ rejecting?'Отклоняем…':'Отклонить' }}</UiButton></div>
      </form>
    </div>

    <AbsenceDrawer :open="drawerOpen" :loading="drawerLoading" :detail="detail" @close="drawerOpen=false" @action="handleAction" @changed="markChanged('Документы обновлены')" @error="notifyError"/>

    <div v-if="showEdit" class="overlay" @click.self="showEdit=false">
      <form class="modal" @submit.prevent="saveEdit">
        <div class="modal-head"><div><div class="eyebrow">РЕДАКТИРОВАНИЕ</div><h2>Изменить отсутствие</h2></div><button class="close" type="button" @click="showEdit=false">×</button></div>
        <label class="irlix-field">Тип<select v-model="editForm.type"><option v-for="(label,key) in typeLabels" :key="key" :value="key">{{ label }}</option></select></label>
        <div class="date-grid"><label class="irlix-field">С<input v-model="editForm.starts_on" type="date" required/></label><label class="irlix-field">По<input v-model="editForm.ends_on" type="date" :required="editForm.type!=='maternity_leave'"/></label></div>
        <label class="irlix-field">Комментарий<textarea v-model="editForm.comment" rows="4" maxlength="2000"/></label>
        <div class="actions"><UiButton type="button" variant="secondary" @click="showEdit=false">Отмена</UiButton><UiButton type="submit" :disabled="editSaving">{{ editSaving?'Сохраняем…':'Сохранить' }}</UiButton></div>
      </form>
    </div>
  </div>
</template>
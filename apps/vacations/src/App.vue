<script setup>
import { computed, onMounted, ref } from 'vue';
import { UiButton } from '@irlix/ui';
import { auth } from './auth';
import { api } from './api';
import { typeLabels } from './constants';
import AbsenceDrawer from './components/AbsenceDrawer.vue';
import MyAbsencesPage from './pages/MyAbsencesPage.vue';
import ApprovalsPage from './pages/ApprovalsPage.vue';
import DepartmentAbsencesPage from './pages/DepartmentAbsencesPage.vue';
import ManageAbsencesPage from './pages/ManageAbsencesPage.vue';
import ActionHistoryPage from './pages/ActionHistoryPage.vue';

const section = ref('mine');
const workspace = ref(null);
const loadingWorkspace = ref(true);
const error = ref('');
const success = ref('');
const refreshToken = ref(0);
const drawerOpen = ref(false);
const drawerLoading = ref(false);
const detail = ref(null);
const showEdit = ref(false);
const editSaving = ref(false);
const editForm = ref({ id: null, type: 'paid_vacation', starts_on: '', ends_on: '', comment: '' });

const roles = computed(() => workspace.value?.access?.roles || []);
const isAdmin = computed(() => roles.value.includes('company-admin') || roles.value.includes('platform-admin'));
const isHr = computed(() => isAdmin.value || roles.value.includes('hr'));
const isManager = computed(() => isAdmin.value || roles.value.includes('manager'));
const canApprove = computed(() => isHr.value || isManager.value || roles.value.includes('account-manager'));
const isElevated = computed(() => isHr.value || isManager.value);

const menuItems = computed(() => [
  { id: 'mine', icon: '◷', label: 'Мои отпуска', visible: true },
  { id: 'approvals', icon: '✓', label: 'Согласования', visible: canApprove.value },
  { id: 'department', icon: '▥', label: 'Отпуска подразделения', visible: isElevated.value },
  { id: 'manage', icon: '▦', label: 'Управление отпусками', visible: isHr.value },
  { id: 'history', icon: '↺', label: 'История действий', visible: true },
].filter((item) => item.visible));

const notifyError = (message) => {
  error.value = message || 'Не удалось выполнить действие';
  success.value = '';
  window.setTimeout(() => { if (error.value === message) error.value = ''; }, 6000);
};
const notifySuccess = (message) => {
  success.value = message;
  error.value = '';
  window.setTimeout(() => { if (success.value === message) success.value = ''; }, 3500);
};

const loadWorkspace = async () => {
  loadingWorkspace.value = true;
  try {
    const payload = await api('/api/vacations/workspace');
    workspace.value = payload.data;
  } catch (e) {
    notifyError(e.message);
  } finally {
    loadingWorkspace.value = false;
  }
};

const absenceIdOf = (item) => Number(item?.absence_id || item?.id || 0);
const loadDetail = async (absenceId, open = true) => {
  if (!absenceId) return;
  if (open) drawerOpen.value = true;
  drawerLoading.value = true;
  try {
    const payload = await api(`/api/vacations/absences/${absenceId}/workspace`);
    detail.value = payload.data;
  } catch (e) {
    notifyError(e.message);
    if (open) drawerOpen.value = false;
  } finally {
    drawerLoading.value = false;
  }
};

const markChanged = async (message = '') => {
  refreshToken.value += 1;
  if (drawerOpen.value && detail.value?.absence?.id) await loadDetail(detail.value.absence.id, false);
  if (message) notifySuccess(message);
};

const openEdit = async (item) => {
  const id = absenceIdOf(item);
  let source = item;
  if (!source?.type || source.absence_id) {
    try {
      const payload = await api(`/api/vacations/absences/${id}/workspace`);
      source = payload.data.absence;
    } catch (e) {
      notifyError(e.message);
      return;
    }
  }
  editForm.value = {
    id,
    type: source.type,
    starts_on: String(source.starts_on || '').slice(0, 10),
    ends_on: source.ends_on ? String(source.ends_on).slice(0, 10) : '',
    comment: source.comment || '',
  };
  showEdit.value = true;
};

const saveEdit = async () => {
  editSaving.value = true;
  try {
    await api(`/api/vacations/absences/${editForm.value.id}`, {
      method: 'PATCH',
      body: {
        type: editForm.value.type,
        starts_on: editForm.value.starts_on,
        ends_on: editForm.value.ends_on || null,
        comment: editForm.value.comment,
      },
    });
    showEdit.value = false;
    await markChanged('Изменения сохранены');
  } catch (e) {
    notifyError(e.message);
  } finally {
    editSaving.value = false;
  }
};

const handleAction = async ({ action, item }) => {
  const absenceId = absenceIdOf(item);
  if (!absenceId) return;

  if (['view', 'history', 'view_attachments', 'upload_attachment'].includes(action)) {
    await loadDetail(absenceId);
    return;
  }
  if (action === 'edit') {
    await openEdit(item);
    return;
  }

  try {
    if (action === 'submit') {
      if (!confirm('Отправить отсутствие на согласование? После первого согласования самостоятельное редактирование будет недоступно.')) return;
      await api(`/api/vacations/absences/${absenceId}/submit`, { method: 'POST' });
      await markChanged('Заявка отправлена на согласование');
      return;
    }

    if (action === 'return_to_planned') {
      if (!confirm('Вернуть заявку сотруднику на доработку? Все текущие согласования будут сброшены.')) return;
      await api(`/api/vacations/absences/${absenceId}/return-to-planned`, { method: 'POST' });
      await markChanged('Заявка возвращена на доработку');
      return;
    }

    if (action === 'approve' || action === 'provide') {
      const approvalId = Number(item.pending_approval_id || (item.absence_id ? item.id : detail.value?.absence?.pending_approval_id) || 0);
      if (!approvalId) throw new Error('Не найдена активная задача согласования');
      const question = action === 'provide' ? 'Предоставить отпуск и завершить согласование?' : 'Согласовать текущий этап?';
      if (!confirm(question)) return;
      await api(`/api/vacations/approvals/${approvalId}/approve`, { method: 'POST' });
      await markChanged(action === 'provide' ? 'Отпуск предоставлен' : 'Этап согласован');
    }
  } catch (e) {
    notifyError(e.message);
  }
};

onMounted(loadWorkspace);
</script>

<template>
  <div class="app-shell irlix-ui">
    <aside class="sidebar">
      <a class="logo" href="/" title="Все сервисы" aria-label="Все сервисы">
        <svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path class="logo-one" fill-rule="evenodd" clip-rule="evenodd" d="M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z"/><path class="logo-two" fill-rule="evenodd" clip-rule="evenodd" d="M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z"/></svg>
      </a>
      <div class="divider"></div>
      <nav>
        <button v-for="item in menuItems" :key="item.id" :class="{ active: section === item.id }" :title="item.label" :aria-label="item.label" @click="section = item.id">{{ item.icon }}</button>
      </nav>
      <div class="sidebar-bottom">
        <button class="user-chip" :title="auth.user?.preferred_username || 'Пользователь'">{{ (auth.user?.preferred_username || 'U').slice(0, 1).toUpperCase() }}</button>
        <button title="Выйти" aria-label="Выйти" @click="auth.logout">↪</button>
      </div>
    </aside>

    <main class="workspace">
      <div v-if="error" class="alert floating-alert">{{ error }}</div>
      <div v-if="success" class="success floating-alert">{{ success }}</div>
      <div v-if="loadingWorkspace" class="empty page-loading">Загрузка Vacations…</div>
      <template v-else-if="workspace">
        <MyAbsencesPage v-if="section === 'mine'" :profile="workspace.employee" :refresh-token="refreshToken" @action="handleAction" @error="notifyError" @changed="markChanged()" />
        <ApprovalsPage v-else-if="section === 'approvals'" :refresh-token="refreshToken" @action="handleAction" @error="notifyError" />
        <DepartmentAbsencesPage v-else-if="section === 'department'" :departments="workspace.departments || []" :employees="workspace.employees || []" :refresh-token="refreshToken" @action="handleAction" @error="notifyError" />
        <ManageAbsencesPage v-else-if="section === 'manage'" :departments="workspace.departments || []" :employees="workspace.employees || []" :refresh-token="refreshToken" @action="handleAction" @error="notifyError" />
        <ActionHistoryPage v-else :employees="workspace.employees || []" :refresh-token="refreshToken" @action="handleAction" @error="notifyError" />
      </template>
    </main>

    <AbsenceDrawer :open="drawerOpen" :loading="drawerLoading" :detail="detail" @close="drawerOpen = false" @action="handleAction" @changed="markChanged('Документы обновлены')" @error="notifyError" />

    <div v-if="showEdit" class="overlay" @click.self="showEdit = false">
      <form class="modal" @submit.prevent="saveEdit">
        <div class="modal-head"><div><div class="eyebrow">РЕДАКТИРОВАНИЕ</div><h2>Изменить отсутствие</h2></div><button class="close" type="button" @click="showEdit = false">×</button></div>
        <label class="irlix-field">Тип<select v-model="editForm.type"><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select></label>
        <div class="date-grid"><label class="irlix-field">С<input v-model="editForm.starts_on" type="date" required /></label><label class="irlix-field">По<input v-model="editForm.ends_on" type="date" :required="editForm.type !== 'maternity_leave'" /></label></div>
        <label class="irlix-field">Комментарий<textarea v-model="editForm.comment" rows="4" maxlength="2000" /></label>
        <div class="actions"><UiButton type="button" variant="secondary" @click="showEdit = false">Отмена</UiButton><UiButton type="submit" :disabled="editSaving">{{ editSaving ? 'Сохраняем…' : 'Сохранить' }}</UiButton></div>
      </form>
    </div>
  </div>
</template>

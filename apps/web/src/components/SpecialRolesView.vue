<script setup>
import { computed, onMounted, ref } from 'vue';
import { UiBadge, UiButton, UiPageHeader, UiPanel } from '@irlix/ui';
import { auth } from '../auth';

const props = defineProps({ employees: { type: Array, default: () => [] } });
const roles = ref([]);
const selectedKey = ref('company-admin');
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const showAssign = ref(false);
const employeeId = ref('');
const search = ref('');

const selectedRole = computed(() => roles.value.find((role) => role.key === selectedKey.value) || roles.value[0] || null);
const memberIds = computed(() => new Set((selectedRole.value?.members || []).map((member) => Number(member.id))));
const availableEmployees = computed(() => {
  const needle = search.value.trim().toLowerCase();
  return props.employees.filter((employee) => {
    if (memberIds.value.has(Number(employee.id))) return false;
    if (employee.employment_status === 'Уволен') return false;
    if (!needle) return true;
    return [employee.full_name, employee.department_name, employee.position, employee.work_email, employee.login]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(needle));
  });
});

const request = async (url, options = {}) => {
  const response = await auth.fetch(url, {
    ...options,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) },
  });
  if (response.status === 204) return null;
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || `HTTP ${response.status}`);
  return payload;
};

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    roles.value = (await request('/api/employees/access/roles')).data || [];
    if (!roles.value.some((role) => role.key === selectedKey.value) && roles.value.length) selectedKey.value = roles.value[0].key;
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
};

const openAssign = () => {
  employeeId.value = '';
  search.value = '';
  showAssign.value = true;
};

const assign = async () => {
  if (!selectedRole.value || !employeeId.value) return;
  saving.value = true;
  error.value = '';
  try {
    await request(`/api/employees/access/roles/${encodeURIComponent(selectedRole.value.key)}/${Number(employeeId.value)}`, { method: 'PUT' });
    showAssign.value = false;
    await load();
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
  }
};

const remove = async (member) => {
  if (!selectedRole.value) return;
  saving.value = true;
  error.value = '';
  try {
    await request(`/api/employees/access/roles/${encodeURIComponent(selectedRole.value.key)}/${Number(member.id)}`, { method: 'DELETE' });
    await load();
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
  }
};

onMounted(load);
</script>

<template>
  <section class="roles-page">
    <UiPageHeader eyebrow="ACCESS" title="Роли" description="Специальные функциональные роли компании, независимые от оргструктуры и должности сотрудника." />
    <div v-if="error" class="alert">{{ error }}</div>

    <div class="roles-layout">
      <UiPanel class="roles-catalog-panel">
        <div class="roles-catalog-head">
          <strong>Специальные роли</strong>
          <span>{{ roles.length }}</span>
        </div>
        <div v-if="loading" class="role-empty">Загрузка…</div>
        <button
          v-for="role in roles"
          v-else
          :key="role.key"
          class="role-item"
          :class="{ active: role.key === selectedRole?.key }"
          type="button"
          @click="selectedKey = role.key"
        >
          <span class="role-icon">◇</span>
          <span class="role-item-copy">
            <strong>{{ role.label }}</strong>
            <small>{{ role.description }}</small>
          </span>
          <UiBadge tone="neutral">{{ role.member_count }}</UiBadge>
        </button>
      </UiPanel>

      <UiPanel class="role-detail-panel">
        <template v-if="selectedRole">
          <div class="role-detail-head">
            <div>
              <div class="role-detail-title"><span class="role-icon large">◇</span><h2>{{ selectedRole.label }}</h2></div>
              <p>{{ selectedRole.description }}</p>
            </div>
            <UiButton @click="openAssign">+ Назначить сотрудника</UiButton>
          </div>

          <div class="role-summary"><strong>{{ selectedRole.member_count }}</strong><span>Назначено сотрудников</span></div>

          <div v-if="!selectedRole.members?.length" class="role-empty role-empty-large">
            <strong>Никто не назначен</strong>
            <span>Добавьте сотрудника, чтобы он получил эту специальную роль.</span>
            <UiButton @click="openAssign">Назначить сотрудника</UiButton>
          </div>
          <div v-else class="table-wrap role-members-wrap">
            <table class="irlix-data-table role-members-table">
              <thead><tr><th>Сотрудник</th><th>Подразделение</th><th>Должность</th><th /></tr></thead>
              <tbody>
                <tr v-for="member in selectedRole.members" :key="member.id">
                  <td><strong>{{ member.full_name }}</strong><small>{{ member.work_email || member.login || '—' }}</small></td>
                  <td>{{ member.department_name || '—' }}</td>
                  <td>{{ member.position || '—' }}</td>
                  <td class="role-actions"><UiButton variant="danger" compact :disabled="saving" @click="remove(member)">Снять роль</UiButton></td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </UiPanel>
    </div>

    <div v-if="showAssign && selectedRole" class="overlay" @click.self="showAssign = false">
      <form class="modal role-assign-modal" @submit.prevent="assign">
        <div class="drawer-head">
          <div><div class="eyebrow">SPECIAL ROLE</div><h2>Назначить роль «{{ selectedRole.label }}»</h2></div>
          <button type="button" class="close" @click="showAssign = false">×</button>
        </div>
        <label class="irlix-field">Поиск сотрудника<input v-model="search" type="search" placeholder="Имя, подразделение, должность" /></label>
        <label class="irlix-field">Сотрудник
          <select v-model="employeeId" required>
            <option value="">Выберите сотрудника</option>
            <option v-for="employee in availableEmployees" :key="employee.id" :value="employee.id">
              {{ employee.full_name }}{{ employee.department_name ? ` · ${employee.department_name}` : '' }}
            </option>
          </select>
        </label>
        <p v-if="!availableEmployees.length" class="form-hint">Подходящих сотрудников нет: все доступные сотрудники уже назначены либо уволены.</p>
        <div class="form-actions"><UiButton type="button" variant="secondary" @click="showAssign = false">Отмена</UiButton><UiButton type="submit" :disabled="saving || !employeeId">{{ saving ? 'Назначаем…' : 'Назначить' }}</UiButton></div>
      </form>
    </div>
  </section>
</template>

<style scoped>
.roles-layout { display: grid; grid-template-columns: minmax(280px, 32%) minmax(0, 1fr); gap: 14px; align-items: start; }
.roles-catalog-panel, .role-detail-panel { overflow: hidden; }
.roles-catalog-head { display: flex; justify-content: space-between; align-items: center; padding: 15px 16px 11px; border-bottom: 1px solid #edf0f2; }
.roles-catalog-head strong { font-size: 13px; color: #1f2937; }
.roles-catalog-head span { color: #969da6; font-size: 12px; }
.role-item { width: 100%; display: grid; grid-template-columns: 34px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 12px 14px; border-radius: 0; border-bottom: 1px solid #f0f2f4; background: #fff; color: #1f2937; text-align: left; font-weight: 400; }
.role-item:hover { background: #f8faf9; filter: none; }
.role-item.active { background: var(--irlix-color-primary-soft); }
.role-icon { width: 32px; height: 32px; display: grid; place-items: center; border-radius: 8px; background: #f0f2f4; color: #69727d; font-size: 17px; }
.role-item.active .role-icon { background: #fff; color: var(--irlix-color-primary-text); }
.role-icon.large { width: 38px; height: 38px; font-size: 20px; }
.role-item-copy { min-width: 0; display: grid; gap: 3px; }
.role-item-copy strong { font-size: 13px; }
.role-item-copy small { overflow: hidden; color: #8b929b; font-size: 11px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.role-detail-head { display: flex; justify-content: space-between; gap: 18px; align-items: flex-start; padding: 17px 18px; border-bottom: 1px solid #edf0f2; }
.role-detail-title { display: flex; align-items: center; gap: 10px; }
.role-detail-title h2 { margin: 0; font-size: 19px; color: #1c2738; }
.role-detail-head p { max-width: 700px; margin: 7px 0 0 48px; color: #7f8995; font-size: 12px; }
.role-summary { display: flex; align-items: baseline; gap: 8px; padding: 13px 18px; background: #fafbfb; border-bottom: 1px solid #edf0f2; }
.role-summary strong { font-size: 20px; color: #1b2534; }.role-summary span { color: #8a939d; font-size: 12px; }
.role-members-table { min-width: 720px; }.role-members-table td:first-child { display: grid; gap: 2px; }.role-members-table td:first-child small { color: #9299a3; font-size: 11px; }.role-actions { width: 115px; text-align: right; }
.role-empty { min-height: 90px; display: grid; place-items: center; padding: 18px; color: #8d96a1; font-size: 12px; }.role-empty-large { min-height: 260px; align-content: center; gap: 7px; }.role-empty-large strong { color: #263244; font-size: 16px; }.role-empty-large span { color: #8d96a1; }.role-assign-modal { width: min(520px, calc(100vw - 28px)); }
@media (max-width: 900px) { .roles-layout { grid-template-columns: 1fr; }.role-detail-head { flex-direction: column; }.role-detail-head p { margin-left: 0; } }
</style>

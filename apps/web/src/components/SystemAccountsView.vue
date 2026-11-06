<script setup>
import { computed, onMounted, ref } from 'vue';
import { UiBadge, UiButton, UiLoader, UiPanel, UiRegistryTable } from '@irlix/ui';
import { auth } from '../auth';

const accounts = ref([]);
const roles = ref([]);
const selectedRole = ref({});
const loading = ref(false);
const saving = ref(null);
const error = ref('');
const loadFailed = ref(false);
const count = computed(() => accounts.value.length);

const request = async (url, options = {}) => {
  const response = await auth.fetch(url, {
    ...options,
    headers: { Accept: 'application/json', ...(options.headers || {}) },
  });
  if (response.status === 204) return null;
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || `HTTP ${response.status}`);
  return payload.data;
};

const load = async () => {
  loading.value = true;
  loadFailed.value = false;
  error.value = '';
  try {
    // Never turn a failed request or malformed payload into an empty directory.
    const accountRows = await request('/api/employees/system-accounts');
    if (!Array.isArray(accountRows)) throw new Error('Сервис вернул некорректный список системных аккаунтов.');
    accounts.value = accountRows;
    // The role catalog is optional for displaying technical identities.
    try {
      roles.value = (await request('/api/employees/access/roles')) || [];
    } catch {
      roles.value = [];
    }
  } catch (e) {
    loadFailed.value = true;
    error.value = e.message;
  } finally {
    loading.value = false;
  }
};

const roleTitle = (key) => roles.value.find((role) => role.key === key)?.label || key;
const available = (account) => roles.value.filter((role) => !role.automatic && !account.roles.includes(role.key));

const mutate = async (account, role, method) => {
  if (account.protected || !role || saving.value !== null) return;
  saving.value = account.id;
  error.value = '';
  try {
    await request(`/api/employees/access/roles/${encodeURIComponent(role)}/${account.id}`, { method });
    selectedRole.value[account.id] = '';
    // Refresh authoritative assignments; the Keycloak role sync is asynchronous.
    const fresh = await request('/api/employees/system-accounts');
    accounts.value = fresh || [];
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = null;
  }
};

// A system account is only shown after loading the authoritative API projection.
// The page is mounted conditionally by App.vue, so each visit must fetch anew.
onMounted(() => { void load(); });
</script>

<template>
  <section class="system-accounts">
    <header class="system-accounts-header">
      <div>
        <h2>Системные аккаунты</h2>
        <p>Технические учётные записи не входят в штат и не участвуют в кадровом учёте. Права на вход определяет Keycloak, разрешения — единая ролевая модель.</p>
      </div>
      <span>{{ count }} аккаунтов</span>
    </header>
    <div v-if="error" class="alert" role="alert">{{ error }}</div>
    <UiLoader v-if="loading" page label="Загрузка системных аккаунтов" />
    <UiPanel v-else-if="loadFailed" class="system-accounts-panel">
      <div class="empty-state">
        <strong>Не удалось загрузить системные аккаунты</strong>
        <UiButton variant="secondary" @click="load">Повторить</UiButton>
      </div>
    </UiPanel>
    <UiPanel v-else class="system-accounts-panel">
      <div v-if="!accounts.length" class="empty-state">Системные аккаунты не найдены</div>
      <div v-else class="table-wrap irlix-registry-scroll">
        <UiRegistryTable table-class="irlix-data-table" :columns="['25%', '19%', '17%', '39%']" min-width="780px" aria-label="Системные аккаунты" density="compact">
          <template #head><tr><th>Аккаунт</th><th>Тип</th><th>Идентичность</th><th>Роли</th></tr></template>
          <tr v-for="account in accounts" :key="account.id">
            <td><strong>{{ account.name || account.login }}</strong><small class="system-account-login">{{ account.login }}</small></td>
            <td><UiBadge tone="neutral">Технический</UiBadge><small v-if="account.protected" class="system-account-note">Защищённый bootstrap</small></td>
            <td><UiBadge :tone="account.identity_linked ? 'success' : 'neutral'">{{ account.identity_linked ? 'Привязан к Keycloak' : 'Ожидает привязки' }}</UiBadge><small class="system-account-note">{{ account.identity_status }}</small></td>
            <td>
              <div class="system-account-roles">
                <div v-for="role in account.roles" :key="role" class="system-account-role">
                  <span>{{ roleTitle(role) }}</span>
                  <UiButton v-if="!account.protected" variant="secondary" compact :disabled="saving !== null" @click="mutate(account, role, 'DELETE')">Снять</UiButton>
                </div>
                <span v-if="!account.roles.length">Ролей нет</span>
                <div v-if="!account.protected && available(account).length" class="system-account-add">
                  <select v-model="selectedRole[account.id]" :aria-label="`Новая роль для ${account.login}`">
                    <option value="">Выбрать роль</option>
                    <option v-for="role in available(account)" :key="role.key" :value="role.key">{{ role.label }}</option>
                  </select>
                  <UiButton compact :disabled="saving !== null || !selectedRole[account.id]" @click="mutate(account, selectedRole[account.id], 'PUT')">Назначить</UiButton>
                </div>
                <small v-if="account.protected" class="system-account-note">Критичный доступ защищён от снятия</small>
              </div>
            </td>
          </tr>
        </UiRegistryTable>
      </div>
    </UiPanel>
  </section>
</template>

<style scoped>
.system-accounts { width:100%; min-width:0; }
.system-accounts-header { display:flex; align-items:flex-start; justify-content:space-between; gap:24px; padding:20px 24px; border-bottom:1px solid var(--irlix-color-border); }
.system-accounts-header h2 { margin:0 0 6px; font-size:var(--irlix-font-size-section-title); }
.system-accounts-header p { max-width:860px; margin:0; color:var(--irlix-color-text-muted); font-size:var(--irlix-font-size-table); line-height:1.5; }
.system-accounts-header > span { white-space:nowrap; color:var(--irlix-color-text-muted); }
.system-accounts-panel { border:0; border-radius:0; box-shadow:none; }
.system-account-login, .system-account-note { display:block; margin-top:5px; color:var(--irlix-color-text-muted); font-size:var(--irlix-font-size-caption); }
.system-account-roles { display:grid; gap:7px; }
.system-account-role { display:flex; align-items:center; justify-content:space-between; gap:10px; }
.system-account-add { display:flex; align-items:center; gap:8px; }
.system-account-add select { min-width:0; flex:1; font:inherit; padding:6px 9px; border:1px solid var(--irlix-color-border); border-radius:6px; color:var(--irlix-color-text); background:var(--irlix-color-surface); }
@media(max-width:700px) { .system-accounts-header { padding:16px; flex-direction:column; } }
</style>

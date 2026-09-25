<script setup>
import { computed, onMounted, ref } from 'vue';
import { UiBadge, UiButton, UiPageHeader, UiPanel } from '@irlix/ui';
import { auth } from './auth';

const section = ref('mine');
const profile = ref(null);
const absences = ref([]);
const year = ref(new Date().getFullYear());
const loading = ref(false);
const error = ref('');
const showForm = ref(false);
const saving = ref(false);
const form = ref({ type: 'paid_vacation', starts_on: '', ends_on: '', comment: '' });

const typeLabels = {
  paid_vacation: 'Оплачиваемый отпуск',
  unpaid_vacation: 'Неоплачиваемый отпуск',
  sick_leave: 'Больничный',
  maternity_leave: 'Декрет',
  day_off: 'Отгул',
};
const statusLabels = { planned: 'Запланировано', pending: 'На согласовании', approved: 'Согласовано', rejected: 'Отклонено', cancelled: 'Отменено' };
const paidDays = computed(() => absences.value.filter((item) => item.type === 'paid_vacation' && item.status !== 'cancelled').reduce((sum, item) => sum + Number(item.calendar_days || 0), 0));

const api = async (url, options = {}) => {
  const response = await auth.fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) } });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
  return payload;
};

const load = async () => {
  loading.value = true; error.value = '';
  try {
    const [me, list] = await Promise.all([
      api('/api/vacations/me'),
      api(`/api/vacations/absences?year=${year.value}`),
    ]);
    profile.value = me.data;
    absences.value = list.data || [];
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
};

const save = async () => {
  saving.value = true; error.value = '';
  try {
    await api('/api/vacations/absences', { method: 'POST', body: JSON.stringify(form.value) });
    showForm.value = false;
    form.value = { type: 'paid_vacation', starts_on: '', ends_on: '', comment: '' };
    await load();
  } catch (e) { error.value = e.message; }
  finally { saving.value = false; }
};

const formatDate = (value) => value ? new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${value}T00:00:00`)) : '—';
onMounted(load);
</script>

<template>
  <div class="app-shell irlix-ui">
    <aside class="sidebar">
      <a class="logo" href="/" title="Все сервисы" aria-label="Все сервисы">
        <svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path class="logo-one" fill-rule="evenodd" clip-rule="evenodd" d="M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z"/><path class="logo-two" fill-rule="evenodd" clip-rule="evenodd" d="M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z"/></svg>
      </a>
      <div class="divider" />
      <nav>
        <button :class="{ active: section === 'mine' }" title="Мои отпуска" @click="section = 'mine'">◷</button>
        <button :class="{ active: section === 'approvals' }" title="Согласования" @click="section = 'approvals'">✓</button>
        <button :class="{ active: section === 'manage' }" title="Управление отсутствиями" @click="section = 'manage'">▦</button>
      </nav>
      <div class="sidebar-bottom">
        <button class="user-chip" :title="auth.user?.preferred_username || 'Пользователь'">{{ (auth.user?.preferred_username || 'U').slice(0, 1).toUpperCase() }}</button>
        <button title="Выйти" aria-label="Выйти" @click="auth.logout">↪</button>
      </div>
    </aside>

    <main class="workspace">
      <template v-if="section === 'mine'">
        <UiPageHeader eyebrow="VACATIONS / ABSENCES" title="Мои отпуска" :description="profile ? `${profile.full_name} · ${profile.department_name || 'Без подразделения'}` : 'Личное планирование отсутствий'">
          <template #actions><UiButton @click="showForm = true">+ Запланировать отсутствие</UiButton></template>
        </UiPageHeader>
        <div v-if="error" class="alert">{{ error }}</div>
        <section class="stats">
          <div><strong>{{ paidDays }}</strong><span>Запланировано оплачиваемых дней</span></div>
          <div><strong>{{ absences.length }}</strong><span>Отсутствий в {{ year }} году</span></div>
          <div><strong>—</strong><span>Лимит оплачиваемых дней уточняется</span></div>
        </section>
        <UiPanel>
          <div class="toolbar"><label>Год<select v-model="year" @change="load"><option v-for="item in [year - 1, year, year + 1]" :key="item" :value="item">{{ item }}</option></select></label><UiButton variant="secondary" @click="load">Обновить</UiButton></div>
          <div v-if="loading" class="empty">Загрузка…</div>
          <div v-else-if="!absences.length" class="empty"><strong>На {{ year }} год отсутствий пока нет</strong><span>Запланируйте первое отсутствие.</span></div>
          <div v-else class="table-wrap"><table class="irlix-data-table"><thead><tr><th>Период</th><th>Дни</th><th>Тип</th><th>Статус</th><th>Комментарий</th></tr></thead><tbody><tr v-for="item in absences" :key="item.id"><td><strong>{{ formatDate(item.starts_on) }}</strong><small> — {{ formatDate(item.ends_on) }}</small></td><td>{{ item.calendar_days }}</td><td>{{ typeLabels[item.type] || item.type }}</td><td><UiBadge tone="info">{{ statusLabels[item.status] || item.status }}</UiBadge></td><td>{{ item.comment || '—' }}</td></tr></tbody></table></div>
        </UiPanel>
      </template>

      <template v-else-if="section === 'approvals'">
        <UiPageHeader eyebrow="VACATIONS / ABSENCES" title="Согласования" description="Очередь заявок сотрудников в зоне ответственности руководителя." />
        <UiPanel><div class="empty"><strong>Следующий этап</strong><span>Цепочку согласования реализуем после фиксации ролей каждого этапа.</span></div></UiPanel>
      </template>

      <template v-else>
        <UiPageHeader eyebrow="VACATIONS / ABSENCES" title="Управление отсутствиями" description="HR/административный реестр отсутствий и аналитика." />
        <UiPanel><div class="empty"><strong>Следующий этап</strong><span>Поиск, фильтры, создание за сотрудника и статистика будут добавлены после первого пользовательского среза.</span></div></UiPanel>
      </template>
    </main>

    <div v-if="showForm" class="overlay" @click.self="showForm = false">
      <form class="modal" @submit.prevent="save">
        <div class="modal-head"><div><div class="eyebrow">НОВОЕ ОТСУТСТВИЕ</div><h2>Запланировать отсутствие</h2></div><button class="close" type="button" @click="showForm = false">×</button></div>
        <label class="irlix-field">Тип<select v-model="form.type"><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select></label>
        <div class="date-grid"><label class="irlix-field">С<input v-model="form.starts_on" type="date" required /></label><label class="irlix-field">По<input v-model="form.ends_on" type="date" required /></label></div>
        <label class="irlix-field">Комментарий<textarea v-model="form.comment" rows="4" maxlength="2000" placeholder="Необязательно" /></label>
        <p class="hint">На первом этапе длительность считается в календарных днях. Правила лимитов и согласований будут подключены отдельно.</p>
        <div class="actions"><UiButton type="button" variant="secondary" @click="showForm = false">Отмена</UiButton><UiButton type="submit" :disabled="saving">{{ saving ? 'Сохраняем…' : 'Запланировать' }}</UiButton></div>
      </form>
    </div>
  </div>
</template>

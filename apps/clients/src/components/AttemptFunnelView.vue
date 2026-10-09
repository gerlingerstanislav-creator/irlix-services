<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { UiFilterBar, UiSearchSelect } from '@irlix/ui';

const loading = ref(false);
const error = ref('');
const data = ref({
  summary: { total: 0, success: 0, failed: 0, in_progress: 0, success_rate: 0, median_success_days: null },
  stages: [],
  failure_reasons_by_stage: {},
  breakdown: [],
  filter_options: { clients: [], directions: [], responsibles: [], technologies: [], levels: [] },
  filters: { locks: { direction: false, responsible: false }, scope: 'none' },
});
const employees = ref([]);
const selectedFailureStage = ref('');
let requestSequence = 0;
let reloadTimer = null;

function localDate(date = new Date()) {
  const offset = date.getTimezoneOffset();
  return new Date(date.getTime() - offset * 60000).toISOString().slice(0, 10);
}
const today = new Date();
const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
const filters = reactive({
  date_from: localDate(monthStart),
  date_to: localDate(today),
  date_mode: 'created',
  client_id: '',
  direction_department_id: '',
  responsible_employee_id: '',
  technology: '',
  level: '',
  specialist_type: 'all',
  group_by: 'direction',
});

const employeeMap = computed(() => new Map(employees.value.map(employee => [String(employee.id), employee.full_name || `#${employee.id}`])));
const responsibleOptions = computed(() => (data.value.filter_options?.responsibles || []).map(value => ({
  value: String(value),
  label: employeeMap.value.get(String(value)) || `#${value}`,
})).sort((a, b) => a.label.localeCompare(b.label, 'ru')));
const breakdownRows = computed(() => (data.value.breakdown || []).map(row => ({
  ...row,
  displayLabel: filters.group_by === 'responsible'
    ? (employeeMap.value.get(String(row.key)) || (String(row.key) === '0' ? 'Без ответственного' : `#${row.key}`))
    : row.label,
})));
const failureStage = computed(() => {
  const key = selectedFailureStage.value || data.value.stages?.find(stage => stage.failed_here > 0)?.key || '';
  return data.value.stages?.find(stage => stage.key === key) || null;
});
const failureReasons = computed(() => data.value.failure_reasons_by_stage?.[failureStage.value?.key] || []);
const scopeHint = computed(() => {
  const scope = data.value.filters?.scope;
  if (scope === 'all') return 'Все доступные попытки';
  if (scope === 'team') return 'Данные ограничены вашей командой / производственным направлением';
  if (scope === 'own') return 'Данные ограничены вашей областью ответственности';
  return 'Нет доступных данных';
});

const groupOptions = [
  { value: 'direction', label: 'Направление' },
  { value: 'technology', label: 'Технология' },
  { value: 'client', label: 'Клиент / лид' },
  { value: 'responsible', label: 'Ответственный' },
  { value: 'level', label: 'Уровень' },
];
const specialistTypeOptions = [
  { value: 'all', label: 'Все специалисты' },
  { value: 'internal', label: 'Внутренние' },
  { value: 'external', label: 'Внешние' },
];

function percent(value) {
  return `${Number(value || 0).toLocaleString('ru-RU', { maximumFractionDigits: 1 })}%`;
}
function number(value) {
  return Number(value || 0).toLocaleString('ru-RU');
}

async function api(url) {
  const response = await fetch(url, { headers: { Accept: 'application/json' } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || `HTTP ${response.status}`);
  return body;
}

async function loadDirectory() {
  try {
    const result = await api('/api/employees/clients-directory');
    employees.value = result.data?.employees || [];
  } catch {
    employees.value = [];
  }
}

async function load() {
  const sequence = ++requestSequence;
  loading.value = true;
  error.value = '';
  try {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) params.set(key, String(value));
    });
    const result = await api(`/api/clients/attempt-funnel?${params.toString()}`);
    if (sequence !== requestSequence) return;
    data.value = result.data || data.value;
    if (data.value.filters?.locks?.direction) filters.direction_department_id = '';
    if (data.value.filters?.locks?.responsible) filters.responsible_employee_id = '';
    if (selectedFailureStage.value && !(data.value.stages || []).some(stage => stage.key === selectedFailureStage.value && stage.failed_here > 0)) {
      selectedFailureStage.value = '';
    }
  } catch (e) {
    if (sequence === requestSequence) error.value = e.message || String(e);
  } finally {
    if (sequence === requestSequence) loading.value = false;
  }
}

function scheduleLoad() {
  window.clearTimeout(reloadTimer);
  reloadTimer = window.setTimeout(load, 180);
}

watch(filters, scheduleLoad, { deep: true });
onMounted(async () => {
  await Promise.all([loadDirectory(), load()]);
});
</script>

<template>
  <section class="attempt-funnel-page">
    <div class="funnel-filter-card">
      <div class="date-filter-block">
        <div class="date-mode-switch" aria-label="Тип периода">
          <button type="button" :class="{ active: filters.date_mode === 'created' }" @click="filters.date_mode = 'created'">Созданы в период</button>
          <button type="button" :class="{ active: filters.date_mode === 'closed' }" @click="filters.date_mode = 'closed'">Завершены в период</button>
        </div>
        <label>С<input v-model="filters.date_from" type="date"></label>
        <label>По<input v-model="filters.date_to" type="date"></label>
      </div>

      <UiFilterBar class="funnel-filter-bar">
        <UiSearchSelect v-model="filters.client_id" :options="data.filter_options?.clients || []" placeholder="Клиент" search-placeholder="Поиск клиента" />
        <div class="locked-filter" :class="{ locked: data.filters?.locks?.direction }" :title="data.filters?.locks?.direction ? 'Фильтр ограничен вашей областью доступа' : ''">
          <UiSearchSelect v-model="filters.direction_department_id" :options="data.filter_options?.directions || []" :disabled="data.filters?.locks?.direction" :placeholder="data.filters?.locks?.direction ? 'Направление · ограничено' : 'Направление'" search-placeholder="Поиск направления" />
        </div>
        <div class="locked-filter" :class="{ locked: data.filters?.locks?.responsible }" :title="data.filters?.locks?.responsible ? 'Фильтр ограничен вашей областью доступа' : ''">
          <UiSearchSelect v-model="filters.responsible_employee_id" :options="responsibleOptions" :disabled="data.filters?.locks?.responsible" :placeholder="data.filters?.locks?.responsible ? 'Ответственный · ограничено' : 'Ответственный'" search-placeholder="Поиск ответственного" />
        </div>
        <UiSearchSelect v-model="filters.technology" :options="data.filter_options?.technologies || []" placeholder="Технология" search-placeholder="Поиск технологии" />
        <UiSearchSelect v-model="filters.level" :options="data.filter_options?.levels || []" placeholder="Уровень" search-placeholder="Поиск уровня" />
        <UiSearchSelect v-model="filters.specialist_type" :options="specialistTypeOptions" :clearable="false" placeholder="Тип специалиста" />
      </UiFilterBar>
      <div class="scope-hint">{{ scopeHint }}</div>
    </div>

    <div v-if="error" class="funnel-error">{{ error }} <button type="button" @click="load">Повторить</button></div>

    <div class="kpi-grid" :class="{ muted: loading }">
      <article><span>Попыток</span><strong>{{ number(data.summary?.total) }}</strong></article>
      <article><span>Итог: успех</span><strong>{{ number(data.summary?.success) }}</strong><small>{{ percent(data.summary?.success_rate) }}</small></article>
      <article><span>Итог: неудача</span><strong>{{ number(data.summary?.failed) }}</strong></article>
      <article><span>В процессе</span><strong>{{ number(data.summary?.in_progress) }}</strong></article>
      <article><span>Медиана до успеха</span><strong>{{ data.summary?.median_success_days == null ? '—' : `${data.summary.median_success_days} дн.` }}</strong></article>
    </div>

    <section class="funnel-panel" :class="{ muted: loading }">
      <div class="panel-head">
        <div><h2>Воронка попыток</h2><p>{{ filters.date_mode === 'created' ? 'Когорта попыток, созданных в выбранный период' : 'Попытки, завершённые в выбранный период' }}</p></div>
      </div>
      <div v-if="!data.stages?.length" class="empty-state">За выбранный период попыток нет.</div>
      <div v-else class="funnel-stages">
        <article v-for="(stage, index) in data.stages" :key="stage.key" class="funnel-stage">
          <div class="stage-card">
            <span class="stage-index">{{ index + 1 }}</span>
            <h3>{{ stage.label }}</h3>
            <strong>{{ number(stage.count) }}</strong>
            <div class="stage-rates"><span>{{ percent(stage.conversion_total) }} от всех</span><span v-if="index">{{ percent(stage.conversion_previous) }} от предыдущего</span></div>
            <button v-if="stage.failed_here" type="button" class="stage-failure" @click="selectedFailureStage = stage.key">− {{ number(stage.failed_here) }} неудач</button>
            <span v-else class="stage-failure stage-failure--empty">Нет отказов на этапе</span>
          </div>
          <div v-if="index < data.stages.length - 1" class="stage-arrow" aria-hidden="true">→</div>
        </article>
      </div>
    </section>

    <section v-if="failureStage" class="failure-panel">
      <div class="panel-head"><div><h2>Причины неудачи</h2><p>Этап: {{ failureStage.label }}</p></div></div>
      <div v-if="!failureReasons.length" class="empty-state">Причины не зафиксированы.</div>
      <div v-else class="failure-reasons">
        <div v-for="reason in failureReasons" :key="reason.reason" class="failure-reason-row">
          <span>{{ reason.reason }}</span><strong>{{ number(reason.count) }}</strong><small>{{ percent(reason.share) }}</small>
        </div>
      </div>
    </section>

    <section class="breakdown-panel" :class="{ muted: loading }">
      <div class="panel-head panel-head--breakdown">
        <div><h2>Конверсия в разрезе</h2><p>Сравнение выбранной когорты по одному измерению</p></div>
        <UiSearchSelect v-model="filters.group_by" :options="groupOptions" :clearable="false" placeholder="Группировка" />
      </div>
      <div class="breakdown-wrap">
        <table>
          <thead><tr><th>Группа</th><th>Попыток</th><th>CV</th><th>Интервью назначено</th><th>Интервью пройдено</th><th>Ожидание</th><th>Успех</th><th>Неудача</th><th>Конверсия</th></tr></thead>
          <tbody>
            <tr v-for="row in breakdownRows" :key="row.key"><td><strong>{{ row.displayLabel }}</strong></td><td>{{ number(row.total) }}</td><td>{{ number(row.cv_sent) }}</td><td>{{ number(row.interview_scheduled) }}</td><td>{{ number(row.interview_completed) }}</td><td>{{ number(row.awaiting_connection) }}</td><td>{{ number(row.success) }}</td><td>{{ number(row.failed) }}</td><td>{{ percent(row.success_rate) }}</td></tr>
            <tr v-if="!breakdownRows.length"><td colspan="9" class="empty-cell">Нет данных</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</template>

<style scoped>
.attempt-funnel-page{display:grid;gap:14px;padding:0 0 24px;color:var(--irlix-color-text)}.funnel-filter-card,.funnel-panel,.failure-panel,.breakdown-panel{background:var(--irlix-color-surface);border:1px solid var(--irlix-color-border);border-radius:12px}.funnel-filter-card{padding:12px}.date-filter-block{display:flex;align-items:flex-end;gap:9px;margin-bottom:10px}.date-filter-block label{display:grid;gap:4px;color:var(--irlix-color-text);font-size:var(--irlix-font-size-caption);font-weight:700;text-transform:uppercase}.date-filter-block input{height:32px;padding:0 9px;border:1px solid var(--irlix-color-border);border-radius:9px;background:var(--irlix-color-surface);color:var(--irlix-color-text);font:inherit}.date-mode-switch{display:inline-flex;padding:2px;border:1px solid var(--irlix-color-border);border-radius:9px;background:var(--irlix-color-surface-muted)}.date-mode-switch button{height:28px;padding:0 12px;border:0;border-radius:7px;background:transparent;color:var(--irlix-color-text);font-size:var(--irlix-font-size-caption);font-weight:600;cursor:pointer}.date-mode-switch button.active{background:var(--irlix-color-surface);color:#137a63;box-shadow:0 1px 3px rgba(35,45,55,.12)}.funnel-filter-bar{padding:0!important;border:0!important;background:transparent!important}.locked-filter.locked{opacity:.55;filter:grayscale(.45)}.scope-hint{margin-top:7px;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}.funnel-error{padding:10px 12px;border:1px solid #efcaca;border-radius:10px;background:var(--irlix-color-surface-muted);color:#a83d3d;font-size:var(--irlix-font-size-caption)}.funnel-error button{margin-left:8px;border:0;background:none;color:inherit;text-decoration:underline;cursor:pointer}.kpi-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;transition:opacity .15s}.kpi-grid article{display:grid;gap:3px;min-height:88px;padding:12px 14px;border:1px solid var(--irlix-color-border);border-radius:11px;background:var(--irlix-color-surface)}.kpi-grid span{color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption);font-weight:700;text-transform:uppercase}.kpi-grid strong{font-size:var(--irlix-font-size-page-title);line-height:1.1;color:var(--irlix-color-text)}.kpi-grid small{color:#168067;font-size:var(--irlix-font-size-caption);font-weight:700}.muted{opacity:.62}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 15px;border-bottom:1px solid var(--irlix-color-border)}.panel-head h2{margin:0;font-size:var(--irlix-font-size-body)}.panel-head p{margin:3px 0 0;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}.panel-head--breakdown>div:last-child{min-width:210px}.funnel-stages{display:flex;align-items:stretch;overflow-x:auto;padding:16px}.funnel-stage{display:flex;align-items:center;min-width:190px;flex:1}.stage-card{display:grid;align-content:start;gap:5px;width:100%;min-height:150px;padding:12px;border:1px solid var(--irlix-color-border);border-radius:11px;background:var(--irlix-color-surface-muted)}.stage-index{display:grid;place-items:center;width:20px;height:20px;border-radius:50%;background:var(--irlix-color-surface-muted);color:#137a63;font-size:var(--irlix-font-size-caption);font-weight:800}.stage-card h3{margin:3px 0 0;font-size:var(--irlix-font-size-caption);min-height:28px}.stage-card>strong{font-size:var(--irlix-font-size-page-title)}.stage-rates{display:grid;gap:2px;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}.stage-failure{justify-self:start;margin-top:auto;padding:0;border:0;background:none;color:#c44c4c;font-size:var(--irlix-font-size-caption);font-weight:700;cursor:pointer}.stage-failure--empty{color:var(--irlix-color-text-muted);font-weight:500;cursor:default}.stage-arrow{padding:0 7px;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-section-title)}.failure-reasons{display:grid}.failure-reason-row{display:grid;grid-template-columns:minmax(0,1fr) 70px 70px;gap:10px;align-items:center;padding:9px 15px;border-bottom:1px solid var(--irlix-color-border);font-size:var(--irlix-font-size-caption)}.failure-reason-row:last-child{border-bottom:0}.failure-reason-row strong,.failure-reason-row small{text-align:right}.failure-reason-row small{color:var(--irlix-color-text-muted)}.breakdown-wrap{overflow:auto}.breakdown-wrap table{width:100%;min-width:920px;border-collapse:collapse}.breakdown-wrap th,.breakdown-wrap td{padding:9px 11px;border-bottom:1px solid var(--irlix-color-border);text-align:left;font-size:var(--irlix-font-size-caption);white-space:nowrap}.breakdown-wrap th{background:var(--irlix-color-surface-muted);color:var(--irlix-color-text);font-weight:700}.breakdown-wrap tbody tr:last-child td{border-bottom:0}.empty-state,.empty-cell{padding:24px!important;text-align:center!important;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}@media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(3,1fr)}}@media(max-width:760px){.date-filter-block{align-items:stretch;flex-wrap:wrap}.kpi-grid{grid-template-columns:1fr 1fr}.funnel-stage{min-width:175px}}
</style>

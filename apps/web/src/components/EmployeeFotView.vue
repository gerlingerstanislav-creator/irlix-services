<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiPanel, UiPeriodPicker, UiSearchSelect } from '@irlix/ui';
import { employeeTreeOptions } from '../staffTree';

const props = defineProps({
  access: { type: Object, required: true },
  employees: { type: Array, default: () => [] },
  departments: { type: Array, default: () => [] },
});
const year = ref(new Date().getFullYear());
const departmentId = ref('');
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const overview = ref({ summary: {}, employees: [] });
const reviews = ref([]);
const inbox = ref([]);
const drawerOpen = ref(false);
const selectedReview = ref(null);
const form = reactive({ employee_id: '', effective_month: '', gross_salary: '', comment: '' });
const comments = reactive({});
const canPlan = computed(() => !!props.access?.permissions?.['employees.fot.plan']);
const canApprove = computed(() => !!props.access?.permissions?.['employees.fot.approve']);
const months = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
const scopedEmployeeOptions = computed(() => employeeTreeOptions(props.departments, props.employees));
const currentYear = new Date().getFullYear();
const statusNames = { draft: 'Черновик', pending: 'На согласовании', returned: 'На доработке', approved: 'Согласован', cancelled: 'Отменён' };
const statusTones = { draft: 'neutral', pending: 'info', returned: 'warning', approved: 'success', cancelled: 'neutral' };
const price = value => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(Number(value || 0)) + ' ₽';
const total = field => Object.values(overview.value.summary || {}).reduce((sum, entry) => sum + Number(entry[field] || 0), 0);
const currentTotal = computed(() => total('current'));
const plannedTotal = computed(() => total('planned'));
const difference = computed(() => plannedTotal.value - currentTotal.value);
const years = computed(() => Array.from({ length: 7 }, (_, i) => currentYear - 2 + i));
async function api(url, options = {}) {
  const res = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...options.headers } });
  const result = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(result.message || Object.values(result.errors || {}).flat()[0] || ('HTTP ' + res.status));
  return result.data;
}
async function load() {
  loading.value = true;
  error.value = '';
  try {
    const qs = new URLSearchParams({ year: String(year.value) });
    if (departmentId.value) qs.set('department_id', String(departmentId.value));
    overview.value = await api('/api/employees/fot/overview?' + qs);
  } catch (e) { error.value = e.message; overview.value = { summary: {}, employees: [] }; }
  try {
    reviews.value = await api('/api/employees/fot/reviews');
    if (canApprove.value) inbox.value = await api('/api/employees/fot/inbox');
  } catch (e) { error.value = error.value || e.message; }
  finally { loading.value = false; }
}
watch([year, departmentId], load);
onMounted(load);
function openForm(review = null, employeeId = '') {
  selectedReview.value = review;
  form.employee_id = review ? String(review.employee_id) : String(employeeId || '');
  form.effective_month = review ? String(review.effective_month).slice(0, 7) : '';
  form.gross_salary = review ? String(review.gross_salary) : '';
  form.comment = review?.comment || '';
  error.value = '';
  drawerOpen.value = true;
}
async function save() {
  if (!canPlan.value || busy.value) return;
  if (!form.employee_id || !form.effective_month) { error.value = 'Укажите сотрудника и месяц'; return; }
  busy.value = true; error.value = '';
  try {
    const body = {
      effective_month: form.effective_month + '-01',
      gross_salary: Number(form.gross_salary),
      comment: form.comment || null,
    };
    if (!selectedReview.value) body.employee_id = Number(form.employee_id);
    const item = selectedReview.value;
    await api(item ? '/api/employees/fot/reviews/' + item.id : '/api/employees/fot/reviews', {
      method: item ? 'PATCH' : 'POST', body: JSON.stringify(body),
    });
    drawerOpen.value = false; await load();
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function reviewAction(id, action, body = null) {
  if (busy.value) return;
  if (action === 'cancel' && !window.confirm('Отменить запланированный пересмотр?')) return;
  busy.value = true; error.value = '';
  try {
    await api('/api/employees/fot/reviews/' + id + '/' + action, { method: 'POST', body: JSON.stringify(body || {}) });
    await load();
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
const canEditReview = review => canPlan.value && Number(review.created_by) === Number(props.access.employee_id)
  && ['draft', 'returned', 'approved'].includes(review.status);
const ownReview = review => canPlan.value && Number(review.created_by) === Number(props.access.employee_id);
const dateLabel = value => value ? new Date(String(value).slice(0, 10) + 'T00:00:00').toLocaleDateString('ru-RU', { month: 'short', year: 'numeric' }) : '—';
</script>

<template>
  <div class="fot-page">
    <div class="fot-toolbar">
      <label>Год <UiPeriodPicker v-model="year" mode="year" :min-year="currentYear - 2" :max-year="currentYear + 4" /></label>
      <label>Направление <select v-model="departmentId" aria-label="Направление ФОТ"><option value="">Все доступные</option><option v-for="dept in overview.departments || departments" :key="dept.id" :value="String(dept.id)">{{ dept.name }}</option></select></label>
      <UiButton v-if="canPlan" @click="openForm()">+ Запланировать пересмотр</UiButton>
    </div>
    <div class="fot-stat-grid">
      <UiPanel><small>Текущий ФОТ за год</small><strong>{{ price(currentTotal) }}</strong></UiPanel>
      <UiPanel><small>Плановый ФОТ за год</small><strong>{{ price(plannedTotal) }}</strong></UiPanel>
      <UiPanel><small>Изменение ФОТ</small><strong :class="difference > 0 ? 'fot-positive' : ''">{{ difference > 0 ? '+' : '' }}{{ price(difference) }}</strong></UiPanel>
    </div>
    <p v-if="error" class="fot-error" role="alert">{{ error }}</p>
    <UiPanel class="fot-table-shell">
      <div v-if="loading" class="fot-empty">Обновляем расчёты…</div>
      <div v-else class="fot-table-scroll">
        <table class="fot-matrix">
          <thead><tr><th>Сотрудник / ФОТ</th><th v-for="month in months" :key="month">{{ month }}</th></tr></thead>
          <tbody>
            <tr class="fot-total"><th>Текущий ФОТ</th><td v-for="m in 12" :key="m">{{ price(overview.summary[m]?.current) }}</td></tr>
            <tr class="fot-total"><th>Плановый ФОТ</th><td v-for="m in 12" :key="m">{{ price(overview.summary[m]?.planned) }}</td></tr>
            <tr class="fot-total fot-diff"><th>Разница</th><td v-for="m in 12" :key="m">{{ price(overview.summary[m]?.difference) }}</td></tr>
            <tr v-if="!overview.employees?.length"><td colspan="13" class="fot-empty">Нет сотрудников с ФОТ за выбранный период</td></tr>
            <tr v-for="employee in overview.employees || []" :key="employee.id">
              <th><button class="fot-person" type="button" @click="openForm(null, employee.id)" :disabled="!canPlan">{{ employee.name }}</button></th>
              <td v-for="m in 12" :key="m" :class="{ 'fot-modified': employee.months[m]?.planned !== employee.months[m]?.current }">
                <span>{{ price(employee.months[m]?.planned) }}</span>
                <small v-if="employee.months[m]?.planned !== employee.months[m]?.current">Факт: {{ price(employee.months[m]?.current) }}</small>
                <button v-for="r in (employee.reviews || []).filter(x => Number(String(x.month).slice(5,7)) === m && Number(String(x.month).slice(0,4)) === year)"
                  :key="r.id" type="button" class="fot-review-tag" :disabled="!canEditReview(reviews.find(review => Number(review.id) === Number(r.id)) || {})" @click="openForm(reviews.find(review => Number(review.id) === Number(r.id)))">{{ statusNames[r.status] || r.status }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </UiPanel>
    <UiPanel v-if="canApprove && inbox.length" class="fot-register">
      <h3>Ожидают моего согласования</h3>
      <div v-for="item in inbox" :key="item.id" class="fot-review-row">
        <div><strong>{{ item.employee_name }}</strong><small>{{ dateLabel(item.effective_month) }} · {{ price(item.gross_salary) }}</small></div>
        <input v-model="comments[item.id]" placeholder="Комментарий для возврата" :aria-label="'Комментарий по ' + item.employee_name" />
        <UiButton compact :disabled="busy" @click="reviewAction(item.id, 'decision', { decision: 'approved' })">Согласовать</UiButton>
        <UiButton compact variant="secondary" :disabled="busy || !comments[item.id]?.trim()" @click="reviewAction(item.id, 'decision', { decision: 'returned', comment: comments[item.id] })">Вернуть</UiButton>
      </div>
    </UiPanel>
    <UiPanel class="fot-register">
      <h3>Пересмотры зарплат</h3>
      <div v-if="!reviews.length" class="fot-empty">Пока нет запланированных пересмотров</div>
      <div v-for="item in reviews" :key="item.id" class="fot-review-row">
        <div><strong>{{ item.employee_name }}</strong><small>{{ dateLabel(item.effective_month) }} · {{ price(item.gross_salary) }}</small></div>
        <UiBadge :tone="statusTones[item.status] || 'neutral'">{{ statusNames[item.status] || item.status }}</UiBadge>
        <UiButton v-if="canEditReview(item)" compact variant="secondary" @click="openForm(item)">Изменить</UiButton>
        <UiButton v-if="ownReview(item) && ['draft','returned'].includes(item.status)" compact :disabled="busy" @click="reviewAction(item.id,'submit')">Отправить</UiButton>
        <UiButton v-if="ownReview(item) && item.status !== 'cancelled'" compact variant="secondary" :disabled="busy" @click="reviewAction(item.id,'cancel')">Отменить</UiButton>
        <details v-if="item.approvals?.length || item.versions?.length"><summary>История</summary>
          <p v-for="version in item.versions || []" :key="'v'+version.id">Предыдущая версия: {{ dateLabel(version.effective_month) }} · {{ price(version.gross_salary) }} · {{ statusNames[version.status] || version.status }}</p>
          <p v-for="step in item.approvals" :key="step.id">{{ step.approver_name }} · {{ step.decision }} <span v-if="step.comment">— {{ step.comment }}</span></p>
        </details>
      </div>
    </UiPanel>
    <UiDrawer :open="drawerOpen" :title="selectedReview ? 'Редактирование пересмотра' : 'Плановый пересмотр зарплаты'" width="520px" @close="drawerOpen=false">
      <form class="fot-form" @submit.prevent="save">
        <label>Сотрудник
          <UiSearchSelect v-model="form.employee_id" :options="scopedEmployeeOptions" :disabled="!!selectedReview" placeholder="Выберите сотрудника" search-placeholder="Поиск сотрудника" />
        </label>
        <label>Месяц вступления в силу <UiPeriodPicker v-model="form.effective_month" mode="month" :min-year="currentYear - 2" :max-year="currentYear + 4" /></label>
        <label>Новый оклад gross, ₽ <input v-model="form.gross_salary" type="number" min="0" step="0.01" required /></label>
        <label>Обоснование <textarea v-model="form.comment" rows="4" placeholder="Причина пересмотра" /></label>
        <p v-if="error" class="fot-error" role="alert">{{ error }}</p>
        <div class="fot-form-actions"><UiButton type="button" variant="secondary" @click="drawerOpen=false">Отмена</UiButton><UiButton type="submit" :disabled="busy">{{ busy ? 'Сохраняем…' : 'Сохранить черновик' }}</UiButton></div>
      </form>
    </UiDrawer>
  </div>
</template>

<style scoped>
.fot-page{padding:16px 20px 24px;display:flex;flex-direction:column;gap:14px;min-width:0;min-height:0;height:100%;overflow-y:auto}
.fot-toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:end}
.fot-toolbar label,.fot-form label{display:flex;flex-direction:column;gap:5px;font-size:12px;color:var(--irlix-color-text-muted)}
.fot-toolbar select,.fot-form input,.fot-form textarea,.fot-review-row input{height:36px;padding:7px 10px;border:1px solid var(--irlix-color-border);background:var(--irlix-color-bg);color:var(--irlix-color-text);border-radius:8px;font:inherit}
.fot-form textarea{height:auto;min-height:96px}
.fot-stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.fot-stat-grid :deep(.irlix-panel){padding:12px}
.fot-stat-grid small{display:block;color:var(--irlix-color-text-muted)}
.fot-stat-grid strong{display:block;margin-top:6px;font-size:18px;font-weight:700}
.fot-positive{color:var(--irlix-color-primary-text)}
.fot-table-shell{min-height:310px;min-width:0;flex:none}
.fot-table-scroll{overflow:auto;max-height:min(55vh,580px)}
.fot-matrix{border-collapse:separate;border-spacing:0;font-variant-numeric:tabular-nums;min-width:1500px;width:100%;font-size:12px}
.fot-matrix th,.fot-matrix td{padding:8px 9px;text-align:right;white-space:nowrap;border-bottom:1px solid var(--irlix-color-border)}
.fot-matrix td{min-width:108px}
.fot-matrix thead th{position:sticky;top:0;background:var(--irlix-color-bg);z-index:2}
.fot-matrix th:first-child{position:sticky;left:0;z-index:1;min-width:220px;max-width:220px;text-align:left;background:var(--irlix-color-bg)}
.fot-matrix thead th:first-child{z-index:3}
.fot-total th,.fot-total td{font-weight:700;background:var(--irlix-color-bg)}
.fot-diff td{color:var(--irlix-color-primary-text)}
.fot-modified{background:color-mix(in srgb, var(--irlix-color-primary) 8%, transparent)}
.fot-matrix td small{display:block;font-size:10px;color:var(--irlix-color-text-muted)}
.fot-person{border:0;background:none;color:inherit;cursor:pointer;text-align:left;font-weight:600}
.fot-person:disabled{cursor:default}
.fot-review-tag{display:block;padding:2px 0;font-size:10px;color:var(--irlix-color-primary-text);background:none;border:0;cursor:pointer}
.fot-register{padding:12px;min-width:0}
.fot-register h3{margin:0 0 12px;font-size:15px}
.fot-review-row{display:flex;align-items:center;flex-wrap:wrap;gap:10px;padding:10px 0;border-bottom:1px solid var(--irlix-color-border)}
.fot-review-row>div:first-child{margin-right:auto;min-width:190px}
.fot-review-row small{display:block;color:var(--irlix-color-text-muted);font-size:11px}
.fot-review-row details{width:100%;font-size:12px}
.fot-review-row details p{margin:4px 0;color:var(--irlix-color-text-muted)}
.fot-empty{padding:24px;color:var(--irlix-color-text-muted)}
.fot-error{padding:10px;color:var(--irlix-color-danger-text,#b42318)}
.fot-form{display:flex;flex-direction:column;gap:18px}
.fot-form-actions{display:flex;justify-content:flex-end;gap:8px}
@media(max-width:760px){.fot-page{padding:12px}.fot-stat-grid{grid-template-columns:1fr}.fot-matrix th:first-child{min-width:150px;max-width:150px}}
</style>

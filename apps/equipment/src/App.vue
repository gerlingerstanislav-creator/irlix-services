<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { UiAppShell, UiBadge, UiButton, UiFilterRail, UiPanel, UiSearchSelect } from '@irlix/ui';
import { auth } from './auth';
import EquipmentCardDrawer from './EquipmentCardDrawer.vue';

const sections = [
  { id: 'registry', label: 'Техника', href: '/equipment/' },
  { id: 'assignments', label: 'Выдачи', href: '/equipment/assignments' },
  { id: 'depreciation', label: 'Амортизация', href: '/equipment/depreciation' },
  { id: 'written-off', label: 'Списанная техника', href: '/equipment/written-off' },
];
const labels = {
  pc: 'ПК', laptop: 'Ноутбук', smartphone: 'Смартфон', tablet: 'Планшет',
  development: 'Разработка', management_qa: 'Менеджмент + QA', qa: 'QA', management: 'Менеджмент',
  ok: 'Исправно', damaged: 'Повреждено', needs_repair: 'Требует ремонта',
};
const typeOrder = { pc: 1, laptop: 2, smartphone: 3, tablet: 4 };

const pathSection = () => location.pathname.includes('/assignments') ? 'assignments'
  : location.pathname.includes('/depreciation') ? 'depreciation'
  : location.pathname.includes('/written-off') ? 'written-off'
  : 'registry';
const itemIdFromPath = () => Number(location.pathname.match(/\/equipment\/items\/(\d+)/)?.[1] || 0) || null;

const section = ref(pathSection());
const items = ref([]);
const assignments = ref([]);
const depreciation = ref([]);
const employees = ref([]);
const permissions = reactive({ view: false, manage: false, operate: false });
const loading = ref(false);
const error = ref('');
const search = ref('');
const typeFilter = ref('');
const selectedItemId = ref(itemIdFromPath());
const drawerReturnPath = ref('/equipment/');

const editor = reactive({ open: false, id: null, inventory_number: '', type: 'laptop', manufacturer: '', model: '', serial_number: '', purpose: 'development', condition: 'ok', comment: '', purchased_on: '', purchase_cost: '', useful_life_months: 36, cpu: '', ram: '', storage: '', gpu: '', os: '', os_version: '', imei: '' });
const assignment = reactive({ open: false, item: null, employee_id: '', starts_on: new Date().toISOString().slice(0, 10), planned_ends_on: '', issue_comment: '' });
const returning = reactive({ open: false, item: null, returned_on: new Date().toISOString().slice(0, 10), return_comment: '' });

const money = (value) => new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 2 }).format(Number(value || 0));
const shortEmployeeName = (id) => {
  const full = employees.value.find((employee) => Number(employee.id) === Number(id))?.full_name || `#${id}`;
  return full.trim().split(/\s+/).slice(0, 2).join(' ');
};

const employeeOptions = computed(() => {
  const grouped = new Map();
  for (const employee of employees.value) {
    const group = employee.department_name || 'Без подразделения';
    if (!grouped.has(group)) grouped.set(group, []);
    grouped.get(group).push(employee);
  }
  return [...grouped.entries()].sort(([a], [b]) => a.localeCompare(b, 'ru')).flatMap(([department, rows]) => [
    { value: `group:${department}`, label: department, kind: 'group' },
    ...rows.sort((a, b) => String(a.full_name).localeCompare(String(b.full_name), 'ru')).map((employee) => ({ value: String(employee.id), label: employee.full_name, depth: 1 })),
  ]);
});
const typeFilterOptions = computed(() => ['pc', 'laptop', 'smartphone', 'tablet'].map((type) => ({ value: type, label: labels[type] })));
const filterItems = computed(() => [
  { id: 'search', label: 'Поиск', icon: 'search', active: Boolean(search.value.trim()), valueLabel: search.value.trim() },
  { id: 'type', label: 'Тип', icon: 'briefcase', active: Boolean(typeFilter.value), valueLabel: labels[typeFilter.value] || '' },
]);

const filtered = computed(() => items.value
  .filter((item) => (!typeFilter.value || item.type === typeFilter.value)
    && (!search.value || `${item.inventory_number} ${item.manufacturer} ${item.model} ${item.serial_number || ''}`.toLowerCase().includes(search.value.toLowerCase())))
  .sort((a, b) => {
    const issued = Number(Boolean(a.assignment)) - Number(Boolean(b.assignment));
    if (issued) return issued;
    const type = (typeOrder[a.type] || 99) - (typeOrder[b.type] || 99);
    if (type) return type;
    const manufacturer = String(a.manufacturer || '').localeCompare(String(b.manufacturer || ''), 'ru', { sensitivity: 'base' });
    if (manufacturer) return manufacturer;
    return String(a.model || '').localeCompare(String(b.model || ''), 'ru', { sensitivity: 'base' });
  }));

const api = async (url, options = {}) => {
  const response = await fetch(url, options);
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.errors ? Object.values(body.errors).flat()[0] : body.message || `HTTP ${response.status}`);
  return body.data;
};
const ensureEmployees = async () => { if (!employees.value.length) employees.value = await api('/api/employees-directory'); };

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    Object.assign(permissions, await api('/api/permissions'));
    if (section.value === 'assignments') {
      await ensureEmployees();
      assignments.value = await api('/api/assignments');
    } else if (section.value === 'depreciation') {
      depreciation.value = await api('/api/depreciation');
    } else {
      await ensureEmployees();
      items.value = await api(`/api/items?${section.value === 'written-off' ? 'written_off=1' : ''}`);
    }
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
};

const changeSection = (next) => {
  section.value = next;
  selectedItemId.value = null;
  const href = sections.find((item) => item.id === next)?.href || '/equipment/';
  history.pushState({}, '', href);
  load();
};
const resetFilters = () => { search.value = ''; typeFilter.value = ''; };
const openItem = (item) => {
  drawerReturnPath.value = `${location.pathname}${location.search}`;
  selectedItemId.value = item.id;
  history.pushState({}, '', `/equipment/items/${item.id}`);
};
const closeItem = () => {
  selectedItemId.value = null;
  if (location.pathname.includes('/equipment/items/')) history.pushState({}, '', drawerReturnPath.value || '/equipment/');
};

const resetEditor = () => Object.assign(editor, { open: false, id: null, inventory_number: '', type: 'laptop', manufacturer: '', model: '', serial_number: '', purpose: 'development', condition: 'ok', comment: '', purchased_on: '', purchase_cost: '', useful_life_months: 36, cpu: '', ram: '', storage: '', gpu: '', os: '', os_version: '', imei: '' });
const openEditor = () => { resetEditor(); editor.open = true; };
const saveItem = async () => {
  try {
    const payload = {};
    for (const key of ['inventory_number', 'type', 'manufacturer', 'model', 'serial_number', 'purpose', 'condition', 'comment', 'purchased_on', 'purchase_cost', 'useful_life_months', 'cpu', 'ram', 'storage', 'gpu', 'os', 'os_version', 'imei']) payload[key] = editor[key] === '' ? null : editor[key];
    await api('/api/items', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    resetEditor();
    await load();
  } catch (e) { error.value = e.message; }
};

const openAssign = async (item) => {
  try {
    await ensureEmployees();
    Object.assign(assignment, { open: true, item, employee_id: '', starts_on: new Date().toISOString().slice(0, 10), planned_ends_on: '', issue_comment: '' });
  } catch (e) { error.value = e.message; }
};
const assignItem = async () => {
  try {
    await api(`/api/items/${assignment.item.id}/assign`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ employee_id: Number(assignment.employee_id), starts_on: assignment.starts_on, planned_ends_on: assignment.planned_ends_on || null, issue_comment: assignment.issue_comment || null }) });
    assignment.open = false;
    await load();
  } catch (e) { error.value = e.message; }
};
const openReturn = (item) => Object.assign(returning, { open: true, item, returned_on: new Date().toISOString().slice(0, 10), return_comment: '' });
const returnItem = async () => {
  try {
    await api(`/api/items/${returning.item.id}/return`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ returned_on: returning.returned_on, return_comment: returning.return_comment || null }) });
    returning.open = false;
    await load();
  } catch (e) { error.value = e.message; }
};
const writeOff = async (item) => {
  const reason = prompt('Причина списания');
  if (!reason) return;
  const date = prompt('Дата списания (YYYY-MM-DD)', new Date().toISOString().slice(0, 10));
  if (!date) return;
  try {
    await api(`/api/items/${item.id}/write-off`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ written_off_on: date, reason }) });
    closeItem();
    await load();
  } catch (e) { error.value = e.message; }
};

const handlePopState = () => {
  section.value = pathSection();
  selectedItemId.value = itemIdFromPath();
  load();
};
onMounted(() => { window.addEventListener('popstate', handlePopState); load(); });
onBeforeUnmount(() => window.removeEventListener('popstate', handlePopState));
watch(section, resetFilters);
</script>

<template>
  <UiAppShell service="equipment" service-name="Учёт техники" :section="section" :items="sections" :current-user="auth.user" :platform-access="() => auth.fetch('/api/employees/access/me')" :loading="loading" @update:section="changeSection" @logout="auth.logout">
    <template #actions>
      <div class="equipment-topbar-summary">
        <span v-if="section === 'registry'"><strong>{{ filtered.length }}</strong> из {{ items.length }} единиц</span>
        <span v-else-if="section === 'assignments'"><strong>{{ assignments.length }}</strong> выдач</span>
        <span v-else-if="section === 'depreciation'"><strong>{{ depreciation.length }}</strong> позиций</span>
        <span v-else><strong>{{ items.length }}</strong> списано</span>
      </div>
      <UiButton v-if="permissions.manage && section === 'registry'" @click="openEditor">+ Техника</UiButton>
    </template>

    <main class="equipment-page" :class="{ 'equipment-page--with-filter': section === 'registry' || section === 'written-off' }">
      <div v-if="error" class="equipment-error">{{ error }}</div>

      <UiPanel v-if="section === 'registry' || section === 'written-off'" class="equipment-registry-panel">
        <div v-if="loading" class="equipment-empty">Загрузка…</div>
        <div v-else class="equipment-table-wrap">
          <table class="irlix-data-table equipment-table">
            <thead><tr><th>Инв. №</th><th>Тип</th><th>Изготовитель</th><th>Модель</th><th>Серийный номер</th><th>Назначение</th><th>Состояние</th><th>Выдана</th><th></th><th>Стоимость</th><th>Остаточная</th></tr></thead>
            <tbody>
              <tr v-for="item in filtered" :key="item.id">
                <td><button type="button" class="equipment-inventory-link" @click="openItem(item)">{{ item.inventory_number }}</button></td>
                <td>{{ labels[item.type] }}</td>
                <td>{{ item.manufacturer }}</td>
                <td>{{ item.model }}</td>
                <td>{{ item.serial_number || '—' }}</td>
                <td>{{ labels[item.purpose] }}</td>
                <td><UiBadge :tone="item.condition === 'ok' ? 'success' : item.condition === 'damaged' ? 'warning' : 'danger'">{{ labels[item.condition] }}</UiBadge></td>
                <td>{{ item.assignment ? shortEmployeeName(item.assignment.employee_id) : '—' }}</td>
                <td class="equipment-row-action">
                  <UiButton v-if="permissions.operate && !item.written_off_at && item.assignment" compact variant="secondary" @click="openReturn(item)">Вернуть</UiButton>
                  <UiButton v-else-if="permissions.operate && !item.written_off_at" compact variant="secondary" @click="openAssign(item)">Выдать</UiButton>
                </td>
                <td>{{ money(item.purchase_cost) }}</td>
                <td>{{ money(item.depreciation?.residual) }}</td>
              </tr>
              <tr v-if="!filtered.length"><td colspan="11" class="equipment-empty">Нет данных</td></tr>
            </tbody>
          </table>
        </div>
      </UiPanel>

      <UiPanel v-else-if="section === 'assignments'" class="equipment-registry-panel">
        <div class="equipment-table-wrap"><table class="irlix-data-table equipment-table"><thead><tr><th>Техника</th><th>Сотрудник</th><th>Начало</th><th>План возврата</th><th>Возврат</th><th>Комментарий после возврата</th><th>Кто выдал</th></tr></thead><tbody><tr v-for="record in assignments" :key="record.id"><td>{{ record.inventory_number }} · {{ record.manufacturer }} {{ record.model }}</td><td>{{ shortEmployeeName(record.employee_id) }}</td><td>{{ record.starts_on }}</td><td>{{ record.planned_ends_on || 'Без срока' }}</td><td>{{ record.returned_on || 'Активна' }}</td><td>{{ record.return_comment || '—' }}</td><td>{{ record.issued_by }}</td></tr></tbody></table></div>
      </UiPanel>

      <UiPanel v-else class="equipment-registry-panel">
        <div class="equipment-table-wrap"><table class="irlix-data-table equipment-table"><thead><tr><th>Инв. №</th><th>Изготовитель</th><th>Модель</th><th>Первоначальная</th><th>В месяц</th><th>Месяцев</th><th>Амортизация</th><th>Остаточная</th></tr></thead><tbody><tr v-for="row in depreciation" :key="row.id"><td><strong>{{ row.inventory_number }}</strong></td><td>{{ row.manufacturer }}</td><td>{{ row.model }}</td><td>{{ money(row.purchase_cost) }}</td><td>{{ money(row.depreciation.monthly) }}</td><td>{{ row.depreciation.months }}</td><td>{{ money(row.depreciation.accumulated) }}</td><td><strong>{{ money(row.depreciation.residual) }}</strong></td></tr></tbody></table></div>
      </UiPanel>
    </main>

    <UiFilterRail v-if="section === 'registry' || section === 'written-off'" class="equipment-filter-rail" :items="filterItems" @reset="resetFilters">
      <label class="irlix-field"><span>Поиск</span><input v-model="search" type="search" placeholder="Инв. №, изготовитель, модель, серийный №" /></label>
      <label class="irlix-field"><span>Тип</span><UiSearchSelect v-model="typeFilter" :options="typeFilterOptions" placeholder="Все типы" search-placeholder="Поиск типа" /></label>
    </UiFilterRail>

    <EquipmentCardDrawer v-if="selectedItemId" :item-id="selectedItemId" :employees="employees" :can-manage="permissions.manage" :can-operate="permissions.operate" @close="closeItem" @updated="load" @write-off="writeOff" />

    <div v-if="editor.open" class="equipment-modal-backdrop" @click.self="resetEditor"><form class="equipment-modal" @submit.prevent="saveItem"><h2>Новая техника</h2><div class="equipment-form-grid"><label>Инв. номер<input v-model="editor.inventory_number" required></label><label>Тип<select v-model="editor.type"><option v-for="type in ['pc','laptop','smartphone','tablet']" :key="type" :value="type">{{ labels[type] }}</option></select></label><label>Изготовитель<input v-model="editor.manufacturer" required></label><label>Модель<input v-model="editor.model" required></label><label>Серийный номер<input v-model="editor.serial_number"></label><label>Назначение<select v-model="editor.purpose"><option v-for="purpose in ['development','management_qa','qa','management']" :key="purpose" :value="purpose">{{ labels[purpose] }}</option></select></label><label>Состояние<select v-model="editor.condition"><option v-for="condition in ['ok','damaged','needs_repair']" :key="condition" :value="condition">{{ labels[condition] }}</option></select></label><label>Дата покупки<input v-model="editor.purchased_on" type="date"></label><label>Стоимость<input v-model="editor.purchase_cost" type="number" min="0" step="0.01"></label><label>Срок, мес.<input v-model="editor.useful_life_months" type="number" min="1"></label><label>Процессор<input v-model="editor.cpu"></label><label>ОЗУ<input v-model="editor.ram"></label><label>HDD/SSD<input v-model="editor.storage"></label><label>Видеокарта<input v-model="editor.gpu"></label><label>ОС<input v-model="editor.os"></label><label>Версия ОС<input v-model="editor.os_version"></label><label v-if="editor.type === 'smartphone' || editor.type === 'tablet'">IMEI<input v-model="editor.imei"></label><label class="wide">Комментарий<textarea v-model="editor.comment"></textarea></label></div><div class="equipment-modal-actions"><UiButton variant="secondary" type="button" @click="resetEditor">Отмена</UiButton><UiButton type="submit">Сохранить</UiButton></div></form></div>

    <div v-if="assignment.open" class="equipment-modal-backdrop" @click.self="assignment.open = false"><form class="equipment-modal equipment-modal--narrow" @submit.prevent="assignItem"><h2>Выдать {{ assignment.item.inventory_number }}</h2><label class="irlix-field"><span>Сотрудник</span><UiSearchSelect v-model="assignment.employee_id" :options="employeeOptions" placeholder="Выберите сотрудника" search-placeholder="Поиск сотрудника" /></label><label>Дата выдачи<input v-model="assignment.starts_on" type="date" required></label><label>Плановый возврат<input v-model="assignment.planned_ends_on" type="date"></label><label>Комментарий<textarea v-model="assignment.issue_comment"></textarea></label><div class="equipment-modal-actions"><UiButton variant="secondary" type="button" @click="assignment.open = false">Отмена</UiButton><UiButton type="submit" :disabled="!assignment.employee_id">Выдать</UiButton></div></form></div>

    <div v-if="returning.open" class="equipment-modal-backdrop" @click.self="returning.open = false"><form class="equipment-modal equipment-modal--narrow" @submit.prevent="returnItem"><h2>Вернуть {{ returning.item.inventory_number }}</h2><label>Дата возврата<input v-model="returning.returned_on" type="date" required></label><label>Комментарий после возврата<textarea v-model="returning.return_comment" placeholder="Состояние, комплектность, замечания"></textarea></label><div class="equipment-modal-actions"><UiButton variant="secondary" type="button" @click="returning.open = false">Отмена</UiButton><UiButton type="submit">Вернуть</UiButton></div></form></div>
  </UiAppShell>
</template>

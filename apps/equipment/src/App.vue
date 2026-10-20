<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { UiAppShell, UiBadge, UiButton, UiFilterRail, UiPanel, UiRegistryTable, UiSearchSelect } from '@irlix/ui';
import { auth } from './auth';
import EquipmentCardDrawer from './EquipmentCardDrawer.vue';

const sections = [
  { id: 'registry', label: 'Техника', href: '/equipment/', icon: 'briefcase' },
  { id: 'assignments', label: 'Выдачи', href: '/equipment/assignments', icon: 'audit' },
  { id: 'valuation', label: 'Оценка стоимости', href: '/equipment/valuation', icon: 'cash' },
  { id: 'written-off', label: 'Списанная техника', href: '/equipment/written-off', icon: 'status' },
  { id: 'settings', label: 'Настройки', href: '/equipment/settings', icon: 'manage' },
];
const labels = {
  pc: 'ПК', laptop: 'Ноутбук', smartphone: 'Смартфон', tablet: 'Планшет',
  development: 'Разработка', management_qa: 'Менеджмент + QA', qa: 'QA', management: 'Менеджмент', unknown: 'Не указано',
  ok: 'Исправно', damaged: 'Повреждено', needs_repair: 'Требует ремонта',
};
const typeOrder = { pc: 1, laptop: 2, smartphone: 3, tablet: 4 };
const defaultSettings = { pc: 48, laptop: 36, smartphone: 36, tablet: 36 };
const pathSection = () => location.pathname.includes('/assignments') ? 'assignments' : (location.pathname.includes('/valuation') || location.pathname.includes('/depreciation')) ? 'valuation' : location.pathname.includes('/written-off') ? 'written-off' : location.pathname.includes('/settings') ? 'settings' : 'registry';
const itemIdFromPath = () => Number(location.pathname.match(/\/equipment\/items\/(\d+)/)?.[1] || 0) || null;

const section = ref(pathSection());
const items = ref([]);
const assignments = ref([]);
const valuation = ref([]);
const employees = ref([]);
const settings = reactive({ ...defaultSettings });
const settingsDraft = reactive({ ...defaultSettings });
const settingsLoaded = ref(false);
const settingsSaved = ref(false);
const permissions = reactive({ view: false, manage: false, operate: false });
const loading = ref(false);
const error = ref('');
const importOpen = ref(false);
const importFile = ref(null);
const importPreview = ref(null);
const importing = ref(false);
const importError = ref('');
const importFileInput = ref(null);
const selectImportFile = (event) => { importFile.value=event.target.files?.[0] || null; importPreview.value=null; importError.value=''; };
const closeImport = () => { importOpen.value=false; importPreview.value=null; importFile.value=null; importError.value=''; if(importFileInput.value)importFileInput.value.value=''; };
const submitImport = async (apply = false) => {
  if(!importFile.value || importing.value)return;
  importing.value=true; importError.value='';
  try {
    const payload = new FormData();
    payload.append('file',importFile.value);
    const result = await api(`/api/import/${apply?'apply':'preview'}`,{method:'POST',body:payload});
    importPreview.value=result;
    if(apply)await load();
  } catch(e){importError.value=e.message;} finally{importing.value=false;}
};

const search = ref('');
const typeFilter = ref([]);
const issueFilter = ref('all');
const manufacturerFilter = ref([]);
const purposeFilter = ref('');
const writeOffFilter = ref(section.value==='written-off'?'written-off':'active');
const selectedItemId = ref(itemIdFromPath());
const drawerReturnPath = ref('/equipment/');
const drawerVersion = ref(0);

const editor = reactive({ open: false, id: null, inventory_number: '', type: 'laptop', manufacturer: '', model: '', serial_number: '', purpose: 'development', condition: 'ok', comment: '', purchased_on: '', purchase_cost: '', useful_life_months: 36, cpu: '', ram: '', storage: '', gpu: '', os: '', os_version: '', imei: '' });
const assignment = reactive({ open: false, item: null, employee_id: '', starts_on: new Date().toISOString().slice(0, 10), issue_comment: '' });
const returning = reactive({ open: false, item: null, returned_on: new Date().toISOString().slice(0, 10), return_comment: '' });

const money = (value) => new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 2 }).format(Number(value || 0));
const employeeFullName = (id) => employees.value.find(employee => Number(employee.id) === Number(id))?.full_name || '';
const shortEmployeeName = (id) => { const full = employees.value.find((employee) => Number(employee.id) === Number(id))?.full_name || `#${id}`; return full.trim().split(/\s+/).slice(0, 2).join(' '); };
const employeeOptions = computed(() => {
  const grouped = new Map();
  for (const employee of employees.value) { const group = employee.department_name || 'Без подразделения'; if (!grouped.has(group)) grouped.set(group, []); grouped.get(group).push(employee); }
  return [...grouped.entries()].sort(([a],[b]) => a.localeCompare(b,'ru')).flatMap(([department, rows]) => [
    { value: `group:${department}`, label: department, kind: 'group' },
    ...rows.sort((a,b) => String(a.full_name).localeCompare(String(b.full_name),'ru')).map((employee) => ({ value: String(employee.id), label: employee.full_name, depth: 1 })),
  ]);
});
const typeFilterOptions = computed(() => ['pc','laptop','smartphone','tablet'].map((type) => ({ value: type, label: labels[type] })));
const manufacturerOptions = computed(() => [...new Set(items.value.map(item => String(item.manufacturer || '').trim()).filter(Boolean))]
  .sort((a,b) => a.localeCompare(b,'ru',{sensitivity:'base'})).map(name => ({ value: name, label: name })));
const purposeOptions = computed(() => [...new Set(items.value.map(item => item.purpose).filter(Boolean))]
  .map(value => ({ value, label: labels[value] || value })).sort((a,b) => a.label.localeCompare(b.label,'ru')));
const issueOptions = [{ value:'all', label:'Все' }, { value:'issued', label:'Выдана' }, { value:'free', label:'Не выдана' }];
const writeOffOptions = [{ value:'active', label:'Не списана' }, { value:'written-off', label:'Списана' }, { value:'all', label:'Все' }];
const filterItems = computed(() => [
  { id:'search', label:'Поиск', icon:'search', active:Boolean(search.value.trim()), valueLabel:search.value.trim() },
  { id:'issue', label:'Выдача', icon:'contact', active:issueFilter.value !== 'all', valueLabel:issueOptions.find(x=>x.value===issueFilter.value)?.label },
  { id:'type', label:'Тип техники', icon:'briefcase', active:typeFilter.value.length > 0, valueLabel:typeFilter.value.map(type => labels[type] || type).join(', ') },
  { id:'manufacturer', label:'Изготовитель', icon:'building', active:manufacturerFilter.value.length > 0, valueLabel:manufacturerFilter.value.join(', ') },
  { id:'purpose', label:'Назначение', icon:'target', active:Boolean(purposeFilter.value), valueLabel:labels[purposeFilter.value] || purposeFilter.value },
  { id:'writeoff', label:'Списание', icon:'status', active:writeOffFilter.value !== 'active', valueLabel:writeOffOptions.find(x=>x.value===writeOffFilter.value)?.label },
]);
const filtered = computed(() => items.value.filter(item =>
  (!typeFilter.value.length || typeFilter.value.includes(item.type)) &&
  (!manufacturerFilter.value.length || manufacturerFilter.value.includes(item.manufacturer)) &&
  (!purposeFilter.value || item.purpose === purposeFilter.value) &&
  (issueFilter.value === 'all' || Boolean(item.assignment) === (issueFilter.value === 'issued')) &&
  (writeOffFilter.value === 'all' || Boolean(item.written_off_at) === (writeOffFilter.value === 'written-off')) &&
  (!search.value || `${item.inventory_number} ${item.manufacturer} ${item.model} ${item.serial_number || ''} ${item.assignment ? employeeFullName(item.assignment.employee_id) : ''}`.toLowerCase().includes(search.value.toLowerCase()))
).sort((a,b) => {
  const issued=Number(Boolean(a.assignment))-Number(Boolean(b.assignment)); if(issued)return issued;
  const type=(typeOrder[a.type]||99)-(typeOrder[b.type]||99); if(type)return type;
  const manufacturer=String(a.manufacturer||'').localeCompare(String(b.manufacturer||''),'ru',{sensitivity:'base'}); if(manufacturer)return manufacturer;
  return String(a.model||'').localeCompare(String(b.model||''),'ru',{sensitivity:'base'});
}));

const api = async (url, options = {}) => { const response=await fetch(url, options); const body=await response.json().catch(()=>({})); if(!response.ok)throw new Error(body.errors?Object.values(body.errors).flat()[0]:body.message||`HTTP ${response.status}`); return body.data; };
const ensureEmployees = async () => { if(!employees.value.length)employees.value=await api('/api/employees-directory'); };
const loadSettings = async (force = false) => {
  if (settingsLoaded.value && !force) return;
  const data = await api('/api/settings');
  Object.assign(settings, defaultSettings, data || {});
  Object.assign(settingsDraft, settings);
  settingsLoaded.value = true;
};
const load = async () => {
  loading.value=true; error.value=''; settingsSaved.value=false;
  try {
    Object.assign(permissions,await api('/api/permissions'));
    if(section.value==='assignments'){await ensureEmployees(); assignments.value=await api('/api/assignments');}
    else if(section.value==='valuation'){valuation.value=await api('/api/valuation');}
    else if(section.value==='settings'){await loadSettings(true);}
    else {await ensureEmployees(); items.value=await api('/api/items?all=1');}
  } catch(e){error.value=e.message;} finally{loading.value=false;}
};
const refreshAfterMutation = async () => { drawerVersion.value += 1; await load(); };
const changeSection = (next) => { section.value=next; selectedItemId.value=null; history.pushState({},'',sections.find((item)=>item.id===next)?.href||'/equipment/'); load(); };
const resetFilters = () => { search.value=''; typeFilter.value=[]; issueFilter.value='all'; manufacturerFilter.value=[]; purposeFilter.value=''; writeOffFilter.value=section.value==='written-off'?'written-off':'active'; };
const openItem = (item) => { drawerReturnPath.value=`${location.pathname}${location.search}`; selectedItemId.value=item.id; history.pushState({},'',`/equipment/items/${item.id}`); };
const closeItem = () => { selectedItemId.value=null; if(location.pathname.includes('/equipment/items/'))history.pushState({},'',drawerReturnPath.value||'/equipment/'); };

const resetEditor = () => Object.assign(editor,{open:false,id:null,inventory_number:'',type:'laptop',manufacturer:'',model:'',serial_number:'',purpose:'development',condition:'ok',comment:'',purchased_on:'',purchase_cost:'',useful_life_months:settings.laptop||36,cpu:'',ram:'',storage:'',gpu:'',os:'',os_version:'',imei:''});
const openEditor = async () => { try { await loadSettings(); resetEditor(); editor.open=true; } catch(e){ error.value=e.message; } };
const saveItem = async () => { try { const payload={}; for(const key of ['inventory_number','type','manufacturer','model','serial_number','purpose','condition','comment','purchased_on','purchase_cost','useful_life_months','cpu','ram','storage','gpu','os','os_version','imei'])payload[key]=editor[key]===''?null:editor[key]; await api('/api/items',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}); resetEditor(); await load(); } catch(e){error.value=e.message;} };
const saveSettings = async () => { try { error.value=''; const payload={}; for(const type of ['pc','laptop','smartphone','tablet'])payload[type]=Number(settingsDraft[type]); const data=await api('/api/settings',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}); Object.assign(settings,data); Object.assign(settingsDraft,data); settingsSaved.value=true; } catch(e){error.value=e.message;} };
const openAssign = async (item) => { try { await ensureEmployees(); Object.assign(assignment,{open:true,item,employee_id:'',starts_on:new Date().toISOString().slice(0,10),issue_comment:''}); } catch(e){error.value=e.message;} };
const assignItem = async () => { try { await api(`/api/items/${assignment.item.id}/assign`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({employee_id:Number(assignment.employee_id),starts_on:assignment.starts_on,issue_comment:assignment.issue_comment||null})}); assignment.open=false; await refreshAfterMutation(); } catch(e){error.value=e.message;} };
const openReturn = (item) => Object.assign(returning,{open:true,item,returned_on:new Date().toISOString().slice(0,10),return_comment:''});
const returnItem = async () => { try { await api(`/api/items/${returning.item.id}/return`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({returned_on:returning.returned_on,return_comment:returning.return_comment||null})}); returning.open=false; await refreshAfterMutation(); } catch(e){error.value=e.message;} };
const deleteEquipment = async (item) => {
  if (!window.confirm(`Безвозвратно удалить технику №${item.inventory_number} вместе со ВСЕЙ историей выдач, повреждений, списания и действий? Восстановить данные будет невозможно.`)) return;
  try { await api(`/api/items/${item.id}`, { method: 'DELETE' }); closeItem(); await load(); }
  catch (e) { error.value = e.message; }
};
const writeOff = async (item) => { const reason=prompt('Причина списания'); if(!reason)return; const date=prompt('Дата списания (YYYY-MM-DD)',new Date().toISOString().slice(0,10)); if(!date)return; try { await api(`/api/items/${item.id}/write-off`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({written_off_on:date,reason})}); closeItem(); await load(); } catch(e){error.value=e.message;} };
const handlePopState = () => { section.value=pathSection(); selectedItemId.value=itemIdFromPath(); load(); };
onMounted(() => { window.addEventListener('popstate',handlePopState); load(); });
onBeforeUnmount(() => window.removeEventListener('popstate',handlePopState));
watch(section,resetFilters);
watch(() => editor.type, (type, previous) => { if(editor.open && type !== previous) editor.useful_life_months=settings[type]||36; });
</script>

<template>
  <UiAppShell service="equipment" service-name="Учёт техники" :section="section" :items="sections" :current-user="auth.user" :platform-access="() => auth.fetch('/api/employees/access/me')" :loading="loading" @update:section="changeSection" @logout="auth.logout">
    <template #actions>
      <div class="equipment-topbar-summary">
        <span v-if="section==='registry'"><strong>{{ filtered.length }}</strong> из {{ items.length }} единиц</span>
        <span v-else-if="section==='assignments'"><strong>{{ assignments.length }}</strong> выдач</span>
        <span v-else-if="section==='valuation'"><strong>{{ valuation.length }}</strong> позиций</span>
        <span v-else-if="section==='written-off'"><strong>{{ items.length }}</strong> списано</span>
        <span v-else>Сроки амортизации по умолчанию</span>
      </div>
      <UiButton v-if="permissions.operate" variant="secondary" @click="importOpen=true">Импорт XLSX</UiButton>
      <UiButton v-if="permissions.manage && section==='registry'" @click="openEditor">+ Техника</UiButton>
      <UiButton v-if="permissions.manage && section==='settings'" @click="saveSettings">Сохранить</UiButton>
    </template>

    <main class="equipment-page" :class="{ 'equipment-page--with-filter': section === 'registry' || section === 'written-off' }">
      <div v-if="error" class="equipment-error">{{ error }}</div>
      <UiPanel v-if="section==='registry' || section==='written-off'" class="equipment-registry-panel"><div v-if="loading" class="equipment-empty">Загрузка…</div><UiRegistryTable class="equipment-shared-registry" :columns="['12%','10%','270px','14%','10%','10%','auto','6%']" min-width="1080px" aria-label="Техника" density="compact"><template #head><tr><th>Инв. №</th><th class="equipment-cell-center">Тип</th><th class="equipment-model-column">Изготовитель / модель</th><th>Серийный номер</th><th class="equipment-cell-center">Назначение</th><th class="equipment-cell-center">Состояние</th><th class="equipment-issued-column">Выдана</th><th></th></tr></template><tr v-for="item in filtered" :key="item.id"><td><button type="button" class="equipment-inventory-link" @click="openItem(item)">{{ item.inventory_number }}</button></td><td class="equipment-cell-center">{{ labels[item.type] }}</td><td class="equipment-model-column">{{ [item.manufacturer, item.model].filter(Boolean).join(' ') }}</td><td>{{ item.serial_number || '—' }}</td><td class="equipment-cell-center">{{ !item.purpose || item.purpose === 'unknown' ? '—' : (labels[item.purpose] || item.purpose) }}</td><td class="equipment-cell-center"><span class="equipment-condition" :class="{ 'equipment-condition--ok': item.condition === 'ok', 'equipment-condition--bad': item.condition === 'damaged' || item.condition === 'needs_repair' }">{{ labels[item.condition] || item.condition }}</span></td><td class="equipment-issued-column">{{ item.assignment ? shortEmployeeName(item.assignment.employee_id) : '—' }}</td><td class="equipment-row-action"><UiButton v-if="permissions.operate && !item.written_off_at && item.assignment" compact variant="ghost" class="equipment-text-action" @click="openReturn(item)">Вернуть</UiButton><UiButton v-else-if="permissions.operate && !item.written_off_at" compact variant="ghost" class="equipment-text-action" @click="openAssign(item)">Выдать</UiButton></td></tr><tr v-if="!filtered.length"><td colspan="8" class="equipment-empty">Нет данных</td></tr></UiRegistryTable></UiPanel>

      <UiPanel v-else-if="section==='assignments'" class="equipment-registry-panel"><UiRegistryTable class="equipment-shared-registry" :columns="['30%','20%','14%','14%','22%']" min-width="760px" aria-label="Выдачи" density="compact"><template #head><tr><th>Техника</th><th>Сотрудник</th><th>Начало</th><th>Возврат</th><th>Кто выдал</th></tr></template><tr v-for="record in assignments" :key="record.id"><td>{{ record.inventory_number }} · {{ record.manufacturer }} {{ record.model }}</td><td>{{ shortEmployeeName(record.employee_id) }}</td><td>{{ record.starts_on }}</td><td>{{ record.returned_on || '—' }}</td><td>{{ record.issued_by }}</td></tr></UiRegistryTable></UiPanel>

      <UiPanel v-else-if="section==='valuation'" class="equipment-registry-panel"><UiRegistryTable class="equipment-shared-registry" :columns="['8%','10%','13%','10%','7%','9%','7%','10%','9%','8%','9%']" min-width="1270px" aria-label="Оценка стоимости" density="compact"><template #head><tr><th>Инв. №</th><th>Изготовитель</th><th>Модель</th><th>Первоначальная</th><th>Срок</th><th>В месяц</th><th>Месяцев</th><th>Амортизация</th><th>Остаточная</th><th>Повреждения</th><th>Текущая оценка</th></tr></template><tr v-for="row in valuation" :key="row.id"><td><strong>{{ row.inventory_number }}</strong></td><td>{{ row.manufacturer }}</td><td>{{ row.model }}</td><td>{{ money(row.purchase_cost) }}</td><td>{{ row.useful_life_months }} мес.</td><td>{{ money(row.depreciation.monthly) }}</td><td>{{ row.depreciation.months }}</td><td>{{ money(row.depreciation.accumulated) }}</td><td>{{ money(row.depreciation.residual) }}</td><td>{{ money(row.damage_accounting?.value_loss) }}</td><td><strong>{{ money(row.damage_accounting?.current_value) }}</strong></td></tr></UiRegistryTable></UiPanel>

      <UiPanel v-else class="equipment-settings-panel">
        <div class="equipment-settings-content">
          <p class="equipment-settings-note">Сроки используются как значения по умолчанию при добавлении новой техники. Уже созданные записи не пересчитываются автоматически.</p>
          <div class="equipment-settings-list">
            <label v-for="type in ['laptop','pc','smartphone','tablet']" :key="type" class="equipment-setting-row"><span>{{ labels[type] }}</span><span class="equipment-setting-control"><input v-model.number="settingsDraft[type]" type="number" min="1" max="600" :disabled="!permissions.manage"><em>месяцев</em></span></label>
          </div>
          <div v-if="settingsSaved" class="equipment-settings-saved">Настройки сохранены.</div>
        </div>
      </UiPanel>
    </main>

    <UiFilterRail v-if="section==='registry' || section==='written-off'" class="equipment-filter-rail" :items="filterItems" @reset="resetFilters">
      <template #filter-search><label class="irlix-field"><span>Поиск</span><input v-model="search" type="search" placeholder="Номер, модель, серийный №, ФИО"></label></template>
      <template #filter-issue><label class="irlix-field"><span>Выдача</span><UiSearchSelect v-model="issueFilter" :options="issueOptions" placeholder="Все" /></label></template>
      <template #filter-type><label class="irlix-field"><span>Тип техники</span><UiSearchSelect v-model="typeFilter" multiple :options="typeFilterOptions" placeholder="Все типы" search-placeholder="Поиск типа" /></label></template>
      <template #filter-manufacturer><label class="irlix-field"><span>Изготовитель</span><UiSearchSelect v-model="manufacturerFilter" multiple :options="manufacturerOptions" placeholder="Все изготовители" search-placeholder="Поиск изготовителя" /></label></template>
      <template #filter-purpose><label class="irlix-field"><span>Назначение</span><UiSearchSelect v-model="purposeFilter" :options="purposeOptions" placeholder="Все назначения" /></label></template>
      <template #filter-writeoff><label class="irlix-field"><span>Списание</span><UiSearchSelect v-model="writeOffFilter" :options="writeOffOptions" placeholder="Не списана" /></label></template>
    </UiFilterRail>

    <div v-if="importOpen" class="equipment-modal-backdrop" @click.self="closeImport">
      <div class="equipment-modal equipment-modal--narrow" role="dialog" aria-modal="true" aria-label="Импорт техники">
        <h2>Импорт техники из Excel</h2>
        <p>Загрузите исходный XLSX с листами «Словарь», «Техника» и «Выдача». Перед импортом можно проверить результат. Файл не сохраняется на сервере.</p>
        <label>Файл Excel <input ref="importFileInput" type="file" accept=".xlsx" @change="selectImportFile" /></label>
        <p v-if="importError" role="alert" class="equipment-import-error">{{ importError }}</p>
        <div v-if="importPreview" class="equipment-import-report">
          <p>Техника: новых {{ importPreview.devices_created }}, уже существует {{ importPreview.devices_existing }} (всего {{ importPreview.devices_total }}).</p>
          <p>Выдачи: новых {{ importPreview.assignments_created }}, уже существует {{ importPreview.assignments_existing }} (всего {{ importPreview.assignments_total }}).</p>
          <p v-if="importPreview.skipped">Замечания / пропуски: {{ importPreview.skipped }}</p>
          <div v-if="importPreview.issues?.length" class="equipment-import-issues">
            <p v-for="(issue,index) in importPreview.issues" :key="index">{{ issue.sheet }}, строка {{ issue.row }}: {{ issue.message }}</p>
          </div>
        </div>
        <div class="equipment-modal-actions">
          <UiButton variant="secondary" type="button" @click="closeImport">Закрыть</UiButton>
          <UiButton variant="secondary" type="button" :disabled="!importFile || importing" @click="submitImport(false)">Проверить</UiButton>
          <UiButton type="button" :disabled="!importFile || !importPreview || importing" @click="submitImport(true)">Загрузить данные</UiButton>
        </div>
      </div>
    </div>
    <EquipmentCardDrawer v-if="selectedItemId" :item-id="selectedItemId" :employees="employees" :can-manage="permissions.manage" :can-operate="permissions.operate" :refresh-key="drawerVersion" @close="closeItem" @updated="refreshAfterMutation" @write-off="writeOff" @delete="deleteEquipment" @assign="openAssign" @return="openReturn" />

    <div v-if="editor.open" class="equipment-modal-backdrop" @click.self="resetEditor"><form class="equipment-modal" @submit.prevent="saveItem"><h2>Новая техника</h2><div class="equipment-form-grid"><label>Инв. номер<input v-model="editor.inventory_number" required></label><label>Тип<select v-model="editor.type"><option v-for="type in ['pc','laptop','smartphone','tablet']" :key="type" :value="type">{{ labels[type] }}</option></select></label><label>Изготовитель<input v-model="editor.manufacturer" required></label><label>Модель<input v-model="editor.model" required></label><label>Серийный номер<input v-model="editor.serial_number"></label><label>Назначение<select v-model="editor.purpose"><option v-for="purpose in ['development','management_qa','qa','management','unknown']" :key="purpose" :value="purpose">{{ labels[purpose] }}</option></select></label><label>Состояние<select v-model="editor.condition"><option v-for="condition in ['ok','damaged','needs_repair']" :key="condition" :value="condition">{{ labels[condition] }}</option></select></label><label>Дата покупки<input v-model="editor.purchased_on" type="date"></label><label>Стоимость<input v-model="editor.purchase_cost" type="number" min="0" step="0.01"></label><label>Срок, мес.<input v-model="editor.useful_life_months" type="number" min="1"></label><label>Процессор<input v-model="editor.cpu"></label><label>ОЗУ<input v-model="editor.ram"></label><label>HDD/SSD<input v-model="editor.storage"></label><label>Видеокарта<input v-model="editor.gpu"></label><label>ОС<input v-model="editor.os"></label><label>Версия ОС<input v-model="editor.os_version"></label><label v-if="editor.type==='smartphone' || editor.type==='tablet'">IMEI<input v-model="editor.imei"></label><label class="wide">Комментарий<textarea v-model="editor.comment"></textarea></label></div><div class="equipment-modal-actions"><UiButton variant="secondary" type="button" @click="resetEditor">Отмена</UiButton><UiButton type="submit">Сохранить</UiButton></div></form></div>

    <div v-if="assignment.open" class="equipment-modal-backdrop" @click.self="assignment.open=false"><form class="equipment-modal equipment-modal--narrow" @submit.prevent="assignItem"><h2>Выдать {{ assignment.item.inventory_number }}</h2><label class="irlix-field"><span>Сотрудник</span><UiSearchSelect v-model="assignment.employee_id" :options="employeeOptions" placeholder="Выберите сотрудника" search-placeholder="Поиск сотрудника" /></label><label>Дата выдачи<input v-model="assignment.starts_on" type="date" required></label><label>Комментарий<textarea v-model="assignment.issue_comment"></textarea></label><div class="equipment-modal-actions"><UiButton variant="secondary" type="button" @click="assignment.open=false">Отмена</UiButton><UiButton type="submit" :disabled="!assignment.employee_id">Выдать</UiButton></div></form></div>

    <div v-if="returning.open" class="equipment-modal-backdrop" @click.self="returning.open=false"><form class="equipment-modal equipment-modal--narrow" @submit.prevent="returnItem"><h2>Вернуть {{ returning.item.inventory_number }}</h2><label>Дата возврата<input v-model="returning.returned_on" type="date" required></label><label>Комментарий после возврата<textarea v-model="returning.return_comment" placeholder="Состояние, комплектность, замечания"></textarea></label><div class="equipment-modal-actions"><UiButton variant="secondary" type="button" @click="returning.open=false">Отмена</UiButton><UiButton type="submit">Вернуть</UiButton></div></form></div>
  </UiAppShell>
</template>

<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { catalogSections as nav } from './catalogSections.js';
import { useCatalogNavigation } from './useCatalogNavigation.js';
import tokenStyles from '../../../packages/ui/src/styles/tokens.css?raw';
import { UiAppShell, UiAppTopbar, UiBadge, UiButton, UiCheckbox, UiDrawer, UiFilterBar, UiFilterRail, UiIcon, UiPageHeader, UiPanel, UiRegistryTable, UiLoader, UiSearchSelect, UiSegmentedControl, UiTabs, UiTreeToggle, UiTooltip, UiViewSwitch, UiViewSelect, UiPeriodPicker, UiKanbanBoard, UiClientSummary, UiStatusCheck, getTheme, setTheme } from '@irlix/ui';

const { section, navigate } = useCatalogNavigation(nav);
const checkboxDemo = ref(true);
const checkboxUncheckedDemo = ref(false);
const displayTheme = ref(getTheme());
function changeDisplayTheme(mode) { displayTheme.value = mode; setTheme(mode); }
function syncDisplayTheme(event) { displayTheme.value = event.detail.mode; }
onMounted(() => document.addEventListener('irlix:theme-change', syncDisplayTheme));
onBeforeUnmount(() => document.removeEventListener('irlix:theme-change', syncDisplayTheme));
const demoKanbanColumns = ['Новая', 'На согласовании', 'Завершена'];
const demoVisibleColumns = ref(demoKanbanColumns.slice(0, -1));
const demoBoardColumns = computed(() => demoKanbanColumns.filter(status => demoVisibleColumns.value.includes(status)));
const demoKanbanItems = ref([
  {id:1, status:'Новая', title:'Кандидат · Frontend', description:'Демо-клиент 01'},
  {id:2, status:'Новая', title:'Кандидат · QA', description:'Демо-клиент 02'},
  {id:3, status:'На согласовании', title:'Кандидат · Backend', description:'Демо-клиент 03'},
]);
const demoDragged = ref(null);
const demoTarget = ref('');
function demoDrag(event,item) { demoDragged.value=item.id; event.dataTransfer.effectAllowed='move'; event.dataTransfer.setData('text/plain',String(item.id)); }
function demoOver(event,status) { if(!demoDragged.value)return; event.preventDefault();demoTarget.value=status; }
function demoDrop(event,status) {event.preventDefault(); const item=demoKanbanItems.value.find(i=>i.id===demoDragged.value); if(item)item.status=status;demoDragged.value=null;demoTarget.value='';}

const publicServiceAccess = { services: {} };


// Generate the palette catalog from the same source that every service consumes.
const colorNames = Array.from(new Set([...tokenStyles.matchAll(/^\s*(--irlix-[\w-]+):\s*(?:#[0-9a-fA-F]{3,8}|rgba?\(|hsla?\()/gm)].map(match => match[1])));
const colorGroups = [
  { name: 'Основные цвета', items: colorNames.filter(name => name.startsWith('--irlix-color-')) },
  { name: 'Навигация', items: colorNames.filter(name => name.startsWith('--irlix-sidebar-')) },
  { name: 'Формы, таблицы и фильтры', items: colorNames.filter(name => !name.startsWith('--irlix-color-') && !name.startsWith('--irlix-sidebar-')) },
].map(group => ({ ...group, items: group.items.map(token => ({ token, label: token.replace('--irlix-', '') })) }));
const colorValue = (token) => typeof window === 'undefined' ? '' : getComputedStyle(document.documentElement).getPropertyValue(token).trim();
const icons = ['users', 'org', 'roles', 'permissions', 'user-badge', 'calendar', 'list', 'briefcase', 'crown', 'contact', 'target', 'rocket', 'cash', 'dashboard', 'code', 'hourglass', 'chart', 'tasks', 'palette', 'audit', 'settings'];
const tones = ['info', 'neutral', 'success', 'warning', 'danger'];
const query = ref('');
const departments = ref([]);
const statuses = ref([]);
const specialist = ref('');
const railSearch = ref('');
const railDepartment = ref('');
const railStatus = ref('');
const railGrouping = ref('department');
const railGroupingOptions = [{ value: 'department', label: 'По подразделению' }, { value: 'status', label: 'По статусу' }, { value: 'none', label: 'Без группировки' }];
const railGroupingItems = computed(() => [{ id: 'primary', label: 'Группировка', icon: 'list', active: railGrouping.value !== 'none', valueLabel: railGroupingOptions.find(item => item.value === railGrouping.value)?.label || '' }]);
const railDepartmentOptions = [
  { value: 'production', label: 'Производство', kind: 'group', depth: 0 },
  { value: 'backend', label: 'Backend', depth: 1 },
  { value: 'qa', label: 'QA', depth: 1 },
];
const railItems = computed(() => [
  { id: 'search', label: 'Поиск', icon: 'search', active: Boolean(railSearch.value), valueLabel: railSearch.value },
  { id: 'department', label: 'Подразделение', icon: 'building', active: Boolean(railDepartment.value), valueLabel: railDepartment.value },
  { id: 'status', label: 'Статус', icon: 'status', active: Boolean(railStatus.value), valueLabel: railStatus.value },
]);
const resetRail = () => {
  railSearch.value = '';
  railDepartment.value = '';
  railStatus.value = '';
  railGrouping.value = 'department';
};
const departmentOptions = ['Backend', 'Frontend', 'QA', 'Analytics'];
const statusOptions = ['В работе', 'На согласовании', 'Завершён'];
const specialistOptions = [
  { value: 'production', label: 'Производство', kind: 'group', depth: 0 },
  { value: 'backend', label: 'Backend', kind: 'group', depth: 1 },
  { value: 'demo-01', label: 'Демо-сотрудник 01', depth: 2 },
  { value: 'demo-02', label: 'Демо-специалист 02', depth: 2 },
  { value: 'qa', label: 'QA', kind: 'group', depth: 1 },
  { value: 'demo-03', label: 'Демо-специалист 03', depth: 2 },
];
const tab = ref('history');
const drawerTab = ref('info');
const tabs = [{ value: 'history', label: 'История', iconName: 'list' }, { value: 'records', label: 'Записи', count: 3, complete: true }, { value: 'details', label: 'Подробности' }];
const view = ref('list');
const requestMode = ref('tree');
const periodYear = ref(2026);
const periodMonth = ref('2026-10');
const views = [{ value: 'list', label: 'Список' }, { value: 'kanban', label: 'Канбан' }];
const chart = ref('month');
const input = ref('Демо-проект');
const formStatus = ref('В работе');
const notes = ref('');
const groupOpen = ref(true);
const childGroupOpen = ref(true);
const drawerOpen = ref(false);
const nestedDrawerOpen = ref(false);
const registryState = ref('data');
const registryRows = Array.from({ length: 16 }, (_, i) => ({ id: i + 1, name: `Демо-специалист ${String(i + 1).padStart(2, '0')}`, project: i % 2 ? 'Демо-проект Б' : 'Демо-проект А', hours: 8, tone: i % 3 ? 'success' : 'warning' }));
const visibleRows = computed(() => registryRows.filter(row => `${row.name} ${row.project}`.toLowerCase().includes(query.value.toLowerCase())));
</script>

<template>
  <UiAppShell :account-enabled="false" :service-access="publicServiceAccess" service="design-system" :section="section" :items="nav" :current-user="null" @update:section="navigate">
    <main class="ds-page">
      <div class="ds-topbar"><span>Общие компоненты IRLIX</span></div>
      <UiPageHeader eyebrow="IRLIX DESIGN SYSTEM" title="Дизайн-система" description="Живые компоненты и паттерны, которые используются в сервисах платформы." />
      <p class="ds-note">Интерактивные примеры общей библиотеки. Все данные вымышлены.</p>

      <section v-for="group in nav" :id="`section-${group.id}`" :key="group.id" :data-catalog-group="group.id" class="ds-section" :aria-labelledby="`heading-${group.id}`">
        <h2 :id="`heading-${group.id}`" class="ds-section-heading">{{ group.label }}</h2>
        <div v-for="entry in group.examples" :id="`section-${entry.id}`" :key="entry.id" class="ds-example">
          <h3>{{ entry.label }}</h3>
        <template v-if="entry.id === 'theme'">
          <UiPanel class="ds-content"><div class="ds-row ds-row--plain"><UiButton variant="secondary" v-for="mode in [{id:'light',label:'Светлая'},{id:'dark',label:'Тёмная'},{id:'system',label:'Системная'}]" :key="mode.id" type="button" :aria-pressed="displayTheme === mode.id" @click="changeDisplayTheme(mode.id)">{{ mode.label }}</UiButton></div><p class="ds-hint">Настройка сохраняется между сервисами и вкладками браузера. Текущая цветовая палитра применяется к этому каталогу.</p></UiPanel>
        </template>
        <template v-if="entry.id === 'colors'">
          <UiPanel class="ds-content"><p class="ds-hint">Общие семантические токены из packages/ui/src/styles/tokens.css. При переключении темы цветовые значения меняются вместе с компонентами.</p><div v-for="group in colorGroups" :key="group.name"><h4>{{ group.name }}</h4><div class="ds-colors"><div v-for="item in group.items" :key="item.token" class="ds-color"><i :style="{ background: `var(${item.token})` }" /><strong>{{ item.label }}</strong><code>{{ item.token }}</code><code>{{ colorValue(item.token) }}</code></div></div></div></UiPanel>
        </template>
        <template v-if="entry.id === 'typography'">
          <UiPanel class="ds-content"><strong>Типографика · Onest</strong><p v-for="sample in [{ label: 'Заголовок страницы', size: 'page-title' },{label:'Заголовок секции',size:'section-title'},{label:'Заголовок карточки',size:'card-title'},{label:'Основной текст',size:'body'},{label:'Текст таблиц',size:'table'},{label:'Подписи',size:'caption'},{label:'Служебный текст',size:'xs'}]" :key="sample.size" :style="{fontSize:`var(--irlix-font-size-${sample.size})`}">{{ sample.label }} — {{ sample.size }}</p></UiPanel>
        <UiPanel class="ds-metrics"><span><b>24 px</b>Заголовок страницы</span><span><b>13–14 px</b>Основной текст</span><span><b>32 px</b>Высота поля / фильтра</span><span><b>4 px</b>Шаг отступов</span><span><b>60 px</b>Левое меню</span></UiPanel>
        </template>
        <template v-if="entry.id === 'client-summary'">
          <p class="ds-note">UiClientSummary — общий компонент краткой сводки. Все значения ниже вымышлены.</p>
        <UiClientSummary name="Демо-клиент 01" sales="Демо-сотрудник 01" account="Демо-сотрудник 02" sector="Демо-сектор" client-type="Демо-тип" />
        </template>
        <template v-if="entry.id === 'kanban'">
          <p class="ds-note">Все сервисные канбаны используют UiKanbanBoard: фиксированные шапки, независимая прокрутка колонок и единые карточки. Перетащите демонстрационную карточку между статусами: прозрачная шапка остаётся внутри общей пунктирной рамки, геометрия колонки не меняется.</p>
        <UiFilterBar class="irlix-kanban-filters"><UiSearchSelect v-model="demoVisibleColumns" :options="demoKanbanColumns" placeholder="Столбцы" multiple/></UiFilterBar>
        <UiKanbanBoard :columns="demoBoardColumns" :items="demoKanbanItems" :active-target="demoTarget" label="Демонстрация канбана" @dragover="demoOver" @drop="demoDrop" @dragleave="demoTarget=''">
          <template #cards="{items}"><button v-for="item in items" :key="item.id" type="button" class="irlix-kanban-card" :draggable="true" :class="{'irlix-kanban-card--dragging':demoDragged===item.id}" @dragstart="demoDrag($event,item)" @dragend="demoDragged=null;demoTarget=''"><strong>{{item.title}}</strong><span>{{item.description}}</span></button></template>
        </UiKanbanBoard>
        </template>
        <template v-if="entry.id === 'buttons'">
          <p class="ds-hint">UiButton: варианты общего компонента и недоступное состояние.</p>
          <UiPanel class="ds-row"><UiButton>Основная</UiButton><UiButton variant="secondary">Вторичная</UiButton><UiButton variant="ghost">Без фона</UiButton><UiButton variant="danger">Удалить</UiButton><UiButton disabled>Недоступна</UiButton></UiPanel>
        </template>
        <template v-if="entry.id === 'badges'">
          <UiPanel class="ds-row"><UiBadge v-for="tone in tones" :key="tone" :tone="tone">{{ tone }}</UiBadge><UiStatusCheck label="Подтверждено" /></UiPanel>
        </template>
        <template v-if="entry.id === 'panel'">
          <UiPanel class="ds-content"><strong>UiPanel</strong><p>Общий контейнер для содержимого. Раскладку примера задаёт каталог.</p></UiPanel>
        </template>
        <template v-if="entry.id === 'loaders'">
          <UiPanel class="ds-content">
          <p class="ds-hint">Общий индикатор для загрузки данных. Вариант overlay располагается поверх уже выделенной области и не сдвигает таблицы или карточки; текст доступен скринридеру, анимация учитывает reduced motion.</p>
          <div class="ds-row ds-row--plain"><UiLoader label="Загрузка данных" /><UiLoader size="small" show-label label="Обновляем…" /></div>
          <div class="ds-loader-preview" aria-busy="true"><span>Место для содержимого фиксированной высоты</span><UiLoader overlay label="Загрузка записей" /></div>
        </UiPanel>
        </template>
        <template v-if="entry.id === 'checkbox'">
          <UiPanel class="ds-row"><UiCheckbox v-model="checkboxDemo">Активный параметр</UiCheckbox><UiCheckbox v-model="checkboxUncheckedDemo">Не выбран</UiCheckbox><UiCheckbox :model-value="false" disabled>Недоступен</UiCheckbox></UiPanel>
        <p class="ds-hint">Единый 18px чекбокс: собственный акцент и фокус-кольцо по токенам, нативная клавиатурная навигация, поддержка v-model и disabled. Переключите примеры для проверки состояния.</p>
        </template>
        <template v-if="entry.id === 'filters'">
          <UiPanel class="ds-content"><UiFilterBar><input v-model="query" class="irlix-search" placeholder="Поиск демо-специалиста" aria-label="Поиск демо-специалиста"><UiSearchSelect v-model="departments" :options="departmentOptions" multiple placeholder="Направления" /><UiSearchSelect v-model="statuses" :options="statusOptions" multiple placeholder="Статусы" /><UiSearchSelect v-model="specialist" :options="specialistOptions" placeholder="Сотрудник" /><UiSearchSelect :options="[]" disabled placeholder="Недоступный фильтр" /></UiFilterBar><p class="ds-hint">Множественный выбор показывает центрированную SVG-галочку в квадрате, остаётся открытым, показывает счётчик и поддерживает сброс. Группы направлений не выбираются; depth задаёт вложенность заголовков и сотрудников. Выбор доступен для дочерних специалистов.</p></UiPanel>
        </template>
        <template v-if="entry.id === 'filter-rail'">
          <UiPanel class="ds-filter-rail-preview">
          <div class="ds-filter-rail-preview__content"><strong>UiFilterRail</strong><span>Наведите на правую панель. Иконки остаются напротив своих полей; сброс закреплён снизу.</span></div>
          <UiFilterRail contained :items="railItems" :grouping-items="railGroupingItems" @reset="resetRail">
            <template #filter-search><label class="irlix-field"><span>Поиск</span><input v-model="railSearch" placeholder="Демо-поиск" /></label></template>
            <template #filter-department><label class="irlix-field"><span>Подразделение</span><UiSearchSelect v-model="railDepartment" :options="railDepartmentOptions" placeholder="Все подразделения" /></label></template>
            <template #filter-status><label class="irlix-field"><span>Статус</span><UiSearchSelect v-model="railStatus" :options="statusOptions" placeholder="Все статусы" /></label></template>
            <template #grouping-primary><label class="irlix-field"><span>Группировка</span><UiSearchSelect v-model="railGrouping" :options="railGroupingOptions" :clearable="false" /></label></template>
          </UiFilterRail>
        </UiPanel>
        </template>
        <template v-if="entry.id === 'view-controls'">
          <UiTabs v-model="tab" variant="card" :style="{ '--irlix-card-tabs-count': tabs.length }" :items="tabs" />
        <div class="ds-row ds-row--plain"><UiViewSwitch v-model="view" :items="views" /><UiSegmentedControl v-model="chart" :items="[{ value: 'month', label: 'Месяц' }, { value: 'year', label: 'Год' }]" /></div>
        </template>
        <template v-if="entry.id === 'card-actions'">
          <p class="ds-hint">SVG-иконки из UiIcon для кнопок и других общих контролов.</p>
        <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;padding:12px 0">
          <span v-for="name in ['info','layers','user-round','file-clock','notebook','pencil']" :key="name" style="display:inline-flex;gap:6px;align-items:center;font-size:12px;color:var(--irlix-color-text-muted)"><UiIcon :name="name" style="width:16px;height:16px;stroke:currentColor;stroke-width:1.8" />{{name}}</span>
        </div>
        </template>
        <template v-if="entry.id === 'forms'">
          <UiPanel class="ds-form"><label class="irlix-field">Название<input v-model="input"></label><label class="irlix-field">Статус<select v-model="formStatus"><option v-for="value in statusOptions" :key="value">{{ value }}</option></select></label><label class="irlix-field">Недоступное поле<input value="Только просмотр" disabled></label><label class="irlix-field">Описание<textarea v-model="notes" rows="3" placeholder="Текст описания" /></label></UiPanel>
        </template>
        <template v-if="entry.id === 'icons'">
          <UiPanel class="ds-icons"><div v-for="icon in icons" :key="icon"><UiIcon :name="icon" /><code>{{ icon }}</code></div></UiPanel>
        </template>
        <template v-if="entry.id === 'hierarchy'">
          <UiPanel class="ds-tree"><div class="ds-tree-row"><UiTreeToggle :expanded="groupOpen" :hover="false" :label="groupOpen ? 'Свернуть группу' : 'Раскрыть группу'" @click="groupOpen = !groupOpen" /><strong>Демо-группа</strong><UiBadge tone="info">В работе</UiBadge></div><template v-if="groupOpen"><div class="ds-tree-row ds-tree-row--position"><UiTreeToggle :expanded="childGroupOpen" variant="plus" :hover="false" :label="childGroupOpen ? 'Свернуть подгруппу' : 'Раскрыть подгруппу'" @click="childGroupOpen = !childGroupOpen" /><span>Демо-подгруппа</span><UiBadge tone="neutral">1 запись</UiBadge></div><div v-if="childGroupOpen" class="ds-tree-row ds-tree-row--attempt"><i class="ds-tree-dot" /><span>Демо-запись 01</span><UiTooltip>Подробности<template #content>Синтетический пример подсказки</template></UiTooltip></div></template></UiPanel>
        <p class="ds-hint">Уровни различаются отступами и соединительными линиями. Стрелка и +/− — варианты общего UiTreeToggle; состояние задаёт сервис.</p>
        </template>
        <template v-if="entry.id === 'tables'">
          <UiPanel class="ds-content ds-permissions"><table class="irlix-data-table"><thead><tr><th>Действие</th><th>Доступ</th></tr></thead><tbody><tr class="irlix-table-group"><th colspan="2">Демо-группа А</th></tr><tr><td>Просмотр записей</td><td><UiBadge tone="success">Разрешено</UiBadge></td></tr><tr class="irlix-table-group"><th colspan="2">Демо-группа Б</th></tr><tr><td>Создание записи</td><td><UiBadge tone="neutral">Свои</UiBadge></td></tr></tbody></table></UiPanel>
        </template>
        <template v-if="entry.id === 'registry'">
          <UiSegmentedControl v-model="registryState" :items="[{ value: 'data', label: 'Данные' }, { value: 'loading', label: 'Загрузка' }, { value: 'empty', label: 'Пусто' }, { value: 'error', label: 'Ошибка' }]" />
        <UiPanel class="ds-registry irlix-registry-panel" :aria-busy="registryState === 'loading'"><div v-if="registryState === 'empty' || (registryState === 'data' && !visibleRows.length)" class="ds-state">Нет записей по заданным условиям.</div><div v-else-if="registryState === 'error'" class="ds-state ds-state--error" role="alert">Не удалось загрузить реестр.<UiButton compact variant="secondary" @click="registryState = 'data'">Повторить</UiButton></div><UiRegistryTable v-else table-class="design-registry-example" aria-label="Пример общего реестра" :columns="['35%','30%','15%','20%']" min-width="650px" density="compact"><template #head><tr><th>Специалист</th><th>Проект</th><th>Часы в день</th><th>Статус</th></tr></template><tr v-for="row in visibleRows" :key="row.id"><td>{{ row.name }}</td><td>{{ row.project }}</td><td>{{ row.hours }}</td><td><UiBadge :tone="row.tone">{{ row.tone === 'success' ? 'На проекте' : 'Согласование' }}</UiBadge></td></tr></UiRegistryTable><UiLoader v-if="registryState === 'loading'" overlay label="Загрузка записей" /></UiPanel>
        <p class="ds-hint">В сервисе реестр занимает оставшуюся высоту окна, заголовок закреплён внутри скролла. Здесь показан ограниченный образец для проверки поведения таблицы.</p>
        </template>
        <template v-if="entry.id === 'drawers'">
          <UiPanel class="ds-row"><UiButton @click="drawerOpen = true">Открыть карточку</UiButton><span class="ds-hint">Начальная ширина 40% и 30% окна; обе карточки растягиваются до 90%. Крестик центрирован независимо от шрифта.</span></UiPanel>
        </template>
        <template v-if="entry.id === 'topbar'">
          <UiAppTopbar service="clients" service-name="Клиентский сервис" :breadcrumbs="[{label:'Запросы'}]">
          <template #breadcrumb-extra><span class="irlix-breadcrumbs__separator">—</span><UiViewSelect v-model="requestMode" :options="[{value:'tree',label:'Общий экран'},{value:'list',label:'Список'}]" /></template>
          <template #actions><UiButton>＋ Новый запрос</UiButton></template>
        </UiAppTopbar>
        <UiAppTopbar service="vacations" :breadcrumbs="[{label:'Отсутствия подразделения'}]">
          <template #actions><UiPeriodPicker v-model="periodYear" mode="year"/><UiButton compact>＋ Отсутствие сотруднику</UiButton></template>
        </UiAppTopbar>
        <UiAppTopbar service="timesheets" :breadcrumbs="[{label:'Управление'}]">
          <template #actions><UiPeriodPicker v-model="periodMonth"/></template>
        </UiAppTopbar>
        <p class="ds-hint">Однострочная панель без внешнего верхнего отступа. Обычный UiButton имеет высоту 27 px; внутри UiAppTopbar общая библиотека выравнивает кнопки, поля и выбор периода до 32 px. Размеры и радиусы задаёт общая библиотека. Центр периода открывает календарь; стрелки переключают период. В календаре месяца нажмите год для выбора другого года. При нехватке ширины прокручивается строка, меню открываются поверх рабочей области.</p>
        <UiAppTopbar service="clients" :breadcrumbs="[{label:'Клиенты',href:'/clients/clients/'},{label:'Демо-клиент Север'}]"><template #actions><UiButton compact variant="secondary">Действие</UiButton></template></UiAppTopbar>
        </template>
        <template v-if="entry.id === 'sidebar'">
          <UiPanel class="ds-content"><p>Общий каталог контуров и доступность сервисов одинаковы в launcher и дашборде. Меню прокручивается в пределах окна; ошибка проверки роли повторяется при следующем открытии.</p><p>Меню слева — живой пример общего компонента. Верхние разделы прокручиваются отдельно, нижние действия закреплены. Подписи появляются одновременно при наведении. Логотип открывает общий каталог сервисов.</p><p class="ds-hint">Сервис передаёт только пункты меню, активный раздел и пользователя. Размеры, цвета, группировка и launcher задаются общей библиотекой.</p><p class="ds-hint">Сервис с собственной маршрутизацией управляет URL и историей сам. Общий legacy-адаптер не должен перехватывать его маршруты. У сервисов с этим адаптером подпись пункта и маршрут согласованы: «Запросы по позициям» открывает /clients/positions/. Employees переключает разделы без перезагрузки, включая штатное расписание.</p></UiPanel>
        </template>
        </div>
      </section>
      <UiDrawer :open="drawerOpen" :inactive="nestedDrawerOpen" title="Демо-проект · Карточка" width="40vw" :min-width="320" @close="drawerOpen = false"><UiTabs v-model="drawerTab" :items="[{ value: 'info', label: 'Информация' }, { value: 'history', label: 'История', count: 2 }]" /><div class="ds-drawer-content"><template v-if="drawerTab === 'info'"><p>Демо-клиент Север · Frontend</p><UiButton @click="nestedDrawerOpen = true">Открыть вложенную карточку</UiButton></template><p v-else>Демонстрационная запись истории.</p></div></UiDrawer>
      <UiDrawer :open="nestedDrawerOpen" title="Демо-специалист 01" width="30vw" :min-width="240" :z-index="1050" @close="nestedDrawerOpen = false"><p>Предыдущая карточка недоступна до закрытия этой.</p><UiBadge tone="success">На проекте</UiBadge></UiDrawer>
    </main>
  </UiAppShell>
</template>


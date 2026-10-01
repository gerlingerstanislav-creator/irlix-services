<script setup>
import { computed, nextTick, ref } from 'vue';
import { UiAppShell, UiAppTopbar, UiBadge, UiButton, UiDrawer, UiFilterBar, UiIcon, UiPageHeader, UiPanel, UiSearchSelect, UiSegmentedControl, UiTabs, UiTreeToggle, UiViewSwitch } from '@irlix/ui';

const section = ref('foundations');
const user = { preferred_username: 'design-system' };
const nav = [
  { id: 'foundations', label: 'Основы', icon: 'palette' },
  { id: 'components', label: 'Компоненты', icon: 'code' },
  { id: 'patterns', label: 'Паттерны', icon: 'list', groupStart: true },
  { id: 'navigation', label: 'Навигация', icon: 'org' },
];
async function navigate(value) {
  section.value = value;
  await nextTick();
  document.getElementById(`section-${value}`)?.scrollIntoView({ behavior: 'instant', block: 'start' });
}
const colors = [
  ['Primary', '--irlix-color-primary'], ['Primary soft', '--irlix-color-primary-soft'],
  ['Surface', '--irlix-color-surface'], ['Muted surface', '--irlix-color-surface-muted'],
  ['Border', '--irlix-color-border'], ['Text', '--irlix-color-text'],
  ['Muted text', '--irlix-color-text-muted'], ['Danger', '--irlix-color-danger'],
  ['Success', '--irlix-color-success'],
];
const icons = ['users', 'org', 'roles', 'calendar', 'list', 'briefcase', 'crown', 'contact', 'target', 'rocket', 'cash', 'dashboard', 'code', 'hourglass', 'chart', 'tasks', 'palette', 'audit'];
const tones = ['info', 'neutral', 'success', 'warning', 'danger'];
const query = ref('');
const departments = ref([]);
const statuses = ref([]);
const specialist = ref('');
const departmentOptions = ['Backend', 'Frontend', 'QA', 'Analytics'];
const statusOptions = ['В работе', 'На согласовании', 'Завершён'];
const specialistOptions = [
  { value: 'backend', label: 'Backend', kind: 'group' },
  { value: 'demo-01', label: 'Демо-специалист 01', depth: 1 },
  { value: 'demo-02', label: 'Демо-специалист 02', depth: 1 },
  { value: 'qa', label: 'QA', kind: 'group' },
  { value: 'demo-03', label: 'Демо-специалист 03', depth: 1 },
];
const tab = ref('history');
const drawerTab = ref('info');
const tabs = [{ value: 'history', label: 'История' }, { value: 'requests', label: 'Запросы', count: 3 }, { value: 'contacts', label: 'Контакты' }, { value: 'legal', label: 'Юр. лица' }];
const view = ref('list');
const views = [{ value: 'list', label: 'Список' }, { value: 'kanban', label: 'Канбан' }];
const chart = ref('month');
const input = ref('Демо-проект');
const formStatus = ref('В работе');
const notes = ref('');
const requestOpen = ref(true);
const positionOpen = ref(true);
const drawerOpen = ref(false);
const nestedDrawerOpen = ref(false);
const registryState = ref('data');
const registryRows = Array.from({ length: 16 }, (_, i) => ({ id: i + 1, name: `Демо-специалист ${String(i + 1).padStart(2, '0')}`, project: i % 2 ? 'Демо-проект Б' : 'Демо-проект А', hours: 8, tone: i % 3 ? 'success' : 'warning' }));
const visibleRows = computed(() => registryRows.filter(row => `${row.name} ${row.project}`.toLowerCase().includes(query.value.toLowerCase())));
</script>

<template>
  <UiAppShell service="design-system" :section="section" :items="nav" :current-user="user" @update:section="navigate">
    <main class="ds-page">
      <div class="ds-topbar"><a href="/">← Дашборд</a><span>Общие компоненты IRLIX</span></div>
      <UiPageHeader eyebrow="IRLIX DESIGN SYSTEM" title="Дизайн-система" description="Живые компоненты и паттерны, которые используются в сервисах платформы." />
      <p class="ds-note">Сверено с сервисом «Клиенты». Все примеры используют общую библиотеку. Данные демонстрационные.</p>

      <section id="section-foundations" class="ds-section">
        <h2>Основы</h2>
        <UiPanel class="ds-colors"><div v-for="color in colors" :key="color[1]" class="ds-color"><i :style="{ background: `var(${color[1]})` }" /><strong>{{ color[0] }}</strong><code>{{ color[1] }}</code></div></UiPanel>
        <UiPanel class="ds-metrics"><span><b>24 px</b>Заголовок страницы</span><span><b>13–14 px</b>Основной текст</span><span><b>32 px</b>Высота поля / фильтра</span><span><b>4 px</b>Шаг отступов</span><span><b>60 px</b>Левое меню</span></UiPanel>
      </section>

      <section id="section-components" class="ds-section">
        <h2>Компоненты</h2>
        <h3>Кнопки и статусы</h3>
        <UiPanel class="ds-row"><UiButton>Основная</UiButton><UiButton variant="secondary">Вторичная</UiButton><UiButton variant="ghost">Без фона</UiButton><UiButton variant="danger">Удалить</UiButton><UiButton compact>Компактная</UiButton><UiButton disabled>Недоступна</UiButton></UiPanel>
        <UiPanel class="ds-row"><UiBadge v-for="tone in tones" :key="tone" :tone="tone">{{ tone }}</UiBadge></UiPanel>
        <h3>Фильтры и поиск</h3>
        <UiPanel class="ds-content"><UiFilterBar><input v-model="query" class="irlix-search" placeholder="Поиск демо-специалиста" aria-label="Поиск демо-специалиста"><UiSearchSelect v-model="departments" :options="departmentOptions" multiple placeholder="Направления" /><UiSearchSelect v-model="statuses" :options="statusOptions" multiple placeholder="Статусы" /><UiSearchSelect v-model="specialist" :options="specialistOptions" placeholder="Специалист" /><UiSearchSelect :options="[]" disabled placeholder="Недоступный фильтр" /></UiFilterBar><p class="ds-hint">Множественный выбор остаётся открытым, показывает счётчик и поддерживает сброс. Группы направлений не выбираются; выбор доступен для дочерних специалистов.</p></UiPanel>
        <h3>Вкладки и представления</h3>
        <UiTabs v-model="tab" :items="tabs" />
        <div class="ds-row ds-row--plain"><UiViewSwitch v-model="view" :items="views" /><UiSegmentedControl v-model="chart" :items="[{ value: 'month', label: 'Месяц' }, { value: 'year', label: 'Год' }]" /></div>
        <h3>Формы</h3>
        <UiPanel class="ds-form"><label class="irlix-field">Название<input v-model="input"></label><label class="irlix-field">Статус<select v-model="formStatus"><option v-for="value in statusOptions" :key="value">{{ value }}</option></select></label><label class="irlix-field">Недоступное поле<input value="Только просмотр" disabled></label><label class="irlix-field">Описание<textarea v-model="notes" rows="3" placeholder="Текст описания" /></label></UiPanel>
        <h3>Общие иконки</h3>
        <UiPanel class="ds-icons"><div v-for="icon in icons" :key="icon"><UiIcon :name="icon" /><code>{{ icon }}</code></div></UiPanel>
      </section>

      <section id="section-patterns" class="ds-section">
        <h2>Рабочие паттерны</h2>
        <h3>Иерархия «Запрос → Позиция → Попытка»</h3>
        <UiPanel class="ds-tree"><div class="ds-tree-row"><UiTreeToggle :expanded="requestOpen" :hover="false" :label="requestOpen ? 'Свернуть запрос' : 'Раскрыть запрос'" @click="requestOpen = !requestOpen" /><strong>Демо-запрос · Разработка портала</strong><UiBadge tone="info">В работе</UiBadge></div><template v-if="requestOpen"><div class="ds-tree-row ds-tree-row--position"><UiTreeToggle :expanded="positionOpen" variant="plus" :hover="false" :label="positionOpen ? 'Свернуть позицию' : 'Раскрыть позицию'" @click="positionOpen = !positionOpen" /><span>Frontend · Middle</span><UiBadge tone="neutral">1 позиция</UiBadge></div><div v-if="positionOpen" class="ds-tree-row ds-tree-row--attempt"><i class="ds-tree-dot" /><span>Демо-специалист 01</span><UiBadge tone="warning">Интервью</UiBadge></div></template></UiPanel>
        <p class="ds-hint">Рамки вложенных карточек не нужны: уровни различаются отступами, соединительными линиями, типографикой и статусами. Стрелка и +/− — варианты общего UiTreeToggle; состояние задаёт сервис.</p>
        <h3>Таблица с группами</h3>
        <UiPanel class="ds-content ds-permissions"><table class="irlix-data-table"><thead><tr><th>Действие</th><th>Доступ</th></tr></thead><tbody><tr class="irlix-table-group"><th colspan="2">Лиды и клиенты</th></tr><tr><td>Просмотр клиентов</td><td><UiBadge tone="success">Разрешено</UiBadge></td></tr><tr class="irlix-table-group"><th colspan="2">Запросы</th></tr><tr><td>Создание запроса</td><td><UiBadge tone="neutral">Свои</UiBadge></td></tr></tbody></table></UiPanel>
        <h3>Реестр: заголовок и внутренний скролл</h3>
        <UiSegmentedControl v-model="registryState" :items="[{ value: 'data', label: 'Данные' }, { value: 'loading', label: 'Загрузка' }, { value: 'empty', label: 'Пусто' }, { value: 'error', label: 'Ошибка' }]" />
        <UiPanel class="ds-registry"><div v-if="registryState === 'loading'" class="ds-state" role="status">Загрузка записей…</div><div v-else-if="registryState === 'empty' || (registryState === 'data' && !visibleRows.length)" class="ds-state">Нет записей по заданным условиям.</div><div v-else-if="registryState === 'error'" class="ds-state ds-state--error" role="alert">Не удалось загрузить реестр.<UiButton compact variant="secondary" @click="registryState = 'data'">Повторить</UiButton></div><div v-else class="ds-table-scroll"><table class="irlix-data-table"><thead><tr><th>Специалист</th><th>Проект</th><th>Часы в день</th><th>Статус</th></tr></thead><tbody><tr v-for="row in visibleRows" :key="row.id"><td>{{ row.name }}</td><td>{{ row.project }}</td><td>{{ row.hours }}</td><td><UiBadge :tone="row.tone">{{ row.tone === 'success' ? 'На проекте' : 'Согласование' }}</UiBadge></td></tr></tbody></table></div></UiPanel>
        <p class="ds-hint">В сервисе реестр занимает оставшуюся высоту окна, заголовок закреплён внутри скролла. Здесь показан ограниченный образец для проверки поведения таблицы.</p>
        <h3>Карточки справа</h3>
        <UiPanel class="ds-row"><UiButton @click="drawerOpen = true">Открыть карточку</UiButton><span class="ds-hint">Изменяемая ширина до 90% окна; вложенная карточка блокирует предыдущую.</span></UiPanel>
      </section>

      <section id="section-navigation" class="ds-section">
        <h2>Навигация</h2>
        <UiAppTopbar service="clients" :breadcrumbs="[{label:'Клиенты',href:'/clients/clients/'},{label:'Демо-клиент Север'}]"><template #actions><UiButton compact variant="secondary">Действие</UiButton></template></UiAppTopbar>
        <UiPanel class="ds-content"><h3>UiAppSidebar</h3><p>Меню слева — живой пример общего компонента. Верхние разделы прокручиваются отдельно, нижние действия закреплены. Подписи появляются одновременно при наведении. Логотип открывает общий каталог сервисов.</p><p class="ds-hint">Сервис передаёт только пункты меню, активный раздел и пользователя. Размеры, цвета, группировка и launcher задаются общей библиотекой.</p></UiPanel>
      </section>
      <UiDrawer :open="drawerOpen" :inactive="nestedDrawerOpen" title="Демо-проект · Карточка" width="620px" @close="drawerOpen = false"><UiTabs v-model="drawerTab" :items="[{ value: 'info', label: 'Информация' }, { value: 'history', label: 'История', count: 2 }]" /><div class="ds-drawer-content"><template v-if="drawerTab === 'info'"><p>Демо-клиент Север · Frontend</p><UiButton @click="nestedDrawerOpen = true">Открыть вложенную карточку</UiButton></template><p v-else>Демонстрационная запись истории.</p></div></UiDrawer>
      <UiDrawer :open="nestedDrawerOpen" title="Демо-специалист 01" width="420px" :min-width="320" :z-index="1050" @close="nestedDrawerOpen = false"><p>Предыдущая карточка недоступна до закрытия этой.</p><UiBadge tone="success">На проекте</UiBadge></UiDrawer>
    </main>
  </UiAppShell>
</template>

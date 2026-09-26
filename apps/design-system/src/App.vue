<script setup>
import { ref } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiPageHeader, UiPanel, UiSegmentedControl, UiTabs } from '@irlix/ui';

const sampleInput = ref('IRLIX');
const sampleSelect = ref('Трудоустроен');
const selectedTab = ref('connections');
const selectedView = ref('kanban');
const drawerOpen = ref(false);
const colors = [
  ['Primary','--irlix-color-primary','var(--irlix-color-primary)'],
  ['Primary soft','--irlix-color-primary-soft','var(--irlix-color-primary-soft)'],
  ['Surface','--irlix-color-surface','var(--irlix-color-surface)'],
  ['Border','--irlix-color-border','var(--irlix-color-border)'],
  ['Text','--irlix-color-text','var(--irlix-color-text)'],
  ['Muted','--irlix-color-text-muted','var(--irlix-color-text-muted)'],
];
const clientTabs = [
  { value:'about', label:'О клиенте' },
  { value:'contacts', label:'Контакты' },
  { value:'projects', label:'Проекты' },
  { value:'connections', label:'Подключения' },
  { value:'periods', label:'Отчётные периоды' },
  { value:'notes', label:'Заметки' },
  { value:'history', label:'История' },
];
const views = [{ value:'kanban', label:'Канбан' }, { value:'gantt', label:'Гант' }];
</script>

<template>
  <main class="ds-page irlix-ui">
    <div class="ds-topbar"><a href="/">← IRLIX services</a><span>IRLIX platform tool</span></div>
    <UiPageHeader eyebrow="IRLIX DESIGN SYSTEM" title="Design System" description="Общая витрина токенов, компонентов и UI-паттернов внутренних сервисов." />

    <section class="ds-section"><h2>Цвета</h2><UiPanel class="grid colors"><div v-for="c in colors" :key="c[1]" class="color"><i :style="{background:c[2]}"/><strong>{{c[0]}}</strong><code>{{c[1]}}</code></div></UiPanel></section>

    <section class="ds-section"><h2>Кнопки и статусы</h2><UiPanel class="row"><UiButton>Primary</UiButton><UiButton variant="secondary">Secondary</UiButton><UiButton variant="ghost">Ghost</UiButton><UiButton variant="danger">Danger</UiButton><UiBadge tone="success">На проекте</UiBadge><span class="status-chip irlix-status-info">Первичный контакт</span><span class="status-chip irlix-status-danger">Сделка закрыта — Отказ</span></UiPanel></section>

    <section class="ds-section"><h2>Фильтры</h2><UiPanel class="toolbar-demo"><div class="irlix-toolbar"><input class="irlix-search" placeholder="Поиск"><select><option>Аккаунты</option></select><select><option>Сейлзы</option></select><select><option>Технологии</option></select><select><option>Подразделения</option></select><label class="check"><input type="checkbox" checked> Только активные</label></div></UiPanel></section>

    <section class="ds-section"><h2>Вкладки карточки</h2><UiTabs v-model="selectedTab" :items="clientTabs" /></section>

    <section class="ds-section"><h2>Переключатель представления</h2><UiSegmentedControl v-model="selectedView" :items="views" /></section>

    <section class="ds-section"><h2>Формы</h2><UiPanel class="grid form"><label class="irlix-field">Текст<input v-model="sampleInput"></label><label class="irlix-field">Статус<select v-model="sampleSelect"><option>Ожидает трудоустройства</option><option>Трудоустроен</option><option>Уволен</option></select></label></UiPanel></section>

    <section class="ds-section"><h2>Data table</h2><UiPanel class="table-panel"><div class="table-wrap"><table class="irlix-data-table"><thead><tr><th>Сотрудник</th><th>Проект</th><th>Период работы</th><th>Технология</th><th>Ставка, руб/ч</th><th>Загрузка, ч/д</th><th>Статус</th></tr></thead><tbody><tr><td>Конихов Артур</td><td>Магнит</td><td>20.03.2026 – 21.12.2026</td><td>SA · Middle</td><td>2300</td><td>8</td><td><UiBadge tone="success">На проекте</UiBadge></td></tr></tbody></table></div></UiPanel></section>

    <section class="ds-section"><h2>Правый drawer</h2><UiPanel class="row"><UiButton @click="drawerOpen = true">Открыть участника проекта</UiButton><span class="hint">Используется для участника проекта, лида и других быстрых карточек.</span></UiPanel></section>

    <UiDrawer :open="drawerOpen" title="Конихов Артур (Магнит)" width="620px" @close="drawerOpen = false">
      <div class="drawer-meta"><span>Ответственный</span><strong>Шестунова Наталья</strong></div>
      <UiTabs v-model="selectedTab" :items="[{value:'connections',label:'Ставки'},{value:'interview',label:'Интервью'},{value:'feedback',label:'Фидбеки'},{value:'notes',label:'Заметки'}]" />
      <div class="drawer-card"><b>20.03.2026 – 21.12.2026</b><span>2300 ₽/ч</span><span>8 ч/д</span><span>System analyst · Middle</span></div>
    </UiDrawer>
  </main>
</template>

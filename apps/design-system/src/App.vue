<script setup>
import { ref } from 'vue';
import { UiBadge, UiButton, UiPageHeader, UiPanel } from '@irlix/ui';

const sampleInput = ref('IRLIX');
const sampleSelect = ref('Трудоустроен');
const colors = [
  ['Primary','--irlix-color-primary','var(--irlix-color-primary)'],
  ['Primary soft','--irlix-color-primary-soft','var(--irlix-color-primary-soft)'],
  ['Surface','--irlix-color-surface','var(--irlix-color-surface)'],
  ['Border','--irlix-color-border','var(--irlix-color-border)'],
  ['Text','--irlix-color-text','var(--irlix-color-text)'],
  ['Muted','--irlix-color-muted','var(--irlix-color-muted)'],
];
</script>

<template>
  <main class="ds-page irlix-ui">
    <div class="ds-topbar"><a href="/">← Employees</a><span>IRLIX platform tool</span></div>
    <UiPageHeader eyebrow="IRLIX DESIGN SYSTEM" title="Design System" description="Общая витрина токенов, компонентов и UI-паттернов всех внутренних сервисов." />

    <section class="ds-section"><h2>Цвета</h2><UiPanel class="grid colors"><div v-for="c in colors" :key="c[1]" class="color"><i :style="{background:c[2]}"/><strong>{{c[0]}}</strong><code>{{c[1]}}</code></div></UiPanel></section>

    <section class="ds-section"><h2>Кнопки и badges</h2><UiPanel class="row"><UiButton>Primary</UiButton><UiButton variant="secondary">Secondary</UiButton><UiButton variant="ghost">Ghost</UiButton><UiButton variant="danger">Danger</UiButton><UiBadge tone="success">Трудоустроен</UiBadge><UiBadge tone="info">Производственное</UiBadge></UiPanel></section>

    <section class="ds-section"><h2>Формы</h2><UiPanel class="grid form"><label class="irlix-field">Текст<input v-model="sampleInput"></label><label class="irlix-field">Статус<select v-model="sampleSelect"><option>Ожидает трудоустройства</option><option>Трудоустроен</option><option>Уволен</option></select></label></UiPanel></section>

    <section class="ds-section"><h2>Data table</h2><UiPanel class="table-panel"><div class="table-wrap"><table class="irlix-data-table"><thead><tr><th>Сотрудник</th><th>Подразделение</th><th>Статус</th><th></th></tr></thead><tbody><tr><td>Иван Иванов</td><td>Backend</td><td><UiBadge tone="success">Трудоустроен</UiBadge></td><td><UiButton compact variant="secondary">✎</UiButton></td></tr></tbody></table></div></UiPanel></section>
  </main>
</template>

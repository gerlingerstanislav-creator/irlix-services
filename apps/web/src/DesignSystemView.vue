<script setup>
import { ref } from 'vue';
import { UiBadge, UiButton, UiPageHeader, UiPanel } from '@irlix/ui';

const sampleInput = ref('IRLIX');
const sampleSelect = ref('Трудоустроен');
const sampleCheckbox = ref(true);

const colors = [
  { name: 'Primary', token: '--irlix-color-primary', css: 'var(--irlix-color-primary)' },
  { name: 'Primary soft', token: '--irlix-color-primary-soft', css: 'var(--irlix-color-primary-soft)' },
  { name: 'Surface', token: '--irlix-color-surface', css: 'var(--irlix-color-surface)' },
  { name: 'Page', token: '--irlix-color-page', css: 'var(--irlix-color-page)' },
  { name: 'Border', token: '--irlix-color-border', css: 'var(--irlix-color-border)' },
  { name: 'Text', token: '--irlix-color-text', css: 'var(--irlix-color-text)' },
  { name: 'Muted', token: '--irlix-color-muted', css: 'var(--irlix-color-muted)' },
  { name: 'Success', token: '--irlix-color-success', css: 'var(--irlix-color-success)' },
  { name: 'Danger', token: '--irlix-color-danger', css: 'var(--irlix-color-danger)' },
];

const spacings = [4, 8, 12, 16, 20, 24, 32, 40];
</script>

<template>
  <div class="design-system-page">
    <UiPageHeader
      eyebrow="IRLIX DESIGN SYSTEM"
      title="Design System"
      description="Живой каталог визуальных токенов, базовых компонентов и повторяемых UI-паттернов внутренних сервисов."
    />

    <div class="ds-intro">
      <UiBadge tone="info">Reference UI</UiBadge>
      <span>Эта страница является визуальным эталоном. Новые сервисы должны переиспользовать компоненты из <code>packages/ui</code>, а не создавать локальные аналоги.</span>
    </div>

    <section class="ds-section">
      <div class="ds-section-heading">
        <div><span class="ds-kicker">FOUNDATIONS</span><h2>Цвета и токены</h2></div>
        <p>База взята из текущих сервисов: нейтральный административный интерфейс, светлые поверхности и синий акцент.</p>
      </div>
      <UiPanel class="ds-panel ds-colors">
        <div v-for="color in colors" :key="color.token" class="ds-color-card">
          <span class="ds-swatch" :style="{ background: color.css }" />
          <strong>{{ color.name }}</strong>
          <code>{{ color.token }}</code>
        </div>
      </UiPanel>
    </section>

    <section class="ds-section">
      <div class="ds-section-heading">
        <div><span class="ds-kicker">FOUNDATIONS</span><h2>Типографика</h2></div>
        <p>Компактная типографика для насыщенных реестров и административных экранов.</p>
      </div>
      <UiPanel class="ds-panel ds-type-grid">
        <div><span class="ds-spec">Page title</span><div class="ds-type-page-title">Сотрудники</div></div>
        <div><span class="ds-spec">Section title</span><div class="ds-type-section-title">Организационная структура</div></div>
        <div><span class="ds-spec">Body</span><div class="ds-type-body">Основной текст интерфейса и описания сущностей.</div></div>
        <div><span class="ds-spec">Caption</span><div class="ds-type-caption">Подсказки, метаданные и вторичная информация</div></div>
      </UiPanel>
    </section>

    <section class="ds-section">
      <div class="ds-section-heading">
        <div><span class="ds-kicker">COMPONENTS</span><h2>Кнопки</h2></div>
        <p>Primary — основное действие, Secondary — альтернативное, Ghost — малозаметное действие, Danger — разрушительное.</p>
      </div>
      <UiPanel class="ds-panel ds-component-row">
        <UiButton>Primary</UiButton>
        <UiButton variant="secondary">Secondary</UiButton>
        <UiButton variant="ghost">Ghost</UiButton>
        <UiButton variant="danger">Danger</UiButton>
        <UiButton compact>Compact</UiButton>
        <UiButton disabled>Disabled</UiButton>
      </UiPanel>
    </section>

    <section class="ds-section">
      <div class="ds-section-heading">
        <div><span class="ds-kicker">COMPONENTS</span><h2>Badges</h2></div>
        <p>Используются для классификации и коротких статусов внутри таблиц и карточек.</p>
      </div>
      <UiPanel class="ds-panel ds-component-row">
        <UiBadge tone="info">Производственное</UiBadge>
        <UiBadge tone="neutral">Ожидает трудоустройства</UiBadge>
        <UiBadge tone="success">Трудоустроен</UiBadge>
      </UiPanel>
    </section>

    <section class="ds-section">
      <div class="ds-section-heading">
        <div><span class="ds-kicker">COMPONENTS</span><h2>Поля форм</h2></div>
        <p>Единая высота control, label сверху, hint/error снизу. Пока это общий pattern; следующим шагом он станет отдельными Vue-компонентами.</p>
      </div>
      <UiPanel class="ds-panel ds-form-grid">
        <label class="irlix-field">Текстовое поле<input v-model="sampleInput" /></label>
        <label class="irlix-field">Select<select v-model="sampleSelect"><option>Ожидает трудоустройства</option><option>Трудоустроен</option><option>Уволен</option></select></label>
        <label class="irlix-field">Пустое поле<input placeholder="Placeholder" /></label>
        <label class="irlix-field ds-field-error">Поле с ошибкой<input value="Некорректное значение" /><small>Проверь значение поля</small></label>
        <label class="checkbox-field"><input v-model="sampleCheckbox" type="checkbox" /> Checkbox / boolean property</label>
      </UiPanel>
    </section>

    <section class="ds-section">
      <div class="ds-section-heading">
        <div><span class="ds-kicker">PATTERN</span><h2>Data table</h2></div>
        <p>Основной паттерн сервисов: плотная таблица, спокойный header, иерархия отступами, компактные действия справа.</p>
      </div>
      <UiPanel class="ds-panel ds-table-panel">
        <div class="table-wrap">
          <table class="irlix-data-table">
            <thead><tr><th>Название / Алиас</th><th>Руководитель</th><th>HR</th><th>Сотрудники</th><th>Статус</th><th /></tr></thead>
            <tbody>
              <tr><td><strong>Irlix</strong></td><td>Иван Иванов</td><td>Анна Иванова</td><td>48</td><td><UiBadge tone="neutral">Активно</UiBadge></td><td><UiButton variant="secondary" compact>✎</UiButton></td></tr>
              <tr><td><span class="ds-tree-indent">Development</span></td><td>Павел Матвеев</td><td>Ксения Гринчук</td><td>33</td><td><UiBadge tone="info">Производственное</UiBadge></td><td><UiButton variant="secondary" compact>✎</UiButton></td></tr>
              <tr><td><span class="ds-tree-indent ds-tree-indent--2">Backend</span></td><td>Павел Матвеев</td><td>Ксения Гринчук</td><td>7</td><td><UiBadge tone="info">Производственное</UiBadge></td><td><UiButton variant="secondary" compact>✎</UiButton></td></tr>
            </tbody>
          </table>
        </div>
      </UiPanel>
    </section>

    <section class="ds-section">
      <div class="ds-section-heading">
        <div><span class="ds-kicker">PATTERNS</span><h2>Состояния</h2></div>
        <p>Каждый экран должен иметь определённые loading, empty, error и success состояния.</p>
      </div>
      <div class="ds-state-grid">
        <UiPanel class="ds-state"><UiBadge tone="neutral">Loading</UiBadge><strong>Загрузка данных…</strong><span>Не меняем layout во время ожидания.</span></UiPanel>
        <UiPanel class="ds-state"><UiBadge tone="neutral">Empty</UiBadge><strong>Пока ничего нет</strong><span>Объясняем, что можно сделать дальше.</span><UiButton compact>Создать</UiButton></UiPanel>
        <UiPanel class="ds-state ds-state--error"><UiBadge tone="neutral">Error</UiBadge><strong>Не удалось загрузить данные</strong><span>Показываем понятную причину и retry.</span><UiButton variant="secondary" compact>Повторить</UiButton></UiPanel>
      </div>
    </section>

    <section class="ds-section">
      <div class="ds-section-heading">
        <div><span class="ds-kicker">FOUNDATIONS</span><h2>Spacing</h2></div>
        <p>Отступы строятся на 4px-сетке. Чаще всего используются 8, 12, 16, 24 и 32 px.</p>
      </div>
      <UiPanel class="ds-panel ds-spacing-list">
        <div v-for="space in spacings" :key="space" class="ds-spacing-row"><code>{{ space }}px</code><span class="ds-spacing-bar" :style="{ width: `${space * 4}px` }" /></div>
      </UiPanel>
    </section>
  </div>
</template>

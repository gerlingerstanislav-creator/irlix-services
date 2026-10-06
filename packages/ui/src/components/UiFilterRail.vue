<script setup>
import { computed, ref } from 'vue';
import UiIcon from './UiIcon.vue';

const props = defineProps({
  items: { type: Array, default: () => [] },
  groupingItems: { type: Array, default: () => [] },
  title: { type: String, default: 'Фильтры' },
  groupingTitle: { type: String, default: 'Группировки' },
  resetLabel: { type: String, default: 'Сбросить все' },
  showReset: { type: Boolean, default: true },
  contained: { type: Boolean, default: false },
});

const emit = defineEmits(['reset']);
const hovered = ref(false);
const pinned = ref(false);
const open = computed(() => hovered.value || pinned.value);

const togglePinned = () => {
  pinned.value = !pinned.value;
};
</script>

<template>
  <aside
    class="irlix-filter-rail"
    :class="{ open, pinned, contained }"
    data-component="ui-filter-rail"
    @mouseenter="hovered = true"
    @mouseleave="hovered = false"
  >
    <div class="irlix-filter-rail__panel-bg" :aria-hidden="!open" />
    <div class="irlix-filter-rail__layout">
      <div class="irlix-filter-rail__header-row">
        <div class="irlix-filter-rail__header irlix-filter-rail__panel-part">
          <strong>{{ title }}</strong>
          <button type="button" class="irlix-filter-rail__pin" :class="{ active: pinned }" @click="togglePinned">
            {{ pinned ? 'Открепить' : 'Закрепить' }}
          </button>
        </div>
        <div class="irlix-filter-rail__rail-spacer" aria-hidden="true" />
      </div>

      <div class="irlix-filter-rail__filters" aria-label="Фильтры страницы">
        <div v-for="item in items" :key="item.id" class="irlix-filter-rail__filter-row">
          <div class="irlix-filter-rail__filter irlix-filter-rail__panel-part">
            <label class="irlix-filter-rail__field">
              <span class="irlix-filter-rail__field-label">{{ item.label }}</span>
              <slot :name="`filter-${item.id}`" :item="item" />
            </label>
          </div>
          <div class="irlix-filter-rail__icon-cell">
            <button
              type="button"
              class="irlix-filter-rail__icon"
              :class="{ active: item.active }"
              :aria-label="item.label"
              :title="item.active && item.valueLabel ? `${item.label}: ${item.valueLabel}` : item.label"
              @click="togglePinned"
            >
              <UiIcon :name="item.icon || 'filter'" />
            </button>
          </div>
        </div>
      </div>

      <div v-if="groupingItems.length" class="irlix-filter-rail__groupings" aria-label="Группировки страницы">
        <div class="irlix-filter-rail__section-title-row">
          <div class="irlix-filter-rail__section-title irlix-filter-rail__panel-part"><strong>{{ groupingTitle }}</strong></div>
          <div class="irlix-filter-rail__icon-cell" aria-hidden="true" />
        </div>
        <div v-for="item in groupingItems" :key="item.id" class="irlix-filter-rail__filter-row">
          <div class="irlix-filter-rail__filter irlix-filter-rail__panel-part">
            <label class="irlix-filter-rail__field">
              <span class="irlix-filter-rail__field-label">{{ item.label }}</span>
              <slot :name="`grouping-${item.id}`" :item="item" />
            </label>
          </div>
          <div class="irlix-filter-rail__icon-cell">
            <button
              type="button"
              class="irlix-filter-rail__icon"
              :class="{ active: item.active }"
              :aria-label="item.label"
              :title="item.active && item.valueLabel ? `${item.label}: ${item.valueLabel}` : item.label"
              @click="togglePinned"
            >
              <UiIcon :name="item.icon || 'list'" />
            </button>
          </div>
        </div>
      </div>

      <div class="irlix-filter-rail__grow" />

      <div v-if="showReset" class="irlix-filter-rail__footer-row">
        <div class="irlix-filter-rail__footer irlix-filter-rail__panel-part">
          <button type="button" class="irlix-filter-rail__reset" @click="emit('reset')">{{ resetLabel }}</button>
        </div>
        <div class="irlix-filter-rail__icon-cell irlix-filter-rail__icon-cell--footer">
          <button type="button" class="irlix-filter-rail__icon irlix-filter-rail__reset-icon" :aria-label="resetLabel" :title="resetLabel" @click="emit('reset')">
            <UiIcon name="reset" />
          </button>
        </div>
      </div>
    </div>
  </aside>
</template>

<style scoped>
.irlix-filter-rail {
  position: fixed;
  top: 0;
  right: 0;
  z-index: 38;
  width: var(--irlix-filter-rail-width);
  height: 100vh;
  border-left: 1px solid var(--irlix-color-border);
  background: var(--irlix-color-surface);
}
.irlix-filter-rail.contained {
  position: absolute;
  height: 100%;
}
.irlix-filter-rail__panel-bg {
  position: absolute;
  pointer-events: none;
  top: 0;
  right: var(--irlix-filter-rail-width);
  width: min(var(--irlix-filter-rail-panel-width), calc(100vw - var(--irlix-sidebar-width) - var(--irlix-filter-rail-width) - 20px));
  height: 100%;
  background: var(--irlix-color-surface);
  border-left: 1px solid var(--irlix-color-border);
  box-shadow: var(--irlix-filter-rail-shadow);
  opacity: 0;
  visibility: hidden;
  transform: translateX(8px);
  transition: opacity .12s ease, transform .12s ease, visibility .12s ease;
}
.irlix-filter-rail.open .irlix-filter-rail__panel-bg {
  opacity: 1;
  visibility: visible;
  transform: translateX(0);
}
.irlix-filter-rail__layout {
  position: absolute;
  inset: 0 0 0 auto;
  width: var(--irlix-filter-rail-width);
  max-width: calc(100vw - var(--irlix-sidebar-width) - 20px);
  height: 100%;
  display: flex;
  flex-direction: column;
}
.irlix-filter-rail.open .irlix-filter-rail__layout {
  width: calc(var(--irlix-filter-rail-panel-width) + var(--irlix-filter-rail-width));
}
.irlix-filter-rail__header-row,
.irlix-filter-rail__filter-row,
.irlix-filter-rail__section-title-row,
.irlix-filter-rail__footer-row {
  display: grid;
  grid-template-columns: 0 var(--irlix-filter-rail-width);
}
.irlix-filter-rail.open .irlix-filter-rail__header-row,
.irlix-filter-rail.open .irlix-filter-rail__filter-row,
.irlix-filter-rail.open .irlix-filter-rail__section-title-row,
.irlix-filter-rail.open .irlix-filter-rail__footer-row {
  grid-template-columns: minmax(0, var(--irlix-filter-rail-panel-width)) var(--irlix-filter-rail-width);
}
.irlix-filter-rail__panel-part {
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition: opacity .12s ease, visibility .12s ease;
}
.irlix-filter-rail.open .irlix-filter-rail__panel-part {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
}
.irlix-filter-rail__header {
  min-height: var(--irlix-topbar-height);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 0 12px;
  border-bottom: 1px solid var(--irlix-color-border);
}
.irlix-filter-rail__header strong,.irlix-filter-rail__section-title strong { font-size: 13px; }
.irlix-filter-rail__pin,
.irlix-filter-rail__reset {
  border: 0;
  background: transparent;
  color: var(--irlix-color-text-muted);
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
}
.irlix-filter-rail__pin.active { color: var(--irlix-color-text); }
.irlix-filter-rail__rail-spacer { min-height: var(--irlix-topbar-height); }
.irlix-filter-rail__filters { padding-top: 10px; }
.irlix-filter-rail__groupings { padding-top: 8px; margin-top: 8px; border-top: 1px solid var(--irlix-color-border); }
.irlix-filter-rail__section-title-row { min-height: var(--irlix-topbar-height); }
.irlix-filter-rail__section-title { min-height:var(--irlix-topbar-height); display:flex; align-items:center; padding:0 12px; color:var(--irlix-color-text); }
.irlix-filter-rail__filter-row { min-height: 66px; }
.irlix-filter-rail__filter {
  min-width: 0;
  display: flex;
  align-items: flex-end;
  padding: 4px 12px 6px;
}
.irlix-filter-rail__filter > :deep(*) { width: 100%; }
.irlix-filter-rail__field { display:grid; gap:4px; width:100%; min-width:0; }
.irlix-filter-rail__field > :deep(*) { width:100%; }
.irlix-filter-rail__field-label { color:var(--irlix-color-text-muted); font-size:11px; font-weight:600; line-height:1.2; }
.irlix-filter-rail__icon-cell {
  display: flex;
  align-items: flex-end;
  justify-content: center;
  padding-bottom: 2px;
}
.irlix-filter-rail__icon {
  width: 40px;
  height: 40px;
  display: grid;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--irlix-filter-rail-icon);
  cursor: pointer;
}
.irlix-filter-rail__icon:hover { background: var(--irlix-filter-rail-hover-bg); color: var(--irlix-filter-rail-icon-hover); }
.irlix-filter-rail__icon.active { background: var(--irlix-filter-rail-active-bg); color: var(--irlix-filter-rail-icon-hover); }
.irlix-filter-rail__icon svg { width: 20px; height: 20px; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.irlix-filter-rail__grow { flex: 1; min-height: 12px; }
.irlix-filter-rail__footer-row { padding-bottom: 10px; }
.irlix-filter-rail__footer { display: flex; align-items: flex-end; padding: 0 12px; }
.irlix-filter-rail__reset {
  width: 100%;
  min-height: var(--irlix-control-height);
  border: 1px solid var(--irlix-color-border);
  border-radius: var(--irlix-control-radius);
}
.irlix-filter-rail__icon-cell--footer { padding-bottom: 0; }
.irlix-filter-rail__reset-icon { color: var(--irlix-color-text-muted); }
@media (max-width: 720px) {
  .irlix-filter-rail { top: 53px; height: calc(100vh - 53px); }
  .irlix-filter-rail.contained { top: 0; height: 100%; }
  .irlix-filter-rail__layout { max-width: 100vw; }
  .irlix-filter-rail__panel-bg {
    width: calc(100vw - var(--irlix-filter-rail-width));
  }
  .irlix-filter-rail__header-row,
  .irlix-filter-rail__filter-row,
  .irlix-filter-rail__section-title-row,
  .irlix-filter-rail__footer-row {
    grid-template-columns: calc(100vw - var(--irlix-filter-rail-width)) var(--irlix-filter-rail-width);
  }
}
</style>

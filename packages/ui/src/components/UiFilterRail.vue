<script setup>
import { computed, ref } from 'vue';
import UiIcon from './UiIcon.vue';

const props = defineProps({
  items: { type: Array, default: () => [] },
  title: { type: String, default: 'Фильтры' },
  resetLabel: { type: String, default: 'Сбросить все' },
  showReset: { type: Boolean, default: true },
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
    :class="{ open, pinned }"
    data-component="ui-filter-rail"
    @mouseenter="hovered = true"
    @mouseleave="hovered = false"
  >
    <div class="irlix-filter-rail__icons" aria-label="Фильтры страницы">
      <button
        v-for="item in items"
        :key="item.id"
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

    <div class="irlix-filter-rail__panel" :aria-hidden="!open">
      <div class="irlix-filter-rail__header">
        <strong>{{ title }}</strong>
        <button type="button" class="irlix-filter-rail__pin" :class="{ active: pinned }" @click="togglePinned">
          {{ pinned ? 'Открепить' : 'Закрепить' }}
        </button>
      </div>
      <div class="irlix-filter-rail__body"><slot /></div>
      <button v-if="showReset" type="button" class="irlix-filter-rail__reset" @click="emit('reset')">{{ resetLabel }}</button>
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
.irlix-filter-rail__icons {
  width: var(--irlix-filter-rail-width);
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 7px;
  padding: calc(var(--irlix-topbar-height) + 12px) 6px 10px;
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
.irlix-filter-rail__panel {
  position: absolute;
  top: 0;
  right: var(--irlix-filter-rail-width);
  width: min(var(--irlix-filter-rail-panel-width), calc(100vw - var(--irlix-sidebar-width) - var(--irlix-filter-rail-width) - 20px));
  height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--irlix-color-surface);
  border-left: 1px solid var(--irlix-color-border);
  box-shadow: var(--irlix-filter-rail-shadow);
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transform: translateX(8px);
  transition: opacity .12s ease, transform .12s ease, visibility .12s ease;
}
.irlix-filter-rail.open .irlix-filter-rail__panel {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  transform: translateX(0);
}
.irlix-filter-rail__header {
  min-height: var(--irlix-topbar-height);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 0 14px;
  border-bottom: 1px solid var(--irlix-color-border);
}
.irlix-filter-rail__header strong { font-size: 13px; }
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
.irlix-filter-rail__body { flex: 1; min-height: 0; overflow: auto; display: grid; align-content: start; gap: 12px; padding: 14px; }
.irlix-filter-rail__reset { margin: 0 14px 14px; min-height: 32px; border: 1px solid var(--irlix-color-border); border-radius: 7px; }
@media (max-width: 720px) {
  .irlix-filter-rail { top: 53px; height: calc(100vh - 53px); }
  .irlix-filter-rail__icons { padding-top: 12px; }
  .irlix-filter-rail__panel { width: calc(100vw - var(--irlix-filter-rail-width)); }
}
</style>

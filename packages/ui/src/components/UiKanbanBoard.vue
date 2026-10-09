<script setup>
import { computed } from 'vue';
import { normalizeKanbanColumns, kanbanItemsFor } from '../kanban.js';

const props = defineProps({
  columns: { type: Array, required: true },
  items: { type: Array, default: () => [] },
  statusKey: { type: String, default: 'status' },
  activeTarget: { type: [String, Number], default: null },
  label: { type: String, default: 'Канбан' },
  minColumnWidth: { type: Number, default: 152 },
});
const emit = defineEmits(['dragover', 'dragleave', 'drop']);
const normalizedColumns = computed(() => normalizeKanbanColumns(props.columns));
function cardsFor(id) { return kanbanItemsFor(props.items, props.statusKey, id); }
function dragOver(event, id) { emit('dragover', event, id); }
function drop(event, id) { emit('drop', event, id); }
function leave(event, id) { emit('dragleave', event, id); }
</script>

<template>
  <div class="irlix-kanban" :style="{ '--irlix-kanban-count': normalizedColumns.length, '--irlix-kanban-min-width': minColumnWidth + 'px' }" role="group" :aria-label="label">
    <section v-for="column in normalizedColumns" :key="column.id" class="irlix-kanban-column"
      :class="{ 'irlix-kanban-column--target': activeTarget === column.id }"
      :aria-label="column.label" @dragover="dragOver($event,column.id)" @drop="drop($event,column.id)" @dragleave="leave($event,column.id)">
      <header class="irlix-kanban-column__header">
        <strong>{{ column.label }}</strong>
        <span class="irlix-kanban-column__count">{{ cardsFor(column.id).length }}</span>
      </header>
      <div class="irlix-kanban-column__cards">
        <slot name="cards" :column="column" :items="cardsFor(column.id)"/>
      </div>
    </section>
  </div>
</template>

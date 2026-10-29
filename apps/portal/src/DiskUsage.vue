<script setup>
import { computed } from 'vue';
import { diskCategoryColor, diskTasks, physicalDiskRows } from './diskInventory.js';

const props = defineProps({
  inventory: { type: Object, default: null },
  snapshot: { type: Object, default: null },
  labels: { type: Function, required: true },
});
const fs = computed(() => !props.inventory?.error && !props.inventory?.stale
  ? props.inventory?.filesystem : null);
const rows = computed(() => physicalDiskRows(props.inventory));
const taskRows = computed(() => diskTasks(props.snapshot));
const size = bytes => bytes == null ? '—' :
  bytes >= 1024 ** 3 ? (bytes / (1024 ** 3)).toFixed(2) + ' ГБ' :
  (bytes / (1024 ** 2)).toFixed(1) + ' МБ';
const proportion = bytes => fs.value?.used_bytes
  ? (100 * bytes / fs.value.used_bytes).toFixed(1) + '%' : '—';
const balance = computed(() => fs.value
  ? fs.value.used_bytes - fs.value.classified_bytes -
    fs.value.unclassified_bytes + fs.value.overlap_bytes : null);
</script>

<template>
  <section class="resource-disk-audit" aria-label="Физическая инвентаризация диска">
    <header class="resource-disk-audit-head">
      <div>
        <h2>Куда расходуется пространство диска</h2>
        <p class="resource-muted">Учёт занятых блоков корневой файловой системы, без суммирования оценок Docker.</p>
      </div>
      <span v-if="inventory?.collected_at" class="resource-muted">
        Замер: {{ new Date(inventory.collected_at * 1000).toLocaleString('ru-RU') }}
      </span>
    </header>
    <p v-if="!fs" class="resource-warning" role="status">
      Полная инвентаризация диска ещё недоступна или устарела. Цифры Docker не заменяют фактический учёт.
    </p>
    <template v-else>
      <div class="resource-disk-capacity">
        <div><span>Всего</span><strong>{{ size(fs.total_bytes) }}</strong></div>
        <div><span>Занято файлами и метаданными</span><strong>{{ size(fs.used_bytes) }}</strong></div>
        <div><span>Доступно</span><strong>{{ size(fs.available_bytes) }}</strong></div>
        <div><span>Резерв файловой системы</span><strong>{{ size(fs.reserved_bytes) }}</strong></div>
      </div>
      <p class="resource-muted">Всего = занято + доступно + резерв. Резерв не считается занятыми файлами. Это показатели одного замера, а не разных циклов опроса.</p>
      <p v-if="fs.partial" class="resource-warning" role="status">
        Обход неполный (ограничение времени/числа файлов или ошибка чтения). Показанные категории — нижняя граница; неизвестная часть может содержать файлы.
      </p>
      <p v-if="fs.overlap_bytes" class="resource-error" role="alert">
        Обнаружено расхождение измерений: объём каталогов превышает занятость по файловой системе на {{ size(fs.overlap_bytes) }}.
        Распределение по кольцевой диаграмме отключено; требуется проверка инвентаризации.
      </p>
      <div class="resource-disk-table-scroll">
        <table class="irlix-data-table resource-table resource-physical-table">
          <thead><tr><th>Категория</th><th>Доля занятого</th><th>Размер</th></tr></thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td><span class="resource-color" :style="{ background:diskCategoryColor(row.id) }" />{{ row.label }}</td>
              <td class="resource-physical-meter">
                <div class="resource-meter-track"><span :style="{ width:proportion(row.bytes), background:diskCategoryColor(row.id) }" /></div>
                <span>{{ proportion(row.bytes) }}</span>
              </td>
              <td :title="row.bytes.toLocaleString('ru-RU') + ' байт'">{{ size(row.bytes) }}</td>
            </tr>
            <tr class="resource-breakdown-other">
              <td>Не отнесено к файлам / накладные расходы ФС</td>
              <td class="resource-physical-meter">
                <div class="resource-meter-track"><span :style="{ width:proportion(fs.unclassified_bytes) }" /></div>
                <span>{{ proportion(fs.unclassified_bytes) }}</span>
              </td>
              <td :title="fs.unclassified_bytes.toLocaleString('ru-RU') + ' байт'">{{ size(fs.unclassified_bytes) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="resource-disk-check">
        <strong>Сверка с файловой системой</strong>
        <span>{{ size(fs.classified_bytes) }} по категориям + {{ size(fs.unclassified_bytes) }} не определено
          <template v-if="fs.overlap_bytes"> − {{ size(fs.overlap_bytes) }} расхождение</template>
          = {{ size(fs.used_bytes) }} занято</span>
        <span>Арифметическая разница: {{ balance?.toLocaleString('ru-RU') }} Б</span>
      </div>
      <p class="resource-muted">
        «Не отнесено» — это не подтверждённые файлы: сюда входят метаданные ФС, удалённые, но открытые файлы,
        скрытые точками монтирования данные, изменения за время обхода и непроверенные каталоги.
        Нулевой арифметический остаток сам по себе не доказывает полноту сканирования.
        Docker-тома и образы физически входят в категории Docker, а не прибавляются к ним отдельно.
      </p>
      <details v-if="taskRows.length" class="resource-disk-extra">
        <summary>Отдельная справка по файлам задач (не прибавлять к итогу)</summary>
        <div class="resource-disk-table-scroll">
          <table class="irlix-data-table resource-table resource-tasks-table">
            <thead><tr><th>Задача</th><th>Сервис</th><th>Оценка</th></tr></thead>
            <tbody><tr v-for="task in taskRows" :key="task.id">
              <td>{{ task.label }}</td>
              <td>{{ task.service ? labels(task.service) : 'Инфраструктура' }}</td>
              <td>{{ task.partial ? '≥ ' : '' }}{{ size(task.bytes) }}</td>
            </tr></tbody>
          </table>
        </div>
      </details>
    </template>
  </section>
</template>

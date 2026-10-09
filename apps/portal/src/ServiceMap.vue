<script setup>
import { computed, ref } from 'vue';
import { UiPanel } from '@irlix/ui';
import { contours, nodes, links } from './serviceMapData.js';
import './serviceMap.css';

const selected = ref(null);
const focusNode = id => {selected.value = selected.value === id ? null : id;};
const direct = computed(()=>selected.value ? links.filter(([from,to])=>from===selected.value||to===selected.value) : []);
const related = computed(()=>new Set(direct.value.flat()));
const edgeLabel = ([from,to])=>`${nodes[from]?.[0] || from} → ${nodes[to]?.[0] || to}`;
</script>

<template>
  <main class="service-map">
    <header class="service-map-heading">
      <div><h1>Карта сервисов</h1><p>Основные сервисы, контуры и зависимости платформы IRLIX</p></div>
      <span class="service-map-note">Доступ: администратор и тестировщик платформы</span>
    </header>
    <div class="service-map-grid">
      <UiPanel v-for="contour in contours" :key="contour.id">
        <section class="service-map-contour" :class="'service-map-'+contour.id" :aria-label="contour.title">
          <header><div><h2>{{ contour.title }}</h2><p>{{ contour.subtitle }}</p></div><span>{{ contour.items.length }}</span></header>
          <div class="service-map-nodes">
            <button v-for="id in contour.items" :key="id" type="button"
              class="service-map-node"
              :class="{'is-selected':selected===id,'is-related':selected && related.has(id),'is-dimmed':selected && !related.has(id)}"
              :aria-pressed="selected===id" @click="focusNode(id)">
              <span class="service-map-node-mark" aria-hidden="true"></span>
              <strong>{{ nodes[id]?.[0] || id }}</strong>
              <small>{{ contour.id==='ui' ? 'Интерфейс' : contour.id==='business' ? 'API' : contour.id==='data' ? 'Хранение' : 'Инфраструктура' }}</small>
            </button>
          </div>
        </section>
      </UiPanel>
    </div>
    <UiPanel>
      <section class="service-map-edges">
        <header>
          <div><h2>{{ selected ? 'Основные зависимости: '+nodes[selected]?.[0] : 'Основные зависимости' }}</h2>
          <p>{{ selected ? 'Связи выбранного компонента' : 'Выберите компонент на карте, чтобы выделить его связи' }}</p></div>
          <button v-if="selected" type="button" class="service-map-clear" @click="selected=null">Сбросить выбор</button>
        </header>
        <div class="service-map-dependencies">
          <div v-for="(edge,i) in (selected ? direct : links.filter((_,i)=>i<13))" :key="edge.join('-')" class="service-map-edge">
            <span>{{ nodes[edge[0]]?.[0] }}</span><span class="service-map-arrow" aria-hidden="true">→</span><strong>{{ nodes[edge[1]]?.[0] }}</strong>
          </div>
          <p v-if="selected && !direct.length" class="service-map-empty">Для компонента прямые связи на упрощённой карте не указаны.</p>
        </div>
      </section>
    </UiPanel>
    <p class="service-map-caption">Показаны только основные логические зависимости, а не текущие сетевые соединения. Нажмите на сервис, чтобы увидеть его связи; интерфейсы с собственными страницами можно открыть через меню платформы.</p>
  </main>
</template>

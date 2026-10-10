<script setup>
import { computed, ref } from 'vue';
import { UiPanel } from '@irlix/ui';
import { contours, nodes, links } from './serviceMapData.js';
import './serviceMap.css';

// Curated, deterministic positions: the diagram never needs network discovery
// or a heavyweight graph layout engine. SVG remains readable on narrow screens.
const columns = [
  {id:'ui', label:'FRONTEND', hint:'Веб-приложения', x:24, width:238},
  {id:'business', label:'BACKEND', hint:'API и межсервисные связи', x:382, width:238},
  {id:'infra', label:'ДАННЫЕ И ИНФРАСТРУКТУРА', hint:'Хранилища и платформенные компоненты', x:738, width:238},
];
const groups = {
  ui:contours.find(c=>c.id==='ui').items,
  business:['platform-core',...contours.find(c=>c.id==='business').items],
  infra:[...contours.find(c=>c.id==='platform').items.filter(id=>id!=='platform-core'),...contours.find(c=>c.id==='data').items],
};
const rowHeight = 62;
const top = 108;
const positions = Object.fromEntries(columns.flatMap(col=>groups[col.id].map((id,index)=>[
  id,{id,x:col.x,y:top+index*rowHeight,w:col.width,h:46,column:col.id}
])));
const height = top + Math.max(...Object.values(groups).map(a=>a.length))*rowHeight+20;
const selected = ref(null);
const nodeInfo = id => nodes[id]?.[0] || id;
const connections = computed(()=>links.map(([from,to],i)=>({
 from,to,id:`${from}:${to}:${i}`,kind:positions[from]?.column==='ui'?'frontend':positions[to]?.column==='infra'?'storage':'integration'
})).filter(l=>positions[l.from]&&positions[l.to]));
const incident = computed(()=>selected.value
 ? connections.value.filter(e=>e.from===selected.value||e.to===selected.value) : connections.value);
const highlighted = computed(()=>new Set(incident.value.flatMap(e=>[e.from,e.to])));
function choose(id){selected.value=selected.value===id?null:id;}
function edgePath(edge){
 const a=positions[edge.from],b=positions[edge.to];
 const forward=b.x>a.x;
 // Same-column backend calls arc around the right edge instead of obscuring nodes.
 if(a.column===b.column){
   const x=a.x+a.w+12+(Math.min(Math.abs(a.y-b.y)/rowHeight,5)*5);
   return `M ${a.x+a.w} ${a.y+a.h/2} C ${x+45} ${a.y+a.h/2}, ${x+45} ${b.y+b.h/2}, ${b.x+b.w} ${b.y+b.h/2}`;
 }
 const x1=forward?a.x+a.w:a.x, x2=forward?b.x:b.x;
 const y1=a.y+a.h/2, y2=b.y+b.h/2;
 const bend=(x2-x1)*0.43;
 return `M ${x1} ${y1} C ${x1+bend} ${y1}, ${x2-bend} ${y2}, ${x2} ${y2}`;
}
</script>

<template>
  <main class="service-map">
    <header class="service-map-heading">
      <div><h1>Карта сервисов</h1><p>Frontend → backend → данные, с основными межсервисными зависимостями</p></div>
      <span class="service-map-note">Доступ: администратор и тестировщик платформы</span>
    </header>
    <UiPanel>
      <section class="service-map-canvas" aria-label="Архитектурная карта сервисов">
        <div class="service-map-toolbar">
          <span><i class="service-map-key frontend"></i> Frontend → API</span>
          <span><i class="service-map-key integration"></i> API → API</span>
          <span><i class="service-map-key storage"></i> Backend → данные/инфраструктура</span>
          <button v-if="selected" class="service-map-clear" type="button" @click="selected=null">Показать все связи</button>
        </div>
        <div class="service-map-scroll">
          <svg class="service-map-diagram" :viewBox="`0 0 1000 ${height}`" role="img"
            aria-label="Схема взаимодействия frontend, backend, платформенных компонентов и хранилищ">
            <defs>
              <marker id="service-map-arrow" markerWidth="7" markerHeight="7" refX="6" refY="3.5" orient="auto" markerUnits="userSpaceOnUse">
                <path d="M0 0 L7 3.5 L0 7 Z" fill="context-stroke"/>
              </marker>
            </defs>
            <g v-for="col in columns" :key="col.id">
              <rect class="service-map-lane" :class="`lane-${col.id}`" :x="col.x-9" y="8" :width="col.width+18" :height="height-16" rx="13"/>
              <text class="service-map-column-title" :x="col.x+10" y="33">{{ col.label }}</text>
              <text class="service-map-column-hint" :x="col.x+10" y="53">{{ col.hint }}</text>
            </g>
            <g class="service-map-links">
              <path v-for="edge in connections" :key="edge.id" :d="edgePath(edge)"
                class="service-map-link" :class="[edge.kind,{'is-muted':selected && !incident.some(e=>e.id===edge.id),'is-focused':selected && incident.some(e=>e.id===edge.id)}]"
                marker-end="url(#service-map-arrow)">
                <title>{{ nodeInfo(edge.from) }} → {{ nodeInfo(edge.to) }}</title>
              </path>
            </g>
            <g v-for="(node,id) in positions" :key="id" class="service-map-graphic-node"
              :class="[node.column,{'is-selected':id===selected,'is-muted':selected && !highlighted.has(id)}]"
              role="button" tabindex="0" :aria-pressed="id===selected" :aria-label="`${nodeInfo(id)}. Выделить связи`"
              @click="choose(id)" @keydown.enter.prevent="choose(id)" @keydown.space.prevent="choose(id)">
              <rect :x="node.x" :y="node.y" :width="node.w" :height="node.h" rx="8"/>
              <rect class="service-map-node-accent" :x="node.x+11" :y="node.y+14" width="5" height="18" rx="2"/>
              <text :x="node.x+25" :y="node.y+28">{{ nodeInfo(id) }}</text>
              <title>{{ nodeInfo(id) }}</title>
            </g>
          </svg>
        </div>
      </section>
    </UiPanel>
    <UiPanel>
      <section class="service-map-details">
        <h2>{{ selected ? nodeInfo(selected) : 'Как читать схему' }}</h2>
        <p v-if="!selected">Все основные связи отображаются сразу. Нажмите на карточку сервиса, чтобы оставить только его зависимости и направления взаимодействия.</p>
        <div v-else class="service-map-details-list">
          <p v-if="!incident.length">Прямые связи на схеме не заданы.</p>
          <div v-for="edge in incident" :key="edge.id">{{ nodeInfo(edge.from) }} <span>→</span> {{ nodeInfo(edge.to) }}</div>
        </div>
      </section>
    </UiPanel>
    <p class="service-map-caption">Схема показывает согласованные логические зависимости, а не текущие сетевые соединения или мониторинг доступности. Общий PostgreSQL не означает общую схему данных: бизнес-сервисы владеют своими данными.</p>
  </main>
</template>

<script setup>
import { computed, ref } from 'vue';
import { contours, domainContours, nodes, links, serviceDescriptions } from './serviceMapData.js';
import './serviceMap.css';

// Curated, deterministic positions: the diagram never needs network discovery
// or a heavyweight graph layout engine. SVG remains readable on narrow screens.
const columns = [
  {id:'ui', label:'FRONTEND', hint:'Веб-приложения', x:148, width:184},
  {id:'business', label:'BACKEND', hint:'API и межсервисные связи', x:420, width:184},
  {id:'infra', label:'ДАННЫЕ И ИНФРАСТРУКТУРА', hint:'Хранилища и платформенные компоненты', x:690, width:184},
];
// Align the frontend and backend by domain rather than by independent row indexes.
// A domain with different frontend/backend counts still gets a single coherent band.
const rowHeight = 50;
const top = 82;
const nodeHeight = 29;
const positions = {};
let domainRow = 0;
for (const domain of domainContours) {
  const front = domain.items.filter(id => nodes[id]?.[1]);
  const back = domain.items.filter(id => !nodes[id]?.[1]);
  for (const [column, items] of [['ui', front], ['business', back]]) {
    const lane = columns.find(c => c.id === column);
    items.forEach((id,index) => {
      positions[id] = {id,x:lane.x,y:top+(domainRow+index)*rowHeight,w:lane.width,h:nodeHeight,column};
    });
  }
  domainRow += Math.max(front.length,back.length);
}
const infra = [...contours.find(c=>c.id==='platform').items.filter(id=>id!=='platform-core'),...contours.find(c=>c.id==='data').items];
const infraLane = columns.find(c=>c.id==='infra');
infra.forEach((id,index) => {
  positions[id] = {id,x:infraLane.x,y:top+index*rowHeight,w:infraLane.width,h:nodeHeight,column:'infra'};
});
const height = top + Math.max(domainRow,infra.length)*rowHeight+14;
const overlays = domainContours.map(c=>{
 const points=c.items.map(id=>positions[id]);
 const y=Math.min(...points.map(n=>n.y))-9;
 const bottom=Math.max(...points.map(n=>n.y+n.h))+5;
 // One horizontal outline spanning both frontend and backend lanes.
 return {...c,x:16,y,width:columns[1].x+columns[1].width-16+10,
   height:bottom-y,labelX:28,labelY:y+16};
});

const selected = ref(null);
const activeNode = computed(() => selected.value ? positions[selected.value] : null);
const activeContour = computed(() => domainContours.find(c=>c.items.includes(selected.value))?.label || (activeNode.value?.column === 'infra' ? 'Общая инфраструктура' : '—'));
const kindName = computed(() => ({ui:'Frontend',business:'Backend / API',infra:'Инфраструктура'}[activeNode.value?.column] || '—'));
const summary = computed(() => serviceDescriptions[selected.value] || 'Компонент платформы IRLIX. На схеме показаны его подтверждённые логические связи.');
const openPage = computed(() => {
  const path = nodes[selected.value]?.[1];
  return typeof path === 'string' && path.startsWith('/') ? path : null;
});
const outgoing = computed(() => connections.value.filter(e=>e.from===selected.value));
const incoming = computed(() => connections.value.filter(e=>e.to===selected.value));
const dataLinks = computed(() => outgoing.value.filter(e=>positions[e.to]?.column==='infra'));
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
    <div class="service-map-workspace">
      <section class="service-map-canvas" aria-label="Архитектурная карта сервисов">
        <div class="service-map-toolbar">
          <span><i class="service-map-key frontend"></i> Frontend → API</span>
          <span><i class="service-map-key integration"></i> API → API</span>
          <span><i class="service-map-key storage"></i> Backend → данные/инфраструктура</span>
          <button class="service-map-clear" :class="{'is-hidden':!selected}" :disabled="!selected" type="button" @click="selected=null">Показать все связи</button>
        </div>
        <div class="service-map-scroll">
          <svg class="service-map-diagram" :viewBox="`0 0 900 ${height}`" role="img"
            aria-label="Схема взаимодействия frontend, backend, платформенных компонентов и хранилищ">
            <defs>
              <marker id="service-map-arrow" markerWidth="6" markerHeight="6" refX="5.5" refY="3" orient="auto" markerUnits="userSpaceOnUse">
                <path d="M0 0 L6 3 L0 6 Z" fill="context-stroke"/>
              </marker>
            </defs>
            <g v-for="col in columns" :key="col.id">
              <rect class="service-map-lane" :class="`lane-${col.id}`" :x="col.x-9" y="8" :width="col.width+18" :height="height-16" rx="13"/>
              <text class="service-map-column-title" :x="col.x+10" y="33">{{ col.label }}</text>
              <text class="service-map-column-hint" :x="col.x+10" y="53">{{ col.hint }}</text>
            </g>
            <g class="service-map-contours" aria-hidden="true">
              <g v-for="contour in overlays" :key="contour.id" :class="`contour-${contour.lane}`">
                <rect :x="contour.x" :y="contour.y" :width="contour.width" :height="contour.height" rx="9" />
                <text class="service-map-contour-label" :x="contour.labelX" :y="contour.labelY">
                  <tspan v-for="(line,index) in (contour.label === 'Клиентский контур' ? ['Клиентский','контур'] : [contour.label])"
                    :key="line" :x="contour.labelX" :dy="index === 0 ? 0 : 12">{{ line }}</tspan>
                </text>
              </g>
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
              <rect :x="node.x" :y="node.y" :width="node.w" :height="node.h" rx="6"/>
              <rect class="service-map-node-accent" :x="node.x+11" :y="node.y+9" width="3" height="11" rx="1.5"/>
              <text :x="node.x+22" :y="node.y+18.5">{{ nodeInfo(id) }}</text>
              <title>{{ nodeInfo(id) }}</title>
            </g>
          </svg>
        </div>
      </section>
      <aside class="service-map-inspector" aria-label="Детали выбранного сервиса">
        <template v-if="selected">
          <div class="service-map-inspector-heading">
            <div><span class="service-map-eyebrow">Компонент платформы</span><h2>{{ nodeInfo(selected) }}</h2></div>
            <button class="service-map-inspector-close" type="button" aria-label="Снять выделение" @click="selected=null">×</button>
          </div>
          <p class="service-map-inspector-summary">{{ summary }}</p>
          <div class="service-map-inspector-meta"><span>Тип</span><strong>{{ kindName }}</strong><span>Контур</span><strong>{{ activeContour }}</strong></div>
          <a v-if="openPage" class="service-map-inspector-open" :href="openPage">Открыть интерфейс ↗</a>
          <section class="service-map-inspector-section">
            <h3>Исходящие зависимости <small>{{ outgoing.length }}</small></h3>
            <div v-if="outgoing.length" class="service-map-relations">
              <button v-for="edge in outgoing" :key="edge.id" type="button" @click="selected=edge.to"><span>{{ nodeInfo(edge.to) }}</span><span aria-hidden="true">↗</span></button>
            </div><p v-else class="service-map-muted">Не указаны на схеме.</p>
          </section>
          <section class="service-map-inspector-section">
            <h3>Входящие связи <small>{{ incoming.length }}</small></h3>
            <div v-if="incoming.length" class="service-map-relations">
              <button v-for="edge in incoming" :key="edge.id" type="button" @click="selected=edge.from"><span>{{ nodeInfo(edge.from) }}</span><span aria-hidden="true">↗</span></button>
            </div><p v-else class="service-map-muted">Не указаны на схеме.</p>
          </section>
          <section v-if="dataLinks.length" class="service-map-inspector-section">
            <h3>Хранение и инфраструктура</h3>
            <p class="service-map-muted">{{ dataLinks.map(edge=>nodeInfo(edge.to)).join(', ') }}</p>
          </section>
        </template>
        <template v-else>
          <span class="service-map-eyebrow">Архитектура IRLIX</span>
          <h2>Детали сервиса</h2>
          <p class="service-map-inspector-summary">Выберите карточку на схеме, чтобы увидеть назначение компонента, его контур, зависимости и доступные переходы.</p>
          <p class="service-map-muted">Стрелки отражают логические зависимости, а не активные сетевые соединения.</p>
        </template>
      </aside>
    </div>
  </main>
</template>

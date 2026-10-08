<script setup>
import { computed } from 'vue';
const props = defineProps({ points: {type:Array,default:()=>[]}, field:String, peakField:String, format:Function, label:String, unit:String });
const valid = computed(() => props.points.filter(p => p[props.field] !== null));
const peak = computed(() => Math.max(...valid.value.map(p => Number(p[props.peakField] ?? p[props.field])), 0));
const max = computed(() => Math.max(peak.value, 1));
const start = computed(() => Number(props.points[0]?.ts || 0));
const span = computed(() => Math.max(1, Number(props.points.at(-1)?.ts || 0) - start.value));
const x = p => 92 + (Number(p.ts) - start.value) / span.value * 692;
const y = v => 155 - Number(v) / max.value * 135;
// Break lines across collection gaps rather than suggesting continuous measurements.
const segments = computed(() => {
  const result = []; let previous = null;
  const step = props.points.length > 1 ? Math.min(...props.points.slice(1).map((p,i) => Number(p.ts)-Number(props.points[i].ts)).filter(n=>n>0)) : 60;
  for (const p of props.points) {
    if (p[props.field] === null) { previous = null; continue; }
    if (!previous || Number(p.ts)-Number(previous.ts)>step*2) result.push([]);
    result.at(-1).push(`${x(p)},${y(p[props.field])}`); previous = p;
  }
  return result.map(s=>s.join(' '));
});
const date = ts => new Date(Number(ts)*1000).toLocaleString('ru-RU',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
</script>
<template>
  <figure class="resource-chart">
    <figcaption>{{ label }} <span>Пик: {{ valid.length ? format(peak) : '—' }} {{ unit || '' }}</span></figcaption>
    <p v-if="!valid.length" class="resource-muted">Данных пока нет. История появится после сбора первых измерений.</p>
    <svg v-else viewBox="0 0 800 190" role="img" :aria-label="`${label}: ${valid.length} измерений; пик ${format(peak)} ${unit || ''}`">
      <g v-for="tick in [0,0.5,1]" :key="tick">
        <line x1="92" x2="784" :y1="y(max*tick)" :y2="y(max*tick)" class="resource-grid" />
        <text x="85" :y="y(max*tick)+4" text-anchor="end">{{ format(max*tick) }}</text>
      </g>
      <polyline v-for="(segment,index) in segments" :key="index" :points="segment" class="resource-line" />
      <circle v-if="valid.length===1" :cx="x(valid[0])" :cy="y(valid[0][field])" r="3" class="resource-dot" />
      <text x="92" y="180">{{ date(start) }}</text><text x="784" y="180" text-anchor="end">{{ date(points.at(-1).ts) }}</text>
    </svg>
  </figure>
</template>

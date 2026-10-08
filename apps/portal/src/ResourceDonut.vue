<script setup>
import { computed, ref } from 'vue';
const props=defineProps({allocation:{type:Object,required:true},title:String,format:Function,unit:String,label:Function,active:String});
const emit=defineEmits(['activate']);
const hover=ref(null);
const selected=computed(()=>props.allocation.segments.find(s=>s.id===props.active) || props.allocation.values?.find(s=>s.id===props.active));
const percentage=value=>value != null && props.allocation.total ? `${(100*value/props.allocation.total).toFixed(1)}%` : '—';
const arcs=computed(()=>{
  let angle=-Math.PI/2;
  const point=(a,r)=>`${100+r*Math.cos(a)},${100+r*Math.sin(a)}`;
  return props.allocation.segments.map(segment=>{
    const start=angle; angle+=segment.size/props.allocation.total*Math.PI*2;
    // Two arcs also handle a whole circle (a single SVG arc cannot draw 360 degrees).
    const middle=(start+angle)/2;
    const path=`M${point(start,82)} A82,82 0 0,1 ${point(middle,82)} A82,82 0 0,1 ${point(angle,82)} L${point(angle,60)} A60,60 0 0,0 ${point(middle,60)} A60,60 0 0,0 ${point(start,60)} Z`;
    return {...segment,path};
  });
});
const description=s=>`${props.label(s.id)}: ${props.format(s.value)} из ${props.format(props.allocation.total)} (${percentage(s.value)})${s.partial?' · неполные данные':''}`;
function activate(id){hover.value=id;emit('activate',id);}
function clear(){hover.value=null;emit('activate',null);}
</script>
<template>
  <div class="resource-donut-wrap">
    <svg class="resource-donut" viewBox="0 0 200 200" role="group" :aria-label="`${title}: ${format(allocation.used)} из ${format(allocation.total)}`" @mouseleave="clear">
      <circle v-if="allocation.unavailable" cx="100" cy="100" r="71" fill="none" stroke="var(--irlix-color-border)" stroke-width="22" />
      <path v-for="arc in arcs" :key="arc.id" :d="arc.path" :fill="arc.color" :opacity="active && active!==arc.id ? .25 : 1" class="resource-donut-segment" role="button" tabindex="0" :aria-label="description(arc)" @mouseenter="activate(arc.id)" @focus="activate(arc.id)" @blur="clear" @click="activate(arc.id)" @keydown.enter.prevent="activate(arc.id)" @keydown.space.prevent="activate(arc.id)" @keydown.esc="clear" />
      <text x="100" y="94" text-anchor="middle" class="resource-donut-number">{{ allocation.unavailable ? '—' : format(selected ? selected.value : allocation.used) }}</text>
      <text x="100" y="116" text-anchor="middle" class="resource-donut-total">из {{ format(allocation.total) }}</text>
      <text x="100" y="135" text-anchor="middle" class="resource-donut-caption">{{ allocation.unavailable ? 'Ожидаем замер' : selected ? percentage(selected.value) : 'занято' }}</text>
    </svg>
    <div v-if="hover && selected" role="tooltip" class="resource-donut-tooltip"><strong>{{ label(selected.id) }}</strong><span>{{ format(selected.value) }} из {{ format(allocation.total) }} · {{ percentage(selected.value) }}</span><small v-if="selected.partial">Неполные данные</small><small v-if="allocation.normalized">Размер сектора нормирован по занятости VM</small></div>
  </div>
</template>

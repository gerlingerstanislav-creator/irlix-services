<script setup>
import { nextTick, onBeforeUnmount, ref, useId } from 'vue';
const id = useId(), trigger = ref(null), bubble = ref(null), open = ref(false), style = ref({});
let timer;
async function show() {
  clearTimeout(timer);
  open.value = true;
  await nextTick();
  if (!trigger.value || !bubble.value) return;
  const anchor = trigger.value.getBoundingClientRect(), box = bubble.value.getBoundingClientRect();
  style.value = { left:`${Math.max(8, Math.min(anchor.left, window.innerWidth-box.width-8))}px`, top:`${Math.max(8, anchor.bottom+box.height+8<=window.innerHeight ? anchor.bottom+6 : anchor.top-box.height-6)}px` };
}
function hide() { clearTimeout(timer); open.value = false; }
function keepOpen() { clearTimeout(timer); }
function hideSoon() { timer = setTimeout(hide, 120); }
function onScroll(event) { if (!bubble.value?.contains(event.target)) hide(); }
window.addEventListener('scroll', onScroll, true);
window.addEventListener('resize', hide);
onBeforeUnmount(() => { clearTimeout(timer); window.removeEventListener('scroll', onScroll, true); window.removeEventListener('resize', hide); });
</script>
<template>
  <span ref="trigger" class="ui-tooltip-trigger" tabindex="0" :aria-describedby="open ? id : undefined" @mouseenter="show" @mouseleave="hideSoon" @focus="show" @blur="hide" @keydown.esc.stop="hide"><slot /></span>
  <Teleport to="body"><div v-if="open" :id="id" ref="bubble" class="ui-tooltip" role="tooltip" :style="style" @mouseenter="keepOpen" @mouseleave="hideSoon"><slot name="content" /></div></Teleport>
</template>
<style>
.ui-tooltip-trigger { display:inline-block; }
.ui-tooltip-trigger:focus-visible { outline:2px solid var(--irlix-color-primary); outline-offset:2px; }
.ui-tooltip { position:fixed; z-index:1000; max-width:calc(100vw - 16px); max-height:calc(100vh - 16px); overflow:auto; padding:8px 10px; border:1px solid var(--irlix-color-border); border-radius:var(--irlix-radius-sm); background:var(--irlix-color-surface); color:var(--irlix-color-text); box-shadow:0 4px 16px color-mix(in srgb,var(--irlix-color-shadow-base) 15%,transparent); font:var(--irlix-font-size-caption)/1.5 var(--irlix-font-sans); }
</style>

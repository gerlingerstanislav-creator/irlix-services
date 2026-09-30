<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
const props=defineProps({actions:{type:Array,default:()=>[]},label:String,busy:Boolean});const emit=defineEmits(['action']);const open=ref(false),root=ref(null);
function dismiss(event){if(!root.value?.contains(event.target))open.value=false;}
function keydown(event){if(event.key==='Escape')open.value=false;}
function choose(action,event){open.value=false;emit('action',action,event);}
onMounted(()=>{document.addEventListener('click',dismiss);document.addEventListener('keydown',keydown)});onBeforeUnmount(()=>{document.removeEventListener('click',dismiss);document.removeEventListener('keydown',keydown)});
</script>
<template><div ref="root" class="entity-actions"><button v-if="actions.length" type="button" class="entity-actions-toggle" :aria-label="label" :aria-expanded="open" :disabled="busy" @click="open=!open">⋮</button><div v-if="open" class="entity-actions-menu" role="menu"><button v-for="action in actions" :key="action.key" type="button" role="menuitem" :class="{danger:action.danger}" :disabled="busy" @click.stop="choose(action,$event)">{{action.label}}</button></div></div></template>
<style scoped>
.entity-actions{position:relative;min-width:28px}.entity-actions-toggle{display:grid;place-items:center;width:28px;height:30px;padding:0;border:0;border-radius:6px;background:transparent;color:#64707c;font-size:24px;cursor:pointer}.entity-actions-toggle:hover{background:#edf4f1}.entity-actions-menu{position:absolute;right:0;top:calc(100% + 4px);z-index:20;min-width:220px;padding:5px;border:1px solid #e0e5e9;border-radius:10px;background:#fff;box-shadow:0 8px 28px rgba(20,30,40,.15)}.entity-actions-menu button{display:block;width:100%;padding:9px 10px;border:0;border-radius:6px;background:transparent;color:#303943;font:inherit;font-size:13px;text-align:left;cursor:pointer}.entity-actions-menu button:hover{background:#eff7f4}.entity-actions-menu button.danger{color:#cc4754}
</style>

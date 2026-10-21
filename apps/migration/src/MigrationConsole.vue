<script setup>
// Public entry point shared by the standalone app and System's embedded route.
import {onBeforeUnmount,ref} from 'vue';
import {UiTabs} from '@irlix/ui';
import MigrationConsoleLegacy from './MigrationConsoleLegacy.vue';
import MigrationControl from './MigrationControl.vue';
import MigrationWorkspace from './MigrationWorkspace.vue';
defineProps({auth:{type:Object,required:true},embedded:{type:Boolean,default:false}});
const viewFromUrl=()=>new URLSearchParams(location.search).get('view')==='control'?'control':'console';
const view=ref(viewFromUrl());
function select(value){
  if(value===view.value)return;
  view.value=value;
  const url=new URL(location.href);
  value==='control'?url.searchParams.set('view','control'):url.searchParams.delete('view');
  history.pushState({},'',url);
}
function syncView(){view.value=viewFromUrl();}
window.addEventListener('popstate',syncView);
onBeforeUnmount(()=>window.removeEventListener('popstate',syncView));
</script>
<template>
 <div v-if="embedded" class="migration-embedded">
  <UiTabs :model-value="view" :items="[{value:'console',label:'Пульт переноса'},{value:'control',label:'Контроль переноса'}]" @update:model-value="select"/>
  <MigrationConsoleLegacy v-if="view==='console'" :auth="auth" embedded/>
  <MigrationControl v-else :auth="auth" @settings="select('console')"/>
 </div>
 <MigrationWorkspace v-else :auth="auth"/>
</template>
<style>
.migration-embedded{height:calc(100dvh - var(--irlix-topbar-height,45px));display:flex;flex-direction:column;min-width:0;min-height:0}
.migration-embedded>.irlix-tabs{flex:none;padding-inline:16px}
.migration-embedded>.mc-root{flex:1;min-height:0}
.migration-embedded>.mc-root .mc-layout{height:100%}
</style>

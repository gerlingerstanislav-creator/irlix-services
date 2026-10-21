<script setup>
import {onBeforeUnmount,ref} from 'vue';
import {UiAppShell,UiTabs} from '@irlix/ui';
import MigrationConsole from './MigrationConsoleLegacy.vue';
import MigrationControl from './MigrationControl.vue';
import {migrationNavigation} from './navigation.js';
defineProps({auth:{type:Object,required:true}});
const view=ref(new URLSearchParams(location.search).get('view')==='control'?'control':'console');
function select(value) {view.value=value;const url=new URL(location.href);value==='control'?url.searchParams.set('view','control'):url.searchParams.delete('view');history.pushState({},'',url);}
function syncView(){view.value=new URLSearchParams(location.search).get('view')==='control'?'control':'console';}
window.addEventListener('popstate',syncView);onBeforeUnmount(()=>window.removeEventListener('popstate',syncView));
</script>
<template><UiAppShell service="migration" :auth="auth" :current-user="auth.user" :platform-admin="true" :platform-access="()=>auth.fetch('/api/employees/access/me',{headers:{Accept:'application/json'}})" :items="migrationNavigation" :section="view" :breadcrumbs="[{label:view==='control'?'Контроль переноса':'Пульт переноса'}]" @update:section="select" @logout="auth.logout()"><div class="migration-workspace"><UiTabs :model-value="view" :items="[{value:'console',label:'Пульт переноса'},{value:'control',label:'Контроль переноса'}]" @update:model-value="select"/><MigrationConsole v-if="view==='console'" :auth="auth" embedded/><MigrationControl v-else :auth="auth" @settings="select('console')"/></div></UiAppShell></template>
<style>.migration-workspace{height:calc(100dvh - 45px);display:flex;flex-direction:column;min-height:0}.migration-workspace>.irlix-tabs{flex:none;padding-inline:16px}.migration-workspace>.mc-root{flex:1;min-height:0}.migration-workspace .mc-layout{height:100%}</style>

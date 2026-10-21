<script setup>
import {onBeforeUnmount,ref} from 'vue';
import {UiAppShell} from '@irlix/ui';
import MigrationConsole from './MigrationConsoleLegacy.vue';
import MigrationControl from './MigrationControl.vue';
import {migrationNavigation} from './navigation.js';

defineProps({auth:{type:Object,required:true}});
const viewFromPath=()=>location.pathname.startsWith('/migration/control')?'control':'console';
const view=ref(viewFromPath());

function select(value){
  const item=migrationNavigation.find(entry=>entry.id===value);
  if(!item)return;
  view.value=value;
  if(location.pathname!==item.href)history.pushState({},'',item.href+location.search+location.hash);
}
function syncView(){view.value=viewFromPath();}
window.addEventListener('popstate',syncView);
onBeforeUnmount(()=>window.removeEventListener('popstate',syncView));
</script>

<template>
  <UiAppShell
    service="migration"
    :auth="auth"
    :current-user="auth.user"
    :platform-admin="true"
    :platform-access="()=>auth.fetch('/api/employees/access/me',{headers:{Accept:'application/json'}})"
    :items="migrationNavigation"
    :section="view"
    :breadcrumbs="[{label:view==='control'?'Контроль переноса':'Пульт переноса'}]"
    @update:section="select"
    @logout="auth.logout()"
  >
    <div class="migration-workspace">
      <MigrationConsole v-if="view==='console'" :auth="auth" embedded/>
      <MigrationControl v-else :auth="auth" @settings="select('console')"/>
    </div>
  </UiAppShell>
</template>

<style>
.migration-workspace{height:calc(100dvh - 45px);display:flex;flex-direction:column;min-height:0}
.migration-workspace>.mc-root{flex:1;min-height:0}
.migration-workspace .mc-layout{height:100%}
</style>

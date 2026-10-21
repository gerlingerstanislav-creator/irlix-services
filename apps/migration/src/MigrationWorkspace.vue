<script setup>
import {UiAppShell} from '@irlix/ui';
import MigrationControl from './MigrationControl.vue';
import {migrationNavigation} from './navigation.js';
defineProps({auth:{type:Object,required:true}});
</script>
<template>
  <UiAppShell service="migration" :auth="auth" :current-user="auth.user"
    :platform-admin="true" :platform-access="()=>auth.fetch('/api/employees/access/me',{headers:{Accept:'application/json'}})"
    :items="migrationNavigation" section="control"
    :breadcrumbs="[{label:'Контроль переноса'}]" @logout="auth.logout()">
    <div class="migration-workspace"><MigrationControl :auth="auth"/></div>
  </UiAppShell>
</template>
<style>
.migration-workspace{height:calc(100dvh - 45px);display:flex;flex-direction:column;min-height:0}
</style>

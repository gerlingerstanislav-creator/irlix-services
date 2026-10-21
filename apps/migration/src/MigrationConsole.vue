<script setup>
import MigrationControl from './MigrationControl.vue';
defineProps({auth:{type:Object,required:true},embedded:{type:Boolean,default:false}});
</script>
<template>
  <div v-if="embedded" class="migration-embedded">
    <MigrationControl :auth="auth"/>
  </div>
</template>
<style>
.migration-embedded{height:calc(100dvh - var(--irlix-topbar-height,45px));display:flex;flex-direction:column;min-width:0;min-height:0}
</style>

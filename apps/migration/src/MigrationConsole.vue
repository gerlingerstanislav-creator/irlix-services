<script setup>
import MigrationConsoleLegacy from './MigrationConsoleLegacy.vue';
import MigrationControl from './MigrationControl.vue';

defineProps({
  auth:{type:Object,required:true},
  embedded:{type:Boolean,default:false},
  view:{type:String,default:'console'},
});
const emit=defineEmits(['navigate']);
</script>

<template>
  <div v-if="embedded" class="migration-embedded">
    <MigrationConsoleLegacy v-if="view==='console'" :auth="auth" embedded/>
    <MigrationControl v-else :auth="auth" @settings="emit('navigate','console')"/>
  </div>
</template>

<style>
.migration-embedded{height:calc(100dvh - var(--irlix-topbar-height,45px));display:flex;flex-direction:column;min-width:0;min-height:0}
.migration-embedded>.mc-root{flex:1;min-height:0}
.migration-embedded>.mc-root .mc-layout{height:100%}
</style>

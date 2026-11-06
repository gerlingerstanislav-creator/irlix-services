<script setup>
defineProps({
  name: { type: String, required: true },
  grade: { type: String, default: '' },
});
</script>
<template>
  <span class="irlix-technology-grade">{{ name }}<sup v-if="grade" class="irlix-technology-grade__grade">{{ grade }}</sup></span>
</template>
<style scoped>
.irlix-technology-grade{display:inline;white-space:normal;color:var(--irlix-color-text);font-weight:400;font-size:var(--irlix-font-size-table)}
.irlix-technology-grade__grade{margin-left:4px;vertical-align:super;font-size:var(--irlix-font-size-caption);font-weight:600;line-height:1;color:var(--irlix-status-success-text, #16803b)}
</style>

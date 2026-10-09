<script setup>
import { computed } from 'vue';
import { attemptStageColors } from '../workflow.js';
const props = defineProps({ attempt: { type: Object, required: true }, large: Boolean });
const colors = computed(() => attemptStageColors(props.attempt));
</script>

<template>
  <span class="attempt-progress" :class="{ 'attempt-progress--large': large }" role="img" :aria-label="attempt.status" :title="attempt.status" tabindex="0">
    <i v-for="(color, index) in colors" :key="index" class="attempt-progress__stage" :class="'attempt-progress__stage--' + color" />
  </span>
</template>

<style scoped>
.attempt-progress{display:inline-flex;align-items:center;flex:none;position:relative;gap:13px;padding:3px 0;vertical-align:middle;outline-offset:4px}
.attempt-progress::before{content:"";position:absolute;left:6px;right:6px;top:50%;border-top:1px dashed var(--irlix-color-border)}
.attempt-progress__stage{position:relative;z-index:1;width:12px;height:12px;box-sizing:border-box;border-radius:50%;border:1px solid var(--irlix-color-border);background:var(--irlix-color-surface)}
.attempt-progress__stage--pending{background:var(--irlix-status-warning-bg);border-color:var(--irlix-status-warning-border)}
.attempt-progress__stage--done{background:var(--irlix-color-primary);border-color:var(--irlix-status-success-border)}
.attempt-progress__stage--failed{background:var(--irlix-color-danger);border-color:var(--irlix-status-danger-border)}
.attempt-progress--large{gap:26px;padding:6px 0}
.attempt-progress--large .attempt-progress__stage{width:18px;height:18px}
</style>

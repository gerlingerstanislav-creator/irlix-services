<script setup>
defineProps({
  tone: { type: String, default: 'info' },
});
</script>

<template>
  <span class="ui-badge" :class="`ui-badge--${tone}`"><slot /></span>
</template>

<style scoped>
.ui-badge {
  display:inline-flex;
  align-items:center;
  min-height:22px;
  padding:2px 7px;
  border-radius:var(--irlix-radius-sm);
  font-size:var(--irlix-font-size-caption);
  font-weight:600;
  white-space:nowrap;
}
.ui-badge--info { background:var(--irlix-color-surface-muted); color:var(--irlix-status-info-text); }
.ui-badge--meta { background:var(--irlix-color-surface-muted); color:var(--irlix-color-text-muted); font-weight:400; }
.ui-badge--neutral { background:var(--irlix-color-surface-muted); color:var(--irlix-color-text); }
.ui-badge--success { background:var(--irlix-color-surface-muted); color:var(--irlix-color-success); }
.ui-badge--warning { background:var(--irlix-color-surface-muted); color:var(--irlix-status-danger-text); }
.ui-badge--danger { background:var(--irlix-color-surface-muted); color:var(--irlix-status-danger-text); }
</style>

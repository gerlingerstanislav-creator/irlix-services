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
  display: inline-flex;
  align-items: center;
  min-height: 22px;
  padding: 2px 7px;
  border-radius: var(--irlix-radius-sm);
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}
.ui-badge--info { background: #eef4ff; color: #2865d3; }
.ui-badge--neutral { background: #f0f1f3; color: #555d69; }
.ui-badge--success { background: #e7f6ee; color: var(--irlix-color-success); }
.ui-badge--warning { background: #fff7e7; color: #a96500; }
.ui-badge--danger { background: #fff0f0; color: #d33a3a; }
</style>

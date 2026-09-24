<template>
  <section class="ui-panel"><slot /></section>
</template>

<style scoped>
.ui-panel {
  background: var(--irlix-color-surface);
  border: 1px solid var(--irlix-color-border);
  border-radius: var(--irlix-radius-md);
  box-shadow: var(--irlix-shadow-sm);
  overflow: hidden;
}
</style>

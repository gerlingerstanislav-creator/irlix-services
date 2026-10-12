<script setup>
defineProps({
  label: { type: String, default: 'Подтверждено' },
});
</script>

<template>
  <span class="irlix-status-check" role="img" :aria-label="label" :title="label" aria-hidden="false">✓</span>
</template>

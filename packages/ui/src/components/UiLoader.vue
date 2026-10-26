<script setup>
import UiPreloader from './UiPreloader.vue';
defineProps({
  label: { type: String, default: 'Загрузка…' },
  overlay: { type: Boolean, default: false },
  page: { type: Boolean, default: false },
  showLabel: { type: Boolean, default: false },
  size: { type: String, default: 'normal', validator: value => ['small', 'normal', 'large'].includes(value) },
  variant: { type: String, default: 'trace', validator: value => ['assembly', 'trace', 'glitch'].includes(value) },
});
</script>
<template>
  <UiPreloader class="ui-loader" :class="{ 'ui-loader--overlay': overlay, 'ui-loader--small': size === 'small' }" :label="label" :overlay="overlay" :page="page"
    :show-label="showLabel" :size="size" :variant="variant" />
</template>

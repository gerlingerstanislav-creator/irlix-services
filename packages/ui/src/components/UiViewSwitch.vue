<script setup>
import UiSegmentedControl from './UiSegmentedControl.vue';
const props = defineProps({ modelValue:{type:String,default:''}, items:{type:Array,default:()=>[]} });
const emit = defineEmits(['update:modelValue']);
</script>
<template><UiSegmentedControl :model-value="props.modelValue" :items="props.items" @update:model-value="emit('update:modelValue',$event)" /></template>

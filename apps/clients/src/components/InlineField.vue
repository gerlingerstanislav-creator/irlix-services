<script setup>
import { nextTick, ref } from 'vue';
import { UiSearchSelect } from '@irlix/ui';
const props=defineProps({value:{default:''},editable:Boolean,busy:Boolean,label:String,type:{default:'text'},options:{type:Array,default:()=>[]},required:{type:Boolean,default:true},min:{default:null},max:{default:null},maxlength:{default:null}});
const emit=defineEmits(['save']);
const editing=ref(false),draft=ref(''),input=ref(null);
async function start(){draft.value=String(props.value??'');editing.value=true;await nextTick();input.value?.focus();}
function save(){emit('save',draft.value,success=>{if(success)editing.value=false;});}
</script>
<template>
 <span class="inline-field" :class="{editing}">
  <span class="inline-field-display"><slot>{{value||'—'}}</slot><button v-if="editable" type="button" class="field-pencil" :disabled="busy" :aria-label="'Редактировать '+label" @click="start">✎</button></span>
  <form v-if="editing" class="inline-field-form" @submit.prevent="save">
   <UiSearchSelect v-if="type==='select'" v-model="draft" :options="options" :clearable="!required" :aria-label="label"/>
   <input v-else ref="input" v-model="draft" class="inline-field-input" :type="type" :required="required" :min="min" :max="max" :maxlength="maxlength" :aria-label="label">
   <button type="submit" class="inline-field-confirm" :disabled="busy||(required&&!draft)" :aria-label="'Сохранить '+label">✓</button><button type="button" class="inline-field-cancel" :disabled="busy" :aria-label="'Отменить редактирование '+label" @click="editing=false">×</button>
  </form>
 </span>
</template>
<style scoped>
.inline-field{position:relative;display:block;width:100%;min-width:0;min-height:34px}.inline-field-display{display:flex;align-items:center;gap:5px;min-width:0;min-height:34px}.inline-field.editing .inline-field-display{visibility:hidden}.inline-field-form{position:absolute;z-index:4;inset:0;display:grid;grid-template-columns:minmax(0,1fr) 28px 28px;align-items:center;gap:4px;min-width:0}.inline-field-input{width:100%;height:32px;min-width:0;padding:0 10px;border:1px solid var(--irlix-control-border,var(--irlix-color-border));border-radius:var(--irlix-control-radius,8px);outline:0;background:var(--irlix-color-surface);color:var(--irlix-control-text,var(--irlix-color-text));font:inherit}.inline-field-input:hover{border-color:var(--irlix-color-border)}.inline-field-input:focus{border-color:var(--irlix-color-primary,var(--irlix-status-success-border));box-shadow:0 0 0 2px color-mix(in srgb, var(--irlix-color-shadow-base) 10%, transparent)}.inline-field-input[type=date]{color-scheme:light}.field-pencil,.inline-field-confirm,.inline-field-cancel{display:grid;place-items:center;border:0;background:transparent;font:inherit;cursor:pointer;border-radius:6px}.field-pencil{padding:3px 5px;color:var(--irlix-status-info-text)}.field-pencil:hover{background:var(--irlix-color-surface-muted)}.inline-field-confirm,.inline-field-cancel{width:28px;height:28px;font-size:var(--irlix-font-size-section-title)}.inline-field-confirm{color:var(--irlix-color-primary-text)}.inline-field-cancel{color:var(--irlix-color-text-muted)}.inline-field-confirm:hover,.inline-field-cancel:hover{background:var(--irlix-color-surface-muted)}.inline-field-form:deep(.ui-search-select__trigger){height:32px;min-height:32px}
</style>

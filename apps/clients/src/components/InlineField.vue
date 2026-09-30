<script setup>
import { nextTick, ref } from 'vue';
import { UiButton, UiSearchSelect } from '@irlix/ui';
const props=defineProps({value:{default:''},editable:Boolean,busy:Boolean,label:String,type:{default:'text'},options:{type:Array,default:()=>[]},required:{type:Boolean,default:true},min:{default:null},max:{default:null},maxlength:{default:null}});
const emit=defineEmits(['save']);
const editing=ref(false),draft=ref(''),input=ref(null);
async function start(){draft.value=String(props.value??'');editing.value=true;await nextTick();input.value?.focus();}
function save(){emit('save',draft.value,success=>{if(success)editing.value=false;});}
</script>
<template>
 <span class="inline-field">
  <form v-if="editing" class="inline-field-form" @submit.prevent="save">
   <UiSearchSelect v-if="type==='select'" v-model="draft" :options="options" :clearable="!required" :aria-label="label"/>
   <input v-else ref="input" v-model="draft" :type="type" :required="required" :min="min" :max="max" :maxlength="maxlength" :aria-label="label">
   <span class="inline-field-actions"><UiButton type="submit" compact :disabled="busy||(required&&!draft)">Сохранить</UiButton><UiButton type="button" compact variant="secondary" :disabled="busy" @click="editing=false">Отмена</UiButton></span>
  </form>
  <template v-else><slot>{{value||'—'}}</slot><button v-if="editable" type="button" class="field-pencil" :disabled="busy" :aria-label="'Редактировать '+label" @click="start">✎</button></template>
 </span>
</template>
<style scoped>
.inline-field{display:inline-flex;align-items:center;gap:6px;min-width:0;max-width:100%;flex-wrap:wrap}.inline-field-form{display:grid;gap:8px;min-width:0;width:100%}.inline-field-form input{min-width:0;width:100%;padding:7px 10px}.inline-field-actions{display:flex;gap:6px;flex-wrap:wrap}.field-pencil{border:0;background:transparent;color:#5989c7;font:inherit;cursor:pointer;padding:3px 5px;border-radius:4px}.field-pencil:hover{background:#edf4fc}
</style>

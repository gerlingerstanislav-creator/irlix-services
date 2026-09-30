<script setup>
import { nextTick, ref } from 'vue';
import { UiButton } from '@irlix/ui';
const props=defineProps({label:String,value:String,editable:Boolean,busy:Boolean,maxlength:{default:5000}});
const emit=defineEmits(['save']);const expanded=ref(true),editing=ref(false),draft=ref(''),input=ref(null);
async function start(){draft.value=props.value||'';expanded.value=true;editing.value=true;await nextTick();input.value?.focus();}
function save(){emit('save',draft.value,success=>{if(success)editing.value=false;});}
</script>
<template><fieldset class="description-block"><legend><button type="button" class="description-toggle" :aria-label="(expanded?'Скрыть ':'Показать ')+label" :aria-expanded="expanded" @click="expanded=!expanded">{{expanded?'⊖':'⊕'}}</button><span>{{label}}</span><button v-if="editable" type="button" class="description-pencil" :disabled="busy" :aria-label="'Редактировать '+label" @click="start">✎</button></legend><template v-if="expanded"><form v-if="editing" @submit.prevent="save"><textarea ref="input" v-model="draft" rows="6" :maxlength="maxlength" :aria-label="label"/><div><UiButton type="submit" compact :disabled="busy">Сохранить</UiButton><UiButton type="button" compact variant="secondary" :disabled="busy" @click="editing=false">Отмена</UiButton></div></form><p v-else>{{value||'Описание не заполнено'}}</p></template></fieldset></template>
<style scoped>
.description-block{min-width:0;margin:0;padding:12px 16px 16px;border:1px solid #d9dde1;border-radius:5px}.description-block legend{padding:0 10px;display:flex;align-items:center;gap:6px;font-size:14px;font-weight:600}.description-block p{margin:0;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.5}.description-block button.description-toggle,.description-pencil{border:0;background:transparent;padding:0 2px;font:inherit;color:#778897;cursor:pointer}.description-pencil{color:#5989c7}.description-block form{display:grid;gap:8px}.description-block textarea{width:100%;min-height:140px;padding:9px;resize:vertical}.description-block form>div{display:flex;gap:6px}
</style>

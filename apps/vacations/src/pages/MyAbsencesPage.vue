<script setup>
import { computed,onMounted,ref,watch } from 'vue';
import { UiButton,UiPanel } from '@irlix/ui';
import { api } from '../api';
import { ownActions } from '../constants';
import AbsenceCreateModal from '../components/AbsenceCreateModal.vue';
import AbsenceTable from '../components/AbsenceTable.vue';

const props=defineProps({year:{type:Number,required:true},profile:{type:Object,default:null},refreshToken:{type:Number,default:0}});
const emit=defineEmits(['action','error','changed']);
const absences=ref([]),loading=ref(false),showForm=ref(false),annualEntitlement=28;
const paidDays=computed(()=>absences.value.filter(i=>i.type==='paid_vacation'&&!['cancelled','rejected'].includes(i.status)).reduce((s,i)=>s+Number(i.entitlement_days??i.calendar_days??0),0));
const personnelBalance=computed(()=>annualEntitlement-paidDays.value);
const rows=computed(()=>absences.value.map(i=>({...i,available_actions:ownActions(i)})));
const load=async()=>{loading.value=true;try{const p=await api(`/api/vacations/absences?year=${props.year}`);absences.value=p.data||[]}catch(e){emit('error',e.message)}finally{loading.value=false}};
const created=async()=>{showForm.value=false;await load();emit('changed')};
onMounted(load);
watch(()=>props.refreshToken,load);
watch(()=>props.year,load);
</script>

<template>
  <Teleport to="#vacations-topbar-actions">
    <UiButton compact @click="showForm=true">+ Запланировать отсутствие</UiButton>
  </Teleport>

  <section class="stats">
    <div><strong>{{ annualEntitlement }}</strong><span>Базовый лимит оплачиваемого отпуска в {{ props.year }} году</span></div>
    <div><strong>{{ paidDays }}</strong><span>Запланировано и предоставлено дней</span></div>
    <div><strong :class="{negative:personnelBalance<0}">{{ personnelBalance }}</strong><span>Расчётный кадровый остаток, дней</span></div>
  </section>

  <UiPanel class="vacations-page-panel">
    <div v-if="loading" class="empty">Загрузка…</div>
    <div v-else-if="!rows.length" class="empty"><strong>На {{ props.year }} год отсутствий пока нет</strong><span>Создайте первое отсутствие.</span></div>
    <AbsenceTable v-else :items="rows" :show-documents="false" :show-actions="false" show-progress @action="emit('action',$event)"/>
  </UiPanel>

  <AbsenceCreateModal :open="showForm" @close="showForm=false" @changed="created"/>
</template>
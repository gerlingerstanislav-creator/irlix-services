<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { UiButton, UiDrawer, UiFilterBar, UiIcon, UiSearchSelect, UiTreeToggle } from '@irlix/ui';

const props = defineProps({ canManage: { type: Boolean, default: false } });
const data = ref({partners:[], unassigned:[]});
const query = ref('');
const activity = ref('all');
const expanded = reactive(new Set());
const selected = ref(null);
const editing = ref(false);
const draft = reactive({name:'',description:'',full_name:'',partner_id:'',comment:'',active:true});
const error = ref('');
const loading = ref(false);
const saving = ref(false);
async function api(url,options={}) {
 const response=await fetch(url,{...options,headers:{Accept:'application/json','Content-Type':'application/json',...(options.headers||{})}});
 const body=await response.json().catch(()=>({}));
 if(!response.ok) throw new Error(body.message||Object.values(body.errors||{}).flat().join(', ')||`HTTP ${response.status}`);
 return body.data;
}
async function load(){
 loading.value=true;error.value='';
 try{data.value=await api('/api/clients/partners');}catch(e){error.value=e.message;}finally{loading.value=false;}
}
const groups=computed(()=>{
 const all=[...data.value.partners];
 if(data.value.unassigned?.length) all.push({id:'unassigned',name:'Без партнёра',active:true,specialists:data.value.unassigned});
 const needle=query.value.trim().toLocaleLowerCase('ru');
 const statusMatches=row=>activity.value==='all'||Boolean(Number(row.active))===(activity.value==='active');
 return all.map(p=>{
   const people=(p.specialists||[]).filter(s=>statusMatches(s));
   const partnerMatches=statusMatches(p)&&(!needle||p.name.toLocaleLowerCase('ru').includes(needle));
   const matchingPeople=people.filter(s=>!needle||s.full_name.toLocaleLowerCase('ru').includes(needle));
   return {...p,specialists:partnerMatches?people:matchingPeople,visible:partnerMatches||matchingPeople.length>0};
  }).filter(p=>p.visible);
});
const title=computed(()=>!selected.value?'':selected.value.mode==='partner'?(selected.value.id?'Карточка партнёра':'Новый партнёр'):(selected.value.id?'Карточка специалиста':'Новый специалист'));
const partnerOptions=computed(()=>data.value.partners.map(p=>({id:p.id,name:p.name})));
const activityOptions=[{value:'all',label:'Все статусы'},{value:'active',label:'Активные'},{value:'inactive',label:'Неактивные'}];
function open(mode,row=null,partnerId=null){
 selected.value={mode,id:row?.id??null};editing.value=!row;error.value='';
 Object.assign(draft,{name:row?.name||'',description:row?.description||'',full_name:row?.full_name||'',
 partner_id:String(row?.partner_id??partnerId??''),comment:row?.comment||'',active:row?.active===undefined?true:Boolean(Number(row.active))});
}
async function save(){
 if(!selected.value||!props.canManage||saving.value) return;
 const isPartner=selected.value.mode==='partner';
 const base=isPartner?'/api/clients/partners':'/api/clients/partner-specialists';
 const url=selected.value.id?`${base}/${selected.value.id}`:base;
 const payload=isPartner?{name:draft.name.trim(),description:draft.description.trim()||null,active:draft.active}:
 {partner_id:Number(draft.partner_id),full_name:draft.full_name.trim(),comment:draft.comment.trim()||null,active:draft.active};
 saving.value=true;error.value='';
 try{const saved=await api(url,{method:selected.value.id?'PATCH':'POST',body:JSON.stringify(payload)});
 selected.value.id=saved.id;editing.value=false;await load(); if(isPartner)expanded.add(saved.id);
 }catch(e){error.value=e.message;}finally{saving.value=false;}
}
function cancel(){editing.value=false;if(!selected.value?.id)selected.value=null;else {const row=selected.value.mode==='partner'?data.value.partners.find(p=>p.id===selected.value.id):[...data.value.partners.flatMap(p=>p.specialists||[]),...data.value.unassigned].find(s=>s.id===selected.value.id);if(row)open(selected.value.mode,row);editing.value=false;}}
</script>

<template>
 <section class="partners-registry">
  <UiFilterBar>
   <input v-model="query" class="registry-search" type="search" placeholder="Поиск партнёра или специалиста" aria-label="Поиск партнёров">
   <UiSearchSelect v-model="activity" :options="activityOptions" placeholder="Активность" :clearable="false" aria-label="Статус партнёра или специалиста" />
   <UiButton v-if="canManage" @click="open('partner')">＋ Партнёр</UiButton>
  </UiFilterBar>
  <div v-if="error&&!selected" class="error-banner">{{error}} <button @click="load">Повторить</button></div>
  <div v-if="loading">Загрузка партнёров…</div>
  <div v-else class="partner-tree">
   <div v-for="partner in groups" :key="partner.id" class="partner-group">
    <div class="partner-parent">
      <UiTreeToggle variant="plus" :expanded="expanded.has(partner.id)" :label="expanded.has(partner.id)?'Свернуть':'Развернуть'" @click="expanded.has(partner.id)?expanded.delete(partner.id):expanded.add(partner.id)" />
      <button type="button" class="partner-name" :disabled="partner.id==='unassigned'" @click="open('partner',partner)"><strong>{{partner.name}}</strong> <small>({{partner.specialists.length}})</small></button>
      <span v-if="!Number(partner.active)" class="partner-muted">Неактивен</span>
      <UiButton v-if="canManage&&partner.id!=='unassigned'" compact @click="open('specialist',null,partner.id)">＋ Специалист</UiButton>
    </div>
    <template v-if="expanded.has(partner.id)||query.trim()">
     <div v-for="s in partner.specialists" :key="s.id" class="partner-child">
       <button class="partner-name" type="button" @click="open('specialist',s)">{{s.full_name}}</button>
       <span v-if="!Number(s.active)" class="partner-muted">Неактивен</span>
       <button type="button" class="partner-pencil" aria-label="Редактировать специалиста" @click="open('specialist',s)"><UiIcon name="pencil"/></button>
     </div>
     <div v-if="!partner.specialists.length" class="partner-child partner-muted">Специалистов пока нет</div>
    </template>
   </div>
   <p v-if="!groups.length" class="partner-muted">Ничего не найдено</p>
  </div>
  <UiDrawer :open="!!selected" :title="title" width="520px" @close="selected=null">
   <div v-if="selected" class="partner-card">
    <p v-if="error" class="error-banner">{{error}}</p>
    <div v-if="selected.mode==='partner'" class="partner-fields">
      <label>Название партнёра <input v-model="draft.name" :disabled="!editing" maxlength="255"></label>
      <label>Описание <textarea v-model="draft.description" :disabled="!editing" rows="4"></textarea></label>
    </div>
    <div v-else class="partner-fields">
      <label>Партнёр <select v-model="draft.partner_id" :disabled="!editing"><option value="">Выберите партнёра</option><option v-for="p in partnerOptions" :key="p.id" :value="String(p.id)">{{p.name}}</option></select></label>
      <label>ФИО <input v-model="draft.full_name" :disabled="!editing" maxlength="255"></label>
      <label>Комментарий <textarea v-model="draft.comment" :disabled="!editing" rows="4"></textarea></label>
    </div>
    <label class="partner-status"><input v-model="draft.active" type="checkbox" :disabled="!editing"> Активен</label>
    <div class="partner-actions">
      <UiButton v-if="canManage&&!editing" @click="editing=true"><UiIcon name="pencil"/> Редактировать</UiButton>
      <template v-if="canManage&&editing">
        <UiButton :disabled="saving" @click="save">✓ Сохранить</UiButton>
        <UiButton variant="secondary" :disabled="saving" @click="cancel">× Отмена</UiButton>
      </template>
    </div>
   </div>
  </UiDrawer>
 </section>
</template>

<style scoped>
.partners-registry{padding:12px}.partner-tree{margin-top:12px}.partner-parent,.partner-child{display:flex;align-items:center;gap:12px;min-height:44px;padding:6px 12px;border-bottom:1px solid var(--irlix-color-border)}.partner-parent{background:var(--irlix-color-surface-muted)}.partner-child{padding-left:48px}.partner-toggle{width:24px;height:24px}.partner-name{border:0;background:none;text-align:left;cursor:pointer;color:inherit;font:inherit}.partner-name:hover{text-decoration:underline}.partner-parent .partner-name{flex:1}.partner-muted,.partner-name small{color:var(--irlix-color-text-muted);font-size:12px}.partner-pencil{border:0;background:none;cursor:pointer;color:inherit}.partner-card{padding:20px}.partner-fields{display:grid;gap:16px}.partner-fields label{display:grid;gap:5px}.partner-fields input,.partner-fields textarea,.partner-fields select{width:100%;padding:8px;border:1px solid var(--irlix-color-border);border-radius:6px;background:var(--irlix-color-surface);color:inherit}.partner-status{display:flex;align-items:center;gap:8px;margin:18px 0}.partner-actions{display:flex;gap:8px}
</style>

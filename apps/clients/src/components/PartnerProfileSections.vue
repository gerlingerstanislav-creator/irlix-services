<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { UiButton, UiDrawer, UiIcon, UiSearchSelect, UiTabs, UiLoader } from '@irlix/ui';
import HistoryTimeline from './HistoryTimeline.vue';
import ContactPersonCard from './ContactPersonCard.vue';

const props = defineProps({
 partnerId: { type: Number, required: true },
 canManage: { type: Boolean, default: false },
 canManageContacts: { type: Boolean, default: false },
});
const tab = ref('about');
const tabs = [
 {value:'about',label:'О партнёре',iconName:'building-2'},
 {value:'history',label:'История',iconName:'notebook'},
 {value:'contacts',label:'Контакты',iconName:'contact'},
 {value:'legal',label:'Юр. лица',iconName:'briefcase'},
];
const data = reactive({partner:null,history:[],contacts:[],legal_entities:[],contacts_available:false});
const loading = ref(false), saving = ref(false), error = ref('');
const contactOptions = ref([]), lookup = ref(''), existing = ref(''), contactPosition = ref('');
const contactOpen = ref(false);
const editingRelation = ref(null), relationPosition = ref(''), contactCardId = ref(null);
const legalOpen = ref(false), legalEditing = ref(''), legalOriginal = ref('');
const legalFields = [
 {key:'name',label:'Наименование',required:true},
 {key:'inn',label:'ИНН',required:true},
 {key:'full_name',label:'Полное название',required:true},
 {key:'ogrn',label:'ОГРН'}, {key:'kpp',label:'КПП'},
 {key:'registration_date',label:'Дата регистрации',type:'date'},
 {key:'okpo',label:'ОКПО'}, {key:'oktmo',label:'ОКТМО'},
 {key:'address',label:'Адрес'},
];
const legal = reactive({id:null,name:'',inn:'',full_name:'',ogrn:'',kpp:'',registration_date:'',okpo:'',oktmo:'',address:''});
const contactSelectOptions = computed(()=>contactOptions.value.map(row=>({value:String(row.id),label:row.full_name})));
async function api(url, options={}) {
 const response=await fetch(url,{...options,headers:{Accept:'application/json','Content-Type':'application/json',...(options.headers||{})}});
 const payload=await response.json().catch(()=>({}));
 if(!response.ok) throw new Error(payload.message||Object.values(payload.errors||{}).flat().join(', ')||'HTTP '+response.status);
 return payload.data;
}
async function load() {
 loading.value=true;error.value='';
 try { Object.assign(data,(await api('/api/clients/partners/'+props.partnerId+'/card'))||{}); }
 catch(e){error.value=e.message;}finally{loading.value=false;}
}
async function mutate(path,method,payload) {
 if(saving.value||!props.canManage)return false;
 saving.value=true;error.value='';
 try {
  await api(path,{method,body:JSON.stringify(payload)});
  await load();return true;
 } catch(e){error.value=e.message;return false;}
 finally{saving.value=false;}
}
async function addNote(text) {
 await mutate('/api/clients/partners/'+props.partnerId+'/history','POST',{text});
}
async function searchContacts() {
 error.value='';
 try{contactOptions.value=await api('/api/clients/partners/contact-options?q='+encodeURIComponent(lookup.value.trim()));}
 catch(e){error.value=e.message;}
}
async function linkContact(){
 const data={contact_id:Number(existing.value),position:contactPosition.value.trim()||null};
 if(!data.contact_id)return;
 if(await mutate('/api/clients/partners/'+props.partnerId+'/contacts','POST',data)){
   contactOpen.value=false;existing.value='';contactPosition.value='';lookup.value='';
 }
}
function editRelation(row){editingRelation.value=row.id;relationPosition.value=row.position||'';}
async function saveRelation(row,payload) {
 if(await mutate('/api/clients/partners/'+props.partnerId+'/contacts/'+row.id,'PATCH',payload))editingRelation.value=null;
}
function openLegal(entity=null){
 legalOpen.value=true;legalEditing.value='';error.value='';
 for(const f of legalFields)legal[f.key]=entity?.[f.key]||'';
 legal.id=entity?.id??null;
}
function cancelLegal(){legalOpen.value=false;legalEditing.value='';}
function editingField(field) {legalOriginal.value=legal[field.key];legalEditing.value=field.key;}
function cancelLegalField(){if(legalEditing.value)legal[legalEditing.value]=legalOriginal.value;legalEditing.value='';}
async function saveLegal(field=null){
 const keys=field?[field.key]:legalFields.map(f=>f.key);
 const payload=Object.fromEntries(keys.map(key=>[key,String(legal[key]||'').trim()||null]));
 if(payload.name===null||payload.inn===null||payload.full_name===null)return;
 const path='/api/clients/partners/'+props.partnerId+'/legal-entities'+(legal.id?'/'+legal.id:'');
 const okay=await mutate(path,legal.id?'PATCH':'POST',payload);
 if(okay){if(!legal.id)legalOpen.value=false;legalEditing.value='';}
}
watch(()=>props.partnerId,()=>{tab.value='about';contactOpen.value=false;contactCardId.value=null;load();},{immediate:true});
</script>

<template>
 <section class="partner-subsections">
  <UiTabs v-model="tab" :items="tabs" variant="card" class="partner-subsections__tabs"/>
  <UiLoader v-if="loading" page label="Загрузка карточки партнёра"/>
  <p v-if="error" class="partner-subsections__error" role="alert">{{error}}</p>
  <main v-if="!loading" class="partner-subsections__body">
  <section v-if="tab==='about'" class="partner-subsections__about"><slot name="about" /></section>
  <section v-else-if="tab==='history'" class="partner-subsections__history">
   <HistoryTimeline :events="data.history" :editable="canManage" :busy="saving" placeholder="Добавить сообщение в историю" @submit="addNote"/>
  </section>
  <section v-else-if="tab==='contacts'" class="partner-subsections__content">
   <div v-if="!data.contacts_available" class="partner-subsections__hint">Нет прав на просмотр контактных лиц.</div>
   <template v-else>
    <div v-if="canManage && canManageContacts" class="partner-subsections__toolbar"><strong>Контакты</strong><span><UiButton compact variant="secondary" @click="contactOpen=true;searchContacts()">Привязать контакт</UiButton> <UiButton compact @click="contactCardId=0">Добавить</UiButton></span></div>
    <table class="partner-subsections__table"><thead><tr><th>ФИО</th><th>Должность</th><th>Актуальность</th></tr></thead>
     <tbody>
      <tr v-for="row in data.contacts" :key="row.id">
       <td><button v-if="canManage && canManageContacts" type="button" class="partner-subsections__link" @click="contactCardId=row.contact_person_id">{{row.full_name}}</button><span v-else>{{row.full_name}}</span></td>
       <td>
        <template v-if="editingRelation===row.id">
         <input v-model="relationPosition" aria-label="Должность контакта">
         <button type="button" :disabled="saving" @click="saveRelation(row,{position:relationPosition})">✓</button>
         <button type="button" @click="editingRelation=null">×</button>
        </template>
        <template v-else>{{row.position||'—'}}
         <button v-if="canManage && canManageContacts" type="button" :aria-label="'Изменить должность '+row.full_name" @click="editRelation(row)"><UiIcon name="pencil" /></button>
        </template>
       </td>
       <td><button v-if="canManage && canManageContacts" type="button" :disabled="saving" @click="saveRelation(row,{active:!Boolean(Number(row.active))})">{{Number(row.active)?'Активен':'Неактивен'}}</button><span v-else>{{Number(row.active)?'Активен':'Неактивен'}}</span></td>
      </tr>
      <tr v-if="!data.contacts.length"><td colspan="3">Контактов пока нет</td></tr>
     </tbody>
    </table>
   </template>
  </section>
  <section v-else-if="tab==='legal'" class="partner-subsections__content">
   <div class="partner-subsections__toolbar"><strong>Юридические лица</strong><UiButton v-if="canManage" compact @click="openLegal()">Добавить</UiButton></div>
   <table class="partner-subsections__table"><thead><tr><th>Наименование</th><th>ИНН</th><th>Адрес</th></tr></thead>
    <tbody>
     <tr v-for="row in data.legal_entities" :key="row.id" class="partner-subsections__clickable" @click="openLegal(row)">
      <td>{{row.name}}</td><td>{{row.inn}}</td><td>{{row.address||'—'}}</td>
     </tr>
     <tr v-if="!data.legal_entities.length"><td colspan="3">Юридических лиц пока нет</td></tr>
    </tbody>
   </table>
  </section>

  </main>
  <UiDrawer :open="legalOpen" :title="legal.id?'Юридическое лицо':'Новое юридическое лицо'" width="620px" :z-index="1300" @close="cancelLegal">
   <div class="partner-subsections__legal">
    <p v-if="error" class="partner-subsections__error" role="alert">{{error}}</p>
    <article v-for="field in legalFields" :key="field.key" class="partner-subsections__field">
     <span>{{field.label}}<strong v-if="field.required"> *</strong></span>
     <div>
      <template v-if="!legal.id || legalEditing===field.key">
       <input v-model="legal[field.key]" :type="field.type||'text'" :aria-label="field.label" :disabled="saving" :required="!legal.id&&field.required" :maxlength="field.key==='address'?2000:field.key==='name'?255:field.key==='full_name'?500:32" />
       <template v-if="legal.id">
        <button type="button" :disabled="saving" @click="saveLegal(field)">✓</button>
        <button type="button" :disabled="saving" @click="cancelLegalField">×</button>
       </template>
      </template>
      <template v-else>
       <span>{{legal[field.key]||'—'}}</span>
       <button v-if="canManage" type="button" :aria-label="'Редактировать '+field.label" @click="editingField(field)"><UiIcon name="pencil" /></button>
      </template>
     </div>
    </article>
    <div v-if="!legal.id&&canManage" class="partner-subsections__actions"><UiButton :disabled="saving||!legal.name.trim()||!legal.inn.trim()||!legal.full_name.trim()" @click="saveLegal()">Добавить</UiButton><UiButton variant="secondary" @click="cancelLegal">Отмена</UiButton></div>
   </div>
  </UiDrawer>
  <UiDrawer :open="contactOpen" title="Привязать контакт" width="520px" :z-index="1300" @close="contactOpen=false">
   <div class="partner-subsections__add">
    <p v-if="error" class="partner-subsections__error" role="alert">{{error}}</p>
    <div class="partner-subsections__row"><input v-model="lookup" placeholder="Поиск контакта по ФИО" aria-label="Найти контакт" @keyup.enter="searchContacts"><UiButton compact variant="secondary" @click="searchContacts">Найти</UiButton></div>
    <UiSearchSelect v-model="existing" :options="contactSelectOptions" placeholder="Выберите контакт" search-placeholder="Контакт" />
    <input v-model="contactPosition" aria-label="Должность у партнёра" placeholder="Должность у партнёра">
    <div class="partner-subsections__actions"><UiButton compact :disabled="saving||!existing" @click="linkContact">Привязать</UiButton><UiButton compact variant="secondary" @click="contactOpen=false">Отмена</UiButton></div>
   </div>
  </UiDrawer>
  <ContactPersonCard v-if="contactCardId!==null" :open="true" :contact-id="contactCardId || null" :initial-partner-id="contactCardId===0 ? partnerId : null" :clients="[]" :z-index="1300" @close="contactCardId=null" @changed="load"/>
 </section>
</template>

<style scoped>
.partner-subsections{display:flex;flex-direction:column;flex:1;min-height:0}
.partner-subsections__tabs{margin:0 10px;width:calc(100% - 20px);flex:none;display:flex;align-items:center;gap:3px;overflow-x:auto}
.partner-subsections__tabs :deep(.irlix-tab){flex:0 0 auto;padding-inline:16px}
.partner-subsections__body{flex:1;min-height:0;overflow:auto;padding:16px 18px 28px}
.partner-subsections__about{min-height:0}
.partner-subsections__history{display:flex;flex-direction:column;gap:12px;min-height:330px;height:100%}
.partner-subsections__history :deep(.client-history){flex:1;min-height:190px}
.partner-subsections__content{padding:12px 0}
.partner-subsections__toolbar,.partner-subsections__row,.partner-subsections__switch{display:flex;align-items:center;gap:8px}
.partner-subsections__toolbar{justify-content:space-between;margin-bottom:12px}
.partner-subsections__actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
.partner-subsections__add{display:grid;gap:8px;margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--irlix-color-border)}
.partner-subsections__row>input{flex:1;min-width:0}
.partner-subsections input{min-width:0;width:100%;height:32px;padding:0 8px;color:var(--irlix-color-text);background:var(--irlix-color-surface);border:1px solid var(--irlix-color-border);border-radius:var(--irlix-control-radius,8px);font:inherit}
.partner-subsections__switch button,.partner-subsections__link,.partner-subsections__table button{padding:4px;border:0;background:transparent;color:inherit;font:inherit;cursor:pointer}
.partner-subsections__switch .active{color:var(--irlix-color-primary-text);text-decoration:underline}
.partner-subsections__table{border-collapse:collapse;width:100%;font-size:var(--irlix-font-size-table,12px)}
.partner-subsections__table th,.partner-subsections__table td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--irlix-color-border)}
.partner-subsections__table th{background:var(--irlix-color-surface-muted);font-weight:600}
.partner-subsections__table td button:disabled{opacity:.5;cursor:default}
.partner-subsections__link{text-decoration:underline!important}
.partner-subsections__clickable{cursor:pointer}
.partner-subsections__clickable:hover{background:var(--irlix-color-surface-muted)}
.partner-subsections__table :deep(svg),.partner-subsections__legal :deep(svg){width:14px;height:14px}
.partner-subsections__error{padding:9px;color:var(--irlix-status-danger-text)}
.partner-subsections__hint{color:var(--irlix-color-text-muted)}
.partner-subsections__legal{display:grid;gap:0;padding:16px}
.partner-subsections__field{display:grid;grid-template-columns:150px minmax(0,1fr);gap:10px;padding:9px 0;border-bottom:1px solid var(--irlix-color-border)}
.partner-subsections__field>span{color:var(--irlix-color-text-muted)}
.partner-subsections__field>span strong{color:var(--irlix-status-danger-text)}
.partner-subsections__field>div{display:flex;align-items:center;gap:6px}
.partner-subsections__field button{border:0;background:none;cursor:pointer;font-size:18px;padding:4px}
.partner-subsections__legal>.ui-button{margin-top:16px}
</style>

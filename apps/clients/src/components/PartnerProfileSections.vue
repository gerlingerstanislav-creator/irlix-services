<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { UiButton, UiDrawer, UiIcon, UiSearchSelect, UiTabs, UiLoader } from '@irlix/ui';
import HistoryTimeline from './HistoryTimeline.vue';
import ContactPersonCard from './ContactPersonCard.vue';

const props = defineProps({
 partnerId: { type: Number, required: true },
 canManage: { type: Boolean, default: false },
});
const tab = ref('about');
const noteText = ref('');
const tabs = [
 {value:'about',label:'О партнёре',iconName:'building-2'},
 {value:'history',label:'История',iconName:'notebook'},
 {value:'contacts',label:'Контактные лица',iconName:'contact'},
 {value:'legal',label:'Юр. лица',iconName:'briefcase'},
];
const data = reactive({partner:null,history:[],contacts:[],legal_entities:[],contacts_available:false});
const loading = ref(false), saving = ref(false), error = ref('');
const contactOptions = ref([]), lookup = ref(''), existing = ref(''), contactName = ref(''), contactPosition = ref(''), contactCreate = ref(false);
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
async function note() {
 const text=noteText.value.trim();
 if(!text)return;
 if(await mutate('/api/clients/partners/'+props.partnerId+'/history','POST',{text}))noteText.value='';
}
async function searchContacts() {
 error.value='';
 try{contactOptions.value=await api('/api/clients/partners/contact-options?q='+encodeURIComponent(lookup.value.trim()));}
 catch(e){error.value=e.message;}
}
async function linkContact(){
 const data=contactCreate.value?{full_name:contactName.value.trim(),position:contactPosition.value.trim()||null}
   :{contact_id:Number(existing.value),position:contactPosition.value.trim()||null};
 if(contactCreate.value?!data.full_name:!data.contact_id)return;
 if(await mutate('/api/clients/partners/'+props.partnerId+'/contacts','POST',data)){
   contactCreate.value=false;existing.value='';contactName.value='';contactPosition.value='';
 }
}
function editRelation(row){editingRelation.value=row.id;relationPosition.value=row.position||'';}
async function saveRelation(row,payload) {
 if(await mutate('/api/clients/partners/'+props.partnerId+'/contacts/'+row.id,'PATCH',payload))editingRelation.value=null;
}
function openLegal(entity=null){
 legalOpen.value=true;legalEditing.value='';
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
watch(()=>props.partnerId,()=>{tab.value='about';noteText.value='';load();},{immediate:true});
</script>

<template>
 <section class="partner-subsections">
  <UiTabs v-model="tab" :items="tabs" variant="card" class="partner-subsections__tabs"/>
  <UiLoader v-if="loading" page label="Загрузка карточки партнёра"/>
  <p v-if="error" class="partner-subsections__error" role="alert">{{error}}</p>
  <main v-if="!loading" class="partner-subsections__body">
  <section v-if="tab==='about'" class="partner-subsections__about"><slot name="about" /></section>
  <section v-else-if="tab==='history'" class="partner-subsections__history">
   <HistoryTimeline :events="data.history" :editable="false" :busy="saving"/>
   <form v-if="canManage" class="partner-subsections__composer" @submit.prevent="note">
    <label for="partner-history-comment">Добавить сообщение в историю</label>
    <textarea id="partner-history-comment" v-model="noteText" rows="2" maxlength="20000" placeholder="Напишите сообщение…" :disabled="saving"></textarea>
    <UiButton type="submit" compact :disabled="saving || !noteText.trim()">Добавить</UiButton>
   </form>
  </section>
  <section v-else-if="tab==='contacts'" class="partner-subsections__content">
   <div v-if="!data.contacts_available" class="partner-subsections__hint">Нет прав на просмотр контактных лиц.</div>
   <template v-else>
    <div v-if="canManage" class="partner-subsections__add">
     <div class="partner-subsections__switch">
      <button type="button" :class="{active:!contactCreate}" @click="contactCreate=false">Привязать контакт</button>
      <button type="button" :class="{active:contactCreate}" @click="contactCreate=true">Создать контакт</button>
     </div>
     <template v-if="!contactCreate">
      <div class="partner-subsections__row"><input v-model="lookup" placeholder="Поиск контакта по ФИО" aria-label="Найти контакт" @keyup.enter="searchContacts">
       <UiButton compact variant="secondary" @click="searchContacts">Найти</UiButton></div>
      <UiSearchSelect v-model="existing" :options="contactSelectOptions" placeholder="Выберите контакт" search-placeholder="Контакт" />
     </template>
     <input v-else v-model="contactName" aria-label="ФИО нового контакта" placeholder="ФИО нового контакта" maxlength="255">
     <div class="partner-subsections__row"><input v-model="contactPosition" aria-label="Должность у партнёра" placeholder="Должность">
      <UiButton compact :disabled="saving||(contactCreate?!contactName.trim():!existing)" @click="linkContact">Добавить</UiButton></div>
    </div>
    <table class="partner-subsections__table"><thead><tr><th>ФИО</th><th>Должность</th><th>Актуальность</th></tr></thead>
     <tbody>
      <tr v-for="row in data.contacts" :key="row.id">
       <td><button type="button" class="partner-subsections__link" @click="contactCardId=row.contact_person_id">{{row.full_name}}</button></td>
       <td>
        <template v-if="editingRelation===row.id">
         <input v-model="relationPosition" aria-label="Должность контакта">
         <button type="button" :disabled="saving" @click="saveRelation(row,{position:relationPosition})">✓</button>
         <button type="button" @click="editingRelation=null">×</button>
        </template>
        <template v-else>{{row.position||'—'}}
         <button v-if="canManage" type="button" :aria-label="'Изменить должность '+row.full_name" @click="editRelation(row)"><UiIcon name="pencil" /></button>
        </template>
       </td>
       <td><button type="button" :disabled="!canManage||saving" @click="saveRelation(row,{active:!Boolean(Number(row.active))})">{{Number(row.active)?'Активен':'Неактивен'}}</button></td>
      </tr>
      <tr v-if="!data.contacts.length"><td colspan="3">Контактов пока нет</td></tr>
     </tbody>
    </table>
   </template>
  </section>
  <section v-else-if="tab==='legal'" class="partner-subsections__content">
   <div class="partner-subsections__toolbar"><strong>Юридические лица</strong><UiButton v-if="canManage" compact @click="openLegal()">＋ Добавить</UiButton></div>
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
  <UiDrawer :open="legalOpen" :title="legal.id?'Юридическое лицо':'Новое юридическое лицо'" width="620px" @close="cancelLegal">
   <div class="partner-subsections__legal">
    <article v-for="field in legalFields" :key="field.key" class="partner-subsections__field">
     <span>{{field.label}}<strong v-if="field.required"> *</strong></span>
     <div>
      <template v-if="!legal.id || legalEditing===field.key">
       <input v-model="legal[field.key]" :type="field.type||'text'" :aria-label="field.label" :disabled="saving" :maxlength="field.key==='address'?2000:500" />
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
    <UiButton v-if="!legal.id&&canManage" :disabled="saving||!legal.name.trim()||!legal.inn.trim()||!legal.full_name.trim()" @click="saveLegal()">Добавить</UiButton>
   </div>
  </UiDrawer>
  <ContactPersonCard v-if="contactCardId" :open="!!contactCardId" :contact-id="Number(contactCardId)" :clients="[]" @close="contactCardId=null" @changed="load"/>
 </section>
</template>

<style scoped>
.partner-subsections{display:flex;flex-direction:column;flex:1;min-height:0}
.partner-subsections__tabs{margin:0 10px;width:calc(100% - 20px);flex:none}
.partner-subsections__tabs :deep(.irlix-tab){flex:0 0 auto;padding-inline:16px}
.partner-subsections__body{flex:1;min-height:0;overflow:auto;padding:16px 18px 28px}
.partner-subsections__about{min-height:0}
.partner-subsections__history{display:flex;flex-direction:column;gap:12px;min-height:330px;height:100%}
.partner-subsections__history :deep(.client-history){flex:1;min-height:190px}
.partner-subsections__composer{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end;flex:none;padding-top:12px;border-top:1px solid var(--irlix-color-border)}
.partner-subsections__composer label{grid-column:1/-1;font-size:var(--irlix-font-size-table);font-weight:600}
.partner-subsections__composer textarea{box-sizing:border-box;width:100%;min-height:48px;padding:8px 10px;border-radius:8px;border:1px solid var(--irlix-color-border);background:var(--irlix-color-surface);color:var(--irlix-color-text);font:inherit;resize:vertical}
.partner-subsections__content{padding:12px 0}
.partner-subsections__toolbar,.partner-subsections__row,.partner-subsections__switch{display:flex;align-items:center;gap:8px}
.partner-subsections__toolbar{justify-content:space-between;margin-bottom:12px}
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

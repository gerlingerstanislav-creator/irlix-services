<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { UiButton, UiDrawer, UiFilterBar, UiIcon, UiSearchSelect, UiRegistryTable } from '@irlix/ui';
const props=defineProps({clients:{type:Array,default:()=>[]},employees:{type:Array,default:()=>[]},canManage:{type:Boolean,default:false},initialClientId:{type:Number,default:null},focusedClientId:{type:Number,default:null},focusedMemberId:{type:Number,default:null},onlyDetails:{type:Boolean,default:false}});
const emit=defineEmits(['close','changed']);
const clientId=ref(''),rows=ref([]),partners=ref([]),search=ref(''),error=ref(''),loading=ref(false),saving=ref(false),selected=ref(null);
const createOpen=ref(false),personType=ref('employee'),personId=ref(''),personName=ref('');
const termForm=reactive({technology:'',level:'',hourly_rate:'',hours_per_day:8,valid_from:'',valid_to:''});
const termOpen=ref(false),editTermId=ref(null),assignmentOpen=ref(false),editAssignmentId=ref(null),feedbackOpen=ref(false);
const assignment=reactive({project_id:'',valid_from:'',valid_to:''});
const feedback=reactive({project_id:'',text:''});
const client=computed(()=>props.clients.find(c=>String(c.id)===clientId.value));
const clientOptions=computed(()=>props.clients.map(c=>({value:String(c.id),label:c.name})).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const projects=computed(()=>client.value?.projects||[]);
const historicalProjectOptions=computed(()=>[...new Map((selected.value?.projects||[]).map(a=>[Number(a.project_id),{value:String(a.project_id),label:a.project_name||`Проект #${a.project_id}`}])).values()]);
const personOptions=computed(()=>personType.value==='employee'?
 props.employees.map(p=>({value:String(p.id),label:p.full_name})).sort((a,b)=>a.label.localeCompare(b.label,'ru')):
 // The canonical catalog endpoint returns only active specialists under active
 // partners; it intentionally omits the `active` flag. Keep compatibility with
 // richer responses while not filtering those valid canonical rows out.
 partners.value.filter(p=>(p.active===undefined||Boolean(Number(p.active)))&&p.partner_id).map(p=>({value:String(p.id),label:p.partner_name+' — '+p.full_name})).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const filtered=computed(()=>rows.value.filter(m=>m.specialist_name.toLocaleLowerCase('ru').includes(search.value.toLocaleLowerCase('ru'))));
function feedbackDetails(item){
 const rating=item.rating===null||item.rating===undefined||String(item.rating).trim()===''?null:`Оценка: ${item.rating}`;
 return [item.project_id?`Проект #${item.project_id}`:'Клиент',item.occurred_on?`Дата: ${fmt(item.occurred_on)}`:null,rating].filter(Boolean).join(' · ');
}
async function api(url,options={}){
 const res=await fetch(url,{...options,headers:{Accept:'application/json','Content-Type':'application/json',...(options.headers||{})}});
 const payload=await res.json().catch(()=>({}));
 if(!res.ok)throw new Error(payload.message||Object.values(payload.errors||{}).flat().join(', ')||`HTTP ${res.status}`);
 return payload.data;
}
async function load(){
 if(!clientId.value){rows.value=[];return;}
 loading.value=true;error.value='';
 try{rows.value=await api('/api/clients/clients/'+clientId.value+'/client-members');}
 catch(e){error.value=e.message;}finally{loading.value=false;}
 if(props.onlyDetails && props.focusedMemberId)selected.value=rows.value.find(m=>Number(m.id)===props.focusedMemberId)||null;
 else if(selected.value?.id)selected.value=rows.value.find(m=>Number(m.id)===Number(selected.value.id))||null;
}
async function loadPartners(){
 try{partners.value=await api('/api/clients/partner-specialists/catalog');}
 catch { partners.value=[]; }
}
function show(m){selected.value=m;termOpen.value=false;assignmentOpen.value=false;feedbackOpen.value=false;editTermId.value=null;error.value='';}
function closeDetails(){selected.value=null;if(props.onlyDetails)emit('close');}
function beginNew(){createOpen.value=true;personId.value='';personName.value='';personType.value='employee';loadPartners();}
async function create(){
 if(!props.canManage||saving.value)return;
 saving.value=true;error.value='';
 try{
  const person=personOptions.value.find(o=>String(o.value)===personId.value);
  if(!person)throw new Error('Выберите специалиста');
  const obj=await api('/api/clients/clients/'+clientId.value+'/client-members',{
   method:'POST',body:JSON.stringify({
    employee_id:personType.value==='employee'?Number(personId.value):null,
    partner_specialist_id:personType.value==='partner'?Number(personId.value):null,
    specialist_name:personType.value==='employee'?person.label:undefined
   })
  });
  createOpen.value=false;await load();emit('changed');show(rows.value.find(m=>Number(m.id)===Number(obj.id))||obj);
  termOpen.value=true;editTermId.value=null;
 }catch(e){error.value=e.message;}finally{saving.value=false;}
}
const fmt=d=>d?String(d).slice(0,10):'—';
function startNewTerm(){Object.assign(termForm,{technology:'',level:'',hourly_rate:'',hours_per_day:8,valid_from:'',valid_to:''});editTermId.value=null;termOpen.value=true;}
function startEditTerm(t){Object.assign(termForm,{technology:t.technology||'',level:t.level||'',hourly_rate:t.hourly_rate??'',hours_per_day:t.hours_per_day,valid_from:fmt(t.valid_from),valid_to:t.valid_to?fmt(t.valid_to):''});editTermId.value=t.id;termOpen.value=true;}
async function saveTerm(){
 if(!props.canManage||saving.value)return;
 saving.value=true;error.value='';
 try{
  const payload={technology:termForm.technology,level:termForm.level,hourly_rate:Number(termForm.hourly_rate),hours_per_day:Number(termForm.hours_per_day),valid_from:termForm.valid_from,valid_to:termForm.valid_to||null};
  if(editTermId.value)await api('/api/clients/client-terms/'+editTermId.value,{method:'PATCH',body:JSON.stringify(payload)});
  else await api('/api/clients/client-members/'+selected.value.id+'/terms',{method:'POST',body:JSON.stringify(payload)});
  await load();termOpen.value=false;editTermId.value=null;emit('changed');
 }catch(e){error.value=e.message;}finally{saving.value=false;}
}
async function saveAssignment(){
 if(!props.canManage||saving.value)return;
 saving.value=true;error.value='';
 try{
  const path=editAssignmentId.value?'/api/clients/project-assignments/'+editAssignmentId.value:'/api/clients/client-members/'+selected.value.id+'/project-assignments';
  await api(path,{method:editAssignmentId.value?'PATCH':'POST',body:JSON.stringify({project_id:Number(assignment.project_id),valid_from:assignment.valid_from,valid_to:assignment.valid_to||null})});
  await load();emit('changed');assignmentOpen.value=false;editAssignmentId.value=null;Object.assign(assignment,{project_id:'',valid_from:'',valid_to:''});
 }catch(e){error.value=e.message;}finally{saving.value=false;}
}
function editAssignment(row){Object.assign(assignment,{project_id:String(row.project_id),valid_from:fmt(row.valid_from),valid_to:row.valid_to?fmt(row.valid_to):''});editAssignmentId.value=row.id;assignmentOpen.value=true;}
function closeAssignment(){assignmentOpen.value=false;editAssignmentId.value=null;Object.assign(assignment,{project_id:'',valid_from:'',valid_to:''});}
async function saveFeedback(){
 if(!props.canManage||saving.value)return;
 saving.value=true;error.value='';
 try{
  await api('/api/clients/client-members/'+selected.value.id+'/feedbacks',{method:'POST',body:JSON.stringify({project_id:feedback.project_id?Number(feedback.project_id):null,text:feedback.text})});
  await load();emit('changed');feedbackOpen.value=false;Object.assign(feedback,{project_id:'',text:''});
 }catch(e){error.value=e.message;}finally{saving.value=false;}
}
watch(clientId,load);
watch(()=>props.focusedClientId,id=>{if(props.onlyDetails && id)clientId.value=String(id);},{immediate:true});
watch(()=>props.initialClientId,id=>{if(!props.onlyDetails && id)clientId.value=String(id);},{immediate:true});
watch(()=>props.clients,c=>{if(!clientId.value && c.length && !props.onlyDetails)clientId.value=String(c[0].id);},{immediate:true});
onMounted(loadPartners);
</script>
<template>
<section class="cm-registry">
 <UiFilterBar v-if="!onlyDetails"><UiSearchSelect v-model="clientId" :options="clientOptions" placeholder="Клиент" search-placeholder="Поиск клиента" :clearable="false" aria-label="Клиент" />
 <input v-model="search" type="search" placeholder="Поиск по специалистам" aria-label="Поиск специалиста">
 <UiButton v-if="canManage" @click="beginNew">＋ Подключить специалиста</UiButton></UiFilterBar>
 <p v-if="error" class="cm-error">{{error}} <UiButton v-if="onlyDetails" compact @click="emit('close')">Закрыть</UiButton></p>
 <p v-if="loading">Загрузка подключений…</p>
 <div v-if="!onlyDetails" class="cm-list irlix-registry-scroll">
  <UiRegistryTable class="shared-registry-content" table-class="irlix-data-table"
    :columns="['40%','20%','20%','20%']" min-width="680px"
    aria-label="Подключения специалистов" density="compact">
    <template #head><tr><th>Специалист</th><th>Тип</th><th>Условия</th><th>Проекты</th></tr></template>
    <tr v-for="m in filtered" :key="m.id" class="contact-clickable-row"
      tabindex="0" role="button" @click="show(m)"
      @keydown.enter.prevent="show(m)" @keydown.space.prevent="show(m)">
      <td><strong>{{m.specialist_name}}</strong></td>
      <td>{{m.partner_specialist_id?'Партнёр':'Штатный'}}</td>
      <td>{{m.terms?.length||0}}</td><td>{{m.projects?.length||0}}</td>
    </tr>
    <tr v-if="!loading&&!filtered.length"><td colspan="4">Подключений нет.</td></tr>
  </UiRegistryTable>
 </div>
 <UiDrawer :open="createOpen&&!onlyDetails" title="Новое подключение к клиенту" width="540px" @close="createOpen=false">
  <div class="cm-card">
   <label>Тип специалиста<select v-model="personType" @change="personId=''"><option value="employee">Штатный</option><option value="partner">Партнёрский</option></select></label>
   <label>Специалист<UiSearchSelect v-model="personId" :options="personOptions" placeholder="Выберите специалиста" search-placeholder="Поиск специалиста" :clearable="false" /></label>
   <p>Подключение к клиенту не требует проекта. Коммерческие условия задаются следующим шагом.</p>
   <UiButton :disabled="saving||!personId" @click="create">Создать</UiButton>
  </div>
 </UiDrawer>
 <UiDrawer :open="!!selected" :title="selected?.specialist_name||'Подключение'" width="640px" @close="closeDetails">
  <div v-if="selected" class="cm-card">
   <h3>Коммерческие условия <UiButton v-if="canManage" compact @click="startNewTerm">＋</UiButton></h3>
   <div v-for="t in selected.terms" :key="t.id" class="cm-line"><span>{{fmt(t.valid_from)}} — {{fmt(t.valid_to)}} · {{t.technology||'—'}} / {{t.level||'—'}} · {{t.hourly_rate??'Неизвестно'}} ₽/ч · {{t.hours_per_day}} ч/д</span><button v-if="canManage" @click="startEditTerm(t)" aria-label="Редактировать условия"><UiIcon name="pencil"/></button></div>
   <div v-if="termOpen" class="cm-panel">
    <label>Технология<input v-model="termForm.technology" required maxlength="100"></label>
    <label>Грейд<input v-model="termForm.level" required maxlength="100"></label>
    <label>Ставка, ₽/ч<input v-model="termForm.hourly_rate" type="number" step=".01" min="0" required></label>
    <label>Загрузка, ч/д<input v-model="termForm.hours_per_day" type="number" step=".25" min="0" max="24" required></label>
    <label>Начало<input v-model="termForm.valid_from" type="date" required></label>
    <label>Конец<input v-model="termForm.valid_to" type="date"></label>
    <UiButton :disabled="saving" @click="saveTerm">✓ Сохранить</UiButton><UiButton variant="secondary" @click="termOpen=false">Отмена</UiButton>
   </div>
   <h3>Проекты <UiButton v-if="canManage&&projects.length" compact @click="assignmentOpen=true">＋</UiButton></h3>
   <div v-for="a in selected.projects" :key="a.id" class="cm-line"><span>{{a.project_name}} · {{fmt(a.valid_from)}} — {{fmt(a.valid_to)}}</span><button v-if="canManage" type="button" aria-label="Изменить назначение на проект" @click="editAssignment(a)"><UiIcon name="pencil"/></button></div>
   <p v-if="!projects.length">У клиента нет проектов — это не препятствует учёту часов.</p>
   <div v-if="assignmentOpen" class="cm-panel">
    <label>Проект<select v-model="assignment.project_id"><option value="">Выберите проект</option><option v-for="p in projects" :key="p.id" :value="String(p.id)">{{p.name||'Основной проект'}}</option></select></label>
    <label>Начало<input v-model="assignment.valid_from" type="date" required></label><label>Конец<input v-model="assignment.valid_to" type="date"></label>
    <UiButton :disabled="saving||!assignment.project_id||!assignment.valid_from" @click="saveAssignment">✓ Сохранить</UiButton><UiButton variant="secondary" :disabled="saving" @click="closeAssignment">Отмена</UiButton>
   </div>
   <h3>Фидбеки <UiButton v-if="canManage" compact @click="feedbackOpen=true">＋</UiButton></h3>
   <div v-for="f in selected.feedbacks" :key="f.id" class="cm-line">{{f.text}} <small>· {{feedbackDetails(f)}}</small></div>
   <div v-if="feedbackOpen" class="cm-panel">
   <label>Проект<select v-model="feedback.project_id"><option value="">Общий фидбек по клиенту</option><option v-for="p in historicalProjectOptions" :key="p.value" :value="p.value">{{p.label}}</option></select></label>
   <label>Текст<textarea v-model="feedback.text" rows="3" required maxlength="10000"></textarea></label>
   <UiButton :disabled="saving||!feedback.text.trim()" @click="saveFeedback">✓ Сохранить</UiButton><UiButton variant="secondary" @click="feedbackOpen=false">Отмена</UiButton>
   </div>
  </div>
 </UiDrawer>
</section>
</template>
<style scoped>
.cm-registry{padding:12px}.cm-registry select,.cm-registry input,.cm-card input,.cm-card select,.cm-card textarea{padding:7px;background:var(--irlix-color-surface);border:1px solid var(--irlix-color-border);border-radius:6px;color:inherit}.cm-list{margin-top:12px}.cm-row{display:flex;gap:12px;align-items:center;width:100%;min-height:44px;background:transparent;border:0;border-bottom:1px solid var(--irlix-color-border);text-align:left;color:inherit;cursor:pointer}.cm-row strong{flex:1}.cm-row span{min-width:90px}.cm-card{padding:20px}.cm-card label{display:grid;gap:5px;margin:10px 0}.cm-card h3{display:flex;align-items:center;justify-content:space-between;margin-top:24px}.cm-line{border-bottom:1px solid var(--irlix-color-border);padding:8px 0;display:flex;justify-content:space-between}.cm-line button{border:0;background:none;color:inherit;cursor:pointer}.cm-panel{padding:12px;background:var(--irlix-color-surface-muted);border-radius:6px}.cm-error{color:#c44}
</style>

<script setup>
import { computed, ref, watch } from 'vue';
import { UiButton, UiDrawer, UiSearchSelect } from '@irlix/ui';
const props=defineProps({
 memberId:{type:Number,default:null}, clientId:{type:Number,default:null},
 clients:{type:Array,default:()=>[]},technologies:{type:Array,default:()=>[]},levels:{type:Array,default:()=>[]},
 canManage:{type:Boolean,default:false},
});
const emit=defineEmits(['close','changed']);
const member=ref(null),feedbacks=ref([]),error=ref(''),saving=ref(false),addMode=ref('');
const termDraft=ref({technology:'',level:'',hourly_rate:'',hours_per_day:8,valid_from:'',valid_to:''});
const projectDraft=ref({project_id:'',valid_from:'',valid_to:''});
const feedbackDraft=ref({project_id:'',text:''});
const projects=computed(()=>(props.clients.find(c=>Number(c.id)===Number(props.clientId))?.projects||[])
  .map(p=>({value:String(p.id),label:p.displayName||p.name||'Проект #'+p.id})));
const assignedProjects=computed(()=>(member.value?.projects||[]).map(a=>({ ...a, label: projects.value.find(p=>Number(p.value)===Number(a.project_id))?.label || 'Проект #'+a.project_id })));
const historicProjectOptions=computed(()=>[...new Map(assignedProjects.value.map(a=>[String(a.project_id),{
 value:String(a.project_id),label:a.label
}])).values()]);
const date=value=>value?String(value).slice(0,10).split('-').reverse().join('.'):'по н.в.';
async function api(url,options={}){
 const response=await fetch(url,{...options,headers:{Accept:'application/json','Content-Type':'application/json',...(options.headers||{})}});
 const data=await response.json().catch(()=>({}));
 if(!response.ok)throw new Error(data.message||Object.values(data.errors||{}).flat()[0]||'HTTP '+response.status);
 return data.data;
}
async function load(){
 if(!props.memberId||!props.clientId)return;
 error.value='';
 try{
  const [rows,notes]=await Promise.all([
   api(`/api/clients/clients/${props.clientId}/client-members`),
   api(`/api/clients/client-members/${props.memberId}/feedbacks`)
  ]);
  member.value=rows.find(m=>Number(m.id)===Number(props.memberId))||null;
  feedbacks.value=notes||[];
 }catch(e){error.value=e.message||String(e);}
}
function begin(mode){
 addMode.value=mode;
 if(mode==='term')termDraft.value={technology:'',level:'',hourly_rate:'',hours_per_day:8,valid_from:new Date().toISOString().slice(0,10),valid_to:''};
 if(mode==='project')projectDraft.value={project_id:'',valid_from:new Date().toISOString().slice(0,10),valid_to:''};
 if(mode==='feedback')feedbackDraft.value={project_id:'',text:''};
}
async function save(){
 if(saving.value||!props.canManage)return;
 saving.value=true;error.value='';
 try{
  const path=addMode.value==='term'?'terms':addMode.value==='project'?'project-assignments':'feedbacks';
  const payload=addMode.value==='term'?termDraft.value:addMode.value==='project'
   ?{...projectDraft.value,project_id:Number(projectDraft.value.project_id)}: {
    text:feedbackDraft.value.text,project_id:feedbackDraft.value.project_id?Number(feedbackDraft.value.project_id):null,
   };
  await api(`/api/clients/client-members/${props.memberId}/${path}`,{
   method:'POST',body:JSON.stringify(payload)
  });
  addMode.value='';await load();emit('changed');
 }catch(e){error.value=e.message||String(e);}
 finally{saving.value=false;}
}
watch(()=>[props.memberId,props.clientId],load,{immediate:true});
</script>
<template>
 <UiDrawer :open="!!memberId" :title="member?.specialist_name || 'Подключение к клиенту'" width="580px" @close="emit('close')">
  <section class="client-member-card">
   <p v-if="error" class="client-member-card__error" role="alert">{{error}}</p>
   <p>Коммерческие условия специалиста привязаны напрямую к клиенту и не зависят от проектов.</p>
   <h4>История условий</h4>
   <div v-for="term in member?.terms||[]" :key="term.id" class="client-member-card__record">
    <strong>{{date(term.valid_from)}} — {{date(term.valid_to)}}</strong>
    <span>{{term.technology}} / {{term.level}} · {{term.hourly_rate == null?'Ставка неизвестна':term.hourly_rate+' ₽/ч'}} · {{term.hours_per_day}} ч/д</span>
   </div>
   <p v-if="!member?.terms?.length">Коммерческих условий пока нет</p>
   <UiButton v-if="canManage && !addMode" compact @click="begin('term')">＋ Новые условия</UiButton>
   <h4>Проекты</h4>
   <div v-for="a in assignedProjects" :key="a.id" class="client-member-card__record">
    {{a.label}} · {{date(a.valid_from)}} — {{date(a.valid_to)}}
   </div>
   <p v-if="!assignedProjects.length">Нет назначений на проекты</p>
   <UiButton v-if="canManage && !addMode" compact @click="begin('project')">＋ Назначить на проект</UiButton>
   <h4>Фидбеки</h4>
   <div v-for="f in feedbacks" :key="f.id" class="client-member-card__record">
    <strong>{{date(f.created_at)}}</strong>
    <span>{{f.project_id ? (projects.find(p=>Number(p.value)===Number(f.project_id))?.label||'Проект #'+f.project_id) : 'Общий по клиенту'}}</span>
    <span>{{f.text}}</span>
   </div>
   <p v-if="!feedbacks.length">Фидбеков нет</p>
   <UiButton v-if="canManage && !addMode" compact @click="begin('feedback')">＋ Фидбек</UiButton>
   <form v-if="addMode" class="client-member-card__form" @submit.prevent="save">
    <strong>{{addMode==='term'?'Добавление условий':addMode==='project'?'Назначение на проект':'Новый фидбек'}}</strong>
    <template v-if="addMode==='term'">
     <label>Технология<UiSearchSelect v-model="termDraft.technology" :options="technologies" :clearable="false"/></label>
     <label>Грейд<UiSearchSelect v-model="termDraft.level" :options="levels" :clearable="false"/></label>
     <label>Ставка, ₽/ч<input v-model="termDraft.hourly_rate" type="number" min="0" step="0.01" required /></label>
     <label>Загрузка, ч/д<input v-model="termDraft.hours_per_day" type="number" min="0" max="24" step="0.25" required /></label>
     <label>Дата начала<input v-model="termDraft.valid_from" type="date" required/></label>
     <label>Дата окончания<input v-model="termDraft.valid_to" type="date" :min="termDraft.valid_from"/></label>
    </template>
    <template v-else-if="addMode==='project'">
     <label>Проект<UiSearchSelect v-model="projectDraft.project_id" :options="projects" :clearable="false" /></label>
     <label>Начало<input v-model="projectDraft.valid_from" type="date" required/></label>
     <label>Окончание<input v-model="projectDraft.valid_to" type="date" :min="projectDraft.valid_from"/></label>
    </template>
    <template v-else>
     <label>Проект (необязательно)<UiSearchSelect v-model="feedbackDraft.project_id" :options="historicProjectOptions" placeholder="Общий по клиенту" /></label>
     <label>Фидбек<textarea v-model="feedbackDraft.text" rows="4" maxlength="10000" required/></label>
    </template>
    <div class="client-member-card__actions">
     <UiButton compact type="submit" :disabled="saving">Сохранить</UiButton>
     <UiButton compact variant="secondary" type="button" @click="addMode=''">Отменить</UiButton>
    </div>
   </form>
  </section>
 </UiDrawer>
</template>
<style scoped>
.client-member-card{display:flex;flex-direction:column;gap:12px;padding:20px;max-height:calc(100vh - 100px);overflow:auto;}
.client-member-card h4{margin:12px 0 0;}
.client-member-card__record{display:flex;flex-direction:column;gap:4px;padding:9px 0;border-bottom:1px solid var(--irlix-border-color,#ddd);}
.client-member-card__form{display:flex;flex-direction:column;gap:12px;border-top:1px solid var(--irlix-border-color,#ddd);padding-top:14px;}
.client-member-card__form label{display:flex;flex-direction:column;gap:6px;}
.client-member-card__form input,.client-member-card__form textarea{font:inherit;border:1px solid var(--irlix-border-color,#ddd);border-radius:6px;padding:8px;background:transparent;color:inherit;}
.client-member-card__actions{display:flex;gap:8px;}
.client-member-card__error{color:#b42318;}
</style>

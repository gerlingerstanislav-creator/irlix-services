<script setup>
import {computed,onMounted,onUnmounted,ref} from 'vue';
import {UiIcon} from '@irlix/ui';
import {attentionSources,normalizeTasks,sortTasks} from './attentionModel.js';
import './attention.css';
const props=defineProps({auth:{type:Object,required:true},serviceAccess:{type:Object,default:null},full:{type:Boolean,default:false},showAll:{type:Boolean,default:false}});
const emit=defineEmits(['openAll','count']);
const tasks=ref([]),failed=ref([]),loading=ref(true),filter=ref('all');
let active=true,timer;
const visible=computed(()=>sortTasks(tasks.value).filter(item=>filter.value==='all'||filter.value===item.kind));
const count=computed(()=>tasks.value.filter(t=>t.kind==='attention').length);
const shown=computed(()=>(props.full||props.showAll)?visible.value:visible.value.slice(0,5));
const label=date=>date ? new Date(date+'T12:00:00').toLocaleDateString('ru-RU',{day:'numeric',month:'long'}) : '';
async function refresh(){
 const sources=attentionSources.filter(s=>props.serviceAccess?.services?.[s.service]===true);
 const results=await Promise.all(sources.map(async source=>{
  try{const response=await props.auth.fetch(source.path,{headers:{Accept:'application/json'},cache:'no-store'});
    if(!response.ok)throw new Error('HTTP '+response.status);
    return {service:source.service,tasks:normalizeTasks((await response.json()).data,source.service)};
  }catch(error){return {service:source.service,error};}
 }));
 if(!active)return;
 tasks.value=results.flatMap(r=>r.tasks||[]);
 failed.value=results.filter(r=>r.error).map(r=>r.service);
 loading.value=false;
 emit('count',tasks.value.filter(task=>task.kind==='attention').length);
 timer=setTimeout(refresh,60000);
}
onMounted(()=>{active=true;refresh()});
onUnmounted(()=>{active=false;clearTimeout(timer)});
</script>
<template>
<section class="attention-center" aria-label="Действия сотрудника">
 <header class="attention-center__head">
 <h2><UiIcon name="list"/>{{full?'Мои задачи':'Требует внимания'}}<span v-if="count">{{count}}</span></h2>
 <button v-if="!full" class="attention-center__more" @click="emit('openAll')">Все задачи →</button>
 <select v-else v-model="filter" aria-label="Фильтр задач"><option value="all">Все</option><option value="attention">Требует внимания</option><option value="upcoming">Предстоит</option></select>
 </header>
 <p v-if="loading" class="attention-center__hint" role="status">Проверяем задачи в сервисах…</p>
 <p v-else-if="failed.length" class="attention-center__warning" role="status">Не удалось загрузить задачи из: {{failed.join(', ')}}. Список может быть неполным.</p>
 <p v-if="!loading&&!visible.length" class="attention-center__empty">{{failed.length?'Доступные задачи не найдены.':'Сейчас нет действий, требующих внимания.'}}</p>
 <div v-else class="attention-center__items">
 <a v-for="task in shown" :key="task.service+':'+task.id" class="attention-center__item" :href="task.href">
 <span class="attention-center__mark" :class="'kind-'+task.kind"><UiIcon :name="task.kind==='attention'?'status':'calendar'"/></span>
 <span class="attention-center__description"><strong>{{task.title}}</strong><small>{{task.detail || (task.kind==='attention'?'Требует действия':'Предстоит')}}</small></span>
 <span class="attention-center__date">{{label(task.due_at)}}</span><UiIcon name="chevron-right"/>
 </a></div>
</section>
</template>
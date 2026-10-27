<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { UiButton } from '@irlix/ui';
const props=defineProps({auth:{type:Object,required:true}});
const rows=ref([]),loading=ref(false),error=ref(''),busy=ref(false),selected=ref([]);
const filters=reactive({status:'open',service_key:'',employee_id:'',from:'',to:''});
const page=ref(1),total=ref(0),lastPage=ref(1);
const visibleIds=computed(()=>rows.value.map(r=>r.id));
const allSelected=computed(()=>visibleIds.value.length>0&&visibleIds.value.every(id=>selected.value.includes(id)));
const services=['dashboard','employees','vacations','clients','timesheets','specialists','equipment','finance','recruitment','cv-converter','design-system','brandbook','system'];
const date=d=>d?new Date(d).toLocaleString('ru-RU'):'—';
function query(){const p=new URLSearchParams();for(const [key,value] of Object.entries(filters))if(value)p.set(key,value);p.set('page',String(page.value));return p;}
async function load(){loading.value=true;error.value='';try{const response=await props.auth.fetch('/api/reports?'+query(),{headers:{Accept:'application/json'},cache:'no-store'});if(!response.ok)throw Error('Не удалось получить репорты ('+response.status+')');const result=await response.json();rows.value=result.data||[];total.value=result.total||0;lastPage.value=result.last_page||1;selected.value=[];}catch(e){error.value=e.message}finally{loading.value=false;}}
function apply(){page.value=1;load()}
function toggleAll(event){selected.value=event.target.checked?[...visibleIds.value]:[];}
async function setStatus(ids,status){if(!ids.length||busy.value)return;busy.value=true;error.value='';try{const res=await props.auth.fetch('/api/reports/status',{method:'PATCH',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({ids,status})});if(!res.ok)throw Error('Не удалось изменить статус ('+res.status+')');await load();}catch(e){error.value=e.message}finally{busy.value=false;}}
async function download(){error.value='';try{const p=query();p.delete('page');const res=await props.auth.fetch('/api/reports/export?'+p,{cache:'no-store'});if(!res.ok)throw Error('Не удалось скачать отчёт ('+res.status+')');const blob=await res.blob();const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='irlix-bug-reports.md';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}catch(e){error.value=e.message;}}
onMounted(load);
</script>
<template>
<section class="bug-reports-page">
<header class="bug-reports-head"><div><h1>Репорты об ошибках</h1><small>Всего по фильтрам: {{total}}</small></div><div class="bug-reports-actions"><UiButton variant="secondary" :disabled="loading" @click="load">Обновить</UiButton><UiButton variant="secondary" @click="download">Скачать все тикеты</UiButton><UiButton :disabled="!selected.length||busy" @click="setStatus(selected,'closed')">Закрыть выбранные ({{selected.length}})</UiButton></div></header>
<div class="bug-reports-filters">
<label>Статус<select v-model="filters.status" @change="apply"><option value="">Все</option><option value="open">Открытые</option><option value="closed">Закрытые</option></select></label>
<label>Сервис<select v-model="filters.service_key" @change="apply"><option value="">Все</option><option v-for="item in services" :key="item" :value="item">{{item}}</option></select></label>
<label>ID сотрудника<input v-model="filters.employee_id" type="number" min="1" placeholder="Все" @change="apply"/></label>
<label>От<input v-model="filters.from" type="date" @change="apply"/></label>
<label>До<input v-model="filters.to" type="date" @change="apply"/></label>
</div>
<p v-if="error" role="alert" class="bug-reports-error">{{error}}</p>
<div class="bug-reports-table-wrap"><table class="bug-reports-table"><thead><tr><th><input type="checkbox" aria-label="Выбрать все на странице" :checked="allSelected" @change="toggleAll"/></th><th>ID</th><th>Дата</th><th>Сотрудник</th><th>Сервис / страница</th><th>Описание</th><th>Статус</th><th>Действие</th></tr></thead><tbody>
<tr v-for="report in rows" :key="report.id"><td><input v-model="selected" type="checkbox" :value="report.id" :aria-label="'Выбрать репорт '+report.id"/></td><td>#{{report.id}}</td><td>{{date(report.created_at)}}</td><td>{{report.employee_name}}<small class="bug-reports-path">ID {{report.employee_id}}</small></td><td><strong>{{report.service_key}}</strong><small class="bug-reports-path">{{report.page_path}}</small></td><td class="bug-reports-description">{{report.description}}</td><td>{{report.status==='open'?'Открыт':'Закрыт'}}</td><td><button type="button" :disabled="busy" @click="setStatus([report.id],report.status==='open'?'closed':'open')">{{report.status==='open'?'Закрыть':'Открыть'}}</button></td></tr>
<tr v-if="!rows.length"><td colspan="8">{{loading?'Загрузка…':'Репортов не найдено'}}</td></tr>
</tbody></table></div>
<footer class="bug-reports-pages"><button :disabled="page<=1||loading" @click="page--;load()">Назад</button><span>{{page}} / {{lastPage}}</span><button :disabled="page>=lastPage||loading" @click="page++;load()">Вперёд</button></footer>
</section>
</template>
<style scoped>
.bug-reports-page{padding:18px 22px;display:flex;flex-direction:column;gap:12px;min-height:0;flex:1;font-size:14px}.bug-reports-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.bug-reports-head h1{margin:0 0 3px;font-size:19px}.bug-reports-head small{color:var(--irlix-color-text-secondary,#777)}.bug-reports-actions{display:flex;gap:8px;flex-wrap:wrap}.bug-reports-filters{display:flex;gap:12px;flex-wrap:wrap}.bug-reports-filters label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--irlix-color-text-secondary,#777)}.bug-reports-filters select,.bug-reports-filters input{min-height:32px;padding:4px 8px;border:1px solid var(--irlix-color-border,#ddd);border-radius:6px;background:var(--irlix-color-surface,#fff);color:var(--irlix-color-text,#222)}.bug-reports-table-wrap{min-height:200px;max-height:calc(100vh - 260px);overflow:auto;border:1px solid var(--irlix-color-border,#ddd);border-radius:8px}.bug-reports-table{border-collapse:collapse;width:100%;min-width:1000px}.bug-reports-table th,.bug-reports-table td{padding:9px 10px;text-align:left;vertical-align:top;border-bottom:1px solid var(--irlix-color-border,#ddd)}.bug-reports-table th{position:sticky;top:0;background:var(--irlix-color-surface,#fff);z-index:1;font-size:12px}.bug-reports-table td button,.bug-reports-pages button{cursor:pointer;border:1px solid var(--irlix-color-border,#ddd);border-radius:6px;padding:5px 10px;background:transparent;color:inherit}.bug-reports-path{display:block;font-size:11px;overflow-wrap:anywhere;color:var(--irlix-color-text-secondary,#777)}.bug-reports-description{white-space:pre-wrap;overflow-wrap:anywhere;min-width:300px;max-width:570px}.bug-reports-error{color:#b33}.bug-reports-pages{display:flex;justify-content:center;align-items:center;gap:12px}
</style>
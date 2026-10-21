<script setup>
import {computed,ref,watch,onBeforeUnmount} from 'vue';
import {UiBadge,UiButton,UiDrawer,UiPanel,UiTreeToggle} from '@irlix/ui';
import {migrationTree,diagnosticItems,progressText,serviceTitles,stageRows,stageTitles,statusText,text,tone} from './migration-control-model.js';

const props=defineProps({operation:{type:Object,required:true},report:{type:Object,default:null},auth:{type:Object,required:true},busy:{type:Boolean,default:false},restoreHistory:{type:Array,default:()=>[]}});
const emit=defineEmits(['retry-service']);
const tree=computed(()=>migrationTree(props.report,props.operation));
const rootOpen=ref(true),servicesOpen=ref({}),detail=ref(null),full=ref(null),loading=ref(false),problem=ref('');
let seq=0;const controllers=new Set();
watch(()=>props.operation.id,()=>{rootOpen.value=true;servicesOpen.value={};detail.value=null;full.value=null;problem.value='';seq++;});
function toggle(s){servicesOpen.value={...servicesOpen.value,[s]:!servicesOpen.value[s]};}
function open(kind,node=null,severity=null){detail.value={kind,node,severity};problem.value='';if(kind==='issues')loadItems();}
function close(){detail.value=null;seq++;loading.value=false;}
const detailTitle=computed(()=>{const d=detail.value;return d?(d.kind==='issues'?(d.severity==='error'?'Ошибки':'Предупреждения'):d.kind==='fields'?'Поля':d.kind==='stages'?'Этапы':'Подробности')+' · '+(d.node?.label||'Весь перенос'):'';});
const groups=computed(()=>(detail.value?.node?.issue_groups||tree.value?.issue_groups||[]).filter(g=>!detail.value?.severity||g.severity===detail.value.severity));
const items=computed(()=>detail.value?.kind==='issues'?diagnosticItems(full.value||props.report,{service:detail.value.node?.service||null,table:detail.value.node?.table_name||null,severity:detail.value.severity}):[]);
const stages=computed(()=>stageRows(props.operation,props.report,detail.value?.node?.service||'all'));
async function loadItems(){
 if(full.value||!props.report)return;
 const id=String(props.operation.id),request=++seq,controller=new AbortController();
 controllers.add(controller);loading.value=true;
 try{
  const response=await props.auth.fetch('/api/migration/control/operations/'+encodeURIComponent(id)+'/report?download=1',{headers:{Accept:'application/json'},cache:'no-store',signal:controller.signal});
  if(!response.ok)throw new Error('HTTP '+response.status);
  const body=await response.json();
  if(seq===request&&String(props.operation.id)===id)full.value=body.data??body;
 }catch(e){if(seq===request)problem.value='Список событий недоступен: '+e.message;}
 finally{controllers.delete(controller);if(seq===request)loading.value=false;}
}
onBeforeUnmount(()=>{seq++;for(const controller of controllers)controller.abort();});
const proof=node=>node.evidence_status==='full'?'Доказательства полные':node.evidence_status==='partial'?'Доказательства частичные':'Доказательства неизвестны';
</script>

<template>
<section class="mctrl-tree" aria-label="Дерево переноса">
 <p class="mctrl-muted">Сводка: перенос → сервис → сущность. Нажатие на показатель откроет детали. Ошибки считаются событиями, а не уникальными строками.</p>
 <ul class="mctrl-tree-list">
  <li>
   <div class="mctrl-tree-row mctrl-tree-root">
    <UiTreeToggle :expanded="rootOpen" label="Развернуть весь перенос" @click="rootOpen=!rootOpen"/>
    <strong class="mctrl-tree-name">Весь перенос #{{operation.id}}</strong>
    <UiButton variant="ghost" compact @click="open('overview')"><UiBadge :tone="tone(operation.status)">{{statusText(operation.status)}}</UiBadge></UiButton>
    <UiButton variant="ghost" compact @click="open('overview')">Полнота: {{report?.coverage?.semantic_complete===true?'подтверждена':'не подтверждена'}}</UiButton>
    <UiButton variant="ghost" compact @click="open('overview')">Без результата: {{text(report?.coverage?.expected_unprocessed_rows)}} ожидаемых</UiButton>
    <UiButton v-if="tree.issues.errors" variant="ghost" compact @click="open('issues',null,'error')"><UiBadge tone="danger">Ошибки · {{tree.issues.errors}}</UiBadge></UiButton>
    <UiButton v-if="tree.issues.warnings" variant="ghost" compact @click="open('issues',null,'warning')"><UiBadge tone="warning">Предупреждения · {{tree.issues.warnings}}</UiBadge></UiButton>
    <UiButton variant="ghost" compact @click="open('stages')">Этапы</UiButton>
   </div>
   <ul v-if="rootOpen" class="mctrl-tree-list mctrl-tree-branch">
    <li v-for="svc in tree.services" :key="svc.service">
     <div class="mctrl-tree-row mctrl-tree-service">
      <UiTreeToggle :expanded="!!servicesOpen[svc.service]" :label="'Развернуть '+svc.label" @click="toggle(svc.service)"/>
      <strong class="mctrl-tree-name">{{svc.label}}</strong>
      <UiButton variant="ghost" compact @click="open('service',svc)"><UiBadge :tone="tone(svc.status)">{{statusText(svc.status)}}</UiBadge></UiButton>
      <span class="mctrl-tree-progress">{{progressText(svc.processed,svc.total)}}</span>
      <UiButton variant="ghost" compact @click="open('service',svc)">Без результата: {{svc.unknown_outcome_tables?'неизвестно / ':''}}{{text(svc.expected_unprocessed)}} ожидаемых</UiButton>
      <UiButton variant="ghost" compact @click="open('service',svc)">{{proof(svc)}}</UiButton>
      <UiButton v-if="svc.issues.errors" variant="ghost" compact @click="open('issues',svc,'error')"><UiBadge tone="danger">Ошибки · {{svc.issues.errors}}</UiBadge></UiButton>
      <UiButton v-if="svc.issues.warnings" variant="ghost" compact @click="open('issues',svc,'warning')"><UiBadge tone="warning">Предупреждения · {{svc.issues.warnings}}</UiBadge></UiButton>
      <UiButton v-if="!svc.issues.errors&&['failed','partial','interrupted'].includes(svc.status)" variant="ghost" compact @click="open('service',svc)">Причина</UiButton>
      <UiButton variant="secondary" compact :disabled="busy" :aria-label="'Повторить перенос: '+svc.label" @click="emit('retry-service',svc.service)">Повторить сервис</UiButton>
     </div>
     <ul v-if="servicesOpen[svc.service]" class="mctrl-tree-list mctrl-tree-branch">
      <li v-for="entity in svc.entities" :key="entity.id" class="mctrl-tree-row mctrl-tree-entity">
       <span aria-hidden="true" class="mctrl-tree-spacer"></span>
       <strong class="mctrl-tree-name">{{entity.label}}</strong>
       <UiButton variant="ghost" compact @click="open('entity',entity)"><UiBadge :tone="entity.expectation?.status==='expected'?'success':entity.expectation?.status==='undocumented'?'warning':'neutral'">{{statusText(entity.expectation?.status)}}</UiBadge></UiButton>
       <span class="mctrl-tree-progress">{{progressText(entity.processed,entity.total)}}</span>
       <UiButton v-if="entity.source_outcomes" variant="ghost" compact @click="open('entity',entity)">Без результата: {{text(entity.source_outcomes?.unprocessed)}}</UiButton>
       <UiButton v-if="entity.issues.errors" variant="ghost" compact @click="open('issues',entity,'error')"><UiBadge tone="danger">Ошибки · {{entity.issues.errors}}</UiBadge></UiButton>
       <UiButton v-if="entity.issues.warnings" variant="ghost" compact @click="open('issues',entity,'warning')"><UiBadge tone="warning">Предупреждения · {{entity.issues.warnings}}</UiBadge></UiButton>
       <UiButton variant="ghost" compact @click="open('fields',entity)">Поля · {{entity.fields?.length??0}}</UiButton>
      </li>
      <li v-if="!svc.entities.length" class="mctrl-tree-empty">Сведения о сущностях отсутствуют — это не означает нулевой объём.</li>
     </ul>
    </li>
    <li v-if="!tree.services.length" class="mctrl-tree-empty">Состав сервисов пока не подтверждён.</li>
   </ul>
  </li>
 </ul>
 <p class="mctrl-muted">Отсутствие доказательств не считается нулём или успешным откатом. Исключённые таблицы отделены от ожидаемых к переносу.</p>
</section>

<UiDrawer :open="!!detail" :title="detailTitle" width="760px" @close="close">
 <UiPanel v-if="detail?.kind==='overview'">
  <p><strong>Статус:</strong> {{statusText(operation.status)}} · исход данных {{statusText(operation.data_outcome)}}</p>
  <p><strong>Этап:</strong> {{serviceTitles[operation.last_service||operation.current_service]||text(operation.current_service)}} · {{stageTitles[operation.last_phase||operation.phase]||text(operation.phase)}}</p>
  <p><strong>Начало:</strong> {{text(operation.started_at)}} · <strong>конец:</strong> {{text(operation.finished_at)}}</p>
  <p><strong>Сообщение:</strong> {{text(operation.message)}}</p>
  <p><strong>Полнота:</strong> {{report?.coverage?.semantic_complete===true?'Подтверждена':'Не подтверждена'}}; архив {{report?.archive_complete===true?'полный':'неполный / неизвестен'}}.</p>
  <p><strong>Без результата:</strong> ожидаемые {{text(report?.coverage?.expected_unprocessed_rows)}}, исключённые {{text(report?.coverage?.excluded_unprocessed_rows)}}, неописанные {{text(report?.coverage?.undocumented_unprocessed_rows)}}.</p>
  <p><strong>Неизвестные итоги:</strong> {{text(report?.coverage?.unknown_table_totals)}} таблиц; поля без решения {{text(report?.coverage?.field_fates?.unmapped)}}.</p>
  <p><strong>Снимки:</strong> {{Object.entries(operation.snapshot_ids||{}).map(([key,id])=>key+' #'+id).join(', ')||'Нет данных'}}.</p>
  <p v-for="r in report?.unarchived_runs||[]" :key="r.id">Без архива: {{r.service}} / {{r.mode}} #{{r.id}} · {{r.reason}}</p>
  <p>Наличие снимка и отмена операции не означают, что выполнен откат.</p>
  <p v-if="restoreHistory.length"><strong>История восстановления (отдельные операции):</strong></p>
  <p v-for="r in restoreHistory" :key="'restore-'+r.operation_id">{{serviceTitles[r.scope]||r.scope}} / снимок #{{text(r.snapshot_id)}} — {{r.confirmed===true?'откат подтверждён':'откат не подтверждён'}}</p>
 </UiPanel>
 <UiPanel v-else-if="detail?.kind==='service'">
  <p><strong>Сервис:</strong> {{detail.node.label}} · {{statusText(detail.node.status)}}</p>
  <p><strong>Этап:</strong> {{stageTitles[detail.node.stage]||text(detail.node.stage)}} ({{statusText(detail.node.stage_status)}})</p>
  <p><strong>Исход записи:</strong> {{statusText(detail.node.write_state)}} · {{progressText(detail.node.processed,detail.node.total)}}</p>
  <p><strong>Доказательства:</strong> {{proof(detail.node)}}.</p>
  <p><strong>Таблицы:</strong> ожидаемых {{detail.node.expected_tables}}, исключённых {{detail.node.excluded_tables}}, без решения {{detail.node.undocumented_tables}}.</p>
  <p><strong>Без результата среди ожидаемых:</strong> {{detail.node.expected_unprocessed}} · неизвестных исходов {{detail.node.unknown_outcome_tables}}.</p>
  <p><strong>Причина:</strong> {{text(detail.node.reason)}}</p>
  <p><strong>Последствия:</strong> {{text(detail.node.consequence)}}</p>
  <UiButton variant="ghost" @click="open('stages',detail.node)">Посмотреть этапы</UiButton>
 </UiPanel>
 <UiPanel v-else-if="detail?.kind==='entity'">
  <p><strong>{{detail.node.table_name}}</strong> · {{statusText(detail.node.expectation?.status)}}</p>
  <p>{{text(detail.node.expectation?.reason)}}</p>
  <p>{{progressText(detail.node.processed,detail.node.total)}} · {{detail.node.source_outcomes?'Счётчики сохранены':'Исход неизвестен'}}</p>
  <p v-for="k in ['domain_written','reference_matched','metadata_only','deferred','failed_unique','unprocessed']" :key="k">{{k}}: {{text(detail.node.source_outcomes?.[k])}}</p>
  <p><strong>Инвентаризация:</strong> {{detail.node.inventory_complete?'Подтверждена':'Не подтверждена'}}. {{text(detail.node.unavailable_reason)}}</p>
  <UiButton variant="ghost" @click="open('fields',detail.node)">Поля</UiButton>
 </UiPanel>
 <div v-else-if="detail?.kind==='stages'" class="mctrl-table"><table class="irlix-data-table"><thead><tr><th>Сервис</th><th>Этап</th><th>Статус</th><th>Прогресс</th></tr></thead><tbody>
  <tr v-for="(r,i) in stages" :key="i"><td>{{serviceTitles[r.service]||r.service}}</td><td>{{stageTitles[r.mode]||r.mode}}</td><td>{{r.known?statusText(r.status):'Неизвестно'}}</td><td>{{progressText(r.run?.processed_count??null,r.run?.total??null)}}</td></tr>
 </tbody></table></div>
 <div v-else-if="detail?.kind==='fields'" class="mctrl-table">
  <p>{{detail.node.label}} · {{text(detail.node.expectation?.reason)}}</p>
  <table class="irlix-data-table"><thead><tr><th>Поле / тип</th><th>Правило</th><th>Значения</th></tr></thead><tbody>
   <tr v-for="(f,i) in detail.node.fields||[]" :key="i"><td>{{f.name}}<small>{{text(f.type)}}</small></td><td>{{statusText(f.mapping?.fate)}} · {{text(f.mapping?.rule)}} · {{text(f.mapping?.reason)}}</td><td><details><summary>{{f.values?.length??0}} значений</summary><p v-for="(v,j) in f.values||[]" :key="j">{{text(v.value)}} · {{text(v.count)}} · {{text(v.interpretation?.rule)}}</p></details></td></tr>
   <tr v-if="!detail.node.fields?.length"><td colspan="3">Нет подтверждённых данных полей.</td></tr>
  </tbody></table>
 </div>
 <template v-else-if="detail?.kind==='issues'">
  <p>Диагностические события операции #{{operation.id}}. Группы и затронутые строки нельзя складывать как уникальные строки.</p>
  <p v-if="loading" role="status">Загружаем подробные события…</p>
  <p v-if="problem" role="alert">{{problem}}. Группы доступны ниже.</p>
  <UiPanel v-for="(r,i) in items" :key="'item-'+i" class="mctrl-tree-issue">
   <header><strong>{{text(r.code)}} · {{text(r.table||r.entity_type)}}</strong><UiBadge :tone="r.severity==='error'?'danger':'warning'">{{text(r.severity)}}</UiBadge></header>
   <p>Источник #{{text(r.legacy_id)}} · {{serviceTitles[r.service]||r.service}} · {{stageTitles[r.stage]||text(r.stage)}} · run #{{text(r.run_id)}}</p>
   <p>{{text(r.message)}}</p>
   <details v-if="r.context&&Object.keys(r.context).length"><summary>Контекст</summary><pre>{{JSON.stringify(r.context,null,2)}}</pre></details>
  </UiPanel>
  <p v-if="!loading&&!items.length">Построчные события недоступны. Ниже — сгруппированная диагностика.</p>
  <UiPanel v-for="(g,i) in groups" :key="'group-'+i" class="mctrl-tree-issue">
   <header><strong>{{text(g.code)}} · {{text(g.table)}}</strong><UiBadge :tone="g.severity==='error'?'danger':'warning'">{{text(g.severity)}}</UiBadge></header>
   <p>{{text(g.message)}} · событий {{text(g.count)}} · затронуто строк {{text(g.affected_rows)}}</p>
   <details v-if="g.examples?.length"><summary>Примеры</summary><pre>{{JSON.stringify(g.examples,null,2)}}</pre></details>
  </UiPanel>
  <p v-if="!groups.length">Диагностических групп нет. Это не является доказательством отсутствия проблем.</p>
 </template>
</UiDrawer>
</template>

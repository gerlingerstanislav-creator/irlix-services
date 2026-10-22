<script setup>
import {computed,ref,watch,onBeforeUnmount,onMounted} from 'vue';
import {UiBadge,UiButton,UiDrawer,UiIcon,UiLoader,UiPanel,UiTreeToggle} from '@irlix/ui';
import MigrationIdentityMapping from './MigrationIdentityMapping.vue';
import {migrationTree,diagnosticItems,progressText,lastMigrationAge,serviceTitles,stageRows,stageTitles,statusText,text,tone} from './migration-control-model.js';
import {VISIBILITY_KEY,filterMigrationTree,hiddenEntities,progressPercent,readHidden,saveHidden,updateHidden} from './migration-control-visibility.js';

const props=defineProps({operation:{type:Object,required:true},report:{type:Object,default:null},auth:{type:Object,required:true},busy:{type:Boolean,default:false},lastMigrations:{type:Object,default:()=>({})},restoreHistory:{type:Array,default:()=>[]}});
const emit=defineEmits(['retry-service','settings','snapshot','restore']);
const rawTree=computed(()=>migrationTree(props.report,props.operation));
const hidden=ref({});
const tree=computed(()=>filterMigrationTree(rawTree.value,hidden.value));
const unverifiedReport=computed(()=>!props.report||props.report.report_degraded===true);
const missingServices=computed(()=>{
 const rendered=new Set((tree.value?.services||[]).map(x=>x.service));
 return ['employees','vacations','clients','timesheets'].filter(key=>!rendered.has(key))
   .map(service=>({service,label:serviceTitles[service]}));
});
const rootOpen=ref(true),servicesOpen=ref({}),detail=ref(null),full=ref(null),loading=ref(false),problem=ref('');
const hiddenPanel=ref(null);
const identityConflict=ref(null);
const employmentConflict=ref(null),employmentInfo=ref(null),employmentError=ref(''),employmentLoading=ref(false);
async function showEmployment(item){
  employmentConflict.value=item;employmentInfo.value=null;employmentError.value='';employmentLoading.value=true;
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),18000);
  try{
    const response=await props.auth.fetch('/api/migration/console/conflicts/'+encodeURIComponent(item.id)+'/details',{
      signal:controller.signal,headers:{Accept:'application/json'},cache:'no-store',
    });
    const json=await response.json();
    if(!response.ok)throw new Error(json.message||'HTTP '+response.status);
    if(employmentConflict.value?.id===item.id)employmentInfo.value=json.data??json;
  }catch(e){if(employmentConflict.value?.id===item.id)employmentError.value=e.message;}
  finally{clearTimeout(timeout);employmentLoading.value=false;}
}

const hiddenRows=computed(()=>hiddenPanel.value?hiddenEntities(rawTree.value,hidden.value,hiddenPanel.value):[]);
const hiddenPanelTitle=computed(()=>hiddenPanel.value
 ?'Скрытые строки · '+(serviceTitles[hiddenPanel.value]||hiddenPanel.value):'Скрытые строки');
onMounted(()=>{hidden.value=readHidden(window.localStorage);});
function setHidden(service,tableName,hide){
 hidden.value=updateHidden(hidden.value,service,tableName,hide);
 saveHidden(window.localStorage,hidden.value);
}
function revealAll(service){
 const names=hiddenRows.value.map(entity=>entity.table_name);
 for(const name of names)hidden.value=updateHidden(hidden.value,service,name,false);
 saveHidden(window.localStorage,hidden.value);
}
function showHidden(service){hiddenPanel.value=service;}
const bar=entity=>progressPercent(entity.processed,entity.total);
const lastWrite=service=>lastMigrationAge(props.lastMigrations,service);

let seq=0;const controllers=new Set();
watch(()=>props.operation.id,()=>{rootOpen.value=true;servicesOpen.value={};detail.value=null;hiddenPanel.value=null;full.value=null;problem.value='';seq++;});
function toggle(s){servicesOpen.value={...servicesOpen.value,[s]:!servicesOpen.value[s]};}
function open(kind,node=null,severity=null){detail.value={kind,node,severity};problem.value='';if(kind==='issues')loadItems();}
function close(){detail.value=null;seq++;loading.value=false;}
const detailTitle=computed(()=>{const d=detail.value;return d?(d.kind==='issues'?(d.severity==='error'?'Ошибки':'Предупреждения'):d.kind==='fields'?'Поля':d.kind==='stages'?'Этапы':'Подробности')+' · '+(d.node?.label||'Весь перенос'):'';});
const groups=computed(()=>(detail.value?.node?.issue_groups||tree.value?.issue_groups||[]).filter(g=>!detail.value?.severity||g.severity===detail.value.severity));
const items=computed(()=>{
 if(detail.value?.kind!=='issues')return [];
 const all=diagnosticItems(full.value||props.report,{service:detail.value.node?.service||null,table:detail.value.node?.table_name||null,severity:detail.value.severity});
 // An aggregated issue counter is scoped to visible entities, not the
 // original full-report list. Unattributed issues must stay visible.
 return all.filter(item=>!new Set(hidden.value[item.service]||[]).has(item.table||item.entity_type));
});
const stages=computed(()=>stageRows(props.operation,props.report,detail.value?.node?.service||'all'));
async function loadItems(){
 if(full.value||!props.report)return;
 const id=String(props.operation.id),request=++seq,controller=new AbortController();
 controllers.add(controller);loading.value=true;
 const timeout=setTimeout(()=>controller.abort(),20000);
 try{
  const response=await props.auth.fetch('/api/migration/control/operations/'+encodeURIComponent(id)+'/report?download=1',{headers:{Accept:'application/json'},cache:'no-store',signal:controller.signal});
  if(!response.ok)throw new Error('HTTP '+response.status);
  const body=await response.json();
  if(seq===request&&String(props.operation.id)===id)full.value=body.data??body;
 }catch(e){if(seq===request)problem.value=controller.signal.aborted?'Список событий недоступен: превышено время ожидания Migration API':'Список событий недоступен: '+e.message;}
 finally{clearTimeout(timeout);controllers.delete(controller);if(seq===request)loading.value=false;}
}
onBeforeUnmount(()=>{seq++;for(const controller of controllers)controller.abort();});
const proof=node=>node.evidence_status==='full'?'Доказательства полные':node.evidence_status==='partial'?'Доказательства частичные':'Доказательства неизвестны';
</script>

<template>
<section class="mctrl-tree" aria-label="Таблица переноса">
 <div class="mctrl-tree-scroll" role="region" aria-label="Результаты переноса по сервисам и сущностям" tabindex="0">
  <table class="irlix-data-table mctrl-tree-table">
   <colgroup><col class="mctrl-tree-col-name"/><col class="mctrl-tree-col-status"/><col class="mctrl-tree-col-progress"/><col class="mctrl-tree-col-unprocessed"/><col class="mctrl-tree-col-errors"/><col class="mctrl-tree-col-warnings"/><col class="mctrl-tree-col-completeness"/><col class="mctrl-tree-col-action"/><col class="mctrl-tree-col-backups"/></colgroup>
   <thead><tr>
    <th scope="col">Перенос / сервис / сущность</th>
    <th scope="col">Статус</th>
    <th scope="col">Обработано / всего</th>
    <th scope="col">Без результата</th>
    <th scope="col">Ошибки</th>
    <th scope="col">Предупреждения</th>
    <th scope="col">Полнота</th>
    <th scope="col">Действия</th><th scope="col">Точки отката</th>
   </tr></thead>
   <tbody>
    <tr class="mctrl-tree-root">
     <th scope="row" class="mctrl-tree-label">
      <div class="mctrl-tree-node mctrl-tree-node--root">
       <UiTreeToggle :expanded="rootOpen" label="Развернуть весь перенос" @click="rootOpen=!rootOpen"/>
       <span class="mctrl-tree-title">Весь перенос <small>#{{operation.id}}</small></span>
      </div>
     </th>
     <td><UiButton variant="ghost" compact @click="open('overview')"><UiBadge :tone="tone(operation.status)">{{statusText(operation.status)}}</UiBadge></UiButton></td>
     <td class="mctrl-tree-numeric mctrl-tree-progress"><div v-if="tree.processed!==null&&tree.total!==null">{{progressText(tree.processed,tree.total).replace('Обработано: ','')}}</div><span v-else class="mctrl-tree-no-data" title="У одного или нескольких сервисов нет подтверждённых счётчиков">—</span><progress v-if="bar(tree)!==null" :value="bar(tree)" max="100" aria-label="Прогресс всего переноса" :title="bar(tree)+'%'">{{bar(tree)}}%</progress></td>
     <td class="mctrl-tree-numeric"><UiButton variant="ghost" compact @click="open('overview')">{{tree.filtered?(tree.expected_unprocessed===null?'Неизвестно':text(tree.expected_unprocessed)):text(report?.coverage?.expected_unprocessed_rows)}}</UiButton></td>
     <td class="mctrl-tree-numeric"><UiButton v-if="unverifiedReport||tree.issues.errors===null" variant="ghost" compact @click="open('issues',null,'error')"><UiBadge tone="danger">Неизвестно</UiBadge></UiButton><UiButton v-else-if="tree.issues.errors" variant="ghost" compact @click="open('issues',null,'error')"><UiBadge tone="danger">{{tree.issues.errors}}</UiBadge></UiButton><span v-else class="mctrl-tree-no-data">—</span></td>
     <td class="mctrl-tree-numeric"><UiButton v-if="unverifiedReport||tree.issues.warnings===null" variant="ghost" compact @click="open('issues',null,'warning')"><UiBadge tone="warning">Неизвестно</UiBadge></UiButton><UiButton v-else-if="tree.issues.warnings" variant="ghost" compact @click="open('issues',null,'warning')"><UiBadge tone="warning">{{tree.issues.warnings}}</UiBadge></UiButton><span v-else class="mctrl-tree-no-data">—</span></td>
     <td><UiButton variant="ghost" compact @click="open('overview')">{{!report?'Нет данных':report.coverage?.semantic_complete===true?'Подтверждена':'Не подтверждена'}}</UiButton></td>
     <td><div class="mctrl-tree-row-actions">
  <UiButton variant="ghost" compact @click="open('stages')">Этапы</UiButton>
  <span v-if="tree.hidden_count" class="mctrl-tree-visual-note">Скрыто: {{tree.hidden_count}}</span>
  <span class="mctrl-last-write" :class="{'mctrl-last-write--stale':lastWrite('all').stale}" :title="lastWrite('all').title">{{lastWrite('all').label}}</span>
 </div></td><td><div class="mctrl-tree-backup-actions">
  <UiButton variant="secondary" compact :disabled="busy" aria-label="Создать точку отката: Все сервисы" @click="emit('snapshot','all')">Создать</UiButton>
  <UiButton variant="secondary" compact aria-label="Откат к точке: Все сервисы" @click="emit('restore','all')">Откат</UiButton>
 </div></td>
    </tr>
    <template v-if="true">
     <template v-for="svc in tree.services" :key="svc.service">
      <tr class="mctrl-tree-service">
       <th scope="row" class="mctrl-tree-label">
        <div class="mctrl-tree-node mctrl-tree-node--service">
         <UiTreeToggle :expanded="!!servicesOpen[svc.service]" :label="'Развернуть '+svc.label" @click="toggle(svc.service)"/>
         <strong class="mctrl-tree-title">{{svc.label}}</strong>
         <UiButton variant="ghost" compact :aria-label="'Настройки подключения: '+svc.label"
           :title="'Настройки подключения · '+svc.label" @click="emit('settings',svc.service)"><UiIcon name="settings"/></UiButton>
        </div>
       </th>
       <td><UiButton variant="ghost" compact @click="open('service',svc)"><UiBadge :tone="tone(svc.status)">{{statusText(svc.status)}}</UiBadge></UiButton></td>
       <td class="mctrl-tree-numeric mctrl-tree-progress"><div>{{progressText(svc.processed,svc.total).replace('Обработано: ','')}}</div><progress v-if="bar(svc)!==null" :value="bar(svc)" max="100" :aria-label="'Прогресс сервиса '+svc.label" :title="bar(svc)+'%'">{{bar(svc)}}%</progress><small v-if="svc.hidden_count">По видимым сущностям</small></td>
       <td class="mctrl-tree-numeric"><UiButton variant="ghost" compact @click="open('service',svc)">{{svc.unknown_outcome_tables>0?(svc.expected_unprocessed>0?'≥ '+svc.expected_unprocessed:'Неизвестно'):text(svc.expected_unprocessed)}}</UiButton></td>
       <td class="mctrl-tree-numeric"><UiButton v-if="unverifiedReport||svc.issues.errors===null" variant="ghost" compact @click="open('issues',svc,'error')"><UiBadge tone="danger">Неизвестно</UiBadge></UiButton><UiButton v-else-if="svc.issues.errors" variant="ghost" compact @click="open('issues',svc,'error')"><UiBadge tone="danger">{{svc.issues.errors}}</UiBadge></UiButton><span v-else class="mctrl-tree-no-data">—</span></td>
       <td class="mctrl-tree-numeric"><UiButton v-if="unverifiedReport||svc.issues.warnings===null" variant="ghost" compact @click="open('issues',svc,'warning')"><UiBadge tone="warning">Неизвестно</UiBadge></UiButton><UiButton v-else-if="svc.issues.warnings" variant="ghost" compact @click="open('issues',svc,'warning')"><UiBadge tone="warning">{{svc.issues.warnings}}</UiBadge></UiButton><span v-else class="mctrl-tree-no-data">—</span></td>
       <td><UiButton variant="ghost" compact @click="open('service',svc)">{{svc.evidence_status==='full'?'Полная':svc.evidence_status==='partial'?'Частичная':'Неизвестно'}}</UiButton></td>
       <td><div class="mctrl-tree-row-actions">
  <UiButton variant="secondary" compact :disabled="busy" :aria-label="'Запустить перенос: '+svc.label" @click="emit('retry-service',svc.service)">Запустить</UiButton>
  <UiButton v-if="svc.hidden_count" variant="ghost" compact :aria-label="'Скрытые строки: '+svc.label" @click="showHidden(svc.service)">Скрытые ({{svc.hidden_count}})</UiButton>
  <span class="mctrl-last-write" :class="{'mctrl-last-write--stale':lastWrite(svc.service).stale}" :title="lastWrite(svc.service).title">{{lastWrite(svc.service).label}}</span>
 </div></td><td><div class="mctrl-tree-backup-actions">
  <UiButton variant="secondary" compact :disabled="busy" :aria-label="'Создать точку отката: '+svc.label" @click="emit('snapshot',svc.service)">Создать</UiButton>
  <UiButton variant="secondary" compact :aria-label="'Откат к точке: '+svc.label" @click="emit('restore',svc.service)">Откат</UiButton>
 </div></td>
      </tr>
      <template v-if="rootOpen&&servicesOpen[svc.service]">
       <tr v-for="entity in svc.entities" :key="entity.id" class="mctrl-tree-entity">
        <th scope="row" class="mctrl-tree-label">
         <div class="mctrl-tree-node mctrl-tree-node--entity">
          <span class="mctrl-tree-indent" aria-hidden="true"></span>
          <span class="mctrl-tree-title">{{entity.label}}<small>{{statusText(entity.expectation?.status)}}</small></span>
         </div>
        </th>
        <td><UiButton variant="ghost" compact @click="open('entity',entity)"><UiBadge :tone="entity.state==='recorded'?'success':'neutral'">{{entity.state==='recorded'?'Результат есть':'Неизвестно'}}</UiBadge></UiButton></td>
        <td class="mctrl-tree-numeric mctrl-tree-progress"><div>{{progressText(entity.processed,entity.total).replace('Обработано: ','')}}</div><progress v-if="bar(entity)!==null" :value="bar(entity)" max="100" :aria-label="'Прогресс сущности '+entity.label" :title="bar(entity)+'%'">{{bar(entity)}}%</progress></td>
        <td class="mctrl-tree-numeric"><UiButton v-if="entity.source_outcomes?.unprocessed!==null&&entity.source_outcomes?.unprocessed!==undefined" variant="ghost" compact @click="open('entity',entity)">{{text(entity.source_outcomes.unprocessed)}}</UiButton><span v-else class="mctrl-tree-no-data">—</span></td>
        <td class="mctrl-tree-numeric"><UiButton v-if="entity.issues.errors===null" variant="ghost" compact @click="open('issues',entity,'error')"><UiBadge tone="danger">Неизвестно</UiBadge></UiButton><UiButton v-else-if="entity.issues.errors" variant="ghost" compact @click="open('issues',entity,'error')"><UiBadge tone="danger">{{entity.issues.errors}}</UiBadge></UiButton><span v-else class="mctrl-tree-no-data">—</span></td>
        <td class="mctrl-tree-numeric"><UiButton v-if="entity.issues.warnings===null" variant="ghost" compact @click="open('issues',entity,'warning')"><UiBadge tone="warning">Неизвестно</UiBadge></UiButton><UiButton v-else-if="entity.issues.warnings" variant="ghost" compact @click="open('issues',entity,'warning')"><UiBadge tone="warning">{{entity.issues.warnings}}</UiBadge></UiButton><span v-else class="mctrl-tree-no-data">—</span></td>
        <td><UiButton variant="ghost" compact @click="open('entity',entity)">{{entity.inventory_complete&&entity.state!=='unknown'?'Подтверждена':'Не подтверждена'}}</UiButton></td>
        <td><div class="mctrl-tree-row-actions"><UiButton variant="ghost" compact @click="open('fields',entity)">Поля · {{entity.fields?.length??0}}</UiButton><UiButton variant="ghost" compact :aria-label="'Скрыть строку '+svc.label+' / '+entity.label" @click="setHidden(svc.service,entity.table_name,true)">Скрыть</UiButton></div></td><td class="mctrl-tree-no-data">—</td>
       </tr>
       <tr v-if="!svc.entities.length" class="mctrl-tree-empty"><td colspan="9">{{svc.hidden_count?'Все '+svc.hidden_count+' строки скрыты. Откройте «Скрытые», чтобы вернуть их.':'Сущности сервиса '+svc.label+' не подтверждены отчётом.'}}</td></tr>
      </template>
     </template>
     <tr v-for="svc in missingServices" :key="'connection:'+svc.service" class="mctrl-tree-settings-only">
      <th scope="row" class="mctrl-tree-label"><div class="mctrl-tree-node mctrl-tree-node--service">
       <strong class="mctrl-tree-title">{{svc.label}}</strong>
       <UiButton variant="ghost" compact :aria-label="'Настройки подключения: '+svc.label"
         :title="'Настройки подключения · '+svc.label" @click="emit('settings',svc.service)"><UiIcon name="settings"/></UiButton>
      </div></th>
      <td class="mctrl-tree-no-data" title="Не участвует в выбранной операции">Вне операции</td>
      <td class="mctrl-tree-no-data">—</td>
      <td class="mctrl-tree-no-data">—</td>
      <td class="mctrl-tree-no-data">—</td>
      <td class="mctrl-tree-no-data">—</td>
      <td class="mctrl-tree-no-data">Не входит</td>
      <td><span class="mctrl-last-write" :class="{'mctrl-last-write--stale':lastWrite(svc.service).stale}" :title="lastWrite(svc.service).title">{{lastWrite(svc.service).label}}</span></td><td><div class="mctrl-tree-backup-actions">
  <UiButton variant="secondary" compact :disabled="busy" :aria-label="'Создать точку отката: '+svc.label" @click="emit('snapshot',svc.service)">Создать</UiButton>
  <UiButton variant="secondary" compact :aria-label="'Откат к точке: '+svc.label" @click="emit('restore',svc.service)">Откат</UiButton>
 </div></td>
     </tr>
    </template>
   </tbody>
  </table>
 </div>
</section>

<UiDrawer :open="!!hiddenPanel" :title="hiddenPanelTitle" width="560px" @close="hiddenPanel=null">
 <div class="mctrl-drawer-content mctrl-hidden-list">
  <p>Это только настройка отображения. История переноса, исходные данные и результаты миграции не изменяются.</p>
  <div class="mctrl-hidden-list-header"><strong>Скрытые сущности ({{hiddenRows.length}})</strong><UiButton v-if="hiddenRows.length" variant="secondary" compact @click="revealAll(hiddenPanel)">Показать все</UiButton></div>
  <div v-for="entity in hiddenRows" :key="entity.id" class="mctrl-hidden-item">
   <span>{{entity.label}}</span>
   <UiButton variant="secondary" compact :aria-label="'Показать строку '+entity.label" @click="setHidden(hiddenPanel,entity.table_name,false)">Показать</UiButton>
  </div>
  <p v-if="!hiddenRows.length">Скрытых строк в выбранной операции нет.</p>
 </div>
</UiDrawer>

<UiDrawer :open="!!detail" :title="detailTitle" width="760px" @close="close">
 <div class="mctrl-drawer-content">
 <UiPanel v-if="detail?.kind==='overview'">
  <p><strong>Статус:</strong> {{statusText(operation.status)}} · исход данных {{statusText(operation.data_outcome)}}</p>
  <p><strong>Этап:</strong> {{serviceTitles[operation.last_service||operation.current_service]||text(operation.current_service)}} · {{stageTitles[operation.last_phase||operation.phase]||text(operation.phase)}}</p>
  <p><strong>Начало:</strong> {{text(operation.started_at)}} · <strong>конец:</strong> {{text(operation.finished_at)}}</p>
  <p><strong>Сообщение:</strong> {{text(operation.message)}}</p>
  <p><strong>Полнота:</strong> {{report?.coverage?.semantic_complete===true?'Подтверждена':'Не подтверждена'}}; архив {{report?.archive_complete===true?'полный':'неполный / неизвестен'}}.</p>
  <p><strong>Без результата:</strong> ожидаемые {{text(report?.coverage?.expected_unprocessed_rows)}}, исключённые {{text(report?.coverage?.excluded_unprocessed_rows)}}, неописанные {{text(report?.coverage?.undocumented_unprocessed_rows)}}.</p>
  <p v-if="tree.filtered"><strong>Отображение:</strong> скрыто {{tree.hidden_count}} сущностей. Суммы в таблице пересчитаны по видимым строкам; показатели исходного отчёта выше не менялись.</p>
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
  <p v-if="detail.node.hidden_count"><strong>Фильтр отображения:</strong> {{detail.node.hidden_count}} скрыто, суммы рассчитаны по {{detail.node.visible_count}} видимым сущностям. Этап и статус исходного переноса не изменены.</p>
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
  <table class="irlix-data-table"><thead><tr><th>Поле / тип</th><th>Заполнено / NULL / всего</th><th>Правило / цель</th><th>Значения</th></tr></thead><tbody>
   <tr v-for="(f,i) in detail.node.fields||[]" :key="i">
    <td>{{f.name}}<small>{{text(f.type)}}</small></td>
    <td>{{text(f.filled)}} / {{text(f.null_count)}} / {{text(detail.node.total)}}</td>
    <td>{{statusText(f.mapping?.fate)}} · {{text(f.mapping?.target)}}<p>{{text(f.mapping?.rule)}} · {{text(f.mapping?.reason)}}</p></td>
    <td><details>
     <summary>{{f.values?.length??0}} наблюдаемых значений{{f.values_complete===false?' · распределение неполное':''}}</summary>
     <p v-for="(v,j) in f.values||[]" :key="j">{{text(v.value)}}: {{text(v.count)}} · цель: {{text(v.interpretation?.target??v.mapping?.target)}} · {{(v.interpretation?.known??v.mapping?.known)===true?'Правило задано':(v.interpretation?.known??v.mapping?.known)===false?'Значение не описано':'Не проверено'}} · {{text(v.interpretation?.rule??v.mapping?.rule)}}</p>
     <p v-if="f.declared_values?.length"><strong>Заявленные статусы источника</strong></p>
     <p v-for="(v,j) in f.declared_values||[]" :key="'declared-'+j">{{text(v.value)}} → {{text(v.interpretation?.target)}} · {{v.interpretation?.known===true?'Правило задано':v.interpretation?.known===false?'Не описано':'Не проверено'}} · {{text(v.interpretation?.rule)}}</p>
     <p>Полнота справочника: {{f.declared_values_complete===true?'Подтверждена':'Не подтверждена'}}.</p>
    </details></td>
   </tr>
   <tr v-if="!detail.node.fields?.length"><td colspan="4">Нет подтверждённых данных полей.</td></tr>
  </tbody></table>
 </div>
 <template v-else-if="detail?.kind==='issues'">
  <p>Диагностические события операции #{{operation.id}}. Группы и затронутые строки нельзя складывать как уникальные строки.</p>
  <p v-if="(full||report)?.runs?.some(r=>r.diagnostics?.items_complete===false)" class="mctrl-blocked-reason">
    Подробные события показаны частично (не более 256 на этап). Полный набор безопасных записей сохранён в неизменяемом серверном архиве; счётчики и группы причин не сокращены.
  </p>
  <UiLoader v-if="loading" show-label label="Загружаем подробные события…"/>
  <p v-if="problem" role="alert">{{problem}}. Группы доступны ниже.</p>
  <UiPanel v-for="(r,i) in items" :key="'item-'+i" class="mctrl-tree-issue">
   <header><strong>{{text(r.code)}} · {{text(r.table||r.entity_type)}}</strong><UiBadge :tone="r.severity==='error'?'danger':'warning'">{{text(r.severity)}}</UiBadge></header>
   <p>Источник #{{text(r.legacy_id)}} · {{serviceTitles[r.service]||r.service}} · {{stageTitles[r.stage]||text(r.stage)}} · run #{{text(r.run_id)}}</p>
   <p>{{text(r.message)}}</p>
   <UiButton v-if="r.id&&r.entity_type==='users'&&r.severity==='error'&&['vacations','clients'].includes(r.service)"
     variant="secondary" compact :disabled="busy" @click="identityConflict=r">Сопоставить пользователя</UiButton>
   <UiButton v-if="r.id&&r.entity_type==='employment'&&r.service==='employees'" variant="secondary" compact
     @click="showEmployment(r)">Подробности периода</UiButton>
   <details v-if="r.context&&Object.keys(r.context).length"><summary>Контекст</summary><pre>{{JSON.stringify(r.context,null,2)}}</pre></details>
  </UiPanel>
  <p v-if="!loading&&!items.length">Построчные события недоступны. Ниже — сгруппированная диагностика.</p>
  <UiPanel v-for="(g,i) in groups" :key="'group-'+i" class="mctrl-tree-issue">
   <header><strong>{{text(g.code)}} · {{text(g.table)}}</strong><UiBadge :tone="g.severity==='error'?'danger':'warning'">{{text(g.severity)}}</UiBadge></header>
   <p>{{text(g.message)}} · событий {{text(g.count)}} · затронуто строк {{text(g.affected_rows)}}</p>
   <details v-if="g.examples?.length"><summary>Примеры</summary><pre>{{JSON.stringify(g.examples,null,2)}}</pre></details>
  </UiPanel>
  <p v-if="!groups.length">Диагностических групп нет. Это не является доказательством отсутствия проблем.</p>
  <template v-for="(impact,i) in report?.dependency_impacts||[]" :key="'dependency-'+i">
   <UiPanel v-if="(!detail.node?.service||impact.dependency_service===detail.node.service)&&(!detail.node?.table_name||impact.dependency_table===detail.node.table_name)" class="mctrl-tree-issue">
    <strong>Зависимость: {{serviceTitles[impact.dependency_service]||impact.dependency_service}} / {{text(impact.dependency_table)}} / #{{text(impact.dependency_legacy_id)}}</strong>
    <p>Заблокировано уникальных строк: {{text(impact.blocked_unique)}} · зависимые таблицы: {{(impact.dependent_tables||[]).join(', ')||'Нет данных'}}</p>
    <p>{{text(impact.cause)}} · {{impact.root_status==='matched_immediate_diagnostic'?'Сопоставлено с непосредственной диагностикой':'Первопричина не подтверждена'}}</p>
   </UiPanel>
  </template>

 </template>
 </div>
</UiDrawer>
<MigrationIdentityMapping :auth="auth" :conflict="identityConflict" :busy="busy"
    @close="identityConflict=null" @saved="identityConflict=null"/>
<UiDrawer :open="!!employmentConflict" title="Подробности периода работы" width="560px"
  @close="employmentConflict=null">
  <UiLoader v-if="employmentLoading" show-label label="Читаем сведения о периоде…"/>
  <p v-if="employmentError" role="alert">{{employmentError}}</p>
  <p v-if="employmentInfo">Источник: {{employmentInfo.basis==='recorded'?'архивные данные':'текущее read-only состояние'}}</p>
  <pre v-if="employmentInfo" class="mctrl-employment-details">{{JSON.stringify(employmentInfo.details,null,2)}}</pre>
</UiDrawer>
</template>

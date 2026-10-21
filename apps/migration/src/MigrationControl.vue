<script setup>
import {computed,onBeforeUnmount,onMounted,ref,watch} from 'vue';
import {UiBadge,UiButton,UiDrawer,UiPanel,UiPageHeader,UiSearchSelect} from '@irlix/ui';
import {
  causeGroups,dateText,isActive,reportRuns,serviceEvidenceRows,serviceTitles,stageRows,stageTitles,
  statusText,tableEvidenceRows,text,tone,
} from './migration-control-model.js';
import './migration-control.css';

const props=defineProps({auth:{type:Object,required:true}});
defineEmits(['settings']);

const state=ref({history:[]});
const report=ref(null);
const historyId=ref('');
const service=ref('all');
const drawer=ref('');
const error=ref('');
const reportError=ref('');
const notice=ref('');
const offline=ref(false);
const loading=ref(true);
const pending=ref(false);
const reason=ref('');
const launchScope=ref('all');

const selected=computed(()=>historyId.value
  ? state.value.history.find(op=>String(op.id)===historyId.value)
  : state.value.operation);
const operation=computed(()=>isActive(selected.value)
  ? selected.value
  : report.value&&String(report.value.operation?.id)===String(selected.value?.id)
    ? report.value.operation
    : selected.value);
const selectedReport=computed(()=>String(report.value?.operation?.id)===String(selected.value?.id)?report.value:null);
const services=computed(()=>[{value:'all',label:'Все сервисы'},...Object.entries(serviceTitles)
  .filter(([key])=>key!=='all').map(([value,label])=>({value,label}))]);
const historyOptions=computed(()=>[{value:'',label:'Текущая операция'},...state.value.history.map(op=>({
  value:String(op.id),
  label:'#'+op.id+' · '+dateText(op.started_at)+' · '+statusText(op.status)+' · '+(serviceTitles[op.scope]||op.scope),
}))]);
const groups=computed(()=>causeGroups(selectedReport.value,service.value));
const tables=computed(()=>tableEvidenceRows(selectedReport.value,service.value));
const serviceEvidence=computed(()=>serviceEvidenceRows(selectedReport.value,operation.value,service.value));
const stages=computed(()=>stageRows(operation.value,selectedReport.value,service.value));
const inventoryCompleteness=computed(()=>{
  const items=(selectedReport.value?.inventory||[]).filter(Boolean);
  return !items.length?'Не подтверждена':items.some(item=>item.complete===false)?'Неполная':items.every(item=>item.complete===true)?'Подтверждена':'Не подтверждена';
});
const stageProgress=computed(()=>{
  const rows=stages.value;
  return rows.length?{done:rows.filter(row=>row.status==='completed').length,total:rows.length}:null;
});
const busy=computed(()=>pending.value||offline.value||loading.value||isActive(state.value.operation)
  ||isActive(state.value.console_operation)||isActive(state.value.snapshot_operation)
  ||isActive(state.value.restore_status)||state.value.restore_status?.maintenance===true||state.value.active_runs?.length>0);
const restoreEvidence=computed(()=>(state.value.restore_history||[])
  .filter(item=>service.value==='all'||item.scope==='all'||item.scope===service.value));
const infrastructureHistory=computed(()=>(state.value.console_history||[])
  .filter(item=>['restore','snapshot'].includes(item.action)&&(service.value==='all'||item.scope==='all'||item.scope===service.value)));
const matchingRestore=computed(()=>restoreEvidence.value.find(item=>
  String(operation.value?.snapshot_ids?.[item.scope]??'')===String(item.snapshot_id)));
const chat=computed(()=>selectedReport.value?.chat_summary||'');

const outcomeLabels={
  domain_written:'Записано в домен',
  reference_matched:'Сопоставлено',
  metadata_only:'Только metadata',
  deferred:'Отложено',
  failed_unique:'Ошибка строки',
  unprocessed:'Без результата',
};
const expectationTone=value=>value==='expected'?'success':value==='undocumented'?'warning':'neutral';
const evidenceTone=value=>value==='full'?'success':value==='partial'?'warning':'neutral';
const fateSummary=row=>{
  const parts=Object.entries(row.field_fates||{}).filter(([,count])=>count).map(([key,count])=>statusText(key)+': '+count);
  return parts.join(' · ')||'Нет описанных полей';
};
const sourceOutcomeText=(row,key)=>text(row.source_outcomes?.[key]);
const writeProgress=item=>{
  if(item.write_processed===null&&item.write_total===null)return 'Нет данных';
  return text(item.write_processed)+' / '+text(item.write_total);
};

let timer,stopped=false,polling=false,reportSequence=0;
let reportLoadingId='';
const controllers=new Set();

async function api(path,options={}) {
  const controller=new AbortController();
  controllers.add(controller);
  const timeout=setTimeout(()=>controller.abort(),20000);
  try {
    const response=await props.auth.fetch('/api/migration'+path,{
      ...options,signal:controller.signal,cache:'no-store',
      headers:{Accept:'application/json','Content-Type':'application/json'},
    });
    let payload;
    try{payload=await response.json();}catch{throw new Error('Migration API: '+response.status);}
    if(!response.ok)throw new Error(payload.message||'Migration API: '+response.status);
    return payload.data??payload;
  } finally {
    clearTimeout(timeout);
    controllers.delete(controller);
  }
}
async function loadReport(force=false) {
  const id=String(selected.value?.id||'');
  if(!id){report.value=null;return;}
  if(reportLoadingId===id)return;
  if(!force&&String(report.value?.operation?.id)===id&&!isActive(selected.value)
    &&report.value.operation?.status===selected.value.status
    &&report.value.operation?.updated_at===selected.value.updated_at)return;
  const sequence=++reportSequence;
  reportLoadingId=id;
  reportError.value='';
  try {
    const result=await api('/control/operations/'+encodeURIComponent(id)+'/report');
    if(!stopped&&sequence===reportSequence&&id===String(selected.value?.id))report.value=result;
  } catch(e) {
    if(!stopped&&sequence===reportSequence)reportError.value=e.message;
  } finally {
    if(reportLoadingId===id)reportLoadingId='';
  }
}
async function refresh() {
  if(stopped||polling)return;
  polling=true;
  try {
    state.value=await api('/control/state');
    offline.value=false;
    error.value='';
    await loadReport();
  } catch(e) {
    if(!stopped){offline.value=true;error.value=e.message;}
  } finally {
    polling=false;
    loading.value=false;
    if(!stopped){
      clearTimeout(timer);
      timer=setTimeout(refresh,isActive(state.value.operation)?1500:5000);
    }
  }
}
watch(historyId,()=>{reportSequence++;reportError.value='';loadReport();});
async function mutate(path,body) {
  if(pending.value)return;
  pending.value=true;
  error.value='';
  notice.value='';
  try{
    const result=await api(path,{method:'POST',body:JSON.stringify(body)});
    if(result.operation)state.value={...state.value,operation:result.operation};
    historyId.value='';
    report.value=null;
    drawer.value='';
    notice.value=path.endsWith('cancel')
      ?'Запрошена остановка между этапами. Выполненные записи сохраняются.'
      :'Операция запущена.';
  }catch(e){error.value=e.message;}
  finally{pending.value=false;await refresh();}
}
function start(){if(!busy.value)mutate('/control/start',{scope:launchScope.value,confirm:true});}
function cancel(){
  if(!reason.value.trim()||pending.value||offline.value)return;
  mutate('/control/cancel',{operation_id:state.value.operation.id,reason:reason.value.trim()});
}
async function copy(){
  if(!chat.value)return;
  try{
    await navigator.clipboard.writeText(chat.value);
    notice.value='Диагностический текст скопирован.';
  }catch{drawer.value='copy';}
}
async function download(){
  if(!selected.value?.id||pending.value)return;
  const operationId=selected.value.id;
  const controller=new AbortController();
  controllers.add(controller);
  const timeout=setTimeout(()=>controller.abort(),30000);
  pending.value=true;
  try{
    const response=await props.auth.fetch('/api/migration/control/operations/'+encodeURIComponent(operationId)+'/report?download=1',{
      signal:controller.signal,headers:{Accept:'application/json'},cache:'no-store',
    });
    if(!response.ok)throw new Error('Не удалось скачать отчёт ('+response.status+')');
    const url=URL.createObjectURL(await response.blob());
    const link=document.createElement('a');
    link.href=url;
    link.download='migration-control-'+operationId+'.json';
    link.click();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
  }catch(e){error.value=e.message;}
  finally{
    clearTimeout(timeout);
    controllers.delete(controller);
    pending.value=false;
  }
}

onMounted(refresh);
onBeforeUnmount(()=>{
  stopped=true;
  reportSequence++;
  clearTimeout(timer);
  for(const controller of controllers)controller.abort();
});
</script>

<template>
<main class="mctrl" aria-label="Контроль переноса">
  <UiPageHeader title="Контроль переноса" description="Доказательства по сервисам, сущностям и этапам переноса без подмены неизвестного состояния нулём.">
    <template #actions>
      <div class="mctrl-actions">
        <UiButton variant="ghost" @click="$emit('settings')">Открыть пульт переноса</UiButton>
        <UiButton :disabled="busy" @click="drawer='start'">Новый перенос</UiButton>
        <UiButton v-if="isActive(state.operation)" variant="secondary" :disabled="pending||offline||state.operation.cancel_requested" @click="reason='';drawer='cancel'">
          {{state.operation.cancel_requested?'Остановка запрошена':'Остановить'}}
        </UiButton>
      </div>
    </template>
  </UiPageHeader>

  <div v-if="offline" class="mctrl-alert" role="alert">
    Нет связи с Migration. Показан последний полученный отчёт; актуальный статус не подтверждён. {{error}}
    <UiButton variant="ghost" compact @click="refresh">Повторить</UiButton>
  </div>
  <div v-else-if="error" class="mctrl-alert" role="alert">{{error}}</div>
  <p v-if="notice" role="status">{{notice}}</p>

  <div class="mctrl-filters">
    <label>Операция
      <UiSearchSelect v-model="historyId" :options="historyOptions" :clearable="false" aria-label="История операций"/>
    </label>
    <label>Сервис
      <UiSearchSelect v-model="service" :options="services" :clearable="false" aria-label="Фильтр сервиса"/>
    </label>
    <div class="mctrl-actions">
      <UiButton variant="secondary" :disabled="!chat" @click="copy">Скопировать диагностический текст</UiButton>
      <UiButton variant="secondary" :disabled="!selected||pending" @click="download">
        {{selectedReport?.archive_complete===false?'Скачать доступный JSON':'Скачать полный JSON'}}
      </UiButton>
    </div>
  </div>
  <p class="mctrl-muted">Диагностический текст и JSON относятся ко всей операции. Фильтр сервиса меняет только представление на экране.</p>
  <p role="status" aria-live="polite" class="mctrl-muted">
    {{operation?statusText(operation.status)+' · '+(stageTitles[operation.phase]||text(operation.phase)):'Операция не выбрана'}}
  </p>
  <p v-if="loading" role="status">Загружаем состояние переноса…</p>

  <UiPanel v-if="operation" class="mctrl-summary">
    <header>
      <strong>Операция #{{operation.id}}</strong>
      <UiBadge :tone="tone(operation.status)">{{statusText(operation.status)}}</UiBadge>
      <UiBadge v-if="historyId" tone="meta">Исторический отчёт</UiBadge>
    </header>
    <dl class="mctrl-facts">
      <div><dt>Область</dt><dd>{{serviceTitles[operation.scope]||text(operation.scope)}}</dd></div>
      <div><dt>Текущий / финальный этап</dt><dd>{{stageTitles[operation.phase]||text(operation.phase)}} · {{serviceTitles[operation.current_service]||text(operation.current_service)}}</dd></div>
      <div><dt>Начало / окончание</dt><dd>{{dateText(operation.started_at)}} / {{dateText(operation.finished_at)}}</dd></div>
      <div><dt>Результат данных</dt><dd>{{statusText(operation.data_outcome)}}</dd></div>
      <div><dt>Ревизия реализации / время отчёта</dt><dd>{{text(selectedReport?.implementation_revision||operation.implementation_revision||reportRuns(selectedReport).find(run=>run.implementation_revision)?.implementation_revision||selectedReport?.revision)}} / {{dateText(selectedReport?.generated_at||reportRuns(selectedReport)[0]?.generated_at)}}</dd></div>
      <div><dt>Точка отката</dt><dd>{{operation.snapshot_ids?Object.entries(operation.snapshot_ids).map(([key,value])=>(serviceTitles[key]||key)+': #'+value).join(' · ')||'Нет данных':'Нет данных'}}</dd></div>
    </dl>
    <div v-if="stageProgress" class="mctrl-overall">
      <label>Подтверждённо завершено этапов: {{stageProgress.done}} / {{stageProgress.total}}</label>
      <progress :value="stageProgress.done" :max="stageProgress.total" aria-label="Прогресс этапов операции"/>
    </div>
    <p>Архив отчёта: {{selectedReport?.archive_complete===true?'Все зарегистрированные этапы подтверждены файлами':selectedReport?.archive_complete===false?'Неполный — есть отсутствующий или несоответствующий архив этапа':'Полнота не подтверждена'}}. Инвентаризация: {{inventoryCompleteness}}.</p>
    <p v-if="selectedReport?.unarchived_runs?.length">
      Неподтверждённые этапы:
      {{selectedReport.unarchived_runs.map(item=>(item.service||'')+' '+(item.mode||'')+' #'+(item.id||item.run_id||'')+' ('+(item.reason||'нет archive_key')+')').join('; ')}}.
    </p>
    <p v-if="selectedReport?.coverage">
      Покрытие: {{selectedReport.coverage.semantic_complete===true?'подтверждено':'не подтверждено'}}
      · строки без результата: {{text(selectedReport.coverage.unprocessed_rows)}}
      · таблицы с неизвестным итогом: {{text(selectedReport.coverage.unknown_table_totals)}}
      · failed unique: {{text(selectedReport.coverage.failed_unique_rows)}}
      · неописанные поля: {{text(selectedReport.coverage.field_fates?.unmapped)}}.
    </p>
    <p>{{operation.message||'Сообщение операции не зарегистрировано.'}}</p>
    <p v-if="operation.cancel_reason"><strong>Причина остановки:</strong> {{operation.cancel_reason}}</p>
    <p v-if="['cancelled','partial','failed','interrupted'].includes(operation.status)">
      Остановка не означает откат. Уже записанные данные сохраняются, пока отдельная операция восстановления не докажет обратное.
    </p>
    <p>
      Последующий откат по точке этой операции:
      {{matchingRestore?(matchingRestore.confirmed?'Подтверждён: база, metadata и схема проверены':'Не подтверждён'):'Нет данных'}}
      <span v-if="matchingRestore"> · восстановление #{{matchingRestore.operation_id}} · {{dateText(matchingRestore.started_at)}}</span>.
    </p>
  </UiPanel>
  <p v-else-if="!loading&&!offline">Перенос ещё не запускался. Настройте подключения в пульте и начните новый перенос.</p>

  <div v-if="reportError" role="alert" class="mctrl-alert">
    Отчёт недоступен: {{reportError}}
    <UiButton compact variant="ghost" @click="loadReport(true)">Повторить загрузку отчёта</UiButton>
  </div>

  <template v-if="operation">
    <section class="mctrl-section" aria-labelledby="mctrl-services-title">
      <header class="mctrl-section__header">
        <div><h2 id="mctrl-services-title">Сводка по сервисам</h2><p>Сначала — место остановки, состояние записи и полнота доказательств.</p></div>
      </header>
      <div class="mctrl-table" tabindex="0" role="region" aria-label="Сводка по сервисам">
        <table class="irlix-data-table">
          <thead><tr><th>Сервис</th><th>Статус / этап</th><th>Запись</th><th>Таблицы</th><th>Проблемы</th><th>Доказательства</th></tr></thead>
          <tbody>
            <tr v-for="item in serviceEvidence" :key="item.service">
              <td><strong>{{serviceTitles[item.service]||item.service}}</strong></td>
              <td><UiBadge :tone="tone(item.status)">{{statusText(item.status)}}</UiBadge><br/>{{item.stage?stageTitles[item.stage]||item.stage:'Точный этап не подтверждён'}}<span v-if="item.stage_status"> · {{statusText(item.stage_status)}}</span></td>
              <td>{{statusText(item.write_state)}} · {{writeProgress(item)}}</td>
              <td>ожидаются: {{item.expected_tables}} · исключены: {{item.excluded_tables}} · решение не описано: {{item.undocumented_tables}}<br/>без результата среди ожидаемых: {{item.expected_unprocessed}} · неизвестный итог: {{item.unknown_outcome_tables}}</td>
              <td>{{item.problem_groups}} групп причин</td>
              <td><UiBadge :tone="evidenceTone(item.evidence_status)">{{item.evidence_status==='full'?'Полные':item.evidence_status==='partial'?'Частичные':'Не подтверждены'}}</UiBadge></td>
            </tr>
            <tr v-if="!serviceEvidence.length"><td colspan="6">Нет подтверждённых данных по сервисам.</td></tr>
          </tbody>
        </table>
      </div>
      <UiPanel v-for="item in serviceEvidence.filter(row=>row.status==='failed'||row.status==='partial'||row.evidence_status!=='full')" :key="'evidence-'+item.service" class="mctrl-evidence">
        <header><strong>{{serviceTitles[item.service]||item.service}} · диагностический контекст</strong><UiBadge :tone="tone(item.status)">{{statusText(item.status)}}</UiBadge></header>
        <p><strong>Точный этап:</strong> {{item.stage?stageTitles[item.stage]||item.stage:'не подтверждён'}}. <strong>Состояние записи:</strong> {{statusText(item.write_state)}}.</p>
        <p><strong>Причина:</strong> {{text(item.reason)}}</p>
        <p><strong>Последствия:</strong> {{text(item.consequence)}}</p>
        <p><strong>Полнота доказательств:</strong> {{item.evidence_status==='full'?'полная':item.evidence_status==='partial'?'частичная':'не подтверждена'}}. Неизвестное состояние не трактуется как 0 или как выполненный откат.</p>
      </UiPanel>
    </section>

    <section class="mctrl-section" aria-labelledby="mctrl-entities-title">
      <header class="mctrl-section__header">
        <div><h2 id="mctrl-entities-title">Сущности и полнота переноса</h2><p>Решение «ожидается / исключено / не описано» берётся из явных правил адаптера и инвентаризации, а не из пустых счётчиков.</p></div>
      </header>
      <div class="mctrl-table" tabindex="0" role="region" aria-label="Сводка по сущностям">
        <table class="irlix-data-table">
          <thead><tr><th>Сервис / таблица</th><th>Ожидание</th><th>Всего</th><th>Записано</th><th>Сопоставлено</th><th>Без результата</th><th>Ошибки строк</th><th>Полнота</th></tr></thead>
          <tbody>
            <tr v-for="row in tables" :key="row.service+':'+row.table_name">
              <td><strong>{{serviceTitles[row.service]||row.service}} / {{row.table_name}}</strong><br/><span class="mctrl-muted">{{row.problem_groups}} групп причин</span></td>
              <td><UiBadge :tone="expectationTone(row.expectation?.status)">{{statusText(row.expectation?.status)}}</UiBadge><br/><span class="mctrl-small">{{text(row.expectation?.reason)}}</span></td>
              <td>{{text(row.total)}}</td>
              <td>{{sourceOutcomeText(row,'domain_written')}}</td>
              <td>{{sourceOutcomeText(row,'reference_matched')}}</td>
              <td>{{sourceOutcomeText(row,'unprocessed')}}</td>
              <td>{{sourceOutcomeText(row,'failed_unique')}}</td>
              <td>{{row.state==='unknown'?'Итог записи неизвестен':'Outcome сохранён'}}<br/><span class="mctrl-muted">{{row.inventory_complete?'Инвентарь подтверждён':'Инвентарь неполный/не подтверждён'}}</span></td>
            </tr>
            <tr v-if="!tables.length"><td colspan="8">Нет данных по сущностям. Это не означает нулевой объём переноса.</td></tr>
          </tbody>
        </table>
      </div>
      <p class="mctrl-muted">Категории «записано», «сопоставлено», metadata, deferred и diagnostics могут пересекаться. Их нельзя складывать как число уникальных исходных строк.</p>
    </section>

    <section class="mctrl-section" aria-labelledby="mctrl-stages-title">
      <header class="mctrl-section__header">
        <div><h2 id="mctrl-stages-title">Этапы операции</h2><p>«Не начато» показывается только когда это следует из подтверждённой точки отказа. Отсутствующий этап без такого доказательства остаётся неизвестным.</p></div>
      </header>
      <div class="mctrl-table" tabindex="0" role="region" aria-label="Этапы операции">
        <table class="irlix-data-table">
          <thead><tr><th>Сервис</th><th>Этап</th><th>Статус</th><th>Обработано / всего</th><th>Начало / окончание</th></tr></thead>
          <tbody>
            <tr v-for="row in stages" :key="row.service+row.mode">
              <td>{{serviceTitles[row.service]||row.service}}</td>
              <td>{{stageTitles[row.mode]}}</td>
              <td><UiBadge :tone="tone(row.status)">{{row.known?statusText(row.status):'Неизвестно — этап не зарегистрирован'}}</UiBadge></td>
              <td>
                {{text(row.run?.processed_count??row.run?.summary?.processed_count)}} / {{text(row.run?.total??row.run?.summary?.total)}}
                <progress v-if="row.run?.total>0&&row.run?.processed_count!=null" :value="row.run.processed_count" :max="row.run.total" :aria-label="'Прогресс '+(serviceTitles[row.service]||row.service)+': '+stageTitles[row.mode]"/>
              </td>
              <td>{{dateText(row.run?.started_at)}} / {{dateText(row.run?.finished_at)}}</td>
            </tr>
            <tr v-if="!stages.length"><td colspan="5">Нет данных об этапах.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <details class="mctrl-section mctrl-disclosure">
      <summary>Причины и зависимости · {{groups.length}}</summary>
      <p>Число диагностических событий и число затронутых строк — разные показатели. Примеры ограничены; полный JSON скачивается отдельно.</p>
      <UiPanel v-for="(group,index) in groups" :key="(group.service||'')+':'+(group.table||'')+':'+(group.code||'')+':'+index" class="mctrl-cause">
        <header><strong>{{text(group.code)}}</strong><UiBadge :tone="group.severity==='error'?'danger':'warning'">{{text(group.severity)}}</UiBadge></header>
        <p>{{serviceTitles[group.service]||text(group.service)}} · {{text(group.table||group.entity_type)}} · повторений: {{text(group.count)}} · строк: {{text(group.affected_rows)}}</p>
        <p>{{text(group.message)}}</p>
        <details><summary>Примеры и контекст</summary><pre>{{JSON.stringify(group.examples||group.items||'Нет данных',null,2)}}</pre></details>
      </UiPanel>
      <UiPanel v-for="(impact,index) in (selectedReport?.dependency_impacts||[]).filter(item=>service==='all'||item.dependency_service===service)" :key="'impact-'+index" class="mctrl-cause">
        <strong>Влияние зависимости: {{serviceTitles[impact.dependency_service]||text(impact.dependency_service)}} / {{text(impact.dependency_table)}} / {{text(impact.dependency_legacy_id)}}</strong>
        <p>Заблокировано уникальных строк: {{text(impact.blocked_unique)}} · зависимые таблицы: {{(impact.dependent_tables||[]).join(', ')||'Нет данных'}}.</p>
        <p>{{text(impact.cause)}} · {{impact.root_status==='matched_immediate_diagnostic'?'Сопоставлено с диагностикой непосредственной зависимости':'Причина зависимости не подтверждена'}}.</p>
      </UiPanel>
      <p v-if="!groups.length">Причины по выбранному сервису не зарегистрированы. Это не доказывает отсутствие неполноты.</p>
    </details>

    <section class="mctrl-section" aria-labelledby="mctrl-fields-title">
      <header class="mctrl-section__header">
        <div><h2 id="mctrl-fields-title">Детали полей по таблицам</h2><p>Плоский список скрыт: сначала видна полнота каждой таблицы, поля раскрываются только по запросу.</p></div>
      </header>
      <details v-for="row in tables" :key="'fields-'+row.service+':'+row.table_name" class="mctrl-table-detail">
        <summary>
          <strong>{{serviceTitles[row.service]||row.service}} / {{row.table_name}}</strong>
          <span>{{fateSummary(row)}} · {{row.fields.length}} полей</span>
        </summary>
        <p><strong>Решение по таблице:</strong> {{statusText(row.expectation?.status)}}. {{text(row.expectation?.reason)}}</p>
        <p v-if="row.unavailable_reason" role="status">Инвентаризация неполная: {{row.unavailable_reason}}</p>
        <div class="mctrl-table" tabindex="0" role="region" :aria-label="'Поля '+row.table_name">
          <table class="irlix-data-table">
            <thead><tr><th>Поле</th><th>Тип</th><th>Заполнено / NULL / всего</th><th>Судьба / цель</th><th>Правило / причина</th><th>Значения</th></tr></thead>
            <tbody>
              <tr v-for="(field,index) in row.fields" :key="field.name+':'+index">
                <td><strong>{{field.name}}</strong></td>
                <td>{{text(field.type)}}</td>
                <td>{{text(field.filled)}} / {{text(field.null_count)}} / {{text(row.total)}}</td>
                <td>{{statusText(field.mapping?.fate)}} · {{text(field.mapping?.target)}}</td>
                <td>{{text(field.mapping?.rule)}}<br/>{{text(field.mapping?.reason)}}</td>
                <td>
                  <details>
                    <summary>{{Array.isArray(field.values)?field.values.length+' наблюдаемых значений':'Нет данных'}}{{field.values_complete===false?' · распределение неполное':''}}</summary>
                    <p v-for="(value,i) in field.values||[]" :key="i">{{text(value.value)}}: {{text(value.count)}} · цель: {{text(value.interpretation?.target??value.mapping?.target)}} · {{(value.interpretation?.known??value.mapping?.known)===false?'Неописанное значение':(value.interpretation?.known??value.mapping?.known)===true?'Правило задано':'Решение не подтверждено'}} · {{text(value.interpretation?.rule??value.mapping?.rule)}}</p>
                    <p v-if="field.declared_values?.length"><strong>Заявленные исходные статусы</strong></p>
                    <p v-for="(value,i) in field.declared_values||[]" :key="'declared-'+i">{{text(value.value)}} → {{text(value.interpretation?.target)}} · {{value.interpretation?.known===true?'Правило задано':value.interpretation?.known===false?'Неописанное значение':'Не проверено'}} · {{text(value.interpretation?.rule)}}</p>
                    <p>Полнота справочника статусов: {{field.declared_values_complete===true?'Подтверждена':'Не подтверждена'}}.</p>
                  </details>
                </td>
              </tr>
              <tr v-if="!row.fields.length"><td colspan="6">Нет подтверждённой инвентаризации полей.</td></tr>
            </tbody>
          </table>
        </div>
      </details>
      <p v-if="!tables.length">Нет данных об инвентаризации таблиц.</p>
    </section>

    <section v-if="restoreEvidence.length||infrastructureHistory.length" class="mctrl-section">
      <details class="mctrl-disclosure">
        <summary>Бэкапы и восстановления · отдельные доказательства отката</summary>
        <p>Эти события могли произойти после выбранного переноса. Исторический отчёт переноса не переписывается.</p>
        <p v-for="item in restoreEvidence" :key="item.operation_id">
          <strong>Восстановление #{{item.operation_id}} · {{serviceTitles[item.scope]||item.scope}} · точка #{{item.snapshot_id}}</strong>
          · {{dateText(item.started_at)}} · {{statusText(item.status)}}
          · {{item.confirmed?'Откат подтверждён: база, metadata и схема проверены':item.stages_completed?'Этапы восстановления завершены; данные независимо не проверены':'Откат не подтверждён'}}
          · {{text(item.database_outcome)}} · {{text(item.stage)}}
        </p>
        <p v-for="item in infrastructureHistory" :key="item.id">
          <strong>{{item.action==='restore'?'Восстановление':'Точка отката'}} #{{item.id}}</strong>
          · {{dateText(item.started_at)}} · {{statusText(item.status)}} · {{text(item.message)}}
        </p>
      </details>
    </section>
  </template>

  <UiDrawer :open="!!drawer" :title="drawer==='start'?'Новый перенос':drawer==='cancel'?'Остановка переноса':'Диагностический текст'" @close="drawer=''">
    <form v-if="drawer==='start'" class="mctrl-form" @submit.prevent="start">
      <p>Последовательно: точка отката, чтение, проверка, запись и проверка результата. Новый перенос допускается поверх частичных данных; автоматический откат не выполняется.</p>
      <label>Область переноса
        <UiSearchSelect v-model="launchScope" :options="services" :clearable="false" aria-label="Область запуска"/>
      </label>
      <p>Перед запуском проверьте подключения в старом пульте. В историю сохранятся результаты каждого этапа.</p>
      <UiButton type="submit" :disabled="busy">Подтвердить и запустить</UiButton>
    </form>
    <form v-if="drawer==='cancel'" class="mctrl-form" @submit.prevent="cancel">
      <p>Активный этап завершится; следующие этапы не начнутся. Выполненные записи останутся в базе. Откат выполняется отдельно через бэкапы.</p>
      <label class="irlix-field">Причина остановки<textarea v-model="reason" required maxlength="500" rows="4"/></label>
      <UiButton type="submit" :disabled="!reason.trim()||pending||offline">Остановить после этапа</UiButton>
    </form>
    <div v-if="drawer==='copy'">
      <p>Автокопирование недоступно. Выделите диагностический текст и скопируйте вручную.</p>
      <textarea class="mctrl-copy" readonly :value="chat" aria-label="Диагностический текст" @focus="$event.target.select()"/>
    </div>
  </UiDrawer>
</main>
</template>

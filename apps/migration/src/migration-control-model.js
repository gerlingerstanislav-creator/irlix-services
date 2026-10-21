export const serviceTitles = {all:'Все сервисы',employees:'Сотрудники',vacations:'Отпуска',clients:'Клиенты',timesheets:'Таймшиты'};
export const stageTitles = {snapshot:'Точка отката',inspect:'Чтение источника','dry-run':'Проверка переноса',migrate:'Запись данных',validate:'Проверка результата'};
export const statusTitles = {queued:'В очереди',running:'Выполняется',completed:'Завершено',partial:'Частично',failed:'Ошибка',cancelled:'Остановлено',interrupted:'Прервано',skipped:'Пропущено',pending:'Ожидает',not_started:'Не начато',changed:'Данные изменены',unknown:'Неизвестно',checked:'Проверено',metadata_only:'Только metadata',domain_written:'Перенесено',reference_matched:'Сопоставлено',deferred:'Отложено',unmapped:'Не описано',blocked:'Заблокировано',direct:'Прямое копирование',transformed:'Преобразовано',reference:'Сопоставленная ссылка',excluded:'Исключено с объяснением',expected:'Ожидается перенос',undocumented:'Решение не описано',recorded:'Результат подтверждён'};
export const text = value => value === null || value === undefined || value === '' ? 'Нет данных' : String(value);
export const statusText = value => statusTitles[value] || text(value);
export const isActive = operation => ['queued','running'].includes(operation?.status??operation?.state);
export const tone = value => ['completed','checked'].includes(value) ? 'success' : ['failed','interrupted'].includes(value) ? 'danger' : ['partial','cancelled','skipped'].includes(value) ? 'warning' : 'neutral';
export function dateText(value) { if(!value) return 'Нет данных'; const raw=String(value).replace(' ','T'); const normalized=/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(raw)?raw+'Z':raw; const date=new Date(typeof value==='number'?value*1000:normalized); return Number.isNaN(date.getTime())?text(value):date.toLocaleString('ru-RU'); }
export function reportRuns(report,service='all') { return (report?.runs || []).filter(run=>service==='all'||run.service===service); }
export function entityRows(report,service='all') { return reportRuns(report,service).filter(run=>run.mode==='migrate').flatMap(run=>(run.tables||[]).map(table=>({...table,service:run.service,run_id:run.run_id??run.id}))); }
export function inventoryRows(report,service='all') {
 const sources=(report?.inventory?.length ? report.inventory : reportRuns(report).map(run=>run.inventory)).filter(Boolean);
 const inventories=[...new Map(sources.map(item=>[item.service,item])).values()];
 return inventories.filter(item=>service==='all'||item.service===service).flatMap(item=>(item.tables||[]).flatMap(table=>(table.columns||[]).map(column=>({...column,service:item.service,table:table.schema&&table.schema!=='public'?`${table.schema}.${table.name||table.table_name}`:(table.name||table.table_name),complete:item.complete,table_total:table.total}))));
}
function inventoryTableName(table) {
 return table?.schema && table.schema!=='public' ? `${table.schema}.${table.name||table.table_name}` : (table?.name||table?.table_name);
}
function expectationFromFates(fates,fieldCount) {
 const domain=['direct','transformed','reference'].reduce((sum,key)=>sum+(fates[key]||0),0), unmapped=fates.unmapped||0;
 if(domain>0) return {status:'expected',domain_write_expected:true,decision_complete:unmapped===0,
  reason:unmapped===0?'Есть проверенные правила доменного переноса. Это не означает, что каждая строка обязана стать отдельной целевой записью.':`Есть правила доменного переноса, но ${unmapped} полей остаются без утверждённого решения.`,fates};
 if(unmapped>0||!fieldCount) return {status:'undocumented',domain_write_expected:null,decision_complete:false,reason:unmapped>0?`${unmapped} полей не имеют утверждённого решения; нулевые счётчики не доказывают исключение.`:'Решение о переносе таблицы не описано.',fates};
 const nonDomain=['metadata_only','deferred','excluded'].reduce((sum,key)=>sum+(fates[key]||0),0);
 if(nonDomain===fieldCount) return {status:'excluded',domain_write_expected:false,decision_complete:true,reason:'Все описанные поля сознательно отнесены вне доменной записи; причины доступны в деталях полей.',fates};
 return {status:'undocumented',domain_write_expected:null,decision_complete:false,reason:'Решение о переносе таблицы не описано.',fates};
}
export function tableEvidenceRows(report,service='all') {
 const rows=new Map();
 const inventories=(report?.inventory||[]).filter(Boolean);
 for(const inventory of inventories) {
  if(service!=='all'&&inventory.service!==service) continue;
  for(const table of inventory.tables||[]) {
   const name=inventoryTableName(table); if(!name) continue;
   const fates={};
   for(const field of table.columns||[]) { const fate=field.mapping?.fate||'unmapped'; fates[fate]=(fates[fate]||0)+1; }
   rows.set(`${inventory.service}:${name}`,{
    service:inventory.service,table_name:name,total:table.total,inventory_complete:inventory.complete===true&&table.unavailable_reason==null,
    unavailable_reason:table.unavailable_reason||inventory.unavailable_reason||null,
    expectation:table.expectation||expectationFromFates(fates,(table.columns||[]).length),
    field_fates:fates,fields:table.columns||[],source_outcomes:null,state:'unknown',problem_groups:0,problem_codes:[],
   });
  }
 }
 for(const table of entityRows(report,service)) {
  const key=`${table.service}:${table.table_name}`;
  const current=rows.get(key)||{service:table.service,table_name:table.table_name,total:null,inventory_complete:false,
   expectation:{status:'undocumented',domain_write_expected:null,decision_complete:false,reason:'Решение о переносе таблицы не подтверждено доступной инвентаризацией.',fates:{}},
   field_fates:{},fields:[],problem_groups:0,problem_codes:[]};
  const outcomes=table.source_outcomes??null;
  rows.set(key,{...current,...table,total:table.total??current.total,source_outcomes:outcomes,
   expectation:table.expectation||current.expectation,
   state:outcomes && outcomes.unprocessed!==null && outcomes.unprocessed!==undefined ? (table.state||'recorded') : 'unknown'});
 }
 const groups=causeGroups(report,service);
 for(const group of groups) {
  const name=group.table||group.entity_type; if(!name) continue;
  const key=`${group.service}:${name}`; const current=rows.get(key);
  if(!current) continue;
  const codes=new Set(current.problem_codes||[]); if(group.code) codes.add(group.code);
  rows.set(key,{...current,problem_groups:(current.problem_groups||0)+1,problem_codes:[...codes]});
 }
 return [...rows.values()].sort((a,b)=>(serviceTitles[a.service]||a.service).localeCompare(serviceTitles[b.service]||b.service,'ru')||String(a.table_name).localeCompare(String(b.table_name),'ru'));
}
export function serviceEvidenceRows(report,operation,service='all') {
 const tables=tableEvidenceRows(report,service);
 const services=[...new Set([...(operation?.services||[]),...tables.map(row=>row.service),...(report?.service_evidence||[]).map(row=>row.service)])].filter(key=>service==='all'||key===service);
 const evidence=new Map((report?.service_evidence||[]).map(item=>[item.service,item]));
 const results=new Map((operation?.service_results||[]).map(item=>[item.service,item]));
 return services.map(key=>{
  const own=tables.filter(row=>row.service===key), detail=evidence.get(key)||{}, result=results.get(key)||{};
  const expected=own.filter(row=>row.expectation?.status==='expected');
  const excluded=own.filter(row=>row.expectation?.status==='excluded');
  const undocumented=own.filter(row=>row.expectation?.status==='undocumented');
  const knownUnprocessed=expected.filter(row=>row.source_outcomes?.unprocessed!==null&&row.source_outcomes?.unprocessed!==undefined);
  return {service:key,status:detail.status||result.status||'unknown',stage:detail.stage||result.stage||null,stage_status:detail.stage_status||result.stage_status||null,
   evidence_status:detail.evidence_status||'unknown',reason:detail.reason||result.message||null,consequence:detail.consequence||null,
   write_state:detail.write_state||'unknown',write_processed:detail.write_processed??result.processed_count??null,write_total:detail.write_total??result.total??null,
   table_count:own.length,expected_tables:expected.length,excluded_tables:excluded.length,undocumented_tables:undocumented.length,
   expected_unprocessed:knownUnprocessed.reduce((sum,row)=>sum+Number(row.source_outcomes.unprocessed||0),0),
   unknown_outcome_tables:expected.filter(row=>row.source_outcomes?.unprocessed===null||row.source_outcomes?.unprocessed===undefined).length,
   problem_groups:own.reduce((sum,row)=>sum+(row.problem_groups||0),0)};
 });
}
export function causeGroups(report,service='all') {
 const groups=report?.root_cause_groups || reportRuns(report).flatMap(run=>(run.diagnostics?.groups||[]).map(group=>({...group,service:group.service||run.service})));
 return groups.filter(group=>service==='all'||group.service===service).map(group=>({...group,examples:(group.examples||reportRuns(report).filter(run=>!group.service||run.service===group.service).flatMap(run=>run.diagnostics?.items||[]).filter(item=>item.code===group.code&&(!group.table||item.table===group.table)).slice(0,3)).slice(0,3)}));
}
export function stageRows(operation,report,service='all') {
 const services=operation?.services || [...new Set((operation?.runs||[]).map(run=>run.service))], order=['inspect','dry-run','migrate','validate'];
 const results=new Map((operation?.service_results||[]).map(item=>[item.service,item]));
 const rows=services.filter(key=>service==='all'||key===service).flatMap(key=>order.map(mode=>{
  const live=operation?.runs?.find(run=>run.service===key&&run.mode===mode);
  const archived=reportRuns(report).find(run=>run.service===key&&run.mode===mode);
  const source=isActive(operation)?(live||archived):(archived||live);
  const run=source?{...source,...source.run}:null;
  if(run?.tables?.length){for(const name of ['total','processed_count'])if(run[name]==null)run[name]=run.tables.every(table=>table[name]!=null)?run.tables.reduce((sum,table)=>sum+Number(table[name]),0):null;}
  const result=results.get(key), stoppedAt=order.indexOf(result?.stage);
  const provenNotStarted=!run&&result?.status==='failed'&&stoppedAt>=0&&order.indexOf(mode)>stoppedAt;
  return {service:key,mode,status:run?.status||(provenNotStarted?'not_started':'unknown'),run:run?{...run,...run.run}:null,known:!!run||provenNotStarted,proven_not_started:provenNotStarted};
 }));
 if(operation) rows.unshift({service:operation.scope||'all',mode:'snapshot',status:operation.phase==='snapshot'?'running':Object.keys(operation.snapshot_ids||{}).length?'completed':'unknown',known:operation.phase==='snapshot'||Object.keys(operation.snapshot_ids||{}).length>0,run:{started_at:operation.started_at}});
 return rows;
}

/**
 * One immutable operation report produces one independent tree.
 * No percentages or zeroes are guessed for missing migration-stage counters.
 */
export function migrationTree(report,operation) {
 if(!operation)return null;
 const tables=tableEvidenceRows(report),services=serviceEvidenceRows(report,operation);
 const groups=causeGroups(report);
 const issueSummary=(items)=>({
  errors:items.filter(item=>item.severity==='error').reduce((sum,item)=>sum+Number(item.count||0),0),
  warnings:items.filter(item=>item.severity==='warning').reduce((sum,item)=>sum+Number(item.count||0),0),
  groups:items.length,
 });
 const serviceNodes=services.map(item=>{
  const children=tables.filter(row=>row.service===item.service).map(row=>{
   const issues=groups.filter(group=>group.service===item.service&&
       (group.table||group.entity_type)===row.table_name);
   const processed=Number.isFinite(Number(row.processed_count)) && row.processed_count!==null && row.processed_count!==undefined
    && row.source_outcomes!==null ? Number(row.processed_count) : null;
   return {...row,id:item.service+':'+row.table_name,label:row.table_name,
    processed,issues:issueSummary(issues),issue_groups:issues};
  });
  const ownIssues=groups.filter(group=>group.service===item.service);
  return {...item,id:item.service,label:serviceTitles[item.service]||item.service,
   entities:children,issues:issueSummary(ownIssues),issue_groups:ownIssues,
   processed:item.write_processed??null,total:item.write_total??null};
 });
 const coverage=report?.coverage||{};
 return {
  id:String(operation.id),status:operation.status,coverage,
  services:serviceNodes,issues:issueSummary(groups),issue_groups:groups,
  stage:operation.last_phase||operation.phase||null,
  snapshot_ids:operation.snapshot_ids||{},
  data_outcome:operation.data_outcome??null,
  archive_complete:report?.archive_complete??null,
  evidence_status:report ? (coverage.semantic_complete===true?'full':'partial') : 'unknown',
 };
}

export function progressText(processed,total) {
 const clean=value=>value===null||value===undefined||value===''||!Number.isFinite(Number(value))
  ?null:Number(value);
 const done=clean(processed),size=clean(total);
 if(done===null&&size===null)return 'Обработано: нет данных';
 const format=value=>value===null?'нет данных':value.toLocaleString('ru-RU');
 const rate=done!==null&&size!==null&&size>0?' ('+Math.min(100,Math.round(done/size*100))+'%)':'';
 return 'Обработано: '+format(done)+' / '+format(size)+rate;
}

/** Return the archived per-row events (when available) without multiplying stages. */
export function diagnosticItems(report,{service=null,table=null,severity=null}={}) {
 const selected=report?.root_cause_groups||[];
 const groups=selected.filter(group=>(!service||group.service===service)&&
   (!table||(group.table||group.entity_type)===table)&&(!severity||group.severity===severity));
 const stageKeys=new Set(groups.map(group=>
  [String(group.service||''),String(group.stage||''),String(group.code||''),String(group.table||group.entity_type||'')].join('|')));
 return reportRuns(report).flatMap(run=>(run.diagnostics?.items||[]).filter(item=>{
  const svc=item.service||run.service, entity=item.table||item.entity_type, level=item.severity;
  if(service&&service!==svc || table&&table!==entity || severity&&severity!==level)return false;
  return stageKeys.has([String(svc),String(run.mode||''),String(item.code||''),String(entity||'')].join('|'))
   || (!selected.length && (!service||svc===service));
 }).map(item=>({...item,service:item.service||run.service,stage:run.mode,run_id:run.run_id||run.id||run.run?.id})));
}

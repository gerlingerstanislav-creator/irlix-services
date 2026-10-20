export const serviceTitles = {all:'Все сервисы',employees:'Сотрудники',vacations:'Отпуска',clients:'Клиенты',timesheets:'Таймшиты'};
export const stageTitles = {snapshot:'Точка отката',inspect:'Чтение источника','dry-run':'Проверка переноса',migrate:'Запись данных',validate:'Проверка результата'};
export const statusTitles = {queued:'В очереди',running:'Выполняется',completed:'Завершено',partial:'Частично',failed:'Ошибка',cancelled:'Остановлено',interrupted:'Прервано',skipped:'Пропущено',pending:'Ожидает',not_started:'Не начато',changed:'Данные изменены',unknown:'Неизвестно',checked:'Проверено',metadata_only:'Только metadata',domain_written:'Перенесено',reference_matched:'Сопоставлено',deferred:'Отложено',unmapped:'Не описано',blocked:'Заблокировано',direct:'Прямое копирование',transformed:'Преобразовано',reference:'Сопоставленная ссылка',excluded:'Исключено с объяснением'};
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
export function causeGroups(report,service='all') {
 const groups=report?.root_cause_groups || reportRuns(report).flatMap(run=>(run.diagnostics?.groups||[]).map(group=>({...group,service:group.service||run.service})));
 return groups.filter(group=>service==='all'||group.service===service).map(group=>({...group,examples:(group.examples||reportRuns(report).filter(run=>!group.service||run.service===group.service).flatMap(run=>run.diagnostics?.items||[]).filter(item=>item.code===group.code&&(!group.table||item.table===group.table)).slice(0,3)).slice(0,3)}));
}
export function stageRows(operation,report,service='all') {
 const services=operation?.services || [...new Set((operation?.runs||[]).map(run=>run.service))];
 const rows=services.filter(key=>service==='all'||key===service).flatMap(key=>Object.keys(stageTitles).filter(stage=>stage!=='snapshot').map(mode=>{
 const live=operation?.runs?.find(run=>run.service===key&&run.mode===mode);
 const archived=reportRuns(report).find(run=>run.service===key&&run.mode===mode);
 const source=isActive(operation)?(live||archived):(archived||live);
 const run=source?{...source,...source.run}:null;
 if(run?.tables?.length){for(const name of ['total','processed_count'])if(run[name]==null)run[name]=run.tables.every(table=>table[name]!=null)?run.tables.reduce((sum,table)=>sum+Number(table[name]),0):null;}
 return {service:key,mode,status:run?.status||'pending',run:run?{...run,...run.run}:null,known:!!run};
 }));
 if(operation) rows.unshift({service:operation.scope||'all',mode:'snapshot',status:operation.phase==='snapshot'?'running':Object.keys(operation.snapshot_ids||{}).length?'completed':'pending',known:operation.phase==='snapshot'||Object.keys(operation.snapshot_ids||{}).length>0,run:{started_at:operation.started_at}});
 return rows;
}

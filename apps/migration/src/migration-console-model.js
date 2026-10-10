export const titles = { all: 'Все сервисы', employees: 'Сотрудники', vacations: 'Отпуска', clients: 'Клиенты', timesheets: 'Таймшиты', specialists: 'Специалисты', recruitment: 'Подбор' };
export const active = item => ['queued', 'running'].includes(item?.status);
export const label = state => ({ queued:'В очереди', running:'Выполняется', completed:'Готово', conflicts:'Есть ошибки', failed:'Ошибка', waiting:'Ожидает', processing:'Перенос', read:'Прочитано', read_only:'Прочитано, без переноса', partial:'Частично', checked:'Проверено', preflight_ready:'Готово к переносу', metadata_only:'Только metadata', interrupted:'Прервано' }[state] || state || '—');
export const modeLabel = mode => ({ inspect:'Inspect', 'dry-run':'Dry run', migrate:'Перенос', validate:'Проверка результата', snapshot:'Создание точки отката', restore:'Откат', finished:'Результат', queued:'В очереди' }[mode] || mode || '—');
export const ready = module => module?.status === 'implemented' && !!module.connection?.verified_at && module.connection?.credential_status === 'ready';
export function orderedModules(modules) {
  const byKey = new Map(modules.map(m => [m.key, m]));
  const result = [], visited = new Set(), visiting = new Set();
  function visit(m) {
    if (visited.has(m.key)) return;
    if (visiting.has(m.key)) throw new Error('Циклическая зависимость модулей переноса');
    visiting.add(m.key);
    for (const key of m.dependencies || []) if (byKey.has(key)) visit(byKey.get(key));
    visiting.delete(m.key); visited.add(m.key); result.push(m);
  }
  modules.forEach(visit);
  return result;
}
export function percent(table) {
  if (table.total === null || table.total === undefined) return null;
  if (!Number(table.total)) return table.state === 'completed' ? 100 : 0;
  return Math.min(100, Math.round(Number(table.processed_count ?? 0) / Number(table.total) * 100));
}
export function shownRuns(module, operation, details) {
  const refs = (operation?.runs || []).filter(r => r.service === module.key);
  if (operation) return refs.map(r => details[r.id]?.reportOperationId === String(operation.id) ? details[r.id] : r);
  return module.latest_run ? [details[module.latest_run.id] || module.latest_run] : [];
}
export function displayRun(runs) {
  return [...runs].reverse().find(r => r.mode === 'migrate') || runs.at(-1) || null;
}
export function tableForConflict(service, entity) {
  return service === 'employees' ? ({department:'departments',employee:'employees',employment:'employments',employee_role:'employee_roles',salary:'salaries'}[entity] || entity) : entity;
}
export function messages(module, runs) {
  return runs.flatMap(run => [
    ...(run.events || []).map(e => ({...e, key:`${run.id}:e:${e.id}`, run_id:run.id, service:module.key, table:e.context?.table || '', kind:e.level, mode:run.mode})),
    ...(run.conflicts || []).map(e => ({...e, conflict_id:e.id, key:`${run.id}:c:${e.id}`, run_id:run.id, service:module.key, table:tableForConflict(module.key,e.entity_type), kind:e.severity, mode:run.mode})),
  ]);
}

export const tableStatus = table => Number(table.error_count) > 0 ? 'conflicts' : table.state;

export function historyForScope(history, scope) {
  return scope === 'all' ? history : history.filter(item => item.scope === scope || item.services?.includes(scope) || item.runs?.some(run => run.service === scope));
}


export function restoreMaintenance(control) {
  const state = control?.restore_status || {};
  return Boolean(state.state === 'running' || (state.state === 'failed' && (state.database_outcome === 'unknown'
    || (state.database_committed && !(state.metadata_complete && state.schemas_upgraded)))));
}


export const restoreStage = stage => ({validation:'Проверка снимка',preflight:'Проверка совместимости',
  'runtime-check':'Проверка окружения',stop:'Остановка сервисов',database:'Восстановление БД',
  metadata:'Восстановление журнала',documents:'Восстановление документов',
  'schema-upgrade':'Обновление схемы',start:'Запуск сервисов',verify:'Проверка доступности',
  execution:'Запуск скрипта',completed:'Завершено'}[stage] || stage || 'Этап не записан');
export const databaseOutcome = outcome => ({unmodified:'БД не изменялась',rolled_back:'Транзакция отменена',
  committed:'Снимок записан в БД',unknown:'Исход записи БД неизвестен'}[outcome] || 'Исход не записан');
export function operationDiagnostics(operation, legacy, scope, historical=false) {
  const candidates = [operation, ...(!historical ? [legacy] : [])];
  return candidates.filter(op => op && (op.status==='failed' || op.state==='failed')
    && (scope==='all' || (op.scope || op.service)==='all' || (op.scope || op.service)===scope))
    .map(op => ({operation_id:String(op.id),action:op.action,scope:op.scope || op.service,
      snapshot_id:op.snapshot_id, ...op.diagnostics}));
}

/**
 * View-only migration report preferences. Never edits the operation/archive.
 * Keys use the service + exact legacy table name (including schema).
 */
export const VISIBILITY_KEY='irlix.migration.control.hidden-entities.v1';

export function readHidden(storage) {
 try {
  const data=JSON.parse(storage?.getItem(VISIBILITY_KEY)||'{}');
  if(!data||Array.isArray(data)||typeof data!=='object')return {};
  return Object.fromEntries(Object.entries(data).filter(([key,val])=>
   /^[a-z][a-z0-9-]*$/.test(key)&&Array.isArray(val)
  ).map(([key,val])=>[key,[...new Set(val.filter(name=>typeof name==='string'&&name.length>0))]]));
 }catch{return {};}
}
export function saveHidden(storage,hidden) {
 try {storage?.setItem(VISIBILITY_KEY,JSON.stringify(hidden));return true;}catch{return false;}
}
export function updateHidden(hidden,service,tableName,shouldHide) {
 const current=hidden?.[service]||[];
 const names=new Set(current);
 if(shouldHide)names.add(tableName);else names.delete(tableName);
 return {...hidden,[service]:[...names]};
}
export function hiddenEntities(tree,hidden,service) {
 const source=tree?.services?.find(s=>s.service===service);
 const names=new Set(hidden?.[service]||[]);
 return (source?.entities||[]).filter(row=>names.has(row.table_name));
}
const valid=value=>value!==null&&value!==undefined&&value!==''&&
 Number.isFinite(Number(value))&&Number(value)>=0?Number(value):null;
export function progressPercent(processed,total) {
 const done=valid(processed),size=valid(total);
 if(done===null||size===null||size<=0)return null;
 return Math.min(100,Math.max(0,Math.round(done/size*100)));
}
function issueSummary(groups) {
 return {
  errors:groups.filter(g=>g.severity==='error').reduce((sum,g)=>sum+Number(g.count||0),0),
  warnings:groups.filter(g=>g.severity==='warning').reduce((sum,g)=>sum+Number(g.count||0),0),
  groups:groups.length,
 };
}
function includeGroup(group,visible) {
 const table=group.table||group.entity_type;
 return !table||visible.has(String(table));
}
function aggregateMetric(rows,key,requiresOutcomes=false) {
 if(!rows.length)return null;
 // No invented zero for an unknown table, or for inspected-but-not-written rows.
 if(rows.some(r=>valid(r[key])===null||(requiresOutcomes&&r.source_outcomes==null)))return null;
 return rows.reduce((sum,r)=>sum+Number(r[key]),0);
}
export function filterMigrationTree(source,hidden) {
 if(!source)return null;
 let changed=false;
 const services=source.services.map(service=>{
  const names=new Set(hidden?.[service.service]||[]);
  const entities=service.entities.filter(row=>!names.has(row.table_name));
  const hiddenCount=service.entities.length-entities.length;
  if(!hiddenCount)return {...service,hidden_count:0,visible_count:entities.length};
  changed=true;
  const visible=new Set(entities.map(row=>row.table_name));
  const groups=(service.issue_groups||[]).filter(g=>includeGroup(g,visible));
  const expected=entities.filter(r=>r.expectation?.status==='expected');
  const known=expected.filter(r=>valid(r.source_outcomes?.unprocessed)!==null);
  return {...service,entities,hidden_count:hiddenCount,visible_count:entities.length,
   processed:aggregateMetric(entities,'processed',true),
   total:aggregateMetric(entities,'total'),
   expected_tables:expected.length,
   excluded_tables:entities.filter(r=>r.expectation?.status==='excluded').length,
   undocumented_tables:entities.filter(r=>r.expectation?.status==='undocumented').length,
   expected_unprocessed:known.reduce((sum,r)=>sum+Number(r.source_outcomes.unprocessed),0),
   unknown_outcome_tables:expected.length-known.length,
   problem_groups:groups.length,issue_groups:groups,issues:issueSummary(groups),
  };
 });
 const visibleIssueGroups=services.flatMap(service=>service.issue_groups||[]);
 const unknownTotal=services.some(s=>s.hidden_count>0&&s.unknown_outcome_tables>0);
 const unprocessed=services.reduce((sum,s)=>sum+(Number(s.expected_unprocessed)||0),0);
 return {...source,services,filtered:changed,
  hidden_count:services.reduce((sum,s)=>sum+s.hidden_count,0),
  expected_unprocessed:changed?(unknownTotal?null:unprocessed):source.coverage?.expected_unprocessed_rows??null,
  // Preserve original operation status/semantic completeness and evidence; these
  // describe the real execution rather than user-defined visual subsets.
  issues:changed?issueSummary(visibleIssueGroups):source.issues,
  issue_groups:changed?visibleIssueGroups:source.issue_groups,
 };
}

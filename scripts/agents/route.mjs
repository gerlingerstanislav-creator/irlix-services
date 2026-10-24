export const SERVICES=['employees','clients','timesheets','vacations','equipment','specialists','recruitment','cv','migration','portal'];
const frontend={employees:'web',clients:'clients',timesheets:'timesheets',vacations:'vacations',equipment:'equipment',specialists:'specialists',recruitment:'recruitment',cv:'cv',migration:'migration',portal:'portal'};
const backend={employees:['employees'],clients:['clients'],timesheets:['timesheets'],vacations:['vacations'],equipment:['equipment'],specialists:['specialists'],recruitment:['recruitment'],cv:['cv-converter'],migration:['migration','migration-ops'],portal:['platform-core']};
const databaseImpact=p=>/\/(?:database|migrations|seeders|Models|models|Repositories|repositories|schemas|etl)\//.test(p)||/\.(?:sql|prisma)$/.test(p)||p.startsWith('services/migration-ops/');
const securityImpact=p=>p.startsWith('packages/auth/')||p.startsWith('infra/')||/^docker-compose.*\.yml$/.test(p)||(p.startsWith('services/')&&(/\/(?:app|routes|config)\//.test(p)))||/(?:Auth|Permission|Policy|Security|Token|Middleware|Upload|Export|Webhook|Secret|OAuth|Keycloak)/i.test(p);

// File names are navigation evidence only: they cannot establish task intent,
// behavioral impact, risk severity, required roles, or completion of a review.
export function routeChanges(paths){
 if(!Array.isArray(paths)||paths.some(p=>typeof p!=='string'))throw new TypeError('paths must be an array of strings');
 const services=SERVICES.filter(s=>paths.some(p=>p.startsWith('apps/'+frontend[s]+'/')||backend[s].some(x=>p.startsWith('services/'+x+'/'))));
 const ui=paths.some(p=>p.startsWith('apps/')||p.startsWith('packages/ui/'));
 const shared=paths.some(p=>p.startsWith('packages/')||p.startsWith('infra/')||p.startsWith('.github/workflows/')||p.startsWith('scripts/ci/')||p.startsWith('scripts/agents/'));
 const database=paths.some(databaseImpact),security=paths.some(securityImpact);
 const candidates=[
  ['analysis',p=>p==='AGENTS.md'||p.startsWith('docs/')||p.startsWith('.github/agents/'),'Documentation or instructions may need interpretation against the request.'],
  ['frontend',p=>p.startsWith('apps/')||p.startsWith('packages/ui/'),'Frontend location; inspect the actual diff before deciding whether UX or visual review is useful.'],
  ['backend',p=>p.startsWith('services/'),'Service location; inspect the affected logic and contract.'],
  ['data',databaseImpact,'Persistence-related location; assess actual data invariants and migration risk.'],
  ['security',securityImpact,'Possible trust or access boundary; assess exposure from content and scenario.'],
  ['integration',p=>p.startsWith('packages/')||/(?:\/|^)(?:contracts|events|integrations)\//i.test(p)||(services.length>1&&p.startsWith('services/')),'Shared or cross-service location; confirm a concrete contract before expanding scope.'],
  ['verification',p=>/(?:\/|^)(?:tests?|__tests__|qa|e2e)\//.test(p)||/\.(?:test|spec)\.[^.]+$/.test(p),'Check-related location; select relevant checks and record their actual results.'],
  ['delivery',p=>p.startsWith('.github/workflows/')||p.startsWith('scripts/ci/')||p.startsWith('infra/')||/^docker-compose.*\.yml$/.test(p),'Delivery-related location; inspect the affected execution or deployment boundary.']
 ];
 const behaviorHints=candidates.flatMap(([behavior,matches,reason])=>{
  const evidence=[...new Set(paths.filter(matches))];
  return evidence.length?[{behavior,reason,evidence}]:[];
 });
 return {
  services,
  // Retained service selectors for older consumers, not people or role assignments.
  developers:services.map(s=>'Developer:'+s),roles:[],
  ui,shared,database,security,
  // Legacy flag means a path area was recognized, never that task scope is confirmed.
  scopeKnown:services.length>0||shared||database||security,
  advisory:true,source:'paths',behaviorHints,
  intent:'unknown',risk:'unknown',
  unknowns:['task intent','actual behavioral impact','risk and uncertainty','required verification'],
  assessmentRequired:['Read the request and relevant diff.','Assess intent, uncertainty and risk.','Select compatible behaviors and proportionate checks from evidence.']
 };
}
export function chooseSpecs(service,paths,max=4){
 const prefix='company-internal-services-81-2.4/services/'+service+'/';
 const pref=['ACCEPTANCE.md','ACCESS.md','REQUIREMENTS.md','README.md','ТЗ.md'];
 return paths.filter(p=>p.startsWith(prefix)&&p.endsWith('.md')).sort((a,b)=>{const rank=x=>{const i=pref.indexOf(x.slice(prefix.length));return i<0?100:i;};return rank(a)-rank(b)||a.localeCompare(b);}).slice(0,max);
}

// Aggregates explicitly supplied review outcomes; never performs an AI review.
// Passing tests alone (PASS) are not evidence that a review was performed.
export function reviewDecision(results){
 if(!Array.isArray(results))throw new TypeError('results must be an array');
 if(results.length===0)return 'NOT_TESTED';
 const statuses=Array.from(results,x=>x?.status);
 if(statuses.some(s=>s==='CHANGES_REQUESTED'||s==='FAIL'))return 'CHANGES_REQUESTED';
 if(statuses.includes('BLOCKED'))return 'BLOCKED';
 const known=['NO_FINDINGS','REVIEWED_NO_FINDINGS','NEEDS_INFO','NOT_TESTED','NOT TESTED'];
 if(statuses.some(s=>!known.includes(s))||statuses.includes('NEEDS_INFO'))return 'NEEDS_INFO';
 if(statuses.some(s=>s==='NOT_TESTED'||s==='NOT TESTED'))return 'NOT_TESTED';
 return 'REVIEWED_NO_FINDINGS';
}

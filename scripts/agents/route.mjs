export const SERVICES=['employees','clients','timesheets','vacations','equipment','specialists','recruitment','cv','migration','portal'];
const frontend={employees:'web',clients:'clients',timesheets:'timesheets',vacations:'vacations',equipment:'equipment',specialists:'specialists',recruitment:'recruitment',cv:'cv',migration:'migration',portal:'portal'};
const backend={employees:['employees'],clients:['clients'],timesheets:['timesheets'],vacations:['vacations'],equipment:['equipment'],specialists:['specialists'],recruitment:['recruitment'],cv:['cv-converter'],migration:['migration','migration-ops'],portal:['platform-core']};
export function routeChanges(paths){
 if(!Array.isArray(paths))throw new TypeError('paths must be an array');
 const services=SERVICES.filter(s=>paths.some(p=>p.startsWith('apps/'+frontend[s]+'/')||backend[s].some(x=>p.startsWith('services/'+x+'/'))));
 const ui=paths.some(p=>p.startsWith('apps/')||p.startsWith('packages/ui/'));
 const shared=paths.some(p=>p.startsWith('packages/')||p.startsWith('infra/')||p.startsWith('.github/workflows/')||p.startsWith('scripts/ci/')||p.startsWith('scripts/agents/'));
 return {services,developers:services.map(s=>'Developer:'+s),roles:['QA','Analyst',...(ui?['Designer']:[]),...(shared||services.length>1?['PlatformEngineer']:[]),'PM'],ui,shared,scopeKnown:services.length>0||shared};
}
export function chooseSpecs(service,paths,max=4){
 const prefix='company-internal-services-81-2.4/services/'+service+'/';
 const pref=['ACCEPTANCE.md','ACCESS.md','REQUIREMENTS.md','README.md','ТЗ.md'];
 return paths.filter(p=>p.startsWith(prefix)&&p.endsWith('.md')).sort((a,b)=>{const rank=x=>{const i=pref.indexOf(x.slice(prefix.length));return i<0?100:i;};return rank(a)-rank(b)||a.localeCompare(b);}).slice(0,max);
}
export function reviewDecision(results){
 if(results.some(x=>x.status==='CHANGES_REQUESTED'))return 'CHANGES_REQUESTED';
 if(results.some(x=>x.status==='NEEDS_INFO'))return 'NEEDS_INFO';
 return 'REVIEWED_NO_FINDINGS';
}

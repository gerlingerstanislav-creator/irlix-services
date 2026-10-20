// Service APIs own action rules. Dashboard validates, merges and sorts the feed.
export const attentionSources=Object.freeze([{service:'vacations',path:'/api/vacations/attention'},{service:'timesheets',path:'/api/timesheets/attention'}]);
export function normalizeTasks(payload,source) {
 if(!Array.isArray(payload))throw new TypeError('Invalid attention response from '+source);
 return payload.filter(item=>item && typeof item.id==='string' && typeof item.title==='string' &&
   typeof item.href==='string' && item.href.startsWith('/') && !item.href.startsWith('//') &&
   !item.href.includes('\\') && !item.href.includes('://') && ['attention','upcoming'].includes(item.kind))
   .map(item=>({...item,service:source,due_at:typeof item.due_at==='string'?item.due_at:null}));
}
export function sortTasks(items) {
 return [...items].sort((a,b)=>(a.kind==='attention'?0:1)-(b.kind==='attention'?0:1) ||
 (a.due_at||'9999').localeCompare(b.due_at||'9999') || a.title.localeCompare(b.title,'ru'));
}

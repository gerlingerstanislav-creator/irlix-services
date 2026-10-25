// Membership and billing periods belong to ClientMember, never ProjectMember.
const day = value => String(value || '').slice(0, 10);

export function activeMemberTerm(member, today) {
 return (member.terms || []).find(term => day(term.valid_from) <= today && (!term.valid_to || day(term.valid_to) >= today)) || null;
}
export function lastMemberTerm(member) {
 return [...(member.terms || [])].sort((a,b)=>day(b.valid_from).localeCompare(day(a.valid_from)))[0] || null;
}
export function visibleClientMembers(client, scope, today) {
 const members=client.client_members || [];
 return scope==='all' ? members : members.filter(member=>!!activeMemberTerm(member,today));
}
export function clientHasActiveMembers(client,today) {
 return visibleClientMembers(client,'active',today).length>0;
}
export function clientMatchesTechnologies(client, allowed) {
 return (client.client_members || []).some(m=>(m.terms || []).some(t=>allowed.has(t.technology)));
}
export function assignedProjectNames(member, today) {
 const assignments=member.projects || [];
 const current=assignments.filter(a=>day(a.valid_from)<=today && (!a.valid_to || day(a.valid_to)>=today));
 return [...new Set((current.length?current:assignments).map(a=>a.project_name||(a.project_id?`Проект #${a.project_id}`:null)).filter(Boolean))];
}

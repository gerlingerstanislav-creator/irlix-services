import test from 'node:test';
import assert from 'node:assert/strict';
import { activeMemberTerm, lastMemberTerm, visibleClientMembers, clientHasActiveMembers, clientMatchesTechnologies, assignedProjectNames } from '../src/clientMemberView.js';
const today='2026-10-25';
const staff={id:1,employee_id:14,terms:[{valid_from:'2026-01-01',valid_to:null,technology:'Vue'}],projects:[]};
const partner={id:2,partner_specialist_id:3,terms:[{valid_from:'2025-01-01',valid_to:'2025-12-31',technology:'PHP'}],projects:[]};
const future={id:3,employee_id:15,terms:[{valid_from:'2099-01-01',valid_to:null,technology:'Python'}],projects:[]};

test('canonical memberships include staff and partners without project assignments',()=>{
 const client={projects:[],client_members:[staff,partner,future]};
 assert.equal(clientHasActiveMembers(client,today),true);
 assert.deepEqual(visibleClientMembers(client,'active',today).map(m=>m.id),[1]);
 assert.deepEqual(visibleClientMembers(client,'all',today).map(m=>m.id),[1,2,3]);
 assert.equal(clientHasActiveMembers({projects:[{members:[staff]}],client_members:[]},today),false);
 assert.equal(clientHasActiveMembers({client_members:[partner,future]},today),false);
});
test('commercial terms and technology filters never follow legacy projects',()=>{
 assert.equal(activeMemberTerm(partner,'2025-12-31')?.technology,'PHP');
 assert.equal(activeMemberTerm(partner,today),null);
 assert.equal(lastMemberTerm(partner)?.technology,'PHP');
 assert.equal(clientMatchesTechnologies({client_members:[staff,partner]},new Set(['Vue'])),true);
 assert.equal(clientMatchesTechnologies({projects:[{members:[staff]}],client_members:[]},new Set(['Vue'])),false);
});
test('simultaneous project assignments remain one specialist row',()=>{
 const member={...staff,projects:[
  {project_id:1,project_name:'Alpha',valid_from:'2026-01-01',valid_to:null},
  {project_id:2,project_name:'Beta',valid_from:'2026-06-01',valid_to:null},
  {project_id:3,project_name:'Past',valid_from:'2025-01-01',valid_to:'2025-12-31'}]};
 assert.deepEqual(assignedProjectNames(member,today),['Alpha','Beta']);
 assert.deepEqual(assignedProjectNames(staff,today),[]);
 assert.equal(visibleClientMembers({client_members:[member]},'active',today).length,1);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { buildCashFlowRows } from '../src/cashFlowRows.js';
const from='2026-10-01',to='2026-10-31',dates=()=>['2026-10-05','2026-10-06'],workday=()=>true;
const staff={id:10,employee_id:5,partner_specialist_id:null,specialist_name:'Staff',terms:[
 {id:101,valid_from:'2026-10-01',valid_to:'2026-10-15',technology:'Vue',hourly_rate:100,hours_per_day:8},
 {id:102,valid_from:'2026-10-16',valid_to:null,technology:'Vue',hourly_rate:200,hours_per_day:8},
 ],projects:[{project_id:1,project_name:'A',valid_from:'2026-01-01',valid_to:null},{project_id:2,project_name:'B',valid_from:'2026-01-01',valid_to:null}]};
const partner={id:20,employee_id:null,partner_specialist_id:9,specialist_name:'Partner',terms:[
 {id:201,valid_from:'2026-10-01',valid_to:null,technology:'Go',hourly_rate:null,hours_per_day:8}],projects:[]};
test('one canonical member per rate version, even with parallel projects',()=>{
 const [a,b]=buildCashFlowRows({clients:[{id:1,name:'Client',client_members:[staff]}],timesheetRows:[
  {term_id:101,timesheet_hours:4,timesheet_amount:400,confirmed_hours:4,confirmed_amount:400},
  {term_id:102,timesheet_hours:5,timesheet_amount:1000,confirmed_hours:0,confirmed_amount:0}
 ],absences:[],from,to,dates,workday});
 assert.equal(a.project,'A, B');assert.equal(b.project,'A, B');
 assert.equal(a.id,10);assert.equal(b.id,10);assert.equal(a.timesheetHours+b.timesheetHours,9);
 assert.equal(a.timesheetAmount+b.timesheetAmount,1400);
});
test('projectless partners, null rates and no fake employee IDs',()=>{
 const [row]=buildCashFlowRows({clients:[{id:1,name:'Client',client_members:[partner],projects:[]}],timesheetRows:[
  {term_id:201,timesheet_hours:7.33,timesheet_amount:null,confirmed_hours:7.33,confirmed_amount:null}
 ],absences:[],from,to,dates,workday});
 assert.equal(row.project,'Без проекта');assert.equal(row.specialistId,null);
 assert.equal(row.specialistKey,'partner:9');assert.equal(row.timesheetHours,7.33);
 assert.equal(row.timesheetAmount,null);assert.equal(row.calendarAmount,null);
});
test('vacation absences apply only to staff and preserve partner calendar',()=>{
 const rows=buildCashFlowRows({clients:[{id:1,name:'Client',client_members:[staff,partner]}],timesheetRows:[],
 absences:[{employee_id:5,starts_on:'2026-10-05',ends_on:'2026-10-05'}],from,to,dates,workday});
 assert.equal(rows.find(r=>r.id===10).calendarHours,8);
 assert.equal(rows.find(r=>r.id===20).calendarHours,16);
});

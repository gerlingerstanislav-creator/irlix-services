// Canonical cash-flow rows: one ClientMember per client/person, independent of projects.
const iso = date => String(date || '').slice(0, 10);
export function buildCashFlowRows({clients,timesheetRows,absences,from,to,dates,workday}) {
 const byTerm = new Map(timesheetRows.map(row => [Number(row.term_id),row]));
 const absencesByEmployee = new Map();
 for (const absence of absences) {
   const key=Number(absence.employee_id);
   if (!absencesByEmployee.has(key)) absencesByEmployee.set(key,[]);
   absencesByEmployee.get(key).push(absence);
 }
 const result=[];
 for (const client of clients) for (const member of client.client_members || []) {
   const employeeId=member.employee_id == null ? null : Number(member.employee_id);
   const partnerId=member.partner_specialist_id == null ? null : Number(member.partner_specialist_id);
   for (const term of member.terms || []) {
     const start=iso(term.valid_from)>from?iso(term.valid_from):from;
     const finish=!term.valid_to||iso(term.valid_to)>to?to:iso(term.valid_to);
     if (!start||start>finish) continue;
     // Concurrent projects never multiply recorded or planned money.
     const assigned=(member.projects||[]).filter(a=>iso(a.valid_from)<=finish&&(!a.valid_to||iso(a.valid_to)>=start));
     const names=[...new Set(assigned.map(a=>a.project_name||(a.project_id?`Проект #${a.project_id}`:'')).filter(Boolean))];
     const calendarHours=dates(start,finish).filter(date=>workday(date)&&(
       employeeId === null || !(absencesByEmployee.get(employeeId)||[]).some(a=>
         iso(a.starts_on)<=date && iso(a.ends_on)>=date)
     )).length * Number(term.hours_per_day||0);
     const ts=byTerm.get(Number(term.id))||{};
     result.push({
       id:Number(member.id),term,clientId:Number(client.id),client:client.name,
       project:names.join(', ')||'Без проекта',
       salesId:Number(client.sales_employee_id)||null,accountId:Number(client.account_employee_id)||null,
       specialistId:employeeId,specialistKey:partnerId===null?`employee:${employeeId}`:`partner:${partnerId}`,
       specialist:member.specialist_name,start,finish,calendarHours,
       calendarAmount:term.hourly_rate==null?null:calendarHours*Number(term.hourly_rate),
       timesheetHours:Number(ts.timesheet_hours||0),
       timesheetAmount:term.hourly_rate==null||ts.timesheet_amount===null?null:Number(ts.timesheet_amount||0),
       confirmedHours:Number(ts.confirmed_hours||0),
       confirmedAmount:term.hourly_rate==null||ts.confirmed_amount===null?null:Number(ts.confirmed_amount||0),
     });
   }
 }
 return result.sort((a,b)=>a.client.localeCompare(b.client,'ru')||a.project.localeCompare(b.project,'ru')||String(a.specialist).localeCompare(String(b.specialist),'ru')||a.start.localeCompare(b.start));
}

// Commercial reporting is client/member based. The API can contain several
// fact rows for the same specialist and MemberTerms version (for example while
// retaining legacy project lineage), so aggregate by person and term without
// exposing project detail to the client.
export function groupReportingTimesheets(rows = []) {
  const groups = new Map();
  for (const row of rows || []) {
    const { project_id: _projectId, project_name: _projectName, ...memberRow } = row;
    const identity = row.employee_id != null ? `employee:${row.employee_id}`
      : row.partner_specialist_id != null ? `partner:${row.partner_specialist_id}`
        : `member:${row.client_member_id ?? row.project_member_id}`;
    if (!groups.has(identity)) groups.set(identity, {
      ...memberRow, key: identity, ratesByTerm: new Map(), worked_hours: 0, worked_amount: 0,
      confirmed_hours: 0, approved_overtime_hours: 0, confirmed_amount: 0,
      account_confirmed: true, has_worked_amount: true, has_confirmed_amount: true,
    });
    const group = groups.get(identity);
    group.worked_hours += Number(row.worked_hours || 0);
    group.confirmed_hours += Number(row.confirmed_hours || 0);
    group.approved_overtime_hours += Number(row.approved_overtime_hours || 0);
    if (row.worked_amount == null) group.has_worked_amount = false;
    else group.worked_amount += Number(row.worked_amount);
    if (row.confirmed_amount == null) group.has_confirmed_amount = false;
    else group.confirmed_amount += Number(row.confirmed_amount);
    if (Number(row.worked_hours || 0) > 0 && !row.account_confirmed) group.account_confirmed = false;

    const termKey = row.term_id == null ? `unknown:${row.valid_from || ''}:${row.hourly_rate ?? ''}` : String(row.term_id);
    const term = group.ratesByTerm.get(termKey) || {
      term_id: row.term_id ?? termKey, rate: row.hourly_rate, from: row.valid_from, to: row.valid_to,
      worked_hours: 0, confirmed_hours: 0, overtime_hours: 0,
    };
    term.worked_hours += Number(row.worked_hours || 0);
    term.confirmed_hours += Number(row.confirmed_hours || 0);
    term.overtime_hours += Number(row.approved_overtime_hours || 0);
    group.ratesByTerm.set(termKey, term);
  }
  return [...groups.values()].map(group => {
    const { ratesByTerm, ...memberRow } = group;
    return {
    ...memberRow,
    worked_hours: Math.round(group.worked_hours * 100) / 100,
    confirmed_hours: Math.round(group.confirmed_hours * 100) / 100,
    approved_overtime_hours: Math.round(group.approved_overtime_hours * 100) / 100,
    worked_amount: group.has_worked_amount ? Math.round(group.worked_amount * 100) / 100 : null,
    confirmed_amount: group.has_confirmed_amount ? Math.round(group.confirmed_amount * 100) / 100 : null,
    rates: [...ratesByTerm.values()].map(term => ({
      ...term,
      worked_hours: Math.round(term.worked_hours * 100) / 100,
      confirmed_hours: Math.round(term.confirmed_hours * 100) / 100,
      overtime_hours: Math.round(term.overtime_hours * 100) / 100,
    })).sort((a, b) => String(a.from).localeCompare(String(b.from))),
    };
  });
}

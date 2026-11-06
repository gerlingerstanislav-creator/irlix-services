export function fotOverviewQuery(year, selectedDepartments = [], employmentStatuses = []) {
  const query = new URLSearchParams({ year: String(year) });
  selectedDepartments.forEach(id => query.append('department_ids[]', String(id)));
  employmentStatuses.forEach(status => query.append('employment_statuses[]', status));
  return query;
}

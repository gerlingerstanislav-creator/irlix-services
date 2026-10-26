// Empty selection means no restriction; the defaults apply only when the URL omits the status filter.
export const DEFAULT_EMPLOYMENT_STATUSES = Object.freeze(['Трудоустроен', 'Ожидает трудоустройства']);

export function employmentStatusesFromQuery(params) {
  if (!params.has('employment_status')) return [...DEFAULT_EMPLOYMENT_STATUSES];
  return params.getAll('employment_status').filter(Boolean);
}

export function matchesEmploymentStatus(selectedStatuses, employmentStatus) {
  return selectedStatuses.length === 0 || selectedStatuses.includes(employmentStatus);
}

// Presentation-only selectors for the canonical ClientMember drawer.
// Project rows belong to this member at the selected client, not every
// project in the client catalogue. Feedback is scoped by client_member_id.
const isoDay = value => String(value ?? '').slice(0, 10);

export const feedbackDate = feedback => isoDay(feedback.occurred_on || feedback.created_at);

const byNewest = (items, dateOf) => [...(items || [])].sort((a, b) =>
  dateOf(b).localeCompare(dateOf(a)) || Number(b.id || 0) - Number(a.id || 0));

export const sortedMemberTerms = member => byNewest(member?.terms, item => isoDay(item.valid_from));
export const sortedProjectAssignments = member => byNewest(member?.projects, item => isoDay(item.valid_from));
export const sortedMemberFeedbacks = member => byNewest(member?.feedbacks, feedbackDate);

export function formatMemberDate(value) {
  const date = isoDay(value);
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
  return match ? `${match[3]}.${match[2]}.${match[1]}` : '—';
}

export function memberPeriod(from, to) {
  return `${formatMemberDate(from)} — ${to ? formatMemberDate(to) : 'по настоящее время'}`;
}

export function feedbackProjectLabel(feedback, assignments = [], clientProjects = []) {
  if (!feedback.project_id) return 'По клиенту';
  const id = Number(feedback.project_id);
  const assigned = assignments.find(row => Number(row.project_id) === id);
  const project = clientProjects.find(row => Number(row.id) === id);
  return assigned?.project_name || project?.name || `Проект #${id}`;
}

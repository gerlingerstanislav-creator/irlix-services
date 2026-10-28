/**
 * Recruitment gets effective permissions from its OWN backend.
 * Keycloak/Employees service markers are not frontend permission sources.
 * Legacy mode preserves old navigation only while backend keeps its old gate.
 */
export const canRecruitment = (mode, permissions, permission) =>
  mode === 'legacy'
    || (mode === 'local' && permissions?.[permission] === true);

export const recruitmentAdmission = (mode, permissions) =>
  mode === 'legacy'
    || (mode === 'local' && canRecruitment(mode, permissions, 'recruitment.workspace.view'));

export function candidateCreatePayload(form, mode, permissions) {
  const payload = {
    ...form,
    stack: String(form.stack || '').split(',').map(item => item.trim()).filter(Boolean),
  };
  if (!canRecruitment(mode, permissions, 'recruitment.compensation.manage')) {
    delete payload.salary_expectation;
  }
  return payload;
}

export function offerCreatePayload(process, mode, permissions) {
  const payload = {
    hiring_process_id: process.id,
    role: process.candidate.role || 'Специалист',
    planned_start_date: null,
  };
  if (canRecruitment(mode, permissions, 'recruitment.compensation.manage')) {
    payload.compensation = process.candidate.salary_expectation || '';
  }
  return payload;
}

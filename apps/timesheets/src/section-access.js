// A pending or failed permission lookup is not an authorization denial.
export const resolveSectionAccess = (section, permissionsPhase, allowed) => {
  if (section === 'not-found') return 'not-found';
  if (permissionsPhase === 'loading') return 'loading';
  if (permissionsPhase === 'error') return 'error';
  return allowed ? 'allowed' : 'forbidden';
};

// Service admission does not imply access to the personal Timesheets page.
// Prefer one's own timesheets only when explicitly allowed; otherwise use the
// first permitted page. Never assign permissions or elevate a data scope here.
export const chooseTimesheetsLanding = (permissions = {}) => {
  for (const section of ['mine', 'management', 'analytics', 'audit']) {
    if (permissions[`timesheets.${section}.view`]?.allowed === true) return section;
  }
  return null;
};

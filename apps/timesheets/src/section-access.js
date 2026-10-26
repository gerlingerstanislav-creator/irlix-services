// A pending or failed permission lookup is not an authorization denial.
export const resolveSectionAccess = (section, permissionsPhase, allowed) => {
  if (section === 'not-found') return 'not-found';
  if (permissionsPhase === 'loading') return 'loading';
  if (permissionsPhase === 'error') return 'error';
  return allowed ? 'allowed' : 'forbidden';
};

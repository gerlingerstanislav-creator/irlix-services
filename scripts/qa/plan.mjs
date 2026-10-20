// QA test selection — pure module, safe to import from node:test.
export const suites = [
  { id: 'ui', label: 'Shared UI contracts', files: ['packages/ui/tests'] },
  { id: 'auth', label: 'Shared authentication', files: ['packages/auth/tests'] },
  { id: 'employees', label: 'Employees', files: ['apps/web/tests/staffTree.test.js'] },
  { id: 'clients', label: 'Clients', files: ['apps/clients/tests/workflow.test.js', 'apps/clients/tests/navigation.test.js', 'apps/clients/tests/registryFormat.test.js'] },
  { id: 'timesheets', label: 'Timesheets', files: ['apps/timesheets/tests'] },
  { id: 'migration', label: 'Migration', files: ['apps/migration/tests/migration-console.test.mjs'] },
  { id: 'portal', label: 'Dashboard', files: ['apps/portal/tests'] },
  { id: 'equipment', label: 'Equipment regression', files: ['apps/equipment/tests/contracts.test.mjs'] },
  { id: 'qa', label: 'QA self-tests', files: ['scripts/qa/tests'] }
];

export const domains = ['ui', 'auth', 'employees', 'clients', 'timesheets', 'migration', 'portal', 'vacations', 'equipment', 'specialists', 'recruitment', 'cv', 'platform', 'ci'];
const mapping = [
  ['packages/ui/', ['ui', 'employees', 'clients', 'timesheets', 'portal', 'migration', 'equipment']],
  ['packages/auth/', ['auth', 'ui', 'employees', 'clients', 'timesheets', 'portal', 'migration', 'equipment']],
  ['apps/design-system/', ['ui']],
  ['apps/web/', ['employees']],
  ['services/employees/', ['employees', 'clients', 'timesheets']],
  ['apps/clients/', ['clients']],
  ['services/clients/', ['clients', 'timesheets']],
  ['apps/timesheets/', ['timesheets']],
  ['services/timesheets/', ['timesheets', 'clients']],
  ['apps/migration/', ['migration']],
  ['services/migration/', ['migration']],
  ['services/migration-ops/', ['migration']],
  ['apps/portal/', ['portal']],
  ['services/platform-core/', ['portal', 'auth']],
  ['apps/vacations/', ['vacations']],
  ['services/vacations/', ['vacations', 'timesheets']],
  ['apps/equipment/', ['equipment']],
  ['services/equipment/', ['equipment']],
  ['apps/specialists/', ['specialists']],
  ['services/specialists/', ['specialists']],
  ['apps/recruitment/', ['recruitment']],
  ['services/recruitment/', ['recruitment']],
  ['apps/cv/', ['cv']],
  ['services/cv-converter/', ['cv']],
  ['infra/', ['ci']],
  ['scripts/ci/', ['ci']],
  ['.github/workflows/', ['ci']],
  ['scripts/qa/', ['qa']],
  ['.github/agents/', ['qa']]
];
const suiteIds = new Set(suites.map(x => x.id));
const scopeSuiteIds = { vacations: [], equipment: ['equipment'], specialists: [], recruitment: [], cv: [], platform: ['portal', 'auth'], ci: ['qa'] };
export function makePlan(changedPaths = null, scope = 'changed') {
  if (scope !== 'all' && scope !== 'changed' && !domains.includes(scope)) {
    throw new Error('Unknown QA scope: ' + scope);
  }
  if (changedPaths !== null && (!Array.isArray(changedPaths) || changedPaths.some(x => typeof x !== 'string'))) {
    throw new Error('changedPaths must be an array or null');
  }
  const targets = new Set(), selected = new Set(['qa']);
  const qaInfraChanged = scope === 'changed' && changedPaths !== null && changedPaths.some(p => p.startsWith('scripts/qa/') || p === '.github/workflows/qa.yml' || p === '.github/workflows/ci.yml');
  if (scope === 'all' || (scope === 'changed' && changedPaths === null) || qaInfraChanged) {
    domains.forEach(x => targets.add(x));
    suites.forEach(x => selected.add(x.id));
  } else if (scope !== 'changed') {
    targets.add(scope);
    (suiteIds.has(scope) ? [scope] : scopeSuiteIds[scope] || []).forEach(x => selected.add(x));
  } else {
    for (const path of changedPaths) {
      let matched = false;
      for (const [prefix, affected] of mapping) {
        if (!path.startsWith(prefix)) continue;
        matched = true;
        affected.forEach(id => {
          targets.add(id);
          if (suiteIds.has(id)) selected.add(id);
        });
      }
      if (!matched && /\.(vue|php|js|mjs|cjs|ts|tsx|py)$/.test(path)) targets.add('unmapped-code');
    }
  }
  const gaps = [...targets].filter(x => !suiteIds.has(x) && x !== 'qa');
  if (targets.has('unmapped-code')) gaps.push('Unmapped source changes require manual QA scoping');
  return {
    scope, changedPaths, suites: suites.filter(x => selected.has(x.id)),
    targets: [...targets].sort(), gaps: [...new Set(gaps)], browserCovered: false,
    note: 'Node contracts only; browser E2E and backend integration are NOT TESTED. Production/test data are never modified.'
  };
}

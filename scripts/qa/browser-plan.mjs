import { makePlan } from './plan.mjs';

export const browserCases = Object.freeze([
  { id: 'design-system-navigation', suite: 'ui', app: 'design-system', port: 5196, test: 'apps/design-system/tests/navigation.browser.cjs', label: 'Design system: grouped catalog, anchors and history' },
  { id: 'ui-registry-table', suite: 'ui', app: 'design-system', port: 5196, test: 'apps/design-system/tests/registry.browser.cjs', label: 'Shared registry: fixed header and body-only scroll' },
  { id: 'equipment-registry', suite: 'equipment', app: 'equipment', port: 5195, test: 'apps/equipment/tests/registry.browser.cjs', label: 'Equipment: scroll, columns, form colors, filters and deletion' },
  { id: 'clients-registry', suite: 'clients', app: 'clients', port: 5193, test: 'apps/clients/tests/registry.browser.cjs', label: 'Clients registry: layouts, filters, scrolling' },
  { id: 'clients-partner-members', suite: 'clients', app: 'clients', port: 5193, test: 'apps/clients/tests/partner-members.browser.cjs', label: 'Clients: partner member edit and save' },
  { id: 'migration-console', suite: 'migration', app: 'migration', port: 5173, test: 'apps/migration/tests/migration-console.browser.cjs', label: 'Migration: snapshots, permissions, recovery and responsiveness' }
]);

export function browserPlan(changedPaths = null, scope = 'changed') {
  const contract = makePlan(changedPaths, scope);
  const ids = new Set(contract.suites.map(s => s.id));
  const cases = browserCases.filter(x => ids.has(x.suite));
  return {
    scope,
    changedPaths: contract.changedPaths,
    cases,
    apps: [...new Set(cases.map(x => x.app))],
    missing: contract.gaps,
    note: 'Only local Vite previews and synthetic auth/API fixtures. No live backend, TEST deployment or production is contacted.'
  };
}

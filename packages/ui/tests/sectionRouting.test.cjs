const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname, '../src/sectionRouting.js'), 'utf8');

function sync(path, navigationLabel = null) {
  let url = new URL(path, 'http://localhost');
  const frames = [];
  const writes = [];
  const window = {
    get location() { return url; },
    history: {
      state: null,
      replaceState(state, title, target) { writes.push(target); url = new URL(target, url); },
      pushState(state, title, target) { writes.push(target); url = new URL(target, url); },
    },
    requestAnimationFrame(callback) { frames.push(callback); },
    addEventListener() {},
  };
  let clickHandler = null;
  const document = { documentElement: {}, querySelectorAll: () => [], addEventListener(type, handler) { if (type === 'click') clickHandler = handler; } };
  vm.runInNewContext(source, { window, document, URLSearchParams, MutationObserver: class { observe() {} } });
  frames.splice(0).forEach(callback => callback());
  if (navigationLabel) {
    const button = { getAttribute: key => key === 'aria-label' ? navigationLabel : null, textContent: navigationLabel,
      matches: selector => selector.includes('.nav-entry button') };
    clickHandler({ target: { closest: selector => selector.includes('ui-app-sidebar') ? button : null } });
  }
  return { path: url.pathname + url.search + url.hash, writes };
}

for (const path of ['/employees/', '/employees/staff-positions', '/employees/staff-positions/', '/employees/?department_id=synthetic', '/employees/roles']) {
  test(`shared legacy adapter leaves app-owned route ${path} unchanged`, () => {
    assert.deepEqual(sync(path), { path, writes: [] });
  });
}
test('legacy section-only service retains route normalization', () => {
  assert.equal(sync('/vacations/').path, '/vacations/mine/');
  assert.deepEqual(sync('/vacations/unknown?filter=synthetic'), { path: '/vacations/unknown?filter=synthetic', writes: [] });
});

for (const path of ['/design-system/', '/design-system/#section-fields', '/design-system/foundations/', '/design-system/components/?section=filters#section-checkbox', '/design-system/?section=patterns']) {
  test(`shared legacy adapter leaves catalog-owned navigation ${path} unchanged`, () => {
    assert.deepEqual(sync(path), { path, writes: [] });
  });
}

test('new Clients and Timesheets sidebar labels map to canonical section URLs', () => {
  assert.equal(sync('/clients/clients/', 'Партнёры').path, '/clients/partners/');
  assert.equal(sync('/clients/clients/', 'Подключения').path, '/clients/members/');
  assert.equal(sync('/timesheets/mine/', 'ТШ партнёров').path, '/timesheets/partners/');
  assert.equal(sync('/clients/partners/').path, '/clients/partners/');
  assert.equal(sync('/timesheets/partners/').path, '/timesheets/partners/');
});

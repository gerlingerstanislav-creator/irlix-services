const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname, '../src/sectionRouting.js'), 'utf8');

function sync(path) {
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
  const document = { documentElement: {}, querySelectorAll: () => [], addEventListener() {} };
  vm.runInNewContext(source, { window, document, URLSearchParams, MutationObserver: class { observe() {} } });
  frames.splice(0).forEach(callback => callback());
  return { path: url.pathname + url.search + url.hash, writes };
}

for (const path of ['/employees/', '/employees/staff-positions', '/employees/staff-positions/', '/employees/?department_id=synthetic', '/employees/roles']) {
  test(`shared legacy adapter leaves app-owned route ${path} unchanged`, () => {
    assert.deepEqual(sync(path), { path, writes: [] });
  });
}
test('legacy section-only service retains route normalization', () => {
  assert.equal(sync('/vacations/').path, '/vacations/mine/');
  assert.equal(sync('/vacations/unknown?filter=synthetic').path, '/vacations/mine/?filter=synthetic');
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { routes } from '../src/routes.js';
import { readFileSync } from 'node:fs';

const byName = Object.fromEntries(routes.filter(route => route.name).map(route => [route.name, route]));

test('Employees owns canonical section routes and keeps staff positions separate', () => {
  assert.equal(byName.employees.path, '/');
  assert.equal(byName.positions.path, '/organization');
  assert.equal(byName.staffPositions.path, '/positions');
  for (const name of ['employees','positions','staffPositions','fot','roles','permissions','audit']) {
    assert.equal(byName[name].meta.section, name);
  }
});

test('Legacy organizational paths redirect, unknown paths render 404', () => {
  for (const alias of ['/departments','/staff-positions']) {
    assert.equal(routes.find(route => route.path === alias)?.redirect.name, 'positions');
  }
  assert.equal(byName['not-found'].path, '/:pathMatch(.*)*');
  assert.equal(byName['not-found'].meta.section, 'not-found');
});

test('Employees navigation uses Vue Router without manual browser history', () => {
  const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
  const main = readFileSync(new URL('../src/main.js', import.meta.url), 'utf8');
  const router = readFileSync(new URL('../src/router.js', import.meta.url), 'utf8');
  assert.match(router, /createWebHistory\(import\.meta\.env\.BASE_URL\)/);
  assert.match(main, /app\.use\(router\)/);
  assert.match(app, /useRoute\(\)/);
  assert.match(app, /router\.push\(/);
  assert.doesNotMatch(app, /history\.(?:pushState|replaceState)/);
  assert.doesNotMatch(app, /addEventListener\('popstate'/);
});

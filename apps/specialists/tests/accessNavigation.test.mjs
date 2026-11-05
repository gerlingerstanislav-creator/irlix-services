import test from 'node:test';
import assert from 'node:assert/strict';
import { accessibleSpecialistsNavigation, specialistsLandingPath } from '../src/accessNavigation.js';

const ids = (permissions, admin = false) =>
  accessibleSpecialistsNavigation(permissions, admin).map(item => item.id);

test('catalog-only grant exposes only technologies and competencies', () => {
  assert.deepEqual(ids({ catalog_view: true, catalog_manage: false, profiles_view: false }), ['competencies']);
});

test('profile read grant exposes profile list without catalog or role administration', () => {
  assert.deepEqual(ids({ profiles_view: true, profiles_manage: false, catalog_view: false }), ['dashboard', 'people']);
});

test('edit permissions do not implicitly expand visible sections', () => {
  assert.deepEqual(ids({ profiles_manage: true, catalog_manage: true }), []);
});

test('admin role permissions menu is independent of staff and catalog data grants', () => {
  assert.deepEqual(ids(null, true), ['role-permissions']);
  assert.deepEqual(ids({ profiles_view: true, catalog_view: true }, true), [
    'dashboard', 'people', 'competencies', 'role-permissions',
  ]);
});

test('catalog-only users enter their readable screen instead of a dashboard 403', () => {
  assert.equal(specialistsLandingPath({ catalog_view: true }), '/competencies');
  assert.equal(specialistsLandingPath({ profiles_view: true, catalog_view: true }), '/');
  assert.equal(specialistsLandingPath({ profiles_manage: true }), null);
});

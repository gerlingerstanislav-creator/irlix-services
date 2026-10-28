import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolveSectionAccess, chooseTimesheetsLanding } from '../src/section-access.js';

test('Timesheets does not show a transient 403 while permissions are loading', () => {
  assert.equal(resolveSectionAccess('mine', 'loading', false), 'loading');
  assert.equal(resolveSectionAccess('management', 'loading', false), 'loading');
});

test('Permission lookup failure shows a retryable error, not a denial', () => {
  assert.equal(resolveSectionAccess('mine', 'error', false), 'error');
});

test('Only resolved permissions decide between access and a real 403', () => {
  assert.equal(resolveSectionAccess('mine', 'ready', true), 'allowed');
  assert.equal(resolveSectionAccess('mine', 'ready', false), 'forbidden');
  assert.equal(resolveSectionAccess('audit', 'ready', false), 'forbidden');
  assert.equal(resolveSectionAccess('not-found', 'loading', false), 'not-found');
});

test('Timesheets view gates 403 and content behind permission resolution', () => {
  const source = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
  assert.match(source, /const permissionsPhase = ref\('loading'\)/);
  assert.match(source, /<UiErrorState v-else-if="pageAccess === 'forbidden'" :status="403"/);
  assert.match(source, /<UiLoader v-if="pageLoading" page label="Загрузка таймшитов"/);
  assert.match(source, /<section v-else-if="pageAccess === 'allowed' && section === 'mine' && workspace"/);
});

test('Partner-only Timesheets grant opens Management without staff access', () => {
  const partner = { 'timesheets.partner-hours.view': { allowed: true, scope: 'own' } };
  assert.equal(chooseTimesheetsLanding(partner), 'management');
  assert.equal(chooseTimesheetsLanding({ 'timesheets.partner-hours.manage': { allowed: true, scope: 'own' } }), null);
  assert.equal(chooseTimesheetsLanding({ 'timesheets.management.view': { allowed: true, scope: 'team' } }), 'management');
  assert.equal(chooseTimesheetsLanding({ 'timesheets.mine.view': { allowed: true, scope: 'own' }, ...partner }), 'mine');
  const source = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
  assert.match(source, /localAuthorization\.value && can\('timesheets\.partner-hours\.view'\)/);
  assert.match(source, /can\('timesheets\.management\.view'\)\s*\? api/);
  assert.match(source, /canViewPartnerHours\(\)\s*\? api/);
  assert.match(source, /else if \(section\.value === 'audit'\) await loadAudit/);
});

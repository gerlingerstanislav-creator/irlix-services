import test from 'node:test';
import assert from 'node:assert/strict';
import { routes } from '../src/routes.js';

const named = Object.fromEntries(routes.filter(route => route.name).map(route => [route.name, route]));
const redirect = path => routes.find(route => route.path === path)?.redirect;

test('Timesheets canonical routes remain independent and unknown paths stay 404', () => {
  assert.equal(named.mine.path, '/mine/');
  assert.equal(named.management.path, '/management/');
  assert.equal(named.analytics.path, '/commercial-load/');
  assert.equal(named.audit.path, '/audit/');
  assert.equal(named['not-found'].meta.section, 'not-found');
});

test('Legacy partner links redirect to unified Management with filters and hash intact', () => {
  const to = { query: { month:'2026-10', client_id:'31', employee_id:'9' }, hash:'#day' };
  assert.deepEqual(redirect('/partners/')(to), { name:'management', query:to.query, hash:'#day' });
  assert.deepEqual(redirect('/')({query:{...to.query,section:'partners'},hash:to.hash}),{
    name:'management',query:to.query,hash:to.hash
  });
  assert.equal(redirect('/')({query:{section:'missing'},hash:''}).name,'not-found');
});

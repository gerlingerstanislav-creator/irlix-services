import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8');

test('Dashboard overview uses 2:1 attention and services columns, tasks stay full-width', () => {
  const portal = read('../src/main.js');
  const styles = read('../src/attention.css');
  assert.match(portal, /dashboard-home--overview/);
  assert.match(portal, /dashboard-home--tasks/);
  assert.match(portal, /layout:'list'/);
  assert.match(styles, /grid-template-columns:minmax\(0,2fr\) minmax\(0,1fr\)/);
  assert.match(styles, /max-width:1000px/);
  assert.match(styles, /align-items:start/);
  assert.doesNotMatch(styles, /align-items:stretch|align-self:stretch/);
  assert.match(styles, /max-height:calc\(100dvh/);
  assert.match(styles, /overflow-y:auto/);
  assert.match(styles, /max-height:none;overflow:visible/);
  assert.match(portal, /showToolbar:false/);
  assert.match(portal, /showAll:section\.value==='dashboard'/);
  const attention = read('../src/AttentionCenter.vue');
  assert.match(attention, /props\.full\|\|props\.showAll/);
  assert.match(styles, /\.dashboard-home--overview\{grid-template-columns:minmax\(0,1fr\)\}/);
});

test('Service launcher list stays grouped and conveys availability accessibly', () => {
  const component = read('../../../packages/ui/src/components/UiServiceDashboard.vue');
  assert.match(component, /getVisibleServiceGroups\(props\.platformAdmin,props\.serviceAccess\)/);
  assert.match(component, /:data-layout="layout"/);
  assert.match(component, /showToolbar:\{type:Boolean,default:true\}/);
  assert.match(component, /v-if="showToolbar"/);
  assert.match(component, /name="power"/);
  assert.match(component, /:aria-label="service\.available \? 'Сервис доступен' : 'Сервис недоступен'"/);
  assert.match(component, /\.ui-service-dashboard\[data-layout="list"\] \.ui-service-dashboard__grid\{grid-template-columns:minmax\(0,1fr\)/);
  assert.match(component, /\.ui-service-dashboard__grid\{display:grid;grid-template-columns:repeat\(4,minmax\(0,1fr\)\)/);
  assert.match(component, /service\.available&&service\.href\?'a':'article'/);
});

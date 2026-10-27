import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fetchAllReportingYearPages } from '../src/reportingYearPages.js';

const root = new URL('../../../', import.meta.url);
const source = path => readFileSync(new URL(path, root), 'utf8');

test('Gantt automatically collects every report page instead of waiting for scroll', async () => {
  const requested = [];
  const loaded = await fetchAllReportingYearPages(async page => {
    requested.push(page);
    return {
      data: { reportingPeriods: Array.from({length: page < 3 ? 80 : 7}, (_, i) => ({id:(page-1)*80+i+1})) },
      meta: {has_more: page < 3},
    };
  });
  assert.deepEqual(requested, [1, 2, 3]);
  assert.equal(loaded.length, 167);
  assert.equal(loaded[0].id, 1);
  assert.equal(loaded.at(-1).id, 167);
});

test('An abandoned year does not expose partial or stale results', async () => {
  let current = true;
  const loaded = await fetchAllReportingYearPages(async page => {
    if (page === 2) current = false;
    return {data:{reportingPeriods:[{id:page}]},meta:{has_more:true}};
  }, () => current);
  assert.equal(loaded, null);
});

test('Broken pagination must fail visibly rather than quietly hiding old periods', async () => {
  await assert.rejects(
    fetchAllReportingYearPages(async page => page === 1
      ? {data:{reportingPeriods:[{id:1}]},meta:{has_more:true}}
      : {data:{reportingPeriods:[]},meta:{has_more:true}}),
    /пустую страницу/
  );
  await assert.rejects(fetchAllReportingYearPages(async () => ({data:{},meta:{has_more:false}})), /Не удалось получить/);
});

test('Gantt uses a year-scoped API and reloads for every year/mode change', () => {
  const app = source('apps/clients/src/App.vue');
  assert.match(app, /params\.set\('year', String\(year\)\)/);
  assert.match(app, /section === 'reports' && reportMode\.value === 'gantt'[\s\S]*fetchReportingYear\(generation, Number\(reportGanttYear\.value\)\)/);
  assert.match(app, /watch\(\[reportMode, reportGanttYear\],[\s\S]*loadSection\(\)/);
  assert.match(app, /generation !== viewGeneration \|\| periods === null/);
  assert.match(app, /else if \(section\) \{\s*tasks\.push\(fetchOverviewPage\(section, 1, generation\)\)/);
});

test('Backend limits the year to periods with any day inside it without widening client scope', {skip: !existsSync(new URL('services/clients/app/Services/ClientsOverviewPageService.php',root))}, () => {
  const service = source('services/clients/app/Services/ClientsOverviewPageService.php');
  const controller = source('services/clients/app/Http/Controllers/ClientsController.php');
  assert.match(controller, /'year' => \['sometimes', 'integer', 'between:1900,2100'\]/);
  assert.match(controller, /'request_target', 'year'\]/);
  assert.match(service, /'reports' && isset\(\$filters\['year'\]\)/);
  assert.match(service, /whereDate\('period_start', '<=', sprintf\('%04d-12-31', \$year\)\)/);
  assert.match(service, /whereDate\('period_end', '>=', sprintf\('%04d-01-01', \$year\)\)/);
  assert.match(service, /in_array\(\$section, \['clients', 'reports'\], true\) && \$visibleClientIds !== null/);
});

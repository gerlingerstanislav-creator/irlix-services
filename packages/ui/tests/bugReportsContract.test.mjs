import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read=path=>readFileSync(new URL('../../../'+path,import.meta.url),'utf8');
test('bug reports use shared bottom sidebar and only authenticated submission',()=>{
 const sidebar=read('packages/ui/src/components/UiAppSidebar.vue');
 const shell=read('packages/ui/src/components/UiAppShell.vue');
 const report=read('packages/ui/src/components/UiBugReport.vue');
 assert.match(sidebar,/UiBugReport v-if="accountEnabled && reportSubmit"/);
 assert.match(sidebar,/:service="currentService"/);
 assert.match(shell,/:report-submit="reportSubmit"/);
 assert.match(report,/event\.key === 'Enter' && !event\.shiftKey/);
 assert.match(report,/isComposing/);
 assert.match(report,/if \(sending\.value \|\| !description\.value\.trim\(\)\) return/);
 assert.match(report,/page_path: window\.location\.pathname/);
});
test('bug report API has authorization boundaries and is routed through Platform Core',()=>{
 const api=read('services/platform-core/routes/reports.php');
 const routes=read('services/platform-core/routes/api.php');
 const controller=read('services/platform-core/app/Http/Controllers/BugReportController.php');
 const identity=read('services/platform-core/app/Reports/ReporterIdentity.php');
 const nginx=read('infra/nginx/irlix-services.conf');
 const docker=read('services/platform-core/Dockerfile');
 assert.match(routes,/require __DIR__\.'\/reports\.php'/);
 assert.match(api,/Route::post\('\/reports'/);
 assert.match(controller,/\$auth->authorize\(\$request\)/);
 assert.match(identity,/EMPLOYEES_URL/);
 assert.match(nginx,/location ~ \^\/api\/reports/);
 assert.match(docker,/COPY routes\/reports\.php/);
});
test('all active frontend shells wire authenticated report submission',()=>{
 for(const [app,path] of Object.entries({
 dashboard:'apps/portal/src/main.js',employees:'apps/web/src/components/AppSidebar.vue',
 vacations:'apps/vacations/src/components/AppSidebar.vue',clients:'apps/clients/src/components/ClientsSidebar.vue',
 timesheets:'apps/timesheets/src/App.vue',specialists:'apps/specialists/src/App.vue',
 equipment:'apps/equipment/src/App.vue',finance:'apps/finance/src/App.vue',
 recruitment:'apps/recruitment/src/App.vue','cv-converter':'apps/cv/src/App.vue',
 system:'apps/system/src/main.js','design-system':'apps/design-system/src/App.vue',brandbook:'apps/brandbook/src/App.vue'
 })){
  assert.match(read(path),/report[Ss]ubmit|report-submit/,app);
 }
});
test('System provides a reports route and management page',()=>{
 assert.match(read('apps/system/src/routes.js'),/name:'reports'/);
 assert.match(read('apps/system/src/main.js'),/BugReportsPage/);
 const page=read('apps/system/src/BugReportsPage.vue');
 assert.match(page,/Скачать все тикеты/);
 assert.match(page,/Закрыть выбранные/);
 assert.match(page,/\/api\/reports\/export/);
});

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { allowedClientSections, sectionPermissions } from '../src/navigation.js';
const permissions = Object.fromEntries(Object.values(sectionPermissions).map(key=>[key,{allowed:true,scope:'all'}]));
test('Clients staff see pages granted by the permission matrix',()=>{
 for(const role of ['account-manager','sales-manager','accounting-head','sales-head','client-service-head']) {
  const pages=allowedClientSections({roles:[role],permissions});
  assert.ok(pages.includes('positions'));assert.ok(pages.includes('requests'));
  assert.ok(pages.includes('members'));assert.ok(pages.includes('specialists'));assert.ok(!pages.includes('tenders'));
 }
});
test('production heads can access every page explicitly granted by the matrix',()=>{
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions}),Object.keys(sectionPermissions));
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{'clients.view':{allowed:true,scope:'team'}}}),['clients']);
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{'clients.view':{allowed:true,scope:'team'},'members.view':{allowed:true,scope:'team'}}}),['clients','members']);
});
test('members page requires members.view independently of client management',()=>{
 const access={roles:['account-manager'],permissions:{'clients.view':{allowed:true,scope:'all'},'clients.manage':{allowed:true,scope:'all'}}};
 assert.ok(!allowedClientSections(access).includes('members'));
 assert.ok(allowedClientSections({...access,permissions:{...access.permissions,'members.view':{allowed:true,scope:'own'}}}).includes('members'));
});
test('page policy never grants missing permissions, preserves funnel-only access and admin exception',()=>{
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{}}),[]);
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{'attempts.analytics.view':{allowed:true}}}),['attempts']);
 assert.deepEqual(allowedClientSections({platform_admin:true,roles:['department-manager'],permissions}),Object.keys(sectionPermissions));
 assert.deepEqual(allowedClientSections({roles:['platform-tester'],permissions:{'clients.view':{allowed:true,scope:'all'}}}),['clients']);
});

test('Clients routes use documented URLs and preserve legacy query and hash', async () => {
 const { routes } = await import('../src/routes.js');
 const named = Object.fromEntries(routes.filter(r=>r.name).map(r=>[r.name,r]));
 assert.equal(named.clients.path, '/clients/');
 assert.equal(named.specialists.path, '/specialists/');
 assert.equal(named.members.path, '/members/', 'Historical connections URL stays accessible without a menu entry');
 assert.equal(named.reports.path, '/reporting-periods/');
 assert.equal(named['attempt-funnel'].path, '/attempt-funnel/');
 assert.equal(named['attempt-funnel'].meta.section, 'attempts');
 const legacy = Object.fromEntries(routes.filter(r=>r.redirect).map(r=>[r.path,r]));
 for (const [path,name] of [['/','clients'],['/reports/','reports']]) {
   const redirect=legacy[path].redirect;
   assert.deepEqual(redirect({query:{month:'2026-10',client_id:'4'},hash:'#detail'}),{
     name,query:{month:'2026-10',client_id:'4'},hash:'#detail'
   });
 }
 assert.equal(named['not-found'].meta.section,'not-found');
});
test('Clients funnel direct links respect the analytics grant and browser route state',()=>{
 const app=readFileSync(new URL('../src/App.vue', import.meta.url),'utf8');
 assert.match(app,/watch\(\(\) => route\.name,[\s\S]*?immediate:\s*true/);
 assert.match(app,/route\.name === 'attempt-funnel' && !can\('attempts\.analytics\.view'\)/);
 assert.match(app,/@update:attempt-mode="changeAttemptMode"/);
 assert.match(app,/mode === 'funnel' \? 'attempt-funnel' : 'attempts'/);
});


test('progressive Clients API keeps scoped per-section server paging and search', () => {
 const app=readFileSync(new URL('../src/App.vue', import.meta.url),'utf8');
 const page=readFileSync(new URL('../../../services/clients/app/Services/ClientsOverviewPageService.php', import.meta.url),'utf8');
 const access=readFileSync(new URL('../../../services/clients/app/Http/Middleware/ClientsAccountingAccess.php', import.meta.url),'utf8');
 assert.match(app,/per_page:'80'/);
 assert.match(app,/new URLSearchParams\(params\)/);
 assert.match(app,/activity = clientFilters\.activity/);
 assert.match(app,/department_employee_ids/);
 assert.match(app,/manager_employee_ids/);
 assert.match(page,/forPage\(\$page, \$perPage\)->get\(\)/);
 assert.match(page,/whereExists/);
 assert.match(page,/groupedByClient\(\$ids\)/);
 assert.match(page,/when\(\$visibleClientIds !== null/);
 assert.match(access,/clients_overview_contact_ids/);
 assert.match(access,/clients_overview_client_ids/);
 assert.match(access,/clients_overview_lead_employee_ids/);
});
test('scroll pagination has a visible bottom loader, retries, and no hidden prefetch of all clients', () => {
 const app=readFileSync(new URL('../src/App.vue', import.meta.url),'utf8');
 const css=readFileSync(new URL('../src/style.css', import.meta.url),'utf8');
 assert.match(app,/new IntersectionObserver/);
 assert.match(app,/onContentScroll/);
 assert.match(app,/loadMoreSentinel/);
 assert.match(app,/clients-load-spinner/);
 assert.match(app,/Загружаем ещё клиентов/);
 assert.match(app,/Ошибка подгрузки\. Повторить/);
 assert.match(app,/if \(!section \|\| !hasMore\.value \|\| loading\.value \|\| loadingMore\.value/);
 assert.match(css,/@keyframes clients-load-spin/);
});

test('partners and specialists use a dedicated menu section and inline editors',()=>{
 const sidebar=readFileSync(new URL('../src/components/ClientsSidebar.vue',import.meta.url),'utf8');
 const partner=readFileSync(new URL('../src/components/PartnersRegistry.vue',import.meta.url),'utf8');
 const roster=readFileSync(new URL('../src/components/SpecialistsAbsenceRoster.vue',import.meta.url),'utf8');
 assert.doesNotMatch(sidebar,/id: 'members', label: 'Подключения'/);
 assert.match(sidebar,/id: 'partners', label: 'Партнёры', icon: 'building'/);
 assert.match(sidebar,/id: 'specialists', label: 'Специалисты', icon: 'users', groupStart: true/);
 assert.match(sidebar,/id: 'contacts', label: 'Контактные лица', icon: 'contact', groupStart: !props.allowedSections.includes\('specialists'\)/);
 assert.ok(sidebar.indexOf("id: 'partners'") < sidebar.indexOf("id: 'specialists'"));
 assert.ok(sidebar.indexOf("id: 'specialists'") < sidebar.indexOf("id: 'contacts'"));
 assert.doesNotMatch(partner,/class="partner-pencil"/);
 assert.match(partner,/editingField === field.key/);
 assert.match(roster,/<UiPeriodPicker v-model="month" mode="month" \/>/);
 assert.match(roster,/statusText/);
});

test('specialists calendar is strictly controlled by the global-scope permission matrix',()=>{
 const user={roles:['sales-manager'],permissions:{}};
 assert.ok(!allowedClientSections(user).includes('specialists'));
 assert.ok(allowedClientSections({platform_admin:true,permissions:{}}).includes('specialists'));
 for(const scope of ['none','own','team']) {
   assert.ok(!allowedClientSections({roles:['sales-manager'],permissions:{'specialists.view':{allowed:true,scope}}}).includes('specialists'));
 }
 const access={...user,permissions:{'specialists.view':{allowed:true,scope:'all'}}};
 assert.ok(allowedClientSections(access).includes('specialists'));
 assert.ok(!allowedClientSections({...access,permissions:{'specialists.view':{allowed:false,scope:'all'}}}).includes('specialists'));
});
test('specialists backend contracts enforce the editable matrix and reject non-global scopes',()=>{
 const vacations=readFileSync(new URL('../../../services/vacations/app/Http/Controllers/ClientServiceCalendarController.php',import.meta.url),'utf8');
 const clients=readFileSync(new URL('../../../services/clients/app/Http/Controllers/ClientSpecialistsController.php',import.meta.url),'utf8');
 const roster=readFileSync(new URL('../src/components/SpecialistsAbsenceRoster.vue',import.meta.url),'utf8');
 assert.match(vacations,/client-service/);
 assert.match(vacations,/abort_unless\(\$inService \|\| \$platformAdmin, 403/);
 assert.match(vacations,/in_array\(\$actorId, \$activeIds, true\)/);
 assert.doesNotMatch(vacations,/['"]type['"]\s*=>\s*\$item/);
 assert.match(clients,/specialists\.view/);
 assert.match(clients,/scope/);
 assert.match(vacations,/checkClientsSpecialistsPermission/);
 assert.match(vacations,/\/permissions\/me/);
 assert.match(clients,/platform_admin/);
 assert.match(roster,/client-service-calendar\?month=/);
});


test('each visible Clients detail card is tied to its effective permission and readonly actions',()=>{
 const app=readFileSync(new URL('../src/App.vue',import.meta.url),'utf8');
 const clientCard=readFileSync(new URL('../src/components/ClientCard.vue',import.meta.url),'utf8');
 const contactCard=readFileSync(new URL('../src/components/ContactPersonCard.vue',import.meta.url),'utf8');
 const memberCard=readFileSync(new URL('../src/components/ClientMembersRegistry.vue',import.meta.url),'utf8');
 const access=readFileSync(new URL('../../../services/clients/app/Http/Middleware/ClientsAccountingAccess.php',import.meta.url),'utf8');
 assert.match(app,/expandedClients\.has\(c\.id\) && can\('members\.view'\)/);
 assert.match(app,/selectedClientMember && can\('members\.view'\)/);
 assert.match(app,/:can-view-reports="can\('reports\.view'\)"/);
 assert.match(app,/:can-manage-reports="can\('reports\.manage'\)"/);
 assert.match(app,/:can-manage="can\('contacts\.manage'\)"/);
 assert.match(clientCard,/props\.canViewReports/);
 assert.match(clientCard,/props\.canViewContacts/);
 assert.match(clientCard,/v-if="canManageReports"/);
 assert.match(contactCard,/v-if="!isLink && canManage"/);
 assert.match(memberCard,/if\(!res\.ok\)throw new Error/);
 assert.match(access,/if \(!\$canReadCardSection\('reports\.view'\)\)/);
 assert.match(access,/if \(!\$canReadCardSection\('contacts\.view'\)\)/);
 assert.match(access,/client_members'\] = \[\]/);
});

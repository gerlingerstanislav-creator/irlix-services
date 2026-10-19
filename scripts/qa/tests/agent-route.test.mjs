import { test } from 'node:test';
import assert from 'node:assert/strict';
import { routeChanges,chooseSpecs,reviewDecision } from '../../agents/route.mjs';

test('Service developer sees own full-stack paths',()=>{
 const r=routeChanges(['apps/timesheets/src/App.vue','services/timesheets/app/Timesheet.php']);
 assert.deepEqual(r.services,['timesheets']);
 assert.deepEqual(r.developers,['Developer:timesheets']);
 assert.deepEqual(r.roles,['QA','Analyst','Designer','PM']);
});
test('Cross-service work calls platform without recursive context',()=>{
 const r=routeChanges(['services/clients/app/a.php','services/timesheets/app/b.php']);
 assert.deepEqual(r.services,['clients','timesheets']);
 assert.ok(r.roles.includes('PlatformEngineer'));
 assert.ok(!r.roles.includes('Designer'));
});
test('Shared UI/Auth and CI call platform, UI calls designer',()=>{
 const shared=routeChanges(['packages/ui/src/index.js']);
 assert.ok(shared.roles.includes('Designer'));
 assert.ok(shared.roles.includes('PlatformEngineer'));
 assert.deepEqual(shared.services,[]);
 const infra=routeChanges(['.github/workflows/ci.yml']);
 assert.ok(infra.roles.includes('PlatformEngineer'));
 assert.ok(!infra.roles.includes('Designer'));
});
test('Migration and Employees special frontend/backend locations',()=>{
 assert.deepEqual(routeChanges(['apps/web/src/main.js','services/employees/app/A.php']).services,['employees']);
 assert.deepEqual(routeChanges(['services/migration-ops/app/X.php']).services,['migration']);
 assert.deepEqual(routeChanges(['services/cv-converter/app/X.php']).services,['cv']);
});
test('Idea selection stays limited to one service and prioritized specs',()=>{
 const paths=['company-internal-services-81-2.4/services/clients/UI.md','company-internal-services-81-2.4/services/clients/README.md','company-internal-services-81-2.4/services/clients/ACCEPTANCE.md','company-internal-services-81-2.4/services/employees/REQUIREMENTS.md'];
 assert.deepEqual(chooseSpecs('clients',paths,2),[paths[2],paths[1]]);
});
test('Reviewer negative or unknown overrides PM no-findings summary',()=>{
 assert.equal(reviewDecision([{status:'NO_FINDINGS'},{status:'NEEDS_INFO'}]),'NEEDS_INFO');
 assert.equal(reviewDecision([{status:'CHANGES_REQUESTED'},{status:'NO_FINDINGS'}]),'CHANGES_REQUESTED');
 assert.equal(reviewDecision([{status:'NO_FINDINGS'}]),'REVIEWED_NO_FINDINGS');
});

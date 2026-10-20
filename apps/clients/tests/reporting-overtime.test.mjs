import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const root = resolve(fileURLToPath(new URL('.', import.meta.url)), '../../..');
const source = path => readFileSync(resolve(root, path), 'utf8');
// The Clients web Docker image intentionally has no PHP/backend files.
// On a full GitHub checkout, these cross-service contract tests are mandatory;
// in isolated frontend-only image builds they are explicitly reported skipped.
const backendPresent = existsSync(resolve(root, 'services/clients/app/Http/Controllers/ReportingPeriodsController.php'));

test('ReportingPeriod copies its multiplier from the client and exposes a separate editor', { skip: !backendPresent }, ()=>{
  const controller=source('services/clients/app/Http/Controllers/ReportingPeriodsController.php');
  const ui=source('apps/clients/src/components/ReportingPeriodsView.vue');
  assert.match(controller, /'overtime_multiplier' => \$client->overtime_multiplier/);
  assert.match(controller, /public function updateOvertimeMultiplier/);
  assert.match(controller, /status === 'Счет оплачен'/);
  assert.match(ui, /Коэффициент переработок/);
  assert.match(ui, /saveOvertimeMultiplier/);
});
test('Client default is independent from date-versioned MemberTerms', { skip: !backendPresent }, ()=>{
  const migration=source('services/clients/database/migrations/2026_10_20_050000_reporting_period_overtime.php');
  const card=source('apps/clients/src/components/ClientCard.vue');
  assert.match(migration, /Schema::table\('clients'/);
  assert.match(migration, /Schema::table\('reporting_periods'/);
  assert.doesNotMatch(migration, /Schema::table\('member_terms'/);
  assert.match(card, /Коэффициент переработок/);
});
test('AM independently edits regular and overtime hours while worked hours stay factual', { skip: !backendPresent }, ()=>{
  const controller=source('services/clients/app/Http/Controllers/ReportingPeriodsController.php');
  const ui=source('apps/clients/src/components/ReportingPeriodsView.vue');
  assert.match(controller, /'overtime_hours' => \['sometimes'/);
  assert.match(controller, /approved_overtime_hours/);
  assert.match(ui, /approved_overtime_hours/);
  assert.match(ui, /\{\{num\(card\.summary\.approved_overtime_hours\)\}\}/);
  assert.match(ui, /overtime_hours/);
  assert.match(ui, /\/ \{\{num\(term.worked_hours\)\}\}/);
});
test('Clients owns editable snapshots and separately persists commercial hours', { skip: !backendPresent }, ()=>{
  const period=source('services/clients/app/Http/Controllers/ReportingPeriodsController.php');
  const api=source('services/clients/routes/api.php');
  assert.match(period, /'overtime_multiplier' => \$client->overtime_multiplier/);
  assert.match(period, /'approved_overtime_hours'/);
  assert.match(api, /reporting-periods\/\{period\}\/overtime-multiplier/);
  // Finance integration is intentionally outside the Clients-only release.
});


test('Every nested reporting-period action is restricted by owning-client scope', { skip: !backendPresent }, ()=>{
  const middleware=source('services/clients/app/Http/Middleware/ClientsAccountingAccess.php');
  const excerpt=middleware.match(/elseif \(preg_match\('(#[^'\n]+)', \$path, \$m\)\) \{\s*\/\/ Subresource/);
  assert.ok(excerpt, 'Period-subresource middleware guard must be present');
  const phpRegex=excerpt[1];
  const matcher=new RegExp(phpRegex.slice(1,-1).replaceAll('\\\\','\\'));
  for (const path of [
    'api/reporting-periods/12',
    'api/reporting-periods/12/notes',
    'api/reporting-periods/12/approved-hours/15',
    'api/reporting-periods/12/overtime-multiplier',
  ]) assert.match(path,matcher);
  for (const path of [
    'api/reporting-periods/12/overtime-multiplier/extra',
    'api/reporting-periods/12/other-write',
    'api/reporting-periods/invalid/approved-hours/15',
  ]) assert.doesNotMatch(path,matcher);
  assert.match(middleware,/\$ownsClientManage\)\(/);
  assert.match(middleware,/reports\.manage/);
});

test('Same-field concurrent edits reject stale values while unrelated fields remain independent', { skip: !backendPresent }, ()=>{
  const api=source('services/clients/app/Http/Controllers/ReportingPeriodsController.php');
  const ui=source('apps/clients/src/components/ReportingPeriodsView.vue');
  assert.match(api,/expected_hours/);
  assert.match(api,/expected_overtime_hours/);
  assert.match(api,/expected_multiplier/);
  assert.match(api,/409, 'Основные часы уже изменены/);
  assert.match(api,/409, 'Коэффициент уже изменён/);
  assert.match(ui,/JSON\.stringify\(\{\[kind\]:value,\[expectedField\]:current\}\)/);
  assert.match(ui,/expected_multiplier:Number\(card\.period\.overtime_multiplier\)/);
  assert.doesNotMatch(ui,/JSON\.stringify\(values\)/);
});

test('Two-month reporting periods accumulate confirmations by each work date', { skip: !backendPresent }, ()=>{
  const controller=source('services/clients/app/Http/Controllers/ReportingPeriodsController.php');
  assert.match(controller,/\['account_confirmed'\] = false/);
  assert.match(controller,/\$approved = \$approvals->contains/);
  assert.doesNotMatch(controller,/substr\(\$from, 0, 7\)/);
});

test('Frontend-only overtime controls work without backend source code',()=>{
  const view=source('apps/clients/src/components/ReportingPeriodsView.vue');
  assert.match(view, /Коэффициент переработок/);
  assert.match(view, /@blur="saveApprovedEdit\\(term,'overtime_hours'\\)"/);
  assert.match(view, /<span> \\+ <\\/span>/);
});

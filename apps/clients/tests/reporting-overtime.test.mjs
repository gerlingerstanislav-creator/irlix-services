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

test('cross-month reporting checks approval for each client member and actual work month', { skip: !backendPresent }, ()=>{
  const controller=source('services/clients/app/Http/Controllers/ReportingPeriodsController.php');
  const sending=controller.slice(controller.indexOf('if ($index === 0)'),controller.indexOf("'timesheet_snapshot'=>json_encode"));
  const display=controller.slice(controller.indexOf('public function show'),controller.indexOf('private function assertNoOverlap'));

  // Readiness is checked for every expected ClientMember and every month slice
  // intersecting the reporting period; a single period-start month is insufficient.
  assert.match(sending,/\$expectedTerms->groupBy\('client_member_id'\)/);
  assert.match(sending,/for\(\$month=\$firstMonth->copy\(\);\$month->lte\(\$lastMonth\);\$month->addMonth\(\)\)/);
  assert.match(sending,/\$sliceFrom=max\(substr\(\(string\)\$current->period_start,0,10\),\$month->toDateString\(\)\)/);
  assert.match(sending,/\$sliceTo=min\(substr\(\(string\)\$current->period_end,0,10\),\$month->copy\(\)->endOfMonth\(\)->toDateString\(\)\)/);
  assert.match(sending,/\$facts->isEmpty\(\)/);
  assert.ok(sending.includes("$approvals->contains(fn($a)=>(int)($a['client_member_id']??0)===(int)$memberId"));
  assert.ok(sending.includes("substr((string)($a['month']??''),0,7)===$month->format('Y-m')"));
  assert.ok(sending.includes("$exceptions->contains(fn($e)=>(int)($e['client_member_id']??0)===(int)$memberId"));
  assert.match(sending,/\$e\['work_date'\].*\$sliceFrom.*\$sliceTo/s);

  // Displayed approval follows the month of each fact and is invalidated by a
  // same-member/date exception; summary readiness is accumulated from those facts.
  assert.match(display,/Carbon::parse\(\$workDate\)->startOfMonth\(\)->toDateString\(\)/);
  assert.match(display,/\$approvals->contains\(fn \(\$approval\) =>[\s\S]*?\$approval\['client_member_id'\][\s\S]*?\$approval\['month'\][\s\S]*?\$monthStart/);
  assert.match(display,/final_approval_exceptions[\s\S]*?\$exception\['client_member_id'\][\s\S]*?\$exception\['work_date'\][\s\S]*?\$workDate/);
  assert.match(display,/\$hours > 0 && !\$approved/);
  assert.match(display,/'all_account_confirmed'\s*=>\s*\$timesheets->filter\(fn \(\$item\) => \$item\['worked_hours'\] > 0\)->every/);
});

test('Frontend-only overtime controls work without backend source code',()=>{
  const view=source('apps/clients/src/components/ReportingPeriodsView.vue');
  assert.match(view, /Коэффициент переработок/);
  assert.ok(view.includes(`@blur="saveApprovedEdit(term,'overtime_hours')"`));
  assert.ok(view.includes('<span> + </span>'));
});

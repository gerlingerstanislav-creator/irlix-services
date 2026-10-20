import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const source = path => readFileSync(path, 'utf8');
test('ReportingPeriod copies its multiplier from the client and exposes a separate editor',()=>{
  const controller=source('services/clients/app/Http/Controllers/ReportingPeriodsController.php');
  const ui=source('apps/clients/src/components/ReportingPeriodsView.vue');
  assert.match(controller, /'overtime_multiplier' => \$client->overtime_multiplier/);
  assert.match(controller, /public function updateOvertimeMultiplier/);
  assert.match(controller, /status === 'Счет оплачен'/);
  assert.match(ui, /Коэффициент переработок/);
  assert.match(ui, /saveOvertimeMultiplier/);
});
test('Client default is independent from date-versioned MemberTerms',()=>{
  const migration=source('services/clients/database/migrations/2026_10_20_050000_reporting_period_overtime.php');
  const card=source('apps/clients/src/components/ClientCard.vue');
  assert.match(migration, /Schema::table\('clients'/);
  assert.match(migration, /Schema::table\('reporting_periods'/);
  assert.doesNotMatch(migration, /Schema::table\('member_terms'/);
  assert.match(card, /Коэффициент переработок/);
});
test('AM independently edits regular and overtime hours while worked hours stay factual',()=>{
  const controller=source('services/clients/app/Http/Controllers/ReportingPeriodsController.php');
  const ui=source('apps/clients/src/components/ReportingPeriodsView.vue');
  assert.match(controller, /'overtime_hours' => \['sometimes'/);
  assert.match(controller, /approved_overtime_hours/);
  assert.match(ui, /approved_overtime_hours/);
  assert.match(ui, /\{\{num\(card\.summary\.approved_overtime_hours\)\}\}/);
  assert.match(ui, /overtime_hours/);
  assert.match(ui, /\/ \{\{num\(term.worked_hours\)\}\}/);
});
test('Finance receives the immutable period snapshot and approved split',()=>{
  const api=source('services/clients/routes/internal.php');
  assert.match(api, /'overtime_multiplier'\)->get\(\)/);
  assert.match(api, /'overtime_hours'\)->get\(\)/);
});

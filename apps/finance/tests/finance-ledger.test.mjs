import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const root = new URL('../../../', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');
test('Finance admission is explicit: no automatic RN access', () => {
  const access = read('services/employees/app/Support/EmployeesAccess.php');
  assert.ok(access.includes("$financeVisible = $financeAdmin || $financeDirector || $accountingDepartment"));
  assert.ok(access.includes("str_starts_with($permission, 'finance.')"));
  assert.ok(access.includes("'finance' => $financeVisible"));
  const mw = read('services/finance/app/Http/Middleware/KeycloakBearer.php');
  assert.ok(mw.includes('data.services.finance'));
  assert.ok(mw.includes('openssl_verify'));
});
test('Invoices remain drafts until a trusted Clients contract exists', () => {
  const migration = read('services/finance/database/migrations/2026_10_20_000001_create_finance_schema.php');
  const api = read('services/finance/routes/api.php');
  assert.ok(migration.includes("document_status',16)->default('draft')"));
  assert.ok(api.includes("where('document_status','issued')"));
  assert.ok(api.includes('Выставление счёта недоступно до проверки'));
  assert.ok(!api.includes('/close-month'));
});
test('No fabricated P&L or Clients/Timesheets profitability', () => {
  const api = read('services/finance/routes/api.php');
  assert.ok(api.includes("'revenue'=>null"));
  assert.ok(api.includes('clients_timesheets_not_integrated'));
  const ui = read('apps/finance/src/App.vue');
  assert.ok(ui.includes('Нет данных'));
  assert.ok(ui.includes('Черновики не закрывают отчётные периоды'));
});
test('Own Finance schema, no direct cross-service DB table writes', () => {
  const compose = read('docker-compose.finance.yml');
  const api = read('services/finance/routes/api.php');
  assert.ok(compose.includes('SCHEMA IF NOT EXISTS finance'));
  assert.ok(!api.includes("DB::table('reporting_periods')"));
});

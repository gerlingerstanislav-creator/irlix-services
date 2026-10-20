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
test('Invoices require trusted Clients contract and cannot be paid as drafts', () => {
  const migration = read('services/finance/database/migrations/2026_10_20_000001_create_finance_schema.php');
  const api = read('services/finance/routes/api.php');
  assert.ok(migration.includes("document_status',16)->default('draft')"));
  assert.ok(api.includes("where('document_status','issued')"));
  assert.ok(api.includes("SettlementController::class,'issue'"));
  assert.ok(read('services/finance/app/Http/Controllers/SettlementController.php').includes("document_status==='issued'"));
  assert.ok(read('services/clients/app/Http/Controllers/ReportingPeriodsController.php').includes("status === 'Счет оплачен'"));
  assert.ok(!api.includes('/close-month'));
});
test('No fabricated P&L or Clients/Timesheets profitability', () => {
  const api = read('services/finance/routes/api.php');
  assert.ok(api.includes("'revenue'=>null"));
  assert.ok(api.includes('RevenueReport::month'));
  assert.ok(read('services/finance/app/Support/RevenueReport.php').includes('rate_terms_missing_or_ambiguous'));
  const ui = read('apps/finance/src/App.vue');
  assert.ok(ui.includes('Нет данных'));
  assert.ok(ui.includes('черновики не участвуют в контроле закрытия периода Clients'));
  assert.ok(ui.includes("i.document_status==='issued'"));
  assert.ok(read('services/clients/routes/internal.php').includes('IRLIX_FINANCE_CLIENTS_TOKEN'));
  assert.ok(read('services/timesheets/routes/system.php').includes('IRLIX_FINANCE_TIMESHEETS_TOKEN'));
  assert.ok(read('services/finance/app/Http/Controllers/SettlementController.php').includes('lockForUpdate'));
});
test('Own Finance schema, no direct cross-service DB table writes', () => {
  const compose = read('docker-compose.finance.yml');
  const api = read('services/finance/routes/api.php');
  assert.ok(compose.includes('SCHEMA IF NOT EXISTS finance'));
  assert.ok(!api.includes("DB::table('reporting_periods')"));
});

test('Overview uses selected service month and real revenue report without fake profit', () => {
  const api = read('services/finance/routes/api.php');
  const ui = read('apps/finance/src/App.vue');
  const overview = api.slice(api.indexOf("Route::get('/overview'"));
  assert.ok(overview.includes('RevenueReport::month($month)'));
  assert.ok(overview.includes('if($accrualCount>0 && $revenue!==null)'));
  assert.ok(overview.includes("whereDate('occurred_on','>='"));
  assert.ok(ui.includes("'overview?month='+month.value"));
  assert.ok(!overview.includes('commercial_data_not_integrated'));
});

test('Revenue report does not fabricate confirmed zero from an empty month', () => {
  const php = read('services/finance/app/Support/RevenueReport.php');
  assert.ok(php.includes("'no_commercial_entries'"));
  assert.ok(php.includes("'client_agreed_amount'=>$quality==='complete'?"));
  assert.ok(php.includes("'preliminary_amount'=>$quality==='complete'?"));
});

test('Finance employee picker uses shared department tree and never exposes salary records to the browser', () => {
  const api=read('services/finance/routes/api.php');
  const ui=read('apps/finance/src/App.vue');
  const route=api.slice(api.indexOf("Route::get('/employee-options'"),api.indexOf("Route::get('/accounts'"));
  assert.ok(route.includes("finance.expenses.manage"));
  assert.ok(route.includes("'full_name'"));
  assert.ok(route.includes("'department_name'"));
  assert.ok(!route.includes("'gross_salary'"));
  assert.ok(!route.includes("'salary_conditions'"));
  assert.ok(ui.includes('UiSearchSelect'));
  assert.ok(ui.includes("kind:'group'"));
  assert.ok(ui.includes('employeePickerOptions'));
  assert.ok(api.includes('required_if:kind,salary,bonus,vacation,sick'));
});

test('Finance uses canonical shared drawer and period components', () => {
  const ui=read('apps/finance/src/App.vue');
  const css=read('apps/finance/src/style.css');
  assert.ok(ui.includes('UiDrawer'));
  assert.ok(ui.includes('UiPeriodPicker'));
  assert.ok(ui.includes('UiSearchSelect'));
  assert.ok(ui.includes('<UiDrawer :open='));
  assert.ok(!css.includes('.finance-backdrop{'));
});
test('Sensitive finance read routes have explicit permission checks', () => {
  const api=read('services/finance/routes/api.php');
  const forRoute=(name,next)=>api.slice(api.indexOf("Route::get('/"+name+"'"),api.indexOf(next,api.indexOf("Route::get('/"+name+"'")));
  assert.ok(forRoute('expenses',"Route::get('/expenses/payment-links'").includes("finance.salary.view"));
  assert.ok(forRoute('invoices',"Route::post('/invoices'").includes("finance.view"));
  assert.ok(forRoute('overview',"return response()->json(['data'=>[").includes("finance.view"));
});

test('Own-account cash transfers cannot inflate reported receipts and require permission', () => {
  const api=read('services/finance/routes/api.php');
  const svc=read('services/finance/app/Http/Controllers/CashTransferController.php');
  const ui=read('apps/finance/src/App.vue');
  assert.ok(api.includes("whereNull('internal_transfer_id')"));
  assert.ok(svc.includes("finance.cash.confirm"));
  assert.ok(svc.includes('lockForUpdate'));
  assert.ok(svc.includes("'internal_transfer'"));
  assert.ok(svc.includes("'request_id'"));
  assert.ok(ui.includes("openForm='transfers'"));
  assert.ok(ui.includes("type==='transfers'?"));
});

test('Internal transfers cannot be allocated as client or expense settlement', () => {
  const invoices=read('services/finance/app/Http/Controllers/SettlementController.php');
  const expenses=read('services/finance/app/Http/Controllers/AccrualPaymentController.php');
  const ui=read('apps/finance/src/App.vue');
  assert.ok(invoices.includes('cash->internal_transfer_id===null'));
  assert.ok(expenses.includes('cash->internal_transfer_id===null'));
  assert.ok(ui.includes("&&!r.internal_transfer_id"));
  assert.ok(ui.includes("&&!x.internal_transfer_id"));
});

test('Invoice list distinguishes document, calculated payment and overdue states', () => {
  const ui=read('apps/finance/src/App.vue');
  assert.ok(ui.includes('invoicePaymentStatus'));
  assert.ok(ui.includes('invoiceIsOverdue'));
  assert.ok(ui.includes('filteredInvoices'));
  assert.ok(ui.includes('Просроченные'));
  assert.ok(ui.includes('cancelInvoice(i)'));
  assert.ok(ui.includes('i.document_status'));
  assert.ok(ui.includes('i.settlement_status'));
});

test('Revenue quality stays incomplete while approval is pending', () => {
  const php = read('services/finance/app/Support/RevenueReport.php');
  assert.ok(php.includes("'awaiting_approvals'"));
  assert.ok(php.includes("$hasUnapproved"));
  assert.ok(php.includes("'status']??null)!=='agreed'"));
  assert.ok(php.includes("'client_agreed_amount'=>$quality==='complete'?"));
});

test('Finance UI degrades independently when external data source fails', () => {
  const ui=read('apps/finance/src/App.vue');
  assert.ok(ui.includes('Promise.allSettled(routes.map'));
  assert.ok(ui.includes('Некоторые источники не обновились'));
  assert.ok(ui.includes("const me=await call('me')"));
});

test('Invoice KPI uses aggregate over entire ledger, not capped UI list', () => {
  const api=read('services/finance/routes/api.php');
  const ui=read('apps/finance/src/App.vue');
  assert.ok(api.includes("Route::get('/invoice-stats'"));
  assert.ok(api.includes("whereNull('a.reversed_at')->sum('a.amount')"));
  assert.ok(ui.includes("'invoice-stats',v=>billingTotals.value=v"));
  assert.ok(ui.includes('const billingTotals = ref(null)'));
});

test('Invoice KPI remains safe while whole-ledger statistics load', () => {
  const ui=read('apps/finance/src/App.vue');
  assert.ok(ui.includes('euro(billingTotals?.issued)'));
  assert.ok(ui.includes('euro(billingTotals?.paid)'));
  assert.ok(ui.includes('euro(billingTotals?.debt)'));
});

test('Invoice register supports older pages without truncating history', () => {
  const api=read('services/finance/routes/api.php');
  const ui=read('apps/finance/src/App.vue');
  assert.ok(api.includes("'before_id'"));
  assert.ok(api.includes("next_before_id"));
  assert.ok(ui.includes("async function loadMoreInvoices()"));
  assert.ok(ui.includes("Загрузить более ранние счета"));
});

test('Invoice list resolves client and reporting period labels', () => {
  const ui=read('apps/finance/src/App.vue');
  assert.ok(ui.includes('clientLabel(i.client_id)'));
  assert.ok(ui.includes('periodLabel(i.reporting_period_id)'));
  assert.ok(ui.includes('period.period_start'));
});

test('Client expense attribution requires documented and Clients-verified reference', () => {
  const api=read('services/finance/routes/api.php');
  const gateway=read('services/finance/app/Support/ClientsGateway.php');
  const clientApi=read('services/clients/routes/internal.php');
  const ui=read('apps/finance/src/App.vue');
  assert.ok(api.includes('ClientsGateway::validateDirectExpense'));
  assert.ok(api.includes('direct_cost_reason'));
  assert.ok(clientApi.includes("where('client_id',(int)$client->id)"));
  assert.ok(gateway.includes('Finance/Clients secret not configured'));
  assert.ok(ui.includes('direct_contribution_margin'));
  assert.ok(ui.includes('clientExpenseOptions'));
  const profitability=read('services/finance/app/Support/ProfitabilityReport.php');
  assert.ok(profitability.includes('other_direct_cost'));
  assert.ok(profitability.includes('direct_contribution_margin'));
});

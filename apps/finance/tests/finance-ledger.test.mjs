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
  assert.ok(ui.includes("'overview?month='+requestedMonth"));
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

test('Expense register redacts individual payroll while Finance read access remains useful', () => {
  const api=read('services/finance/routes/api.php');
  const body=api.slice(api.indexOf("Route::get('/expenses'"),api.indexOf("Route::get('/expenses/payment-links'"));
  assert.ok(body.includes("finance.view"));
  assert.ok(body.includes("finance.salary.view"));
  assert.ok(body.includes("whereNull('accruals.employee_id')"));
  assert.ok(body.includes("whereIn('accruals.kind',['contractor','other'])"));
  assert.ok(body.includes("'Invalid month'"));
});

test('Finance navigation and restricted requests follow effective permissions', () => {
  const ui=read('apps/finance/src/App.vue');
  assert.ok(ui.includes("const sectionAllowed = id => can('finance.view')"));
  assert.ok(ui.includes("id!=='profitability' || can('finance.salary.view')"));
  assert.ok(ui.includes('sections.filter(s=>sectionAllowed(s.id))'));
  assert.ok(ui.includes("if(can('finance.salary.view')){"));
  assert.ok(ui.includes("if(can('finance.expenses.manage')){"));
  assert.ok(ui.includes('У вас нет доступа к финансовому сервису.'));
  assert.ok(ui.includes('&&sectionAllowed(requested)'));
});

test('Monthly reports never publish an obsolete asynchronous response', () => {
  const ui=read('apps/finance/src/App.vue');
  assert.ok(ui.includes('let loadSequence = 0;'));
  assert.ok(ui.includes('const requestId=++loadSequence;'));
  assert.ok(ui.includes('const requestedMonth=month.value;'));
  assert.ok(ui.includes('if(requestId!==loadSequence)return;'));
  assert.ok(ui.includes('if(requestId===loadSequence)busy.value=false'));
  assert.ok(ui.includes('overview.value=null;pnl.value=null;cashflow.value=null;'));
  assert.ok(ui.includes("revenue.value=null;profitability.value=null;expenses.value=[];"));
  assert.ok(ui.includes('loadSequence++;window.removeEventListener'));
});

test('P&L marks incomplete manual cost pool as an unverified difference, never profit', () => {
  const api=read('services/finance/routes/api.php');
  const ui=read('apps/finance/src/App.vue');
  const pnl=api.slice(api.indexOf("Route::get('/pnl'"),api.indexOf("Route::get('/revenue'"));
  const overview=api.slice(api.indexOf("Route::get('/overview'"));
  assert.ok(pnl.includes("'result'=>null,'known_recorded_difference'=>$knownDifference"));
  assert.ok(pnl.includes("'completeness'=>$knownDifference===null?'incomplete':'partial_manual_accruals'"));
  assert.ok(overview.includes("$revenue=null;$pnl=null;$knownDifference=null;"));
  assert.ok(overview.includes("'known_recorded_difference'=>$knownDifference"));
  assert.ok(ui.includes('Разница известных начислений (не прибыль)'));
  assert.ok(ui.includes('Разность известных данных (не подтверждённая прибыль)'));
});

test('Salary analytics and personal payout register require both view and salary permissions', () => {
  const api=read('services/finance/routes/api.php');
  const ctrl=read('services/finance/app/Http/Controllers/AccrualPaymentController.php');
  const profitability=api.slice(api.indexOf("Route::get('/profitability'"),api.indexOf("Route::get('/planning/"));
  assert.ok(profitability.includes("financeGuard($r,'finance.view')"));
  assert.ok(profitability.includes("financeGuard($r,'finance.salary.view')"));
  assert.ok(ctrl.includes("['permissions']['finance.view']"));
  assert.ok(ctrl.includes("['permissions']['finance.salary.view']"));
});

test('Single receipt can be allocated across multiple invoices with complete ledger balance', () => {
  const api=read('services/finance/routes/api.php');
  const ui=read('apps/finance/src/App.vue');
  const receipts=api.slice(api.indexOf("Route::get('/receipts'"),api.indexOf("Route::post('/receipts'"));
  assert.ok(receipts.includes('AS allocated_amount'));
  assert.ok(receipts.includes('pa.reversed_at IS NULL'));
  assert.ok(ui.includes("items:[{invoice_id:'',amount:''}]"));
  assert.ok(ui.includes('function addAllocationLine()'));
  assert.ok(ui.includes('function removeAllocationLine('));
  assert.ok(ui.includes('allocations:items'));
  assert.ok(ui.includes('receiptRemainingCents'));
  assert.ok(ui.includes('allocationRequestCents'));
  assert.ok(ui.includes('new Set(items.map(x=>x.invoice_id))'));
  assert.ok(ui.includes('Math.abs(Math.round(x.amount*100)-x.amount*100)>1e-6'));
  assert.ok(ui.includes('Добавить счёт'));
});

test('Audit history is permission-scoped and available through paginated settings UI', () => {
  const api=read('services/finance/routes/api.php');
  const ui=read('apps/finance/src/App.vue');
  const audit=api.slice(api.indexOf("Route::get('/audit'"),api.indexOf("Route::get('/profitability'"));
  assert.ok(audit.includes("financeGuard($r,'finance.view')"));
  assert.ok(audit.includes("financeGuard($r,'finance.salary.view')"));
  assert.ok(audit.includes("DB::table('finance_audit_log')"));
  assert.ok(audit.includes("'next_before_id'"));
  assert.ok(ui.includes("['Аудит','audit?limit=50'"));
  assert.ok(ui.includes('function loadMoreAudit()'));
  assert.ok(ui.includes("v-for=\"event in auditLog\""));
  assert.ok(ui.includes('История финансовых операций'));
});

test('Finance runtime probes use actual internal routes and always fail CI on exceptions', () => {
  const routes=read('services/finance/tests/finance_routes_runtime.php');
  const integration=read('services/finance/tests/finance_runtime.php');
  assert.ok(routes.includes("Request::create('/api'.$uri"));
  assert.ok(integration.includes("Request::create('/api/'.$reportEndpoint"));
  assert.ok(routes.includes("app()->instance('request',$request)"));
  assert.ok(integration.includes("app()->instance('request',$receiptRead)"));
  assert.ok(!routes.includes("Request::create('/api/finance'.$uri"));
  assert.ok(!integration.includes("Request::create('/api/finance/"));
  for(const script of [routes,integration]){
    assert.ok(script.includes('set_exception_handler('));
    assert.ok(script.includes('exit(1);'));
  }
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
  assert.ok(api.includes("'cost_center'=>$r->filled('client_id')?'client:'"));
  assert.ok(clientApi.includes("where('client_id',(int)$client->id)"));
  assert.ok(gateway.includes('Finance/Clients secret not configured'));
  assert.ok(ui.includes('direct_contribution_margin'));
  assert.ok(ui.includes('clientExpenseOptions'));
  const profitability=read('services/finance/app/Support/ProfitabilityReport.php');
  assert.ok(profitability.includes('other_direct_cost'));
  assert.ok(profitability.includes('direct_contribution_margin'));
  assert.ok(profitability.includes("$revenue['quality']!=='complete'"));
});

test('Direct expense selectors load real protected Clients catalog', () => {
  const ui=read('apps/finance/src/App.vue');
  assert.ok(ui.includes("'client-projects',v=>clientCatalog.value=v"));
  assert.ok(ui.includes('projectExpenseOptions'));
  assert.ok(ui.includes('Без конкретного проекта'));
  const clients=read('services/clients/routes/internal.php');
  assert.ok(clients.includes("Route::get('/finance/client-projects'"));
});

test('Budget horizon is at least 12 months and can extend through a revision', () => {
  const budget=read('services/finance/app/Http/Controllers/BudgetController.php');
  assert.ok(budget.includes('addMonthsNoOverflow(11)'));
  assert.ok(budget.includes("$end=max($end,collect($lines)->pluck('month')->max())"));
  assert.ok(budget.includes("'last_month'=>$end"));
  assert.ok(!budget.includes('Период бюджета не может превышать 12 месяцев'));
});

test('Plan/fact compares only supported actuals and preserves budget horizon', () => {
  const backend=read('services/finance/app/Http/Controllers/BudgetController.php');
  const api=read('services/finance/routes/api.php');
  const ui=read('apps/finance/src/App.vue');
  assert.ok(backend.includes('public function variance(Request $r,int $id)'));
  assert.ok(backend.includes("'actual_total'=>$complete?"));
  assert.ok(backend.includes('revenue_scope_unsupported'));
  assert.ok(backend.includes('$revenueLines->count()===1'));
  assert.ok(backend.includes("'quality'=>$complete?'recorded_actuals':'incomplete'"));
  assert.ok(api.includes("Route::get('/planning/{budget}/variance'"));
  assert.ok(ui.includes('showBudgetVariance'));
  assert.ok(ui.includes('Если подтверждённые данные отсутствуют, факт не заменяется нулём'));
});

test('cash operations respect opening balances and immutable idempotency payload', () => {
  const api = read('services/finance/routes/api.php');
  assert.ok(api.includes("where('id',(int)$r->input('financial_account_id'))->lockForUpdate()->first()"));
  assert.ok(api.includes("'Дата операции раньше даты начального остатка'"));
  assert.ok(api.includes("'Нельзя подтвердить операцию до даты начального остатка'"));
  assert.ok(api.includes("(string)($existing->counterparty??'')"));
  assert.ok(api.includes("(string)($existing->flow_type??'operating')"));
  assert.ok(api.includes("abort_unless($account && $account->active"));
});

test('invoice aggregate endpoint enforces explicit finance read permission', () => {
  const api = read('services/finance/routes/api.php');
  assert.ok(api.includes("Route::get('/invoice-stats',function(Request $r){\n    if($g=financeGuard($r,'finance.view'))return $g;"));
});

test('overview does not invent liquidity when accounts or movements are incomplete', () => {
  const ui = read('apps/finance/src/App.vue');
  assert.ok(ui.includes('const totalCashBalance = computed'));
  assert.ok(ui.includes('balances.length!==allAccounts.length'));
  assert.ok(ui.includes('balances.some(row=>row.balance===null'));
  assert.ok(ui.includes('Дебиторская задолженность по выставленным счетам'));
  assert.ok(ui.includes('Остаток денег на счетах'));
  assert.ok(ui.includes("if(label==='ДДС')cashflow.value=null"));
  assert.ok(ui.includes("if(label==='Итоги всех счетов')billingTotals.value=null"));
});

test('Finance deployment checks migrated DB readiness instead of container liveness', () => {
  const script = read('scripts/deploy-from-ghcr.sh');
  const api = read('services/finance/routes/api.php');
  assert.ok(script.includes('Checking Finance database readiness...'));
  assert.ok(script.includes('http://127.0.0.1:8102/api/health'));
  assert.ok(script.includes('Finance failed the database-readiness gate'));
  assert.ok(api.includes("DB::table('finance_audit_log')->limit(1)->exists()"));
  assert.ok(api.includes("'status'=>'unavailable','service'=>'finance'],503"));
});

test('Finance role matrix may revoke access but cannot grant it to unauthorized RNs', () => {
  const access = read('services/employees/app/Support/EmployeesAccess.php');
  assert.ok(access.includes("$overrides[$role][$permission] === false"));
  assert.ok(access.includes("$access['permissions'][$permission] = false"));
  assert.ok(access.includes("$access['services']['finance'] = (bool)($access['permissions']['finance.view'] ?? false)"));
  assert.ok(access.includes("$financeVisible = $financeAdmin || $financeDirector || $accountingDepartment"));
});

test('failed finance source reload clears stale ledger IDs and employee options', () => {
  const ui = read('apps/finance/src/App.vue');
  assert.ok(ui.includes("if(label==='Счета'){invoices.value=[];hasMoreInvoices.value=false;}"));
  assert.ok(ui.includes("if(label==='Денежные операции')receipts.value=[]"));
  assert.ok(ui.includes("if(label==='Отчётные периоды')clientPeriods.value=[]"));
  assert.ok(ui.includes("if(label==='Каталог клиентов')clientCatalog.value={clients:[],projects:[]}"));
  assert.ok(ui.includes('financeEmployees.value=[];'));
});

test('Finance release gates check live source contracts without leaking payloads', () => {
  const script = read('services/finance/tests/finance_integrations_smoke.php');
  const deploy = read('scripts/deploy-from-ghcr.sh');
  assert.ok(script.includes('IRLIX_FINANCE_CLIENTS_TOKEN'));
  assert.ok(script.includes('IRLIX_FINANCE_TIMESHEETS_TOKEN'));
  assert.ok(script.includes('IRLIX_FINANCE_EMPLOYEES_TOKEN'));
  assert.ok(script.includes("is_array($response->json('data'))"));
  assert.ok(script.includes("if($errors)exit(1)"));
  assert.ok(deploy.includes('finance php tests/finance_integrations_smoke.php'));
  assert.ok(deploy.includes('Finance Clients/Timesheets/Employees integration checks failed'));
});

test('Individual payout allocations require salary permission, not generic Finance view', () => {
  const controller=read('services/finance/app/Http/Controllers/AccrualPaymentController.php');
  const index=controller.slice(controller.indexOf('public function index('),controller.indexOf('public function attach('));
  assert.ok(index.includes("['finance.salary.view']"));
  assert.ok(index.includes("['finance.view']"));
  assert.ok(read('services/finance/tests/finance_runtime.php').includes('payment links forbidden without salary detail permission'));
});

test('Salary write paths cannot be used with general expense editor permission', () => {
  const api=read('services/finance/routes/api.php');
  const endpoint=api.slice(api.indexOf("Route::post('/expenses'"),api.indexOf("Route::get('/cash-flow'"));
  assert.ok(endpoint.includes("finance.salary.view"));
  assert.ok(endpoint.includes("$r->filled('employee_id')"));
  const controller=read('services/finance/app/Http/Controllers/AccrualPaymentController.php');
  assert.ok(controller.includes("Доступ к индивидуальным выплатам запрещён"));
  assert.ok(controller.includes("Доступ к индивидуальным начислениям запрещён"));
  assert.ok(read('services/finance/tests/finance_runtime.php').includes('unauthorized salary write leaves no links'));
});

test('Employee-linked accruals validate IDs against protected Employees directory', () => {
  const api=read('services/finance/routes/api.php');
  const endpoint=api.slice(api.indexOf("Route::post('/expenses'"),api.indexOf("Route::get('/cash-flow'"));
  const gateway=read('services/finance/app/Support/EmployeesDirectory.php');
  const employees=read('services/employees/routes/finance.php');
  assert.ok(endpoint.includes('EmployeesDirectory::assertExists'));
  assert.ok(gateway.includes('X-Irlix-Finance-Employees-Token'));
  assert.ok(gateway.includes('Сотрудник не найден в Employees'));
  assert.ok(employees.includes("Route::get('/finance/employees/{employee}'"));
  assert.ok(!employees.includes('Finance browser endpoint'));
  assert.ok(read('services/finance/tests/finance_routes_runtime.php').includes('payroll records must reference an existing Employees ID'));
});

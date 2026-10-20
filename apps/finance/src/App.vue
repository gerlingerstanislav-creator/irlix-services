<script setup>
import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { UiAppShell, UiButton, UiBadge, UiSearchSelect, UiPeriodPicker, UiDrawer } from '@irlix/ui';
import { auth } from './auth';

const sections = [
  { id:'overview', label:'Обзор', icon:'dashboard', groupStart:false },
  { id:'revenue', label:'Выручка', icon:'chart' },
  { id:'pnl', label:'P&L', icon:'status' },
  { id:'profitability', label:'Рентабельность', icon:'target' },
  { id:'invoices', label:'Счета и оплаты', icon:'document', groupStart:true },
  { id:'expenses', label:'Расходы и выплаты', icon:'cash' },
  { id:'cash-flow', label:'ДДС', icon:'audit' },
  { id:'planning', label:'Планирование', icon:'calendar', groupStart:true },
  { id:'settings', label:'Настройки', icon:'settings' },
];
const fromPath = () => location.pathname.replace(/^\/finance\/?/,'').split('/')[0] || 'overview';
const section = ref(sections.some(s=>s.id===fromPath()) ? fromPath() : 'overview');
const busy = ref(false), error = ref(''), notice = ref('');
// A later month refresh invalidates all earlier in-flight report responses.
let loadSequence = 0;
const month = ref(new Date().toISOString().slice(0,7));
const invoices = ref([]), receipts = ref([]), expenses = ref([]), accounts = ref([]), clientPeriods = ref([]), allocations=ref([]);
const financeEmployees=ref([]), employeeDirectoryError=ref('');
const auditLog=ref([]), hasMoreAudit=ref(false);
const auditDetails=details=>{
  if(details==null)return '—';
  const value=typeof details==='string'?details:JSON.stringify(details);
  return value.length>200?value.slice(0,200)+'…':value;
};
const overview = ref(null), pnl = ref(null), cashflow = ref(null), revenue = ref(null), profitability = ref(null), budgets = ref([]);
const budgetVariance=ref(null);
const billAllocation = reactive({receipt_id:'',items:[{invoice_id:'',amount:''}]});
const allocationReceipt=computed(()=>receipts.value.find(r=>String(r.id)===String(billAllocation.receipt_id)));
const receiptRemainingCents=computed(()=>{
  const receipt=allocationReceipt.value;
  if(!receipt)return null;
  return Math.round(Number(receipt.amount)*100)-Math.round(Number(receipt.allocated_amount||0)*100);
});
const allocationRequestCents=computed(()=>billAllocation.items.reduce(
  (sum,x)=>sum+Math.round(Number(x.amount||0)*100),0));
function addAllocationLine(){
  if(billAllocation.items.length<100)billAllocation.items.push({invoice_id:'',amount:''});
}
function removeAllocationLine(index){
  if(billAllocation.items.length>1)billAllocation.items.splice(index,1);
}
const expensePayment = reactive({accrual_id:'',cash_transaction_id:'',amount:''});
const budget = reactive({name:'',scenario:'base',lines:[{month:month.value,cost_center:'corporate',article:'',amount:''}]});
const operationState = ref(null);

const permissions = ref({});
const openForm = ref('');
const invoice = reactive({client_id:'',reporting_period_id:'',number:'',payer:'',issued_on:'',due_on:'',amount:'',vat_amount:'0',comment:''});
const newRequestId = () => {
  const bytes=window.crypto.getRandomValues(new Uint8Array(16));
  bytes[6]=(bytes[6]&15)|64;
  bytes[8]=(bytes[8]&63)|128;
  const hex=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
  return hex.slice(0,8)+'-'+hex.slice(8,12)+'-'+hex.slice(12,16)+'-'+hex.slice(16,20)+'-'+hex.slice(20);
};
const receipt = reactive({request_id:newRequestId(),financial_account_id:'',occurred_on:new Date().toISOString().slice(0,10),direction:'in',flow_type:'operating',amount:'',reference:'',counterparty:''});
const cashTransfer=reactive({request_id:newRequestId(),source_account_id:'',destination_account_id:'',occurred_on:new Date().toISOString().slice(0,10),amount:''});
const accrual = reactive({recognition_month:month.value,kind:'other',article:'',cost_center:'corporate',amount:'',employee_id:'',counterparty:'',client_id:'',project_id:'',direct_cost_reason:''});
const account = reactive({name:'',type:'bank',opening_on:new Date().toISOString().slice(0,10),opening_balance:'0'});
const euro = value => value == null ? 'Нет данных' : new Intl.NumberFormat('ru-RU',{style:'currency',currency:'RUB'}).format(Number(value));
const can = name => permissions.value[name] === true;
const sectionAllowed = id => can('finance.view')
  && (id!=='profitability' || can('finance.salary.view'));
const visible = computed(()=>sections.filter(s=>sectionAllowed(s.id))
  .map(s=>({...s,href:'/finance/'+s.id+'/'})));
const formTitle=computed(()=>({
  budgets:'Новый бюджет',allocation:'Распределить оплату',
  'expense-payment':'Связать выплату с начислением',
  invoices:'Новый черновик счёта',receipts:'Денежная операция',transfers:'Перевод между своими счетами',
  expenses:'Новое начисление/расход',accounts:'Денежный счёт',
})[openForm.value]||'Финансовая операция');
const clientCatalog=ref({clients:[],projects:[]});
const clientExpenseOptions=computed(()=>clientCatalog.value.clients.length
  ? clientCatalog.value.clients.map(c=>({client_id:c.id,client_name:c.name}))
  : [...new Map(clientPeriods.value.map(p=>[p.client_id,p])).values()]);
const projectExpenseOptions=computed(()=>clientCatalog.value.projects.filter(p=>
  Number(p.client_id)===Number(accrual.client_id)));
const employeePickerOptions=computed(()=>{
  const groups=new Map();
  for(const e of financeEmployees.value){
    const key=String(e.department_id??'none');
    if(!groups.has(key))groups.set(key,{name:e.department_name||'Без подразделения',employees:[]});
    groups.get(key).employees.push(e);
  }
  const options=[];
  for(const group of [...groups.values()].sort((a,b)=>a.name.localeCompare(b.name,'ru'))){
    options.push({kind:'group',label:group.name,value:'group:'+group.name,depth:0});
    for(const e of group.employees.sort((a,b)=>a.full_name.localeCompare(b.full_name,'ru'))){
      options.push({kind:'option',value:e.id,label:e.full_name,depth:1,
        meta:String(e.employment_status||'')});
    }
  }
  return options;
});
const hasMoreInvoices=ref(false);
const invoiceQuery=ref('');
const invoiceDocFilter=ref('all');
const invoicePayFilter=ref('all');
const invoiceOverdueOnly=ref(false);
const invoicePaymentStatus=row=>{
  if(row.document_status!=='issued')return '—';
  if(Number(row.paid)>=Number(row.amount))return 'paid';
  return Number(row.paid)>0?'partial':'unpaid';
};
const invoiceIsOverdue=row=>row.document_status==='issued'
  && row.due_on && String(row.due_on).slice(0,10)<new Date().toISOString().slice(0,10)
  && invoicePaymentStatus(row)!=='paid';
const clientLabel=id=>{
  const period=clientPeriods.value.find(p=>Number(p.client_id)===Number(id));
  return period?.client_name||('Клиент #'+id);
};
const periodLabel=id=>{
  const period=clientPeriods.value.find(p=>Number(p.id)===Number(id));
  return period ? (String(period.period_start).slice(0,10)+' — '+String(period.period_end).slice(0,10)) : ('Период #'+id);
};
const filteredInvoices=computed(()=>invoices.value.filter(row=>{
  const q=invoiceQuery.value.trim().toLocaleLowerCase('ru');
  if(q && ![row.number,row.client_id,row.reporting_period_id,row.payer].some(
    v=>String(v||'').toLocaleLowerCase('ru').includes(q)))return false;
  if(invoiceDocFilter.value!=='all'&&row.document_status!==invoiceDocFilter.value)return false;
  if(invoicePayFilter.value!=='all'&&invoicePaymentStatus(row)!==invoicePayFilter.value)return false;
  if(invoiceOverdueOnly.value&&!invoiceIsOverdue(row))return false;
  return true;
}));
const billingTotals = ref(null); // Complete server ledger; never sum just the first 500 register rows.
// Company cash is known only when EVERY financial account has an opening
// balance and a usable movement history as of the selected month.
const totalCashBalance = computed(() => {
  const balances = cashflow.value?.balances;
  const allAccounts = accounts.value;
  if (!Array.isArray(balances) || !Array.isArray(allAccounts) || allAccounts.length===0
    || balances.length!==allAccounts.length) return null;
  if (balances.some(row=>row.balance===null || row.balance===undefined
      || !Number.isFinite(Number(row.balance)))) return null;
  return balances.reduce((total,row)=>total+Number(row.balance),0);
});
const call=async(path, init={})=>{
  const r=await auth.fetch('/api/finance/'+path,{...init,headers:{'Content-Type':'application/json',...(init.headers||{})}});
  const j=await r.json().catch(()=>({message:'Не удалось прочитать ответ'}));
  if(!r.ok)throw new Error(j.message||'Ошибка сервера');
  return j.data;
};
async function load(){
  const requestId=++loadSequence;
  const requestedMonth=month.value;
  busy.value=true;error.value='';
  // Never show figures from a prior reporting month under the new month.
  overview.value=null;pnl.value=null;cashflow.value=null;
  revenue.value=null;profitability.value=null;expenses.value=[];
  try{
    // Authentication is mandatory. Independent financial registers must remain
    // usable if another source (for example commercial revenue) is unavailable.
    const me=await call('me');
    if(requestId!==loadSequence)return;
    permissions.value=me.permissions||{};
    if(!can('finance.view')){
      error.value='У вас нет доступа к финансовому сервису.';
      return;
    }
    if(!sectionAllowed(section.value))section.value='overview';
    const routes=[
      ['Счета','invoices?limit=100',v=>{invoices.value=v;hasMoreInvoices.value=v.length===100;}],
      ['Итоги всех счетов','invoice-stats',v=>billingTotals.value=v],
      ['Денежные операции','receipts',v=>receipts.value=v],
      ['Расходы','expenses?month='+requestedMonth,v=>expenses.value=v],
      ['Денежные счета','accounts',v=>accounts.value=v],
      ['Обзор','overview?month='+requestedMonth,v=>overview.value=v],
      ['P&L','pnl?month='+requestedMonth,v=>pnl.value=v],
      ['ДДС','cash-flow?month='+requestedMonth,v=>cashflow.value=v],
      ['Выручка','revenue?month='+requestedMonth,v=>revenue.value=v],
      ['Планирование','planning',v=>budgets.value=v],
      ['Отчётные периоды','client-periods',v=>clientPeriods.value=v],
      ['Каталог клиентов','client-projects',v=>clientCatalog.value=v],
      ['Разнесение платежей','allocations',v=>allocations.value=v],
    ];
    if(can('finance.salary.view')){
      routes.push(['Рентабельность','profitability?month='+requestedMonth,v=>profitability.value=v]);
      routes.push(['Аудит','audit?limit=50',v=>{auditLog.value=v;hasMoreAudit.value=v.length===50;}]);
    }else {
      profitability.value=null;
      auditLog.value=[];hasMoreAudit.value=false;
    }
    if(can('finance.expenses.manage')){
      routes.push(['Сотрудники','employee-options?month='+requestedMonth,v=>{
        financeEmployees.value=Array.isArray(v)?v:[];employeeDirectoryError.value='';
      }]);
    }else{
      financeEmployees.value=[];
      employeeDirectoryError.value='';
    }
    const results=await Promise.allSettled(routes.map(([,path])=>call(path)));
    if(requestId!==loadSequence)return;
    const failures=[];
    results.forEach((result,i)=>{
      const [label,,apply]=routes[i];
      if(result.status==='fulfilled')apply(result.value);
      else {
        failures.push(label);
        // Never render a prior month's sensitive totals as this month's facts
        // after a source outage. Keep independent successfully loaded ledgers.
        if(label==='ДДС')cashflow.value=null;
        if(label==='Итоги всех счетов')billingTotals.value=null;
        if(label==='Обзор')overview.value=null;
        if(label==='P&L')pnl.value=null;
        if(label==='Выручка')revenue.value=null;
        if(label==='Рентабельность')profitability.value=null;
        if(label==='Аудит'){auditLog.value=[];hasMoreAudit.value=false;}
        if(label==='Счета'){invoices.value=[];hasMoreInvoices.value=false;}
        if(label==='Денежные операции')receipts.value=[];
        if(label==='Расходы')expenses.value=[];
        if(label==='Денежные счета')accounts.value=[];
        if(label==='Отчётные периоды')clientPeriods.value=[];
        if(label==='Каталог клиентов')clientCatalog.value={clients:[],projects:[]};
        if(label==='Разнесение платежей')allocations.value=[];
        if(label==='Планирование')budgets.value=[];
        if(label==='Сотрудники'){
          financeEmployees.value=[];
          employeeDirectoryError.value=result.reason?.message||'Источник недоступен';
        }
      }
    });
    if(failures.length)error.value='Некоторые источники не обновились: '+failures.join(', ')+'. Недоступные регистры очищены; остальные продолжают работать.';
  }catch(ex){if(requestId===loadSequence)error.value=ex.message}
  finally{if(requestId===loadSequence)busy.value=false}
}
async function loadMoreInvoices(){
  if(busy.value||!hasMoreInvoices.value||!invoices.value.length)return;
  busy.value=true;error.value='';
  try {
    const cursor=invoices.value[invoices.value.length-1].id;
    const next=await call('invoices?limit=100&before_id='+cursor);
    invoices.value=[...invoices.value,...next];
    hasMoreInvoices.value=next.length===100;
  } catch(ex){error.value=ex.message} finally{busy.value=false}
}
async function loadMoreAudit(){
  if(busy.value||!hasMoreAudit.value||!auditLog.value.length)return;
  busy.value=true;error.value='';
  try{
    if(!can('finance.view')||!can('finance.salary.view'))
      throw new Error('Нет доступа к финансовому аудиту');
    const cursor=auditLog.value[auditLog.value.length-1].id;
    const next=await call('audit?limit=50&before_id='+cursor);
    auditLog.value=[...auditLog.value,...next];
    hasMoreAudit.value=next.length===50;
  }catch(e){error.value=e.message}finally{busy.value=false}
}
async function submit(type){
  busy.value=true;error.value='';notice.value='';
  try{
    const payload=type==='budgets'?{
      name:budget.name,scenario:budget.scenario,
      lines:budget.lines.map(l=>({month:l.month,cost_center:l.cost_center,article:l.article,amount:Number(l.amount)}))
    }:type==='invoices'?{...invoice,client_id:Number(invoice.client_id),reporting_period_id:Number(invoice.reporting_period_id),amount:Number(invoice.amount),vat_amount:Number(invoice.vat_amount)}:
      type==='receipts'?{...receipt,financial_account_id:Number(receipt.financial_account_id),amount:Number(receipt.amount)}:
      type==='transfers'?{...cashTransfer,source_account_id:Number(cashTransfer.source_account_id),destination_account_id:Number(cashTransfer.destination_account_id),amount:Number(cashTransfer.amount)}:
      type==='expenses'?{...accrual,amount:Number(accrual.amount),employee_id:accrual.employee_id?Number(accrual.employee_id):null,
        client_id:['contractor','other'].includes(accrual.kind)&&accrual.client_id?Number(accrual.client_id):null,
        project_id:['contractor','other'].includes(accrual.kind)&&accrual.client_id&&accrual.project_id?Number(accrual.project_id):null,
        direct_cost_reason:['contractor','other'].includes(accrual.kind)&&accrual.client_id?accrual.direct_cost_reason:null}:
      {...account,opening_balance:Number(account.opening_balance)};
    await call(type==='budgets'?'planning':type,{method:'POST',body:JSON.stringify(payload)});
    if(type==='receipts')receipt.request_id=newRequestId();
    if(type==='transfers')cashTransfer.request_id=newRequestId();
    openForm.value='';notice.value='Сохранено с записью в журнале';await load();
  }catch(ex){error.value=ex.message}finally{busy.value=false}
}
function periodSelected(){
  const found=clientPeriods.value.find(p=>p.id===Number(invoice.reporting_period_id));
  invoice.client_id=found ? String(found.client_id) : '';
}
async function cancelInvoice(row){
  if(!window.confirm('Отменить документ '+(row.number||row.id)+'? История сохранится.'))return;
  busy.value=true;error.value='';notice.value='';
  try{
    await call('invoices/'+row.id+'/cancel',{method:'POST'});
    notice.value='Счёт отменён с сохранением истории';await load();
  }catch(e){error.value=e.message}finally{busy.value=false}
}
async function issueInvoice(row){
  if(!window.confirm('Выставить документ '+(row.number||row.id)+'?'))return;
  busy.value=true;error.value='';
  try{await call('invoices/'+row.id+'/issue',{method:'POST'});notice.value='Счёт выставлен';await load()}
  catch(e){error.value=e.message}finally{busy.value=false}
}
async function reverseExpense(row){
  const reason=window.prompt('Причина сторно начисления (минимум 3 символа)');
  if(!reason || reason.trim().length<3)return;
  busy.value=true;error.value='';notice.value='';
  try{
    await call('expenses/'+row.id+'/reverse',{
      method:'POST',body:JSON.stringify({reason:reason.trim()})
    });
    notice.value='Создана корректирующая запись. Исходное начисление и история сохранены.';
    await load();
  }catch(e){error.value=e.message}finally{busy.value=false}
}
async function linkExpensePayment(){
  busy.value=true;error.value='';notice.value='';
  try {
    await call('expenses/'+expensePayment.accrual_id+'/payment-links',{
      method:'POST',body:JSON.stringify({
        cash_transaction_id:Number(expensePayment.cash_transaction_id),
        amount:Number(expensePayment.amount)
      })
    });
    openForm.value='';notice.value='Выплата привязана к начислению без повторного списания денег';
    await load();
  }catch(e){error.value=e.message}finally{busy.value=false}
}
async function allocateReceipt(){
  busy.value=true;error.value='';
  try{
    const receipt=allocationReceipt.value;
    if(!receipt||receipt.direction!=='in'||receipt.status!=='confirmed'||receipt.internal_transfer_id)
      throw new Error('Выберите подтверждённое внешнее поступление');
    const items=billAllocation.items.map(x=>({
      invoice_id:Number(x.invoice_id),amount:Number(x.amount)
    }));
    if(!items.length||items.some(x=>!Number.isSafeInteger(x.invoice_id)||x.invoice_id<1
      ||!Number.isFinite(x.amount)||x.amount<=0
      ||Math.abs(Math.round(x.amount*100)-x.amount*100)>1e-6))
      throw new Error('Укажите счета и положительные суммы с точностью до копейки');
    if(new Set(items.map(x=>x.invoice_id)).size!==items.length)
      throw new Error('Один счёт нельзя выбрать дважды в одном распределении');
    if(receiptRemainingCents.value===null||allocationRequestCents.value>receiptRemainingCents.value)
      throw new Error('Сумма распределений превышает нераспределённый остаток поступления');
    for(const x of items){
      const invoiceRow=invoices.value.find(i=>Number(i.id)===x.invoice_id);
      if(!invoiceRow||invoiceRow.document_status!=='issued'
        ||(invoiceRow.settlement_status&&invoiceRow.settlement_status!=='open')
        ||Math.round(x.amount*100)>Math.round((Number(invoiceRow.amount)-Number(invoiceRow.paid))*100))
        throw new Error('Нельзя распределить больше остатка выставленного счёта');
    }
    const response=await call('receipts/'+billAllocation.receipt_id+'/allocations',{
      method:'POST',body:JSON.stringify({allocations:items})
    });
    billAllocation.receipt_id='';
    billAllocation.items=[{invoice_id:'',amount:''}];
    openForm.value='';
    notice.value=response?.settlements?.some(s=>s.synchronized===false)
      ?'Оплата разнесена, Clients пока не подтвердил закрытие периода'
      :'Оплата распределена по '+items.length+' счетам';
    await load();
  }catch(e){error.value=e.message}finally{busy.value=false}
}
async function reverseAllocation(a){
  const reason=window.prompt('Причина отмены распределения оплаты (обязательно)');
  if(!reason || reason.trim().length<3)return;
  busy.value=true;error.value='';
  try{
    await call('allocations/'+a.id+'/reverse',{method:'POST',body:JSON.stringify({reason:reason.trim()})});
    notice.value='Распределение отменено с сохранением истории';await load();
  }catch(e){error.value=e.message}finally{busy.value=false}
}
async function retrySettlement(period){
  busy.value=true;error.value='';
  try{
    const result=await call('periods/'+period+'/retry-sync',{method:'POST'});
    notice.value=result.synchronized?'Синхронизация Clients завершена':'Clients недоступен; можно повторить операцию';
    await load();
  }catch(e){error.value=e.message}finally{busy.value=false}
}
function addBudgetLine(){
  const latest=budget.lines.at(-1)?.month || month.value;
  const [year,m]=latest.split('-').map(Number);
  const next=m===12?String(year+1)+'-01':String(year)+'-'+String(m+1).padStart(2,'0');
  budget.lines.push({month:next,cost_center:'corporate',article:'',amount:''});
}
function removeBudgetLine(index){
  if(budget.lines.length>1)budget.lines.splice(index,1);
}
async function showBudgetVariance(id){
  busy.value=true;error.value='';budgetVariance.value=null;
  try{budgetVariance.value=await call('planning/'+id+'/variance?month='+month.value)}
  catch(e){error.value=e.message}finally{busy.value=false}
}
async function approveBudget(id){
  busy.value=true;error.value='';
  try{await call('planning/'+id+'/approve',{method:'POST'});await load()}
  catch(e){error.value=e.message}finally{busy.value=false}
}
async function confirm(r){
  if(r.status==='confirmed')return;
  busy.value=true;
  try{await call('receipts/'+r.id+'/confirm',{method:'POST'});await load()}catch(ex){error.value=ex.message}finally{busy.value=false}
}
function open(sectionId){
  section.value=sectionId;
}
function locationChanged(){
  const requested=fromPath();
  section.value=sections.some(s=>s.id===requested)&&sectionAllowed(requested)?requested:'overview';
}
watch(section,(id)=>{
  const url='/finance/'+id+'/';
  if(location.pathname!==url)history.pushState({},'',url);
  openForm.value='';notice.value='';error.value='';
});
watch(()=>accrual.client_id,()=>{accrual.project_id='';});
watch(month,()=>{budgetVariance.value=null;load();});
onMounted(()=>{window.addEventListener('popstate',locationChanged);load()});
onBeforeUnmount(()=>{loadSequence++;window.removeEventListener('popstate',locationChanged);});
</script>

<template>
  <UiAppShell service="finance" v-model:section="section" :items="visible" :current-user="auth.user" @logout="auth.logout">
    <template #actions>
      <UiButton v-if="section==='invoices' && can('finance.invoices.manage')" @click="openForm='invoices'">Создать счёт</UiButton>
      <UiButton v-if="section==='invoices' && can('finance.cash.confirm')" @click="openForm='receipts'">Поступление</UiButton>
      <UiButton v-if="section==='planning' && can('finance.planning.manage')" @click="openForm='budgets'">Новый бюджет</UiButton>
      <UiButton v-if="section==='invoices' && can('finance.invoices.manage')" @click="openForm='allocation'">Распределить оплату</UiButton>
      <UiButton v-if="section==='expenses' && can('finance.expenses.manage')" @click="openForm='expenses'">Новый расход</UiButton>
      <UiButton v-if="section==='expenses' && can('finance.expenses.manage')" @click="openForm='expense-payment'">Связать выплату</UiButton>
      <UiButton v-if="section==='settings' && can('finance.accounts.manage')" @click="openForm='accounts'">Добавить счёт</UiButton>
      <UiButton v-if="section==='cash-flow' && can('finance.cash.confirm')" @click="openForm='transfers'">Перевод между счетами</UiButton>
    </template>
    <main class="finance-workspace">
      <div class="finance-controls">
        <label>Месяц <UiPeriodPicker v-model="month" mode="month"/></label>
        <span class="finance-subtle">Источник данных: ручной ввод. Интеграция с 1С не подключена.</span>
        <UiButton variant="secondary" :disabled="busy" @click="load">Обновить</UiButton>
      </div>
      <div v-if="busy" class="finance-state" role="status">Загрузка финансовых данных…</div>
      <div v-if="error" class="finance-error" role="alert">{{error}}</div>
      <div v-if="notice" class="finance-success" role="status">{{notice}}</div>
      <template v-if="section==='overview'">
        <div class="finance-kpis">
          <div><small>Выставлено счетов (все даты)</small><strong>{{euro(overview?.issued_amount)}}</strong></div>
          <div><small>Поступления подтверждены за месяц</small><strong>{{euro(overview?.cash_in)}}</strong></div>
          <div><small>Выручка по подтверждённым данным</small><strong>{{euro(overview?.revenue)}}</strong><small>{{overview?.completeness||'Источник недоступен'}}</small></div>
          <div><small>Управленческий P&L (подтверждённый)</small><strong>{{euro(overview?.pnl)}}</strong><small>{{overview?.completeness||'Не рассчитано'}}</small></div>
          <div v-if="overview?.known_recorded_difference!==null && overview?.known_recorded_difference!==undefined"><small>Разница известных начислений (не прибыль)</small><strong>{{euro(overview.known_recorded_difference)}}</strong><small>Неполный ручной учёт</small></div>
          <div><small>Дебиторская задолженность по выставленным счетам</small><strong>{{euro(billingTotals?.debt)}}</strong><small>Все даты, только зачтённые оплаты</small></div>
          <div><small>Остаток денег на счетах</small><strong>{{euro(totalCashBalance)}}</strong><small>{{totalCashBalance===null?'Недостаточно данных':'На конец выбранного месяца'}}</small></div>
        </div>
        <div class="finance-explainer">Дебиторская задолженность по счетам и денежные поступления — разные показатели. Пока полнота ручных начислений не подтверждена, разница известной выручки и затрат не является финансовой прибылью.</div>
      </template>
      <template v-else-if="section==='invoices'">
        <div class="finance-kpis">
          <div><small>Всего выставлено</small><strong>{{euro(billingTotals?.issued)}}</strong></div>
          <div><small>Подтверждено распределённых оплат</small><strong>{{euro(billingTotals?.paid)}}</strong></div>
          <div><small>Не оплачено</small><strong>{{euro(billingTotals?.debt)}}</strong></div>
        </div>
        <div class="finance-explainer">Выгружаются счета бухгалтерии. Статус оплаты вычисляется из подтверждённых и распределённых поступлений; черновики не участвуют в контроле закрытия периода Clients.</div>
        <div class="finance-filters">
          <label>Поиск <input v-model="invoiceQuery" placeholder="Номер, клиент, плательщик" aria-label="Поиск счета"/></label>
          <label>Документ <select v-model="invoiceDocFilter"><option value="all">Все</option><option value="draft">Черновики</option><option value="issued">Выставлены</option><option value="cancelled">Отменены</option></select></label>
          <label>Оплата <select v-model="invoicePayFilter"><option value="all">Все</option><option value="unpaid">Не оплачено</option><option value="partial">Частично</option><option value="paid">Оплачено</option></select></label>
          <label class="finance-overdue-filter"><input v-model="invoiceOverdueOnly" type="checkbox"/> Просроченные</label>
          <small>{{filteredInvoices.length}} показано из {{invoices.length}} загруженных · всего выставлено {{billingTotals?.invoice_count??'неизвестно'}}</small>
        </div>
        <div class="finance-table"><table><thead><tr><th>№ / ID</th><th>Клиент</th><th>Отчётный период</th><th>Дата</th><th>Срок оплаты</th><th>Сумма</th><th>Оплачено</th><th>Остаток</th><th>Документ</th><th>Оплата / действия</th></tr></thead>
        <tbody><tr v-for="i in filteredInvoices" :key="i.id"><td>{{i.number||'Черновик #'+i.id}}</td><td>{{clientLabel(i.client_id)}}</td><td>{{periodLabel(i.reporting_period_id)}}</td><td>{{i.issued_on||'—'}}</td><td>{{i.due_on||'—'}}</td><td>{{euro(i.amount)}}</td><td>{{euro(i.paid)}}</td><td>{{euro(Math.max(0,Number(i.amount)-Number(i.paid)))}}</td><td><UiBadge>{{i.document_status==='issued'?'Выставлен':i.document_status==='cancelled'?'Отменён':'Черновик'}}</UiBadge></td><td><UiBadge>{{invoicePaymentStatus(i)==='paid'?'Оплачен':invoicePaymentStatus(i)==='partial'?'Частично':invoicePaymentStatus(i)==='unpaid'?'Не оплачен':'—'}}</UiBadge><UiBadge v-if="invoiceIsOverdue(i)">Просрочено</UiBadge><UiButton v-if="i.document_status==='draft' && can('finance.invoices.manage')" :disabled="busy" @click="issueInvoice(i)">Выставить</UiButton><UiButton v-if="i.document_status!=='cancelled' && Number(i.paid)===0 && i.settlement_status!=='pending' && can('finance.invoices.manage')" variant="secondary" :disabled="busy" @click="cancelInvoice(i)">Отменить</UiButton><UiButton v-if="i.settlement_status==='pending' && can('finance.cash.confirm')" :disabled="busy" @click="retrySettlement(i.reporting_period_id)">Повторить синхронизацию</UiButton></td></tr></tbody></table>
        <div v-if="!invoices.length" class="finance-empty">Счета пока не внесены.</div></div>
        <UiButton v-if="hasMoreInvoices" :disabled="busy" variant="secondary" @click="loadMoreInvoices">Загрузить более ранние счета</UiButton>
        <h3>Распределения подтверждённых поступлений</h3>
        <div class="finance-table"><table><thead><tr><th>Счёт</th><th>Операция</th><th>Сумма</th><th>Состояние</th><th>Действие</th></tr></thead>
        <tbody><tr v-for="a in allocations" :key="a.id"><td>{{a.number||a.invoice_id}}</td><td>{{a.cash_transaction_id}}</td><td>{{euro(a.amount)}}</td><td>{{a.reversed_at?'Отменено':'Действует'}}</td><td>
          <UiButton v-if="!a.reversed_at && can('finance.invoices.manage')" :disabled="busy" @click="reverseAllocation(a)">Отменить распределение</UiButton>
        </td></tr></tbody></table></div>
        <h3>Денежные поступления и списания</h3>
        <div class="finance-table"><table><thead><tr><th>Дата</th><th>Направление</th><th>Сумма</th><th>Контрагент</th><th>Статус</th><th>Действие</th></tr></thead><tbody><tr v-for="r in receipts" :key="r.id"><td>{{r.occurred_on}}</td><td>{{r.direction==='in'?'Поступление':'Списание'}}</td><td>{{euro(r.amount)}}</td><td>{{r.counterparty||'—'}}</td><td>{{r.status}}</td><td><UiButton v-if="r.status==='draft' && can('finance.cash.confirm')" :disabled="busy" @click="confirm(r)">Подтвердить</UiButton></td></tr></tbody></table></div>
      </template>
      <template v-else-if="section==='expenses'">
        <div class="finance-explainer">Вручную внесённые начисления и расходы. Оклад сотрудника в Employees не считается фактически начисленной зарплатой.</div>
        <div class="finance-table"><table><thead><tr><th>Месяц признания</th><th>Статья</th><th>Тип</th><th>Центр затрат</th><th>Начислено</th><th>Связано выплатами</th><th>Не связано</th><th>Действие</th></tr></thead><tbody><tr v-for="e in expenses" :key="e.id"><td>{{e.recognition_month}}</td><td>{{e.article}}</td><td>{{e.kind}}</td><td>{{e.cost_center}}</td><td>{{euro(e.amount)}}</td><td>{{euro(e.linked_payments)}}</td><td>{{euro(Number(e.amount)-Number(e.linked_payments))}}</td><td><span v-if="e.reversal_of_accrual_id">Сторно #{{e.reversal_of_accrual_id}}</span><span v-else-if="e.is_reversed">Сторнировано</span><UiButton v-else-if="can('finance.expenses.manage') && Number(e.linked_payments)===0" variant="secondary" :disabled="busy" @click="reverseExpense(e)">Сторнировать</UiButton></td></tr></tbody></table><div v-if="!expenses.length" class="finance-empty">Расходы не внесены за выбранный месяц.</div></div>
      </template>
      <template v-else-if="section==='cash-flow'">
        <div class="finance-explainer">Списания и поступления показаны по фактическим датам, отдельно от начислений. Переводы между собственными счетами изменяют остатки этих счетов, но не считаются доходом или расходом компании. Начальные остатки вводит бухгалтерия в настройках.</div>
        <div class="finance-kpis"><div v-for="a in cashflow?.balances||[]" :key="a.account_id"><small>Счёт #{{a.account_id}} · остаток на {{a.as_of}}</small><strong>{{euro(a.balance)}}</strong><small>{{a.quality}}</small></div></div>
        <div class="finance-table"><table><thead><tr><th>Дата</th><th>Счёт ID</th><th>Направление</th><th>Сумма</th><th>Вид потока</th><th>Источник</th></tr></thead><tbody><tr v-for="x in cashflow?.transactions||[]" :key="x.id"><td>{{x.occurred_on}}</td><td>{{x.financial_account_id}}</td><td>{{x.direction}}</td><td>{{euro(x.amount)}}</td><td>{{x.flow_type==='internal_transfer'?'Перевод между своими счетами':x.flow_type==='investing'?'Инвестиционная':x.flow_type==='financing'?'Финансовая':'Операционная'}}</td><td>{{x.reference||'Ручной ввод'}}</td></tr></tbody></table><div v-if="!cashflow?.transactions?.length" class="finance-empty">Подтверждённых движений нет.</div></div>
      </template>
      <template v-else-if="section==='pnl'">
        <div class="finance-explainer">Выручка рассчитывается по версиям ставок Clients и подтверждениям Timesheets. Если нет подтверждённой себестоимости или корректных источников, результат показывается как неизвестный.</div><div class="finance-kpis"><div><small>Выручка месяца</small><strong>{{euro(pnl?.revenue)}}</strong></div><div><small>Результат P&L</small><strong>{{euro(pnl?.result)}}</strong><small>{{pnl?.completeness}}</small></div></div>
        <div v-if="pnl?.known_recorded_difference!==null && pnl?.known_recorded_difference!==undefined" class="finance-explainer">Разность известных данных (не подтверждённая прибыль): {{euro(pnl.known_recorded_difference)}}. Не все начисления месяца обязательно внесены.</div>
         <div class="finance-table"><table><thead><tr><th>Центр затрат</th><th>Расходы за {{month}}</th></tr></thead><tbody><tr v-for="e in pnl?.expenses||[]" :key="e.cost_center"><td>{{e.cost_center}}</td><td>{{euro(e.total)}}</td></tr></tbody></table></div>
      </template>
      <template v-else-if="section==='profitability'">
        <div class="finance-explainer">{{profitability?.note||'Источники себестоимости или производственного календаря недоступны'}}. Статус: {{profitability?.quality||'Нет данных'}}</div>
        <div class="finance-kpis"><div><small>Месячная норма, часов</small><strong>{{profitability?.norm_hours||'Нет данных'}}</strong></div><div><small>Клиентов</small><strong>{{profitability?.rows?.length ?? 'Нет данных'}}</strong></div></div>
        <div class="finance-table"><table><thead><tr><th>Клиент</th><th>Часы</th><th>Выручка</th><th>Прямая стоимость труда</th><th>Маржа по труду</th><th>Другие прямые расходы</th><th>Маржа после прямых расходов</th><th>Полнота</th></tr></thead>
        <tbody><tr v-for="c in profitability?.rows||[]" :key="c.client_id"><td>{{c.client_name}}</td><td>{{c.hours}}</td><td>{{euro(c.revenue)}}</td><td>{{euro(c.direct_labor_cost)}}</td><td>{{euro(c.labor_margin)}}</td><td>{{euro(c.other_direct_cost)}}</td><td>{{euro(c.direct_contribution_margin)}}</td><td>{{c.quality}}</td></tr></tbody></table>
        <div v-if="!profitability?.rows?.length" class="finance-empty">Нет достаточных подтверждённых данных для расчёта.</div></div>
      </template>
      <template v-else-if="section==='revenue'">
        <div class="finance-kpis">
          <div><small>Выручка по подтверждённым данным</small><strong>{{euro(revenue?.client_agreed_amount)}}</strong></div>
          <div><small>Предварительный расчёт</small><strong>{{euro(revenue?.preliminary_amount)}}</strong></div>
          <div><small>Часы</small><strong>{{revenue?.hours ?? 'Нет данных'}}</strong></div>
        </div>
        <div class="finance-explainer">{{revenue?.note||'Данные Clients/Timesheets недоступны'}}; полнота: {{revenue?.quality||'unknown'}}.</div>
        <div class="finance-table"><table><thead><tr><th>Дата услуги</th><th>Клиент</th><th>Проект</th><th>Сотрудник ID</th><th>Часы</th><th>Ставка</th><th>Сумма</th><th>Статус</th></tr></thead>
        <tbody><tr v-for="(v,idx) in revenue?.rows||[]" :key="idx"><td>{{v.work_date}}</td><td>{{v.client_name}}</td><td>{{v.project_name}}</td><td>{{v.employee_id}}</td><td>{{v.hours}}</td><td>{{euro(v.rate)}}</td><td>{{euro(v.amount)}}</td><td>{{v.status}}</td></tr></tbody></table>
        <div v-if="!revenue?.rows?.length" class="finance-empty">Нет подтверждённой коммерческой выработки за месяц либо источник недоступен.</div></div>
      </template>
      <template v-else-if="section==='planning'">
        <div class="finance-explainer">Помесячные бюджеты с версиями и сценариями. Утверждённая редакция остаётся неизменной.</div>
        <div class="finance-table"><table><thead><tr><th>Бюджет</th><th>Сценарий</th><th>Версия</th><th>Статус</th><th>Период</th><th>Сумма</th><th>Действие</th></tr></thead>
        <tbody><tr v-for="b in budgets" :key="b.id"><td>{{b.name}}</td><td>{{b.scenario}}</td><td>{{b.revision}}</td><td>{{b.status}}</td><td>{{b.first_month}} – {{b.last_month}}</td><td>{{euro((b.lines||[]).reduce((n,l)=>n+Number(l.amount),0))}}</td><td><UiButton variant="secondary" :disabled="busy" @click="showBudgetVariance(b.id)">План/факт</UiButton><UiButton v-if="b.status==='draft' && can('finance.planning.approve')" @click="approveBudget(b.id)">Утвердить</UiButton></td></tr></tbody></table><div v-if="!budgets.length" class="finance-empty">Планы пока не сформированы.</div></div>
        <template v-if="budgetVariance">
          <h3>План/факт · бюджет #{{budgetVariance.budget_id}} · {{budgetVariance.month}}</h3>
          <div class="finance-explainer">Отклонение = факт − план. Если подтверждённые данные отсутствуют, факт не заменяется нулём. Полнота: {{budgetVariance.quality}}.</div>
          <div class="finance-table"><table><thead><tr><th>Центр затрат</th><th>Статья</th><th>План</th><th>Факт</th><th>Отклонение</th><th>Качество источника</th></tr></thead><tbody>
            <tr v-for="(v,i) in budgetVariance.rows" :key="i"><td>{{v.cost_center}}</td><td>{{v.article}}</td><td>{{euro(v.plan)}}</td><td>{{euro(v.actual)}}</td><td>{{euro(v.deviation)}}</td><td>{{v.quality}}</td></tr>
          </tbody></table></div>
        </template>
      </template>
      <template v-else-if="section==='settings'">
        <div class="finance-explainer">Денежные счета и начальные остатки. Реестр оборудования ведётся отдельно в сервисе Equipment.</div>
        <div class="finance-table"><table><thead><tr><th>Название</th><th>Тип</th><th>Начальный остаток</th><th>Дата</th></tr></thead><tbody><tr v-for="a in accounts" :key="a.id"><td>{{a.name}}</td><td>{{a.type}}</td><td>{{euro(a.opening_balance)}}</td><td>{{a.opening_on}}</td></tr></tbody></table><div v-if="!accounts.length" class="finance-empty">Добавьте банковский или денежный счёт.</div></div>
        <template v-if="can('finance.salary.view')">
          <h3>История финансовых операций</h3>
          <div class="finance-explainer">Аудит показывает автора, действие и сохранённые основания. В журнале могут находиться сведения о начислениях, поэтому просмотр требует разрешения на детализацию зарплат.</div>
          <div class="finance-table"><table><thead><tr><th>Время</th><th>Автор</th><th>Объект</th><th>Действие</th><th>Детали</th></tr></thead><tbody><tr v-for="event in auditLog" :key="event.id"><td>{{event.created_at}}</td><td>{{event.actor}}</td><td>{{event.entity_type}} #{{event.entity_id}}</td><td>{{event.action}}</td><td :title="auditDetails(event.details)">{{auditDetails(event.details)}}</td></tr></tbody></table><div v-if="!auditLog.length" class="finance-empty">В журнале пока нет операций либо данные недоступны.</div></div>
          <UiButton v-if="hasMoreAudit" variant="secondary" :disabled="busy" @click="loadMoreAudit">Загрузить более ранние события</UiButton>
        </template>
      </template>
      <template v-else>
        <div class="finance-explainer">
          <strong>{{sections.find(s=>s.id===section)?.label}}</strong>
          <p>Этот раздел не публикует фиктивные показатели. {{ section==='planning'?'Бюджетирование требует подтверждённых расчётных источников и версий бюджета.':'Данные из Clients, Timesheets и Employees ещё не подключены к расчётам Finance.' }}</p>
          <p>Раздел предусмотрен в навигации и спецификации, но функциональность пока не реализована.</p>
        </div>
      </template>
    </main>
    <UiDrawer :open="Boolean(openForm)" :title="formTitle" width="540px" @close="openForm=''">
      <form class="finance-editor" @submit.prevent="openForm==='allocation'?allocateReceipt():openForm==='expense-payment'?linkExpensePayment():submit(openForm)">
        <template v-if="openForm==='budgets'">
          <label>Название <input v-model="budget.name" required /></label>
          <label>Сценарий <select v-model="budget.scenario"><option value="base">Базовый</option><option value="optimistic">Оптимистичный</option><option value="pessimistic">Пессимистичный</option><option value="custom">Свой</option></select></label>
          <div v-for="(line,idx) in budget.lines" :key="idx" class="finance-budget-line">
            <label>Месяц <input v-model="line.month" type="month" required /></label>
            <label>Центр затрат <input v-model="line.cost_center" required /></label>
            <label>Статья <input v-model="line.article" required /></label>
            <label>Сумма ₽ <input v-model="line.amount" type="number" step="0.01" required /></label>
            <UiButton v-if="budget.lines.length>1" variant="secondary" type="button" @click="removeBudgetLine(idx)">Убрать строку</UiButton>
          </div>
          <UiButton variant="secondary" type="button" :disabled="budget.lines.length>=100" @click="addBudgetLine">Добавить строку бюджета</UiButton>
          <small>Бюджетный горизонт до 12 месяцев; можно вводить несколько статей в каждом месяце.</small>
        </template>
        <template v-else-if="openForm==='expense-payment'">
          <div class="finance-explainer">Выберите уже подтверждённое исходящее денежное движение и начисление. Связь не создаёт новую выплату и не повторяет расход в P&amp;L.</div>
          <label>Начисление <select v-model="expensePayment.accrual_id" required><option value="">Выберите</option><option v-for="e in expenses.filter(e=>!e.is_reversed && !e.reversal_of_accrual_id && Number(e.amount)>Number(e.linked_payments))" :key="e.id" :value="e.id">{{e.article}} · {{e.recognition_month}} · остаток {{euro(Number(e.amount)-Number(e.linked_payments))}}</option></select></label>
          <label>Подтверждённое списание <select v-model="expensePayment.cash_transaction_id" required><option value="">Выберите</option><option v-for="x in receipts.filter(x=>x.direction==='out'&&x.status==='confirmed'&&!x.internal_transfer_id)" :key="x.id" :value="x.id">{{x.occurred_on}} · {{x.counterparty||'Операция #'+x.id}} · {{euro(x.amount)}}</option></select></label>
          <label>Сумма связи, ₽ <input v-model="expensePayment.amount" type="number" step="0.01" min="0.01" required/></label>
          <small>НДФЛ, net и взносы могут быть разными денежными движениями: связь подтверждает распределение денег, не заменяет документы начисления.</small>
        </template>
        <template v-else-if="openForm==='allocation'">
          <label>Подтверждённое поступление <select v-model="billAllocation.receipt_id" required><option value="">Выберите</option><option v-for="r in receipts.filter(r=>r.direction==='in'&&r.status==='confirmed'&&!r.internal_transfer_id)" :value="r.id" :key="r.id">{{r.id}} · {{euro(r.amount)}} · {{r.counterparty}}</option></select></label>
          <div class="finance-explainer" v-if="receiptRemainingCents!==null">Доступно для распределения: {{euro(receiptRemainingCents/100)}}. Уже распределённые суммы учтены по всему реестру.</div>
          <div v-for="(item,idx) in billAllocation.items" :key="idx" class="finance-budget-line">
            <label>Выставленный счёт {{idx+1}}
              <select v-model="item.invoice_id" required><option value="">Выберите</option><option v-for="i in invoices.filter(i=>i.document_status==='issued' && Number(i.amount)>Number(i.paid) && (!i.settlement_status||i.settlement_status==='open'))" :key="i.id" :value="i.id">{{i.number||'#'+i.id}} · остаток {{euro(Number(i.amount)-Number(i.paid))}}</option></select>
            </label>
            <label>Сумма на счёт, ₽ <input v-model="item.amount" type="number" min="0.01" step="0.01" required /></label>
            <UiButton v-if="billAllocation.items.length>1" variant="secondary" type="button" @click="removeAllocationLine(idx)">Убрать счёт</UiButton>
          </div>
          <UiButton variant="secondary" type="button" :disabled="billAllocation.items.length>=100" @click="addAllocationLine">Добавить счёт</UiButton>
          <small>К распределению: {{euro(allocationRequestCents/100)}}. После полного погашения всех выставленных счетов периода Finance автоматически запросит его закрытие в Clients.</small>
        </template>
        <template v-else-if="openForm==='invoices'">
          <label>Согласованный отчётный период Clients
            <select v-model="invoice.reporting_period_id" required @change="periodSelected">
              <option value="">Выберите период</option>
              <option v-for="p in clientPeriods.filter(p=>p.status==='Акт согласован')" :key="p.id" :value="p.id">
                {{p.client_name}} · {{p.period_start}} — {{p.period_end}}
              </option>
            </select>
          </label><small>Клиент ID: {{invoice.client_id||'Выберите период'}}. Оплаченные периоды не редактируются.</small>
          <label>Номер вручную <input v-model="invoice.number"/></label>
          <label>Плательщик <input v-model="invoice.payer"/></label>
          <label>Дата документа <input v-model="invoice.issued_on" type="date"/></label>
          <label>Срок платежа <input v-model="invoice.due_on" type="date"/></label>
          <label>Общая сумма RUB <input v-model="invoice.amount" type="number" step="0.01" min="0.01" required /></label>
          <label>Включённый НДС RUB <input v-model="invoice.vat_amount" type="number" step="0.01" min="0" required /></label>
          <label>Примечание <textarea v-model="invoice.comment"></textarea></label>
        </template>
        <template v-else-if="openForm==='transfers'">
          <div class="finance-explainer">Один подтверждённый перевод создаёт два связанных движения — списание с одного своего счёта и приход на другой. В общую выручку перевод не входит.</div>
          <label>С какого счёта <select v-model="cashTransfer.source_account_id" required><option value="">Выберите</option><option v-for="a in accounts.filter(a=>a.active)" :key="a.id" :value="a.id">{{a.name}}</option></select></label>
          <label>На какой счёт <select v-model="cashTransfer.destination_account_id" required><option value="">Выберите</option><option v-for="a in accounts.filter(a=>a.active&&String(a.id)!==String(cashTransfer.source_account_id))" :key="a.id" :value="a.id">{{a.name}}</option></select></label>
          <label>Дата движения <input v-model="cashTransfer.occurred_on" type="date" required/></label>
          <label>Сумма, RUB <input v-model="cashTransfer.amount" type="number" min="0.01" step="0.01" required/></label>
        </template>
        <template v-else-if="openForm==='receipts'">
          <label>Счёт <select v-model="receipt.financial_account_id" required><option value="">Выберите</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{a.name}}</option></select></label>
          <label>Дата денег <input v-model="receipt.occurred_on" type="date" required/></label>
          <label>Тип <select v-model="receipt.direction"><option value="in">Поступление</option><option value="out">Списание</option></select></label>
          <label>Вид денежного потока <select v-model="receipt.flow_type"><option value="operating">Операционный</option><option value="investing">Инвестиционный</option><option value="financing">Финансовый</option></select></label>
          <label>Сумма RUB <input v-model="receipt.amount" type="number" min="0.01" step="0.01" required /></label>
          <label>Контрагент <input v-model="receipt.counterparty"/></label>
          <label>Номер платежного основания <input v-model="receipt.reference"/></label>
        </template>
        <template v-else-if="openForm==='expenses'">
          <label>Месяц начисления <input v-model="accrual.recognition_month" type="month" required/></label>
          <label>Тип <select v-model="accrual.kind"><option v-if="can('finance.salary.view')" value="salary">Зарплата</option><option v-if="can('finance.salary.view')" value="bonus">Премия</option><option v-if="can('finance.salary.view')" value="contribution">Взносы</option><option v-if="can('finance.salary.view')" value="vacation">Отпускные</option><option v-if="can('finance.salary.view')" value="sick">Больничные</option><option value="contractor">Подрядчик</option><option value="other">Прочее</option></select></label>
          <label>Статья <input v-model="accrual.article" required/></label>
          <label>Центр затрат <input v-model="accrual.cost_center" required /></label>
          <label>Сумма RUB <input v-model="accrual.amount" min="0.01" step="0.01" type="number" required /></label>
          <label v-if="can('finance.salary.view')">Сотрудник (при необходимости)
            <UiSearchSelect v-model="accrual.employee_id" :options="employeePickerOptions"
              placeholder="Выберите из подразделений" search-placeholder="Найти сотрудника"
              :teleport="true" aria-label="Выбор сотрудника"/>
          </label>
          <small v-if="employeeDirectoryError" role="status">Каталог сотрудников сейчас недоступен: {{employeeDirectoryError}}. Для зарплаты и отпускных требуется выбрать сотрудника.</small>
          <label>Контрагент <input v-model="accrual.counterparty"/></label>
          <template v-if="['contractor','other'].includes(accrual.kind)">
            <label>Прямая связь с клиентом (необязательно) <select v-model="accrual.client_id">
              <option value="">Общекорпоративно / направление — без привязки к клиенту</option>
              <option v-for="p in clientExpenseOptions" :key="p.client_id" :value="p.client_id">{{p.client_name}}</option>
            </select></label>
            <template v-if="accrual.client_id">
              <label>Проект (необязательно) <select v-model="accrual.project_id">
                <option value="">Без конкретного проекта</option>
                <option v-for="p in projectExpenseOptions" :key="p.id" :value="p.id">{{p.name}}</option>
              </select></label>
              <label>Основание прямого расхода (обязательно, минимум 10 символов) <textarea v-model="accrual.direct_cost_reason" minlength="10" required/></label>
            </template>
          </template>
        </template>
        <template v-else>
          <label>Название счёта <input v-model="account.name" required/></label>
          <label>Тип <select v-model="account.type"><option value="bank">Банковский</option><option value="cash">Наличные</option></select></label>
          <label>Дата начального остатка <input v-model="account.opening_on" type="date" required/></label>
          <label>Начальный остаток RUB <input v-model="account.opening_balance" type="number" step="0.01" required /></label>
        </template>
        <div class="finance-editor-actions"><UiButton variant="secondary" type="button" @click="openForm=''">Отмена</UiButton><UiButton :disabled="busy" type="submit">Сохранить</UiButton></div>
      </form>
    </UiDrawer>
  </UiAppShell>
</template>

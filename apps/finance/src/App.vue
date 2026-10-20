<script setup>
import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { UiAppShell, UiButton, UiBadge } from '@irlix/ui';
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
const month = ref(new Date().toISOString().slice(0,7));
const invoices = ref([]), receipts = ref([]), expenses = ref([]), accounts = ref([]), clientPeriods = ref([]);
const overview = ref(null), pnl = ref(null), cashflow = ref(null), revenue = ref(null), profitability = ref(null), budgets = ref([]);
const billAllocation = reactive({receipt_id:'',invoice_id:'',amount:''});
const budget = reactive({name:'',scenario:'base',month:month.value,cost_center:'corporate',article:'',amount:''});
const operationState = ref(null);

const permissions = ref({});
const openForm = ref('');
const invoice = reactive({client_id:'',reporting_period_id:'',number:'',payer:'',issued_on:'',due_on:'',amount:'',vat_amount:'0',comment:''});
const receipt = reactive({financial_account_id:'',occurred_on:new Date().toISOString().slice(0,10),direction:'in',amount:'',reference:'',counterparty:''});
const accrual = reactive({recognition_month:month.value,kind:'other',article:'',cost_center:'corporate',amount:'',employee_id:'',counterparty:''});
const account = reactive({name:'',type:'bank',opening_on:new Date().toISOString().slice(0,10),opening_balance:'0'});
const euro = value => value == null ? 'Нет данных' : new Intl.NumberFormat('ru-RU',{style:'currency',currency:'RUB'}).format(Number(value));
const visible = computed(()=>sections.map(s=>({...s,href:'/finance/'+s.id+'/'})));
const can = name => permissions.value[name] === true;
const billingTotals = computed(()=>{
  const issued=invoices.value.filter(i=>i.document_status==='issued');
  return {issued:issued.reduce((v,i)=>v+Number(i.amount),0), paid:issued.reduce((v,i)=>v+Number(i.paid),0), debt:issued.reduce((v,i)=>v+Math.max(0,Number(i.amount)-Number(i.paid)),0)};
});
const call=async(path, init={})=>{
  const r=await auth.fetch('/api/finance/'+path,{...init,headers:{'Content-Type':'application/json',...(init.headers||{})}});
  const j=await r.json().catch(()=>({message:'Не удалось прочитать ответ'}));
  if(!r.ok)throw new Error(j.message||'Ошибка сервера');
  return j.data;
};
async function load(){
  busy.value=true;error.value='';
  try{
    const [me, i, r, e, a, o, p, c, v, b, pr, cp]=await Promise.all([
      call('me'),call('invoices'),call('receipts'),call('expenses?month='+month.value),
      call('accounts'),call('overview'),call('pnl?month='+month.value),call('cash-flow?month='+month.value),
      call('revenue?month='+month.value).catch(()=>null),call('planning').catch(()=>[]),
      call('profitability?month='+month.value).catch(()=>null),
      call('client-periods').catch(()=>[])
    ]);
    permissions.value=me.permissions;invoices.value=i;receipts.value=r;
    expenses.value=e;accounts.value=a;overview.value=o;pnl.value=p;cashflow.value=c;
    revenue.value=v;budgets.value=b;profitability.value=pr;clientPeriods.value=cp;
  }catch(ex){error.value=ex.message}finally{busy.value=false}
}
async function submit(type){
  busy.value=true;error.value='';notice.value='';
  try{
    const payload=type==='budgets'?{
      name:budget.name,scenario:budget.scenario,
      lines:[{month:budget.month,cost_center:budget.cost_center,article:budget.article,amount:Number(budget.amount)}]
    }:type==='invoices'?{...invoice,client_id:Number(invoice.client_id),reporting_period_id:Number(invoice.reporting_period_id),amount:Number(invoice.amount),vat_amount:Number(invoice.vat_amount)}:
      type==='receipts'?{...receipt,financial_account_id:Number(receipt.financial_account_id),amount:Number(receipt.amount)}:
      type==='expenses'?{...accrual,amount:Number(accrual.amount),employee_id:accrual.employee_id?Number(accrual.employee_id):null}:
      {...account,opening_balance:Number(account.opening_balance)};
    await call(type==='budgets'?'planning':type,{method:'POST',body:JSON.stringify(payload)});
    openForm.value='';notice.value='Сохранено с записью в журнале';await load();
  }catch(ex){error.value=ex.message}finally{busy.value=false}
}
function periodSelected(){
  const found=clientPeriods.value.find(p=>p.id===Number(invoice.reporting_period_id));
  invoice.client_id=found ? String(found.client_id) : '';
}
async function issueInvoice(row){
  if(!window.confirm('Выставить документ '+(row.number||row.id)+'?'))return;
  busy.value=true;error.value='';
  try{await call('invoices/'+row.id+'/issue',{method:'POST'});notice.value='Счёт выставлен';await load()}
  catch(e){error.value=e.message}finally{busy.value=false}
}
async function allocateReceipt(){
  busy.value=true;error.value='';
  try{
    const response=await call('receipts/'+billAllocation.receipt_id+'/allocations',{
      method:'POST',body:JSON.stringify({allocations:[{
        invoice_id:Number(billAllocation.invoice_id),amount:Number(billAllocation.amount)
      }]})
    });
    openForm.value='';
    notice.value=response?.settlements?.some(s=>s.synchronized===false)
      ?'Оплата разнесена, Clients пока не подтвердил закрытие периода'
      :'Оплата распределена';
    await load();
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
function locationChanged(){section.value=sections.some(s=>s.id===fromPath())?fromPath():'overview';}
watch(section,(id)=>{
  const url='/finance/'+id+'/';
  if(location.pathname!==url)history.pushState({},'',url);
  openForm.value='';notice.value='';error.value='';
});
watch(month,load);
onMounted(()=>{window.addEventListener('popstate',locationChanged);load()});
onBeforeUnmount(()=>window.removeEventListener('popstate',locationChanged));
</script>

<template>
  <UiAppShell service="finance" v-model:section="section" :items="visible" :current-user="auth.user" @logout="auth.logout">
    <template #actions>
      <UiButton v-if="section==='invoices' && can('finance.invoices.manage')" @click="openForm='invoices'">Создать счёт</UiButton>
      <UiButton v-if="section==='invoices' && can('finance.cash.confirm')" @click="openForm='receipts'">Поступление</UiButton>
      <UiButton v-if="section==='planning' && can('finance.planning.manage')" @click="openForm='budgets'">Новый бюджет</UiButton>
      <UiButton v-if="section==='invoices' && can('finance.invoices.manage')" @click="openForm='allocation'">Распределить оплату</UiButton>
      <UiButton v-if="section==='expenses' && can('finance.expenses.manage')" @click="openForm='expenses'">Новый расход</UiButton>
      <UiButton v-if="section==='settings' && can('finance.accounts.manage')" @click="openForm='accounts'">Добавить счёт</UiButton>
    </template>
    <main class="finance-workspace">
      <div class="finance-controls">
        <label>Месяц <input v-model="month" type="month" /></label>
        <span class="finance-subtle">Источник данных: ручной ввод. Интеграция с 1С не подключена.</span>
        <UiButton variant="secondary" :disabled="busy" @click="load">Обновить</UiButton>
      </div>
      <div v-if="busy" class="finance-state" role="status">Загрузка финансовых данных…</div>
      <div v-if="error" class="finance-error" role="alert">{{error}}</div>
      <div v-if="notice" class="finance-success" role="status">{{notice}}</div>
      <template v-if="section==='overview'">
        <div class="finance-kpis">
          <div><small>Выставлено счетов (все даты)</small><strong>{{euro(overview?.issued_amount)}}</strong></div>
          <div><small>Поступления подтверждены (все даты)</small><strong>{{euro(overview?.cash_in)}}</strong></div>
          <div><small>Выручка по подтверждённым данным</small><strong>{{euro(pnl?.revenue)}}</strong><small>{{pnl?.revenue_quality||'Источник недоступен'}}</small></div>
          <div><small>Управленческий P&L</small><strong>{{euro(pnl?.result)}}</strong><small>{{pnl?.completeness||'Не рассчитано'}}</small></div>
        </div>
        <div class="finance-explainer">Дебиторская задолженность по счетам и денежные поступления — разные показатели. Без привязки к коммерческим часам расчёт прибыли не выполняется.</div>
      </template>
      <template v-else-if="section==='invoices'">
        <div class="finance-kpis">
          <div><small>Всего выставлено</small><strong>{{euro(billingTotals.issued)}}</strong></div>
          <div><small>Подтверждено распределённых оплат</small><strong>{{euro(billingTotals.paid)}}</strong></div>
          <div><small>Не оплачено</small><strong>{{euro(billingTotals.debt)}}</strong></div>
        </div>
        <div class="finance-explainer">Выставление счетов и распределение подтверждённых поступлений возможны после настройки защищённого канала Clients. Черновики не закрывают отчётный период.</div>
        <div class="finance-table"><table><thead><tr><th>№ / ID</th><th>Клиент ID</th><th>Период ID</th><th>Дата</th><th>Сумма</th><th>Оплачено</th><th>Документ</th></tr></thead>
        <tbody><tr v-for="i in invoices" :key="i.id"><td>{{i.number||'Черновик #'+i.id}}</td><td>{{i.client_id}}</td><td>{{i.reporting_period_id}}</td><td>{{i.issued_on||'—'}}</td><td>{{euro(i.amount)}}</td><td>{{euro(i.paid)}}</td><td><UiBadge>{{i.document_status}}</UiBadge><UiButton v-if="i.document_status==='draft' && can('finance.invoices.manage')" :disabled="busy" @click="issueInvoice(i)">Выставить</UiButton><UiButton v-if="i.settlement_status==='pending' && can('finance.cash.confirm')" :disabled="busy" @click="retrySettlement(i.reporting_period_id)">Повторить синхронизацию</UiButton></td></tr></tbody></table>
        <div v-if="!invoices.length" class="finance-empty">Счета пока не внесены.</div></div>
        <h3>Денежные поступления и списания</h3>
        <div class="finance-table"><table><thead><tr><th>Дата</th><th>Направление</th><th>Сумма</th><th>Контрагент</th><th>Статус</th><th>Действие</th></tr></thead><tbody><tr v-for="r in receipts" :key="r.id"><td>{{r.occurred_on}}</td><td>{{r.direction==='in'?'Поступление':'Списание'}}</td><td>{{euro(r.amount)}}</td><td>{{r.counterparty||'—'}}</td><td>{{r.status}}</td><td><UiButton v-if="r.status==='draft' && can('finance.cash.confirm')" :disabled="busy" @click="confirm(r)">Подтвердить</UiButton></td></tr></tbody></table></div>
      </template>
      <template v-else-if="section==='expenses'">
        <div class="finance-explainer">Вручную внесённые начисления и расходы. Оклад сотрудника в Employees не считается фактически начисленной зарплатой.</div>
        <div class="finance-table"><table><thead><tr><th>Месяц признания</th><th>Статья</th><th>Тип</th><th>Центр затрат</th><th>Сумма</th></tr></thead><tbody><tr v-for="e in expenses" :key="e.id"><td>{{e.recognition_month}}</td><td>{{e.article}}</td><td>{{e.kind}}</td><td>{{e.cost_center}}</td><td>{{euro(e.amount)}}</td></tr></tbody></table><div v-if="!expenses.length" class="finance-empty">Расходы не внесены за выбранный месяц.</div></div>
      </template>
      <template v-else-if="section==='cash-flow'">
        <div class="finance-explainer">Списания и поступления показаны по фактическим датам, отдельно от начислений. Начальные остатки вводит бухгалтерия в настройках.</div>
        <div class="finance-kpis"><div v-for="a in cashflow?.balances||[]" :key="a.account_id"><small>Счёт #{{a.account_id}} · остаток на {{a.as_of}}</small><strong>{{euro(a.balance)}}</strong><small>{{a.quality}}</small></div></div>
        <div class="finance-table"><table><thead><tr><th>Дата</th><th>Счёт ID</th><th>Направление</th><th>Сумма</th><th>Источник</th></tr></thead><tbody><tr v-for="x in cashflow?.transactions||[]" :key="x.id"><td>{{x.occurred_on}}</td><td>{{x.financial_account_id}}</td><td>{{x.direction}}</td><td>{{euro(x.amount)}}</td><td>{{x.reference||'Ручной ввод'}}</td></tr></tbody></table><div v-if="!cashflow?.transactions?.length" class="finance-empty">Подтверждённых движений нет.</div></div>
      </template>
      <template v-else-if="section==='pnl'">
        <div class="finance-explainer">Выручка рассчитывается по версиям ставок Clients и подтверждениям Timesheets. Если нет подтверждённой себестоимости или корректных источников, результат показывается как неизвестный.</div><div class="finance-kpis"><div><small>Выручка месяца</small><strong>{{euro(pnl?.revenue)}}</strong></div><div><small>Результат P&L</small><strong>{{euro(pnl?.result)}}</strong><small>{{pnl?.completeness}}</small></div></div>
        <div class="finance-table"><table><thead><tr><th>Центр затрат</th><th>Расходы за {{month}}</th></tr></thead><tbody><tr v-for="e in pnl?.expenses||[]" :key="e.cost_center"><td>{{e.cost_center}}</td><td>{{euro(e.total)}}</td></tr></tbody></table></div>
      </template>
      <template v-else-if="section==='profitability'">
        <div class="finance-explainer">{{profitability?.note||'Источники себестоимости или производственного календаря недоступны'}}. Статус: {{profitability?.quality||'Нет данных'}}</div>
        <div class="finance-kpis"><div><small>Месячная норма, часов</small><strong>{{profitability?.norm_hours||'Нет данных'}}</strong></div><div><small>Клиентов</small><strong>{{profitability?.rows?.length ?? 'Нет данных'}}</strong></div></div>
        <div class="finance-table"><table><thead><tr><th>Клиент</th><th>Часы</th><th>Выручка</th><th>Прямая стоимость труда</th><th>Маржа по труду</th><th>Полнота</th></tr></thead>
        <tbody><tr v-for="c in profitability?.rows||[]" :key="c.client_id"><td>{{c.client_name}}</td><td>{{c.hours}}</td><td>{{euro(c.revenue)}}</td><td>{{euro(c.direct_labor_cost)}}</td><td>{{euro(c.labor_margin)}}</td><td>{{c.quality}}</td></tr></tbody></table>
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
        <tbody><tr v-for="b in budgets" :key="b.id"><td>{{b.name}}</td><td>{{b.scenario}}</td><td>{{b.revision}}</td><td>{{b.status}}</td><td>{{b.first_month}} – {{b.last_month}}</td><td>{{euro((b.lines||[]).reduce((n,l)=>n+Number(l.amount),0))}}</td><td><UiButton v-if="b.status==='draft' && can('finance.planning.approve')" @click="approveBudget(b.id)">Утвердить</UiButton></td></tr></tbody></table><div v-if="!budgets.length" class="finance-empty">Планы пока не сформированы.</div></div>
      </template>
      <template v-else-if="section==='settings'">
        <div class="finance-explainer">Денежные счета и начальные остатки. Реестр оборудования ведётся отдельно в сервисе Equipment.</div>
        <div class="finance-table"><table><thead><tr><th>Название</th><th>Тип</th><th>Начальный остаток</th><th>Дата</th></tr></thead><tbody><tr v-for="a in accounts" :key="a.id"><td>{{a.name}}</td><td>{{a.type}}</td><td>{{euro(a.opening_balance)}}</td><td>{{a.opening_on}}</td></tr></tbody></table><div v-if="!accounts.length" class="finance-empty">Добавьте банковский или денежный счёт.</div></div>
      </template>
      <template v-else>
        <div class="finance-explainer">
          <strong>{{sections.find(s=>s.id===section)?.label}}</strong>
          <p>Этот раздел не публикует фиктивные показатели. {{ section==='planning'?'Бюджетирование требует подтверждённых расчётных источников и версий бюджета.':'Данные из Clients, Timesheets и Employees ещё не подключены к расчётам Finance.' }}</p>
          <p>Раздел предусмотрен в навигации и спецификации, но функциональность пока не реализована.</p>
        </div>
      </template>
    </main>
    <div v-if="openForm" class="finance-backdrop" @click.self="openForm=''">
      <form class="finance-editor" @submit.prevent="openForm==='allocation'?allocateReceipt():submit(openForm)">
        <h2>{{openForm==='budgets'?'Новый бюджет':openForm==='allocation'?'Распределить оплату':openForm==='invoices'?'Новый черновик счёта':openForm==='receipts'?'Денежная операция':openForm==='expenses'?'Новое начисление/расход':'Денежный счёт'}}</h2>
        <template v-if="openForm==='budgets'">
          <label>Название <input v-model="budget.name" required /></label>
          <label>Сценарий <select v-model="budget.scenario"><option value="base">Базовый</option><option value="optimistic">Оптимистичный</option><option value="pessimistic">Пессимистичный</option><option value="custom">Свой</option></select></label>
          <label>Месяц <input v-model="budget.month" type="month" required /></label>
          <label>Центр затрат <input v-model="budget.cost_center" required /></label>
          <label>Статья <input v-model="budget.article" required /></label>
          <label>Сумма ₽ <input v-model="budget.amount" type="number" step="0.01" required /></label>
          <small>Первая версия с одной строкой. Для полного годового бюджета потребуется загрузка/редактор строк.</small>
        </template>
        <template v-else-if="openForm==='allocation'">
          <label>Подтверждённое поступление <select v-model="billAllocation.receipt_id" required><option value="">Выберите</option><option v-for="r in receipts.filter(r=>r.direction==='in'&&r.status==='confirmed')" :value="r.id" :key="r.id">{{r.id}} · {{euro(r.amount)}} · {{r.counterparty}}</option></select></label>
          <label>Выставленный счёт <select v-model="billAllocation.invoice_id" required><option value="">Выберите</option><option v-for="i in invoices.filter(i=>i.document_status==='issued')" :key="i.id" :value="i.id">{{i.number}} · {{euro(Number(i.amount)-Number(i.paid))}}</option></select></label>
          <label>Распределить ₽ <input v-model="billAllocation.amount" type="number" step="0.01" min="0.01" required /></label>
          <small>После полного погашения всех счетов Finance автоматически запросит закрытие периода в Clients.</small>
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
        <template v-else-if="openForm==='receipts'">
          <label>Счёт <select v-model="receipt.financial_account_id" required><option value="">Выберите</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{a.name}}</option></select></label>
          <label>Дата денег <input v-model="receipt.occurred_on" type="date" required/></label>
          <label>Тип <select v-model="receipt.direction"><option value="in">Поступление</option><option value="out">Списание</option></select></label>
          <label>Сумма RUB <input v-model="receipt.amount" type="number" min="0.01" step="0.01" required /></label>
          <label>Контрагент <input v-model="receipt.counterparty"/></label>
          <label>Номер платежного основания <input v-model="receipt.reference"/></label>
        </template>
        <template v-else-if="openForm==='expenses'">
          <label>Месяц начисления <input v-model="accrual.recognition_month" type="month" required/></label>
          <label>Тип <select v-model="accrual.kind"><option value="salary">Зарплата</option><option value="bonus">Премия</option><option value="contribution">Взносы</option><option value="vacation">Отпускные</option><option value="sick">Больничные</option><option value="contractor">Подрядчик</option><option value="other">Прочее</option></select></label>
          <label>Статья <input v-model="accrual.article" required/></label>
          <label>Центр затрат <input v-model="accrual.cost_center" required /></label>
          <label>Сумма RUB <input v-model="accrual.amount" min="0.01" step="0.01" type="number" required /></label>
          <label>Сотрудник ID (при наличии) <input v-model="accrual.employee_id" type="number" min="1"/></label>
          <label>Контрагент <input v-model="accrual.counterparty"/></label>
        </template>
        <template v-else>
          <label>Название счёта <input v-model="account.name" required/></label>
          <label>Тип <select v-model="account.type"><option value="bank">Банковский</option><option value="cash">Наличные</option></select></label>
          <label>Дата начального остатка <input v-model="account.opening_on" type="date" required/></label>
          <label>Начальный остаток RUB <input v-model="account.opening_balance" type="number" step="0.01" required /></label>
        </template>
        <div class="finance-editor-actions"><UiButton variant="secondary" type="button" @click="openForm=''">Отмена</UiButton><UiButton :disabled="busy" type="submit">Сохранить</UiButton></div>
      </form>
    </div>
  </UiAppShell>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiFilterBar, UiTabs, UiViewSwitch } from '@irlix/ui';

const view = ref('clients');
const query = ref('');
const selectedClientId = ref(null);
const selectedMember = ref(null);
const selectedLead = ref(null);
const reportMode = ref('kanban');
const requestMode = ref('tree');
const expandedClients = reactive(new Set([1, 2]));
const expandedRequests = reactive(new Set([402, 405]));
const expandedPositions = reactive(new Set([4021, 4051]));

const nav = [
  ['clients','Клиенты','▣'],['leads','Лиды','♛'],['contacts','Контакты','◉'],
  ['requests','Запросы','◎'],['positions','Позиции','≡'],['attempts','Попытки','↗'],
  ['members','Участники','♙'],['cashflow','ДДС','◫'],['reports','Отчётные периоды','▦']
];

const clients = ref([
  { id:1, name:'Extyl', type:'Субподряд', sector:'IT', account:'Филиппова Юлия', sales:'Шестунова Наталья', technologies:['SA','Java','React'], projects:[{id:11,name:'Магнит',members:[{id:111,name:'Конюхов Артур',technology:'SA',level:'Middle',rate:2300,hours:8,from:'20.03.2026',to:'21.12.2026',status:'На проекте',terms:[{from:'20.03.2026',to:'21.12.2026',rate:2300,hours:8,technology:'System analyst',level:'Middle'}]}]}]},
  { id:2, name:'УралСиб', type:'Прямой', sector:'Финансы', account:'Топорова А.О.', sales:'Шестунова Н.А.', technologies:['Android','QAM','UX/UI'], projects:[{id:21,name:'Цифровой Рубль',members:[{id:211,name:'Рысьев Александр',technology:'Android',level:'Senior',rate:3000,hours:8,from:'06.07.2026',to:null,status:'На проекте',terms:[{from:'06.07.2026',to:null,rate:3000,hours:8,technology:'Android',level:'Senior'}]},{id:212,name:'Чугунов Алексей',technology:'QAM',level:'Middle+',rate:2700,hours:8,from:'29.07.2026',to:null,status:'На проекте',terms:[{from:'29.07.2026',to:null,rate:2700,hours:8,technology:'QAM',level:'Middle+'}]}]}]},
  { id:3, name:'RedLabs', type:'Прямой', sector:'IT', account:'Топорова А.О.', sales:'Руссков В.А.', technologies:['Java','QA'], projects:[{id:31,name:'Основной проект',members:[]}]}
]);

const leads = ref([
  {id:1,name:'Далее',status:'Новый лид',source:'tg',responsible:'Шестунова Н.А.',created:'01.09.2026',updated:'01.09.2026',contacts:0},
  {id:2,name:'BSS',status:'Новый лид',source:'tg',responsible:'Шестунова Н.А.',created:'04.08.2026',updated:'04.08.2026',contacts:0},
  {id:3,name:'Быстроном',status:'Первичный контакт',source:'leadgen',responsible:'Шестунова Н.А.',created:'20.07.2026',updated:'20.07.2026',contacts:1},
  {id:4,name:'Русская медная компания',status:'Сделка закрыта - Отказ',source:'leadgen',responsible:'Шестунова Н.А.',created:'17.07.2026',updated:'17.07.2026',contacts:1}
]);

const requests = ref([
  {id:402,title:'Заявка №402_CA_Middle',client:'ЛеманаПро',status:'В работе',until:'18.08.2026',responsible:'Топорова А.О.',department:'Analytics',technology:'SA',positions:[{id:4021,title:'SA',level:'Middle+',quantity:1,status:'На рассмотрении',attempts:[{id:1,specialist:'Коршиков Виталий',status:'CV отправлено'},{id:2,specialist:'Фрост А.М.',status:'CV отправлено'},{id:3,specialist:'Ребрий Виктор',status:'CV отправлено'}]}]},
  {id:403,title:'Тинек от 5 августа React',client:'Тинькофф',status:'В работе',until:'19.08.2026',responsible:'Топорова А.О.',department:'Frontend',technology:'React',positions:[{id:4031,title:'React',level:'Senior',quantity:1,status:'Ждёт кандидатов',attempts:[]}]},
  {id:404,title:'Тинек от 5 августа Go',client:'Тинькофф',status:'В работе',until:'19.08.2026',responsible:'Топорова А.О.',department:'Backend',technology:'Go',positions:[{id:4041,title:'Go',level:'Senior',quantity:1,status:'Ждёт кандидатов',attempts:[]}]},
  {id:405,title:'Тинек от 5 августа Java',client:'Тинькофф',status:'В работе',until:'19.08.2026',responsible:'Топорова А.О.',department:'Backend',technology:'Java',positions:[{id:4051,title:'Java',level:'Senior',quantity:1,status:'На рассмотрении',attempts:[{id:4,specialist:'Роман Вороновский',status:'Закрыта: неудача'}]}]}
]);

const reports = ref([
  {client:'RedLabs',period:'01.08.2026 - 31.08.2026',stage:'Акт на согласовании',timesheet:'03.09.2026'},
  {client:'ООО «Оптимус Дуо»',period:'01.08.2026 - 31.08.2026',stage:'Акт согласован',timesheet:'08.09.2026'},
  {client:'Синемекс',period:'01.08.2026 - 31.08.2026',stage:'Акт согласован',timesheet:'03.09.2026'},
  {client:'Инвитро',period:'01.08.2026 - 31.08.2026',stage:'Счет оплачен',timesheet:'03.09.2026'}
]);
const reportStages = ['Новый','ТШ на согласовании','ТШ согласованы','Акт на согласовании','Акт согласован','Счет оплачен'];

const cashRows = computed(() => clients.value.flatMap(c => c.projects.flatMap(p => p.members.map(m => ({client:c.name,project:p.name,...m})))).sort((a,b)=>a.client.localeCompare(b.client)||a.project.localeCompare(b.project)||a.name.localeCompare(b.name)));
const filteredClients = computed(()=>clients.value.filter(c=>!query.value||[c.name,c.type,c.sector,c.account,c.sales,...c.technologies].join(' ').toLowerCase().includes(query.value.toLowerCase())));
const allPositions = computed(()=>requests.value.flatMap(r=>r.positions.map(p=>({...p,request:r.title,client:r.client,responsible:r.responsible,department:r.department,until:r.until}))));
const allAttempts = computed(()=>requests.value.flatMap(r=>r.positions.flatMap(p=>p.attempts.map(a=>({...a,position:`${p.title} ${p.level}`,client:r.client,responsible:r.responsible,control:r.until}))));
const title = computed(()=>({clients:'Клиенты',leads:'Лиды',contacts:'Контактные лица',requests:'Запросы',positions:'Позиции',attempts:'Попытки подключения',members:'Участники проектов',cashflow:'ДДС',reports:'Отчётные периоды'}[view.value]));

function openClient(id){ selectedClientId.value=id; }
function toggle(set,id){ set.has(id)?set.delete(id):set.add(id); }
function leadTone(s){ return s.includes('Отказ')?'danger':s.includes('контакт')?'info':'neutral'; }
function money(v){ return new Intl.NumberFormat('ru-RU').format(v)+' ₽'; }
</script>

<template>
<div class="clients-app irlix-ui">
  <aside class="rail">
    <div class="brand">X</div><a class="home" href="/">⌂</a><div class="rail-chip">ГС</div>
    <nav><button v-for="item in nav" :key="item[0]" :class="{active:view===item[0]}" :title="item[1]" @click="view=item[0]">{{item[2]}}</button></nav>
  </aside>
  <section class="workspace">
    <header class="topbar"><span class="crumb">▣ {{ title }}</span></header>
    <main class="content">
      <div class="page-head"><div><h1>{{ title }}</h1><p v-if="view==='clients'">Список всех клиентов компании и участников их проектов</p><p v-else-if="view==='leads'">Клиенты, которые проявили интерес к услугам компании</p><p v-else-if="view==='reports'">Управление отчётными периодами</p></div><UiButton v-if="['clients','leads','requests','reports'].includes(view)">＋ {{ view==='clients'?'Новый клиент':view==='leads'?'Новый лид':view==='requests'?'Новый запрос':'Новый отчётный период' }}</UiButton></div>

      <template v-if="view==='clients'">
        <UiFilterBar><input v-model="query" placeholder="Поиск по клиентам"><select><option>Аккаунты</option></select><select><option>Сейлзы</option></select><select><option>Технологии</option></select><select><option>Подразделения</option></select><select><option>Активность</option></select><label class="check"><input type="checkbox" checked> Только активные подключения</label></UiFilterBar>
        <div class="tag-row"><span>SA: 30%</span><span>Java: 24%</span><span>React: 16%</span><span>Android: 8%</span><span>QAM: 14%</span></div>
        <div class="count">{{ filteredClients.length }} клиента</div>
        <div class="client-table">
          <div class="client-head client-grid"><div>Клиент</div><div>Тип</div><div>Сектор</div><div>Технологии</div><div>Ставки</div><div>Аккаунт-менеджер</div><div>Sales-менеджер</div></div>
          <template v-for="client in filteredClients" :key="client.id">
            <div class="client-row client-grid" @dblclick="openClient(client.id)"><div><button class="chev" @click.stop="toggle(expandedClients,client.id)">{{expandedClients.has(client.id)?'⌄':'›'}}</button><strong @click="openClient(client.id)">{{client.name}}</strong></div><div>{{client.type}}</div><div>{{client.sector}}</div><div class="link">{{client.technologies.length}} технологий</div><div>{{client.projects.reduce((s,p)=>s+p.members.length,0)}} / {{client.projects.reduce((s,p)=>s+p.members.length,0)}}</div><div>{{client.account}}</div><div>{{client.sales}}</div></div>
            <template v-if="expandedClients.has(client.id)" v-for="project in client.projects" :key="project.id">
              <div class="member-subhead member-grid"><div>Сотрудник</div><div>Проект</div><div>Период работы</div><div>Технология</div><div>Уровень</div><div>Ставка, руб/ч</div><div>Загрузка, ч/д</div><div>Статус</div></div>
              <div v-for="member in project.members" :key="member.id" class="member-row member-grid" @click="selectedMember={...member,project:project.name,client:client.name,responsible:client.sales}"><div>{{member.name}}</div><div>{{project.name}}</div><div>{{member.from}} - {{member.to||'...'}}</div><div>{{member.technology}}</div><div>{{member.level}}</div><div>{{member.rate}}</div><div>{{member.hours}}</div><div><UiBadge tone="success">✓ {{member.status}}</UiBadge></div></div>
            </template>
          </template>
        </div>
      </template>

      <template v-else-if="view==='leads'">
        <UiFilterBar><input v-model="query" placeholder="Поиск"><select><option>Ответственные</option></select><select><option>Клиенты</option></select><select><option>Статус</option></select><input type="date"></UiFilterBar>
        <table class="irlix-data-table"><thead><tr><th>Название</th><th>Статус</th><th>Источник</th><th>Ответственный</th><th>Добавлен</th><th>Изменён</th><th>Контакты</th></tr></thead><tbody><tr v-for="lead in leads" :key="lead.id" @click="selectedLead=lead"><td>{{lead.name}}</td><td><UiBadge :tone="leadTone(lead.status)">{{lead.status}}</UiBadge></td><td>{{lead.source}}</td><td>{{lead.responsible}}</td><td>{{lead.created}}</td><td>{{lead.updated}}</td><td>{{lead.contacts}} контактов</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='requests'">
        <div class="toolbar"><UiViewSwitch v-model="requestMode" :items="[{value:'tree',label:'Общий экран'},{value:'list',label:'Список'}]"/><UiFilterBar><input v-model="query" placeholder="Поиск по названию, технологии или специалисту"><select><option>Открыт</option></select><select><option>Ответственные</option></select><select><option>Подразделение</option></select><select><option>Технологии</option></select></UiFilterBar></div>
        <div v-if="requestMode==='tree'" class="request-tree"><div class="request-head request-grid"><div>Запрос</div><div>Технологии</div><div>Кол-во</div><div>Статус</div><div>Активность</div><div>Ответственный</div></div><template v-for="r in requests" :key="r.id"><div class="request-row request-grid"><div><button class="chev" @click="toggle(expandedRequests,r.id)">{{expandedRequests.has(r.id)?'⌄':'›'}}</button><strong>{{r.title}}</strong></div><div>{{r.technology}}</div><div>{{r.positions.reduce((s,p)=>s+p.quantity,0)}}</div><div><UiBadge tone="info">{{r.status}}</UiBadge></div><div>{{r.until}}</div><div>{{r.responsible}}</div></div><template v-if="expandedRequests.has(r.id)" v-for="p in r.positions" :key="p.id"><div class="position-row request-grid"><div class="indent"><button class="chev" @click="toggle(expandedPositions,p.id)">{{p.attempts.length?(expandedPositions.has(p.id)?'⌄':'›'):''}}</button>{{p.title}} <em>{{p.level}}</em></div><div></div><div>{{p.quantity}}</div><div><UiBadge tone="success">{{p.status}}</UiBadge></div><div>{{r.until}}</div><div>{{r.department}}</div></div><div v-if="expandedPositions.has(p.id)" class="attempt-stack"><div v-for="a in p.attempts" :key="a.id" class="attempt-row"><span>{{a.specialist}}</span><UiBadge :tone="a.status.includes('неудача')?'danger':'info'">{{a.status}}</UiBadge></div></div></template></template></div>
        <table v-else class="irlix-data-table"><thead><tr><th>Запрос</th><th>Клиент</th><th>Активен до</th><th>Позиции</th><th>Статус</th><th>Ответственный</th></tr></thead><tbody><tr v-for="r in requests" :key="r.id"><td>{{r.title}}</td><td>{{r.client}}</td><td>{{r.until}}</td><td>{{r.positions.length}}</td><td>{{r.status}}</td><td>{{r.responsible}}</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='positions'">
        <UiFilterBar><input placeholder="Поиск"><select><option>Технологии</option></select><select><option>Направления</option></select><select><option>Клиенты</option></select><select><option>Ответственные</option></select><select><option>Статусы</option></select></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Позиция</th><th>Клиент</th><th>Ответственный</th><th>Направление</th><th>Срок</th><th>Статус</th><th>Рассмотрения</th></tr></thead><tbody><tr v-for="p in allPositions" :key="p.id"><td>{{p.request}} — {{p.title}} {{p.level}}</td><td>{{p.client}}</td><td>{{p.responsible}}</td><td>{{p.department}}</td><td>{{p.until}}</td><td><UiBadge tone="info">{{p.status}}</UiBadge></td><td>{{p.attempts.length}} попыток</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='attempts'">
        <UiFilterBar><select><option>Статусы</option></select><select><option>Специалисты</option></select><select><option>Клиенты</option></select><select><option>Технологии</option></select><select><option>Подразделения</option></select><select><option>Ответственные</option></select></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Специалист</th><th>Позиция</th><th>Клиент</th><th>Ответственный</th><th>Статус</th><th>Контроль</th></tr></thead><tbody><tr v-for="a in allAttempts" :key="a.id"><td>{{a.specialist}}</td><td>{{a.position}}</td><td>{{a.client}}</td><td>{{a.responsible}}</td><td><UiBadge :tone="a.status.includes('неудача')?'danger':'info'">{{a.status}}</UiBadge></td><td>{{a.control}}</td></tr></tbody></table>
        <section class="funnel"><h2>Воронка попыток</h2><div class="funnel-stage" v-for="(s,i) in ['Новая','CV отправлено','Интервью','Ожидает подключения','Закрыта: успех']" :key="s" :style="{width:(100-i*12)+'%'}"><strong>{{s}}</strong><span>{{allAttempts.filter(a=>a.status===s).length}}</span></div><div class="funnel-fail">Закрыта: неудача — {{allAttempts.filter(a=>a.status.includes('неудача')).length}}</div></section>
      </template>

      <template v-else-if="view==='members'">
        <UiFilterBar><input placeholder="Поиск по сотруднику"><select><option>Клиенты</option></select><select><option>Проекты</option></select><select><option>Технологии</option></select></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Сотрудник</th><th>Клиент</th><th>Проект</th><th>Технология / уровень</th><th>Ставка</th><th>Загрузка</th><th>Период</th></tr></thead><tbody><tr v-for="m in cashRows" :key="m.id" @click="selectedMember=m"><td>{{m.name}}</td><td>{{m.client}}</td><td>{{m.project}}</td><td>{{m.technology}} / {{m.level}}</td><td>{{m.rate}}</td><td>{{m.hours}}</td><td>{{m.from}} - {{m.to||'...'}}</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='cashflow'">
        <UiFilterBar><input placeholder="Поиск по сотрудникам"><select><option>Клиенты</option></select><select><option>Сейлзы</option></select><select><option>Аккаунты</option></select><select><option>Подразделения</option></select><select><option>Технологии</option></select><input type="month" value="2026-06"></UiFilterBar><table class="irlix-data-table cash"><thead><tr><th>Сотрудник</th><th>Направление / Технология</th><th>Клиент / Проект</th><th>Загрузка, ч/д</th><th>Период</th><th>Ставка</th><th>Часы: Календарь / ТШ / Подтверждено</th><th>ДС: Календарь / ТШ / Подтверждено</th></tr></thead><tbody><tr v-for="m in cashRows" :key="m.id"><td>{{m.name}}</td><td>{{m.technology}} / {{m.level}}</td><td>{{m.client}}<small>{{m.project}}</small></td><td>{{m.hours}}</td><td>{{m.from}} - {{m.to||'...'}}</td><td>{{m.rate}}</td><td>{{20*m.hours}} / — / —</td><td>{{money(20*m.hours*m.rate)}} / — / —</td></tr></tbody></table><p class="todo">Календарный план в первой версии — демонстрационный. Интеграция с производственным календарём и Vacations подключается на backend; ТШ и подтверждённые значения отмечены как TODO.</p>
      </template>

      <template v-else-if="view==='reports'">
        <UiViewSwitch v-model="reportMode" :items="[{value:'kanban',label:'Канбан'},{value:'gantt',label:'Гант'}]"/>
        <div v-if="reportMode==='kanban'" class="kanban"><section v-for="stage in reportStages" :key="stage"><h3>{{stage}} <span>{{reports.filter(r=>r.stage===stage).length}}</span></h3><article v-for="r in reports.filter(r=>r.stage===stage)" :key="r.client"><strong>{{r.client}}</strong><small>{{r.period}}</small><small>ТШ согласованы: {{r.timesheet}}</small></article></section></div>
        <div v-else class="gantt"><UiFilterBar><input type="month" value="2026-04"><input type="month" value="2026-12"><select><option>Активные клиенты</option></select></UiFilterBar><div class="gantt-grid"><div class="g-head">Клиент</div><div v-for="m in ['Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь']" class="g-head">{{m}}</div><template v-for="r in reports" :key="r.client"><div class="g-client"><strong>{{r.client}}</strong><small>Топорова А.О.</small></div><div v-for="i in 9" :key="i" class="g-cell"><span v-if="i<=5" :class="['period',r.stage.includes('оплачен')?'paid':r.stage.includes('согласован')?'approved':'progress']">01.0{{i}}.2026 - 30.0{{i}}.2026<br>{{r.stage}}</span></div></template></div></div>
      </template>

      <template v-else-if="view==='contacts'">
        <div class="empty-card"><h2>Контактные лица</h2><p>Единый реестр контактов Lead/Client с M2M-привязками. Каркас готов; детальные поля и CRUD подключаются вместе с backend.</p><UiButton>＋ Новый контакт</UiButton></div>
      </template>
    </main>
  </section>

  <UiDrawer :open="!!selectedMember" :title="selectedMember?`${selectedMember.name} (${selectedMember.project||''})`:''" @close="selectedMember=null"><template v-if="selectedMember"><div class="drawer-meta"><b>Ответственный</b><span>{{selectedMember.responsible||'—'}}</span></div><UiTabs :items="['Ставки','Интервью','Фидбеки','Заметки']" model-value="Ставки"/><div class="drawer-actions"><UiButton>↗ Новые условия</UiButton></div><div class="terms-head"><span>Период</span><span>Ставка, руб/ч</span><span>Нагрузка</span><span>Технология</span></div><div v-for="t in selectedMember.terms||[]" :key="t.from" class="term-row"><span>{{t.from}} - {{t.to||'...'}}</span><span>{{t.rate}}</span><span>{{t.hours}}</span><span>{{t.technology}} ({{t.level}})</span></div></template></UiDrawer>

  <UiDrawer :open="!!selectedLead" :title="selectedLead?`Лид ${selectedLead.name}`:''" @close="selectedLead=null"><template v-if="selectedLead"><h2>Информация</h2><div class="info-grid"><b>Название</b><span>{{selectedLead.name}}</span><b>Дата создания</b><span>{{selectedLead.created}}</span><b>Источник</b><span>{{selectedLead.source}}</span><b>Ответственный</b><span>{{selectedLead.responsible}}</span><b>Статус / Итог</b><UiBadge :tone="leadTone(selectedLead.status)">{{selectedLead.status}}</UiBadge></div><UiTabs :items="['Заметки','Контакты']" model-value="Заметки"/><textarea class="note" placeholder="Введите текст заметки"></textarea><UiButton>Отправить</UiButton></template></UiDrawer>

  <UiDrawer :open="!!selectedClientId" :title="clients.find(c=>c.id===selectedClientId)?.name||''" wide @close="selectedClientId=null"><template v-if="selectedClientId"><div class="client-card-head"><div><strong>{{clients.find(c=>c.id===selectedClientId).account}}</strong><small>Аккаунт-менеджер</small></div><div><strong>{{clients.find(c=>c.id===selectedClientId).sales}}</strong><small>Sales-менеджер</small></div></div><UiTabs :items="['О клиенте','Контакты','Проекты','Подключения','Отчётные периоды','Заметки','История']" model-value="Подключения"/><h3>Проекты и участники</h3><div v-for="p in clients.find(c=>c.id===selectedClientId).projects" :key="p.id" class="project-card"><strong>{{p.name}}</strong><div v-for="m in p.members" :key="m.id" class="project-member" @click="selectedMember={...m,project:p.name,client:clients.find(c=>c.id===selectedClientId).name,responsible:clients.find(c=>c.id===selectedClientId).sales}"><span>{{m.name}}</span><span>{{m.technology}} {{m.level}}</span><span>{{m.rate}} ₽/ч</span><span>{{m.hours}} ч/д</span><UiBadge tone="success">{{m.status}}</UiBadge></div></div></template></UiDrawer>
</div>
</template>

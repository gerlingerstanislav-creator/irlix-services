<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { UiAppTopbar, UiAppSidebar } from '@irlix/ui';
import { auth } from './auth';

const navItems = [
  { id: 'dashboard', label: 'Обзор', icon: 'dashboard' },
  { id: 'requests', label: 'Заявки', icon: 'briefcase' },
  { id: 'candidates', label: 'Кандидаты', icon: 'users' },
  { id: 'hiring', label: 'Найм', icon: 'tasks' },
  { id: 'interviews', label: 'Интервью', icon: 'assessment' },
  { id: 'offers', label: 'Офферы', icon: 'document' },
  { id: 'talent-pools', label: 'Talent Pools', icon: 'users' },
  { id: 'employment', label: 'Трудоустройство', icon: 'briefcase' },
  { id: 'onboarding', label: 'Онбординг', icon: 'dashboard' },
  { id: 'tasks', label: 'Задачи', icon: 'tasks' },
  { id: 'analytics', label: 'Аналитика', icon: 'assessment' },
];

const candidates = ref([
  { id: 1, name: 'Иван Петров', role: 'Senior PHP Developer', grade: 'Senior', stack: ['PHP', 'Laravel', 'PostgreSQL'], city: 'Москва', recruiter: 'Анна Смирнова', source: 'HH', stage: 'HR + руководитель', last: 'Сегодня', salary: '280–320 тыс.', pool: 'Готовы быстро выйти' },
  { id: 2, name: 'Мария Орлова', role: 'QA Engineer', grade: 'Middle+', stack: ['Manual QA', 'API', 'SQL'], city: 'Санкт-Петербург', recruiter: 'Ольга Ким', source: 'Рекомендация', stage: 'HR интервью', last: 'Вчера', salary: '190–220 тыс.', pool: 'Сильные QA' },
  { id: 3, name: 'Алексей Волков', role: 'Frontend Developer', grade: 'Middle', stack: ['Vue', 'TypeScript'], city: 'Казань', recruiter: 'Анна Смирнова', source: 'Telegram', stage: 'Контакт', last: '2 дня назад', salary: '200–230 тыс.', pool: null },
  { id: 4, name: 'Дарья Белова', role: 'Project Manager', grade: 'Senior', stack: ['Delivery', 'Agile'], city: 'Удалённо', recruiter: 'Ольга Ким', source: 'База', stage: 'Оффер', last: 'Сегодня', salary: '260–300 тыс.', pool: 'Potential Leads' },
  { id: 5, name: 'Сергей Котов', role: 'DevOps Engineer', grade: 'Senior', stack: ['Kubernetes', 'Linux', 'CI/CD'], city: 'Екатеринбург', recruiter: 'Анна Смирнова', source: 'LinkedIn', stage: 'Новый', last: '4 дня назад', salary: '320–360 тыс.', pool: 'Вернуться позже' },
]);

const requests = ref([
  { id: 101, title: 'Senior PHP Developer', department: 'Backend', manager: 'Александр Морозов', recruiter: 'Анна Смирнова', count: 2, priority: 'Высокий', status: 'В работе', candidates: 14, age: 18 },
  { id: 102, title: 'Middle QA Engineer', department: 'QA', manager: 'Елена Соколова', recruiter: 'Ольга Ким', count: 1, priority: 'Средний', status: 'В работе', candidates: 8, age: 9 },
  { id: 103, title: 'DevOps Engineer', department: 'DevOps', manager: 'Максим Левин', recruiter: 'Анна Смирнова', count: 1, priority: 'Высокий', status: 'Новая', candidates: 3, age: 3 },
]);

const stages = ['Новые', 'Поиск контакта', 'Контакт', 'HR интервью', 'HR + руководитель', 'Решение', 'Оффер'];
const funnel = [
  ['Попытки контакта', 190], ['Контакты', 123], ['HR интервью', 72], ['HR + руководитель', 38], ['Офферы', 16], ['Приняты', 11], ['Трудоустроены', 9],
];
const employment = ref([
  { name: 'Никита Фролов', role: 'Backend Developer', manager: 'Александр Морозов', start: '05.10', stage: 'Согласование', owner: 'HR' },
  { name: 'Анна Павлова', role: 'QA Engineer', manager: 'Елена Соколова', start: '12.10', stage: 'Документы', owner: 'HR' },
  { name: 'Дмитрий Ильин', role: 'DevOps Engineer', manager: 'Максим Левин', start: '19.10', stage: 'Готов к выходу', owner: 'HR' },
]);
const onboardings = ref([
  { name: 'Анна Павлова', role: 'QA Engineer', start: '12.10', progress: 4, total: 7, next: 'Назначить buddy', manager: 'Елена Соколова' },
  { name: 'Дмитрий Ильин', role: 'DevOps Engineer', start: '19.10', progress: 2, total: 7, next: 'Заказать доступы', manager: 'Максим Левин' },
]);
const pools = ref([
  { name: 'Готовы быстро выйти', count: 7, description: 'Проверенные кандидаты с подтверждённой актуальностью и коротким сроком выхода' },
  { name: 'Сильные QA', count: 12, description: 'Кандидаты QA-направления, к которым стоит возвращаться при новых заявках' },
  { name: 'Potential Leads', count: 5, description: 'Сильные кандидаты с руководительским потенциалом' },
  { name: 'Вернуться позже', count: 18, description: 'Хорошие кандидаты, контакт с которыми нужно возобновить позднее' },
]);

const currentPath = ref(window.location.pathname);
const selectedCandidate = ref(null);
const normalize = () => currentPath.value.replace(/^\/recruitment\/?/, '').replace(/\/$/, '');
const route = computed(() => {
  const path = normalize();
  if (!path) return { section: 'dashboard' };
  const detail = path.match(/^candidates\/(\d+)$/);
  if (detail) return { section: 'candidate', id: Number(detail[1]) };
  return { section: navItems.some((item) => item.id === path) ? path : 'dashboard' };
});
const sidebarSection = computed(() => route.value.section === 'candidate' ? 'candidates' : route.value.section);
const navigate = (path) => {
  const target = path ? `/recruitment/${path}` : '/recruitment/';
  if (window.location.pathname !== target) window.history.pushState({}, '', target);
  currentPath.value = window.location.pathname;
  if (route.value.section === 'candidate') selectedCandidate.value = candidates.value.find((c) => c.id === route.value.id) || null;
};
const onPopState = () => { currentPath.value = window.location.pathname; if (route.value.section === 'candidate') selectedCandidate.value = candidates.value.find((c) => c.id === route.value.id) || null; };
const openCandidate = (candidate) => navigate(`candidates/${candidate.id}`);
const initials = (name) => name.split(' ').slice(0, 2).map((part) => part[0]).join('');
const stat = (value, label, hint) => ({ value, label, hint });
const dashboardStats = computed(() => [
  stat(requests.value.filter((r) => r.status !== 'Закрыта').length, 'Активные заявки', '3 высокого приоритета'),
  stat(27, 'Кандидаты в работе', '8 требуют действия'),
  stat(6, 'Интервью на неделе', '2 сегодня'),
  stat(3, 'Активные офферы', '1 ждёт ответа'),
]);

onMounted(() => { window.addEventListener('popstate', onPopState); if (route.value.section === 'candidate') selectedCandidate.value = candidates.value.find((c) => c.id === route.value.id) || null; });
onBeforeUnmount(() => window.removeEventListener('popstate', onPopState));
</script>

<template>
  <div class="recruitment-app">
    <UiAppSidebar :section="sidebarSection" :items="navItems" current-service="recruitment" :current-user="auth.user" :platform-access="() => auth.fetch('/api/employees/access/me')" aria-label="Навигация Recruitment" @update:section="(id) => navigate(id === 'dashboard' ? '' : id)" @logout="auth.logout()" />
    <main class="irlix-service-workspace">
      <UiAppTopbar service="recruitment" :section="sidebarSection" :items="navItems" />
      <div class="content irlix-service-content">
      <template v-if="route.section === 'dashboard'">
        <header class="page-head"><div><div class="eyebrow">Recruitment</div><h1>Обзор найма</h1><p>Операционный центр рекрутера: заявки, воронка, интервью, офферы и трудоустройство.</p></div><button class="primary" @click="navigate('requests')">+ Новая заявка</button></header>
        <section class="metric-grid"><article v-for="item in dashboardStats" :key="item.label" class="metric"><span>{{ item.label }}</span><strong>{{ item.value }}</strong><small>{{ item.hint }}</small></article></section>
        <section class="panel"><div class="panel-head"><div><h2>Воронка найма</h2><p>Текущий период · каждый этап можно будет раскрывать до кандидатов</p></div><button class="ghost" @click="navigate('analytics')">Вся аналитика →</button></div><div class="funnel"><div v-for="([label, value], i) in funnel" :key="label" class="funnel-step"><strong>{{ value }}</strong><span>{{ label }}</span><small v-if="i">{{ Math.round(value / funnel[i-1][1] * 100) }}%</small></div></div></section>
        <section class="dashboard-grid"><article class="panel"><div class="panel-head"><div><h2>Требуют внимания</h2><p>То, что не должно зависнуть сегодня</p></div></div><div class="attention-list"><button><b>5 кандидатов</b><span>без активности больше 3 дней</span><em>Открыть →</em></button><button><b>3 feedback</b><span>ожидаются от руководителей</span><em>Открыть →</em></button><button><b>1 оффер</b><span>ожидает ответа кандидата</span><em>Открыть →</em></button><button><b>2 выхода</b><span>нужно подготовить onboarding</span><em>Открыть →</em></button></div></article><article class="panel"><div class="panel-head"><div><h2>Ближайшие интервью</h2><p>Сегодня и завтра</p></div></div><div class="timeline"><div><time>Сегодня · 14:00</time><b>Иван Петров</b><span>HR + Александр Морозов</span></div><div><time>Сегодня · 16:30</time><b>Мария Орлова</b><span>HR интервью</span></div><div><time>Завтра · 11:00</time><b>Алексей Волков</b><span>HR интервью</span></div></div></article></section>
      </template>

      <template v-else-if="route.section === 'requests'">
        <header class="page-head"><div><div class="eyebrow">Потребность бизнеса</div><h1>Заявки на подбор</h1><p>Раздел доступен Recruitment и руководителям направлений в рамках их scope.</p></div><button class="primary">+ Создать заявку</button></header>
        <section class="table-card"><table><thead><tr><th>Заявка</th><th>Направление</th><th>Руководитель</th><th>Recruiter</th><th>Кандидаты</th><th>Возраст</th><th>Статус</th></tr></thead><tbody><tr v-for="item in requests" :key="item.id"><td><b>{{ item.title }}</b><small>{{ item.count }} позиция(и) · {{ item.priority }} приоритет</small></td><td>{{ item.department }}</td><td>{{ item.manager }}</td><td>{{ item.recruiter }}</td><td>{{ item.candidates }}</td><td>{{ item.age }} дн.</td><td><span class="status">{{ item.status }}</span></td></tr></tbody></table></section>
      </template>

      <template v-else-if="route.section === 'candidates'">
        <header class="page-head"><div><div class="eyebrow">Recruiting CRM</div><h1>Кандидаты</h1><p>Единая база людей независимо от количества вакансий и попыток найма.</p></div><button class="primary">+ Кандидат</button></header>
        <section class="filters"><input placeholder="Поиск по имени, роли, стеку" /><select><option>Все направления</option><option>Backend</option><option>QA</option><option>DevOps</option></select><select><option>Все этапы</option><option v-for="stage in stages" :key="stage">{{ stage }}</option></select><select><option>Все рекрутеры</option><option>Анна Смирнова</option><option>Ольга Ким</option></select></section>
        <section class="table-card"><table><thead><tr><th>Кандидат</th><th>Стек</th><th>Локация</th><th>Последний контакт</th><th>Этап</th><th>Recruiter</th><th></th></tr></thead><tbody><tr v-for="candidate in candidates" :key="candidate.id" @click="openCandidate(candidate)"><td><div class="person-cell"><span class="avatar">{{ initials(candidate.name) }}</span><span><b>{{ candidate.name }}</b><small>{{ candidate.role }} · {{ candidate.grade }}</small></span></div></td><td><div class="chips"><span v-for="skill in candidate.stack" :key="skill">{{ skill }}</span></div></td><td>{{ candidate.city }}</td><td>{{ candidate.last }}</td><td><span class="status">{{ candidate.stage }}</span></td><td>{{ candidate.recruiter }}</td><td class="arrow">→</td></tr></tbody></table></section>
      </template>

      <template v-else-if="route.section === 'candidate'">
        <button class="back" @click="navigate('candidates')">← К кандидатам</button><template v-if="selectedCandidate"><header class="profile-head"><span class="avatar xl">{{ initials(selectedCandidate.name) }}</span><div><div class="eyebrow">Candidate #{{ selectedCandidate.id }}</div><h1>{{ selectedCandidate.name }}</h1><p>{{ selectedCandidate.role }} · {{ selectedCandidate.city }}</p></div><span class="stage-pill">{{ selectedCandidate.stage }}</span></header><section class="profile-grid"><article class="panel"><h2>Профиль</h2><div class="details"><div><span>Grade</span><b>{{ selectedCandidate.grade }}</b></div><div><span>Ожидания</span><b>{{ selectedCandidate.salary }}</b></div><div><span>Источник</span><b>{{ selectedCandidate.source }}</b></div><div><span>Recruiter</span><b>{{ selectedCandidate.recruiter }}</b></div></div><h3>Технологии</h3><div class="chips large"><span v-for="skill in selectedCandidate.stack" :key="skill">{{ skill }}</span></div><h3>Talent Pool</h3><div class="pool-box">{{ selectedCandidate.pool || 'Кандидат пока не входит ни в один пул' }}</div></article><aside class="stack"><article class="panel"><h2>История</h2><div class="timeline"><div><time>Сегодня</time><b>{{ selectedCandidate.stage }}</b><span>Текущий этап процесса</span></div><div><time>2 дня назад</time><b>Контакт с кандидатом</b><span>Telegram · кандидат подтвердил интерес</span></div><div><time>5 дней назад</time><b>Добавлен в Recruitment</b><span>Источник: {{ selectedCandidate.source }}</span></div></div></article><article class="panel"><h2>Следующее действие</h2><p>Назначить ответственное действие, срок и участника процесса.</p><button class="primary full">+ Добавить задачу</button></article></aside></section></template>
      </template>

      <template v-else-if="route.section === 'hiring'">
        <header class="page-head"><div><div class="eyebrow">Pipeline</div><h1>Найм</h1><p>Рабочая доска активных hiring-процессов. Candidate и попытка найма остаются разными сущностями.</p></div></header><section class="kanban"><article v-for="stage in stages" :key="stage" class="kanban-col"><header><b>{{ stage }}</b><span>{{ candidates.filter((c) => c.stage === stage).length }}</span></header><button v-for="candidate in candidates.filter((c) => c.stage === stage)" :key="candidate.id" class="candidate-card" @click="openCandidate(candidate)"><b>{{ candidate.name }}</b><span>{{ candidate.role }}</span><small>{{ candidate.recruiter }} · {{ candidate.last }}</small></button><div v-if="!candidates.some((c) => c.stage === stage)" class="empty-mini">Нет кандидатов</div></article></section>
      </template>

      <template v-else-if="route.section === 'interviews'">
        <header class="page-head"><div><div class="eyebrow">Оценка</div><h1>Интервью</h1><p>Календарь интервью и структурированный feedback от HR и руководителей направлений.</p></div></header><section class="panel"><div class="timeline interviews"><div><time>Сегодня · 14:00</time><b>Иван Петров · Senior PHP Developer</b><span>HR + руководитель · Александр Морозов</span><button>Открыть feedback</button></div><div><time>Сегодня · 16:30</time><b>Мария Орлова · QA Engineer</b><span>HR интервью · Ольга Ким</span><button>Начать интервью</button></div><div><time>Завтра · 11:00</time><b>Алексей Волков · Frontend Developer</b><span>HR интервью · Анна Смирнова</span><button>Карточка кандидата</button></div></div></section>
      </template>

      <template v-else-if="route.section === 'offers'">
        <header class="page-head"><div><div class="eyebrow">Offer management</div><h1>Офферы</h1><p>Подготовка, согласование, отправка и решение кандидата.</p></div></header><section class="offer-grid"><article class="panel offer"><span class="status">Отправлен</span><h2>Дарья Белова</h2><p>Senior Project Manager</p><dl><div><dt>Условия</dt><dd>290 000 ₽</dd></div><div><dt>Выход</dt><dd>12 октября</dd></div><div><dt>Ответ до</dt><dd>29 сентября</dd></div></dl></article><article class="panel offer"><span class="status muted">Согласование</span><h2>Иван Петров</h2><p>Senior PHP Developer</p><dl><div><dt>Условия</dt><dd>310 000 ₽</dd></div><div><dt>Выход</dt><dd>19 октября</dd></div><div><dt>Согласующий</dt><dd>Руководитель</dd></div></dl></article></section>
      </template>

      <template v-else-if="route.section === 'talent-pools'">
        <header class="page-head"><div><div class="eyebrow">Recruiting CRM</div><h1>Talent Pools</h1><p>Повторное использование сильной базы кандидатов без отдельной сущности «спортсмен».</p></div><button class="primary">+ Новый пул</button></header><section class="pool-grid"><article v-for="pool in pools" :key="pool.name" class="panel pool-card"><div class="pool-count">{{ pool.count }}</div><h2>{{ pool.name }}</h2><p>{{ pool.description }}</p><button class="ghost">Открыть кандидатов →</button></article></section>
      </template>

      <template v-else-if="route.section === 'employment'">
        <header class="page-head"><div><div class="eyebrow">После принятого оффера</div><h1>Трудоустройство</h1><p>HR отправляет заявку на трудоустройство, которая проходит отдельный workflow до создания Employee.</p></div><button class="primary">+ Заявка на трудоустройство</button></header><section class="workflow"><span>Заявка</span><i>→</i><span>Проверка</span><i>→</i><span>Согласование</span><i>→</i><span>Документы</span><i>→</i><span>Готов к выходу</span><i>→</i><span>Трудоустроен</span></section><section class="table-card"><table><thead><tr><th>Специалист</th><th>Роль</th><th>Руководитель</th><th>Дата выхода</th><th>Этап</th><th>Ответственный</th></tr></thead><tbody><tr v-for="item in employment" :key="item.name"><td><b>{{ item.name }}</b></td><td>{{ item.role }}</td><td>{{ item.manager }}</td><td>{{ item.start }}</td><td><span class="status">{{ item.stage }}</span></td><td>{{ item.owner }}</td></tr></tbody></table></section>
      </template>

      <template v-else-if="route.section === 'onboarding'">
        <header class="page-head"><div><div class="eyebrow">Ready for work</div><h1>Онбординг</h1><p>Общий контроль подготовки новых сотрудников. Руководители направлений видят onboarding своего дерева.</p></div></header><section class="onboarding-grid"><article v-for="item in onboardings" :key="item.name" class="panel onboarding-card"><div class="panel-head"><div><h2>{{ item.name }}</h2><p>{{ item.role }} · выход {{ item.start }}</p></div><strong>{{ item.progress }}/{{ item.total }}</strong></div><div class="progress"><i :style="{ width: `${item.progress / item.total * 100}%` }"></i></div><div class="details"><div><span>Руководитель</span><b>{{ item.manager }}</b></div><div><span>Следующее действие</span><b>{{ item.next }}</b></div></div><button class="ghost">Открыть checklist →</button></article></section>
      </template>

      <template v-else-if="route.section === 'tasks'">
        <header class="page-head"><div><div class="eyebrow">Daily work</div><h1>Задачи</h1><p>Действия рекрутера и ожидаемые действия участников процесса.</p></div><button class="primary">+ Задача</button></header><section class="dashboard-grid"><article class="panel"><h2>Сегодня</h2><div class="task-list"><label><input type="checkbox" /> Связаться с Сергеем Котовым</label><label><input type="checkbox" /> Получить feedback по Ивану Петрову</label><label><input type="checkbox" /> Уточнить решение по офферу Дарьи Беловой</label></div></article><article class="panel"><h2>Просрочено</h2><div class="task-list danger"><label><input type="checkbox" /> Повторный контакт с кандидатом из Backend pool</label><label><input type="checkbox" /> Согласование заявки Senior PHP Developer</label></div></article></section>
      </template>

      <template v-else-if="route.section === 'analytics'">
        <header class="page-head"><div><div class="eyebrow">Recruitment analytics</div><h1>Аналитика</h1><p>Конверсии, скорость этапов, источники и причины потерь.</p></div></header><section class="metric-grid"><article class="metric"><span>Contact rate</span><strong>64.7%</strong><small>123 контакта из 190 попыток</small></article><article class="metric"><span>Offer acceptance</span><strong>68.8%</strong><small>11 из 16 офферов</small></article><article class="metric"><span>Time to hire</span><strong>31 д.</strong><small>медиана по закрытым наймам</small></article><article class="metric"><span>Time to first contact</span><strong>1.4 д.</strong><small>с момента добавления</small></article></section><section class="dashboard-grid"><article class="panel"><h2>Источники</h2><div class="bars"><div><span>HH</span><i style="width:78%"></i><b>42%</b></div><div><span>Рекомендации</span><i style="width:51%"></i><b>27%</b></div><div><span>Telegram</span><i style="width:32%"></i><b>17%</b></div><div><span>База</span><i style="width:26%"></i><b>14%</b></div></div></article><article class="panel"><h2>Причины отказов</h2><div class="bars"><div><span>Зарплата</span><i style="width:65%"></i><b>31%</b></div><div><span>Hard skills</span><i style="width:44%"></i><b>21%</b></div><div><span>Не вышел на связь</span><i style="width:38%"></i><b>18%</b></div><div><span>Принял другой оффер</span><i style="width:29%"></i><b>14%</b></div></div></article></section>
      </template>
      </div>
    </main>
  </div>
</template>

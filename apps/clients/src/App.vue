<script setup>
import { computed, reactive, ref } from 'vue';

const query = ref('');
const status = ref('open');
const responsible = ref('');
const department = ref('');
const technology = ref('');

const expandedRequests = reactive(new Set([402, 405]));
const expandedPositions = reactive(new Set([4021, 4051]));

const requests = ref([
  {
    id: 402,
    title: 'Заявка №402_CA_Middle',
    logo: 'ЛП',
    technology: 'SA',
    status: 'open',
    activityUntil: '18.08.2026',
    responsible: 'Топорова А.О.',
    department: 'Analytics',
    comments: 0,
    positions: [
      {
        id: 4021,
        title: 'SA',
        level: 'Middle+',
        quantity: 1,
        status: 'open',
        department: 'Analytics',
        comments: 0,
        attempts: [
          { id: 1, specialist: 'Коршиков Виталий', initials: 'КВ', comments: 0, status: 'cv_sent' },
          { id: 2, specialist: 'Фрост А.М.', initials: 'ФА', comments: 0, status: 'cv_sent' },
          { id: 3, specialist: 'Ребрий Виктор', initials: 'РВ', comments: 0, status: 'cv_sent' },
        ],
      },
    ],
  },
  {
    id: 403,
    title: 'Тинек от 5 августа React',
    logo: 'Т',
    technology: 'React',
    status: 'open',
    activityUntil: '19.08.2026',
    responsible: 'Топорова А.О.',
    department: 'Frontend',
    comments: 0,
    positions: [
      { id: 4031, title: 'React', level: 'Senior', quantity: 1, status: 'open', department: 'Frontend', comments: 2, attempts: [] },
    ],
  },
  {
    id: 404,
    title: 'Тинек от 5 августа Go',
    logo: 'Т',
    technology: 'GO',
    status: 'open',
    activityUntil: '19.08.2026',
    responsible: 'Топорова А.О.',
    department: 'Backend',
    comments: 0,
    positions: [
      { id: 4041, title: 'GO', level: 'Senior', quantity: 1, status: 'open', department: 'Backend', comments: 0, attempts: [] },
    ],
  },
  {
    id: 405,
    title: 'Тинек от 5 августа Java',
    logo: 'Т',
    technology: 'Java',
    status: 'open',
    activityUntil: '19.08.2026',
    responsible: 'Топорова А.О.',
    department: 'Backend',
    comments: 0,
    positions: [
      {
        id: 4051,
        title: 'Java',
        level: 'Senior',
        quantity: 1,
        status: 'open',
        department: 'Backend',
        comments: 2,
        attempts: [
          { id: 4, specialist: 'Роман Вороновский', initials: 'РВ', comments: 0, status: 'failed' },
        ],
      },
    ],
  },
]);

const attemptLabel = (value) => ({
  cv_sent: 'CV: отправлено',
  interview: 'Интервью',
  waiting: 'Ожидает подключения',
  success: 'Закрыт: успех',
  failed: 'Закрыт: неудача',
}[value] || value);

const filteredRequests = computed(() => {
  const needle = query.value.trim().toLowerCase();
  return requests.value.filter((request) => {
    const haystack = [request.title, request.technology, request.responsible, request.department,
      ...request.positions.flatMap((position) => [position.title, position.level, ...position.attempts.map((attempt) => attempt.specialist)])]
      .join(' ').toLowerCase();
    return (!needle || haystack.includes(needle))
      && (!status.value || request.status === status.value)
      && (!responsible.value || request.responsible === responsible.value)
      && (!department.value || request.department === department.value)
      && (!technology.value || request.technology === technology.value);
  });
});

const unique = (field) => [...new Set(requests.value.map((item) => item[field]).filter(Boolean))];
const responsibles = computed(() => unique('responsible'));
const departments = computed(() => unique('department'));
const technologies = computed(() => unique('technology'));

const toggleRequest = (id) => expandedRequests.has(id) ? expandedRequests.delete(id) : expandedRequests.add(id);
const togglePosition = (id) => expandedPositions.has(id) ? expandedPositions.delete(id) : expandedPositions.add(id);
</script>

<template>
  <div class="clients-shell">
    <aside class="rail">
      <div class="brand">X</div>
      <div class="rail-chip">ГС</div>
      <nav>
        <button>⌂</button><button>♛</button><button class="active">◎</button><button>◉</button><button>🚀</button><button>▣</button><button>▥</button><button>▤</button>
      </nav>
    </aside>

    <main class="page">
      <header class="page-head">
        <h1>Запросы <span>(Общий экран)</span></h1>
        <button class="new-request">⊕ Новый запрос</button>
      </header>

      <section class="filters">
        <label class="search"><span>⌕</span><input v-model="query" placeholder="Поиск по названию, технологии или специалисту" /></label>
        <select v-model="status"><option value="">Все статусы</option><option value="open">☆ Открыт</option></select>
        <select v-model="responsible"><option value="">Ответственные</option><option v-for="item in responsibles" :key="item">{{ item }}</option></select>
        <select v-model="department"><option value="">Подразделение</option><option v-for="item in departments" :key="item">{{ item }}</option></select>
        <select v-model="technology"><option value="">Технологии</option><option v-for="item in technologies" :key="item">{{ item }}</option></select>
      </section>

      <section class="tree-table">
        <div class="tree-header grid request-grid">
          <div>Запрос</div><div>Технологии</div><div>Кол-во</div><div>Статус</div><div>Активность</div><div>Ответственный</div>
        </div>

        <template v-for="request in filteredRequests" :key="request.id">
          <div class="request-row grid request-grid">
            <div class="entity-cell">
              <button class="chevron" @click="toggleRequest(request.id)">{{ expandedRequests.has(request.id) ? '⌄' : '›' }}</button>
              <span class="client-logo">{{ request.logo }}</span>
              <strong>{{ request.title }}</strong>
              <span class="comments">◌ {{ request.comments }}</span>
            </div>
            <div class="muted">{{ request.technology }}</div>
            <div>{{ request.positions.reduce((sum, item) => sum + item.quantity, 0) }}</div>
            <div><span class="badge badge-blue">◌ Открыт</span></div>
            <div>{{ request.activityUntil }}</div>
            <div class="responsible-cell"><span class="avatar">ТА</span>{{ request.responsible }}</div>
          </div>

          <template v-if="expandedRequests.has(request.id)">
            <template v-for="position in request.positions" :key="position.id">
              <div class="position-row grid request-grid">
                <div class="entity-cell position-indent">
                  <button class="chevron" :disabled="!position.attempts.length" @click="togglePosition(position.id)">{{ position.attempts.length ? (expandedPositions.has(position.id) ? '⌄' : '›') : '' }}</button>
                  <span class="position-name">{{ position.title }} <em>{{ position.level }}</em></span>
                  <span class="comments">◌ {{ position.comments }}</span>
                </div>
                <div></div><div>{{ position.quantity }}</div><div><span class="badge badge-green">◷ Открыта</span></div><div>{{ request.activityUntil }}</div><div>{{ position.department }}</div>
              </div>

              <div v-if="expandedPositions.has(position.id) && position.attempts.length" class="attempt-group">
                <div v-for="attempt in position.attempts" :key="attempt.id" class="attempt-row">
                  <div class="attempt-person"><span class="attempt-avatar">{{ attempt.initials }}</span><span>{{ attempt.specialist }}</span><span class="comments">◌ {{ attempt.comments }}</span></div>
                  <span class="attempt-status" :class="`attempt-${attempt.status}`">{{ attemptLabel(attempt.status) }}</span>
                </div>
              </div>
            </template>
          </template>
        </template>

        <div v-if="!filteredRequests.length" class="empty">Ничего не найдено</div>
      </section>
    </main>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from './auth';

const navItems = [
  { id: 'dashboard', label: 'Дашборд', icon: 'dashboard' },
  { id: 'people', label: 'Специалисты', icon: 'users' },
  { id: 'competencies', label: 'Технологии и компетенции', icon: 'code' },
];

const loading = ref(true);
const saving = ref(false);
const catalogSaving = ref(false);
const error = ref('');
const workspace = ref({ people: [], departments: [], catalog: { technologies: [], competencies: [] } });
const currentPath = ref(window.location.pathname);
const selected = ref(null);
const filters = reactive({ search: '', department: '', grade: '', technology: '', status: 'active' });
const editor = reactive({ grade: '', manager_note: '', technology_ids: [], competencies: [] });
const technologyEditor = reactive({ id: null, name: '', alias: '', category: '', parent_id: '' });
const technologyModalOpen = ref(false);
const expandedTechnologies = ref(new Set());

const normalizeRoute = () => currentPath.value.replace(/^\/specialists\/?/, '').replace(/\/$/, '');
const route = computed(() => {
  const path = normalizeRoute();
  if (!path) return { section: 'dashboard', employeeId: null };
  const detail = path.match(/^people\/(\d+)$/);
  if (detail) return { section: 'person', employeeId: Number(detail[1]) };
  if (path === 'people') return { section: 'people', employeeId: null };
  if (path === 'competencies') return { section: 'competencies', employeeId: null };
  return { section: 'dashboard', employeeId: null };
});
const sidebarSection = computed(() => route.value.section === 'person' ? 'people' : route.value.section);

const navigate = (path) => {
  const target = path ? `/specialists/${path}` : '/specialists/';
  if (window.location.pathname !== target) window.history.pushState({}, '', target);
  currentPath.value = window.location.pathname;
};
const onPopState = () => { currentPath.value = window.location.pathname; };

const api = async (path, options = {}) => {
  const response = await fetch(`/api/specialists${path}`, {
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    ...options,
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || `Ошибка ${response.status}`);
  return payload.data;
};

const loadWorkspace = async () => {
  loading.value = true;
  error.value = '';
  try {
    workspace.value = await api('/workspace');
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
};

const refreshCatalog = async () => {
  const catalog = await api('/catalog');
  workspace.value = { ...workspace.value, catalog };
};

const loadPerson = async (id) => {
  error.value = '';
  try {
    selected.value = await api(`/people/${id}`);
    const professional = selected.value.professional || {};
    editor.grade = professional.grade || '';
    editor.manager_note = professional.manager_note || '';
    editor.technology_ids = (professional.technologies || []).map((item) => Number(item.id));
    editor.competencies = (professional.competencies || []).map((item) => ({ id: Number(item.id), level: item.level ?? null, comment: item.comment || '' }));
  } catch (e) {
    error.value = e.message;
    selected.value = null;
  }
};

const savePerson = async () => {
  if (!selected.value?.employee?.id) return;
  saving.value = true;
  error.value = '';
  try {
    selected.value = await api(`/people/${selected.value.employee.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        grade: editor.grade || null,
        manager_note: editor.manager_note || null,
        technology_ids: editor.technology_ids,
        competencies: editor.competencies,
      }),
    });
    await loadWorkspace();
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
  }
};

const activePeople = computed(() => workspace.value.people.filter((p) => p.employment_status !== 'Уволен'));
const dismissedPeople = computed(() => workspace.value.people.filter((p) => p.employment_status === 'Уволен'));
const gradeStats = computed(() => {
  const map = new Map();
  activePeople.value.forEach((p) => map.set(p.grade || 'Не указан', (map.get(p.grade || 'Не указан') || 0) + 1));
  return [...map.entries()].sort((a, b) => b[1] - a[1]);
});
const techStats = computed(() => {
  const map = new Map();
  activePeople.value.forEach((p) => (p.technologies || []).forEach((t) => map.set(t.name, (map.get(t.name) || 0) + 1)));
  return [...map.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8);
});
const incompleteCount = computed(() => activePeople.value.filter((p) => !p.grade || !(p.technologies || []).length).length);
const grades = computed(() => [...new Set(workspace.value.people.map((p) => p.grade).filter(Boolean))].sort());
const filteredPeople = computed(() => {
  const q = filters.search.trim().toLowerCase();
  return workspace.value.people.filter((p) => {
    if (filters.status === 'active' && p.employment_status === 'Уволен') return false;
    if (filters.status === 'dismissed' && p.employment_status !== 'Уволен') return false;
    if (filters.department && String(p.department_id) !== filters.department) return false;
    if (filters.grade && p.grade !== filters.grade) return false;
    if (filters.technology && !(p.technologies || []).some((t) => String(t.id) === filters.technology)) return false;
    if (q && !`${p.full_name || ''} ${p.position || ''} ${p.department_name || ''}`.toLowerCase().includes(q)) return false;
    return true;
  });
});

const technologyTree = computed(() => {
  const technologies = workspace.value.catalog.technologies || [];
  const children = new Map();
  technologies.forEach((technology) => {
    const parentId = technology.parent_id ? Number(technology.parent_id) : null;
    if (!children.has(parentId)) children.set(parentId, []);
    children.get(parentId).push(technology);
  });
  children.forEach((items) => items.sort((a, b) => a.name.localeCompare(b.name, 'ru')));

  const rows = [];
  const walk = (parentId, depth) => {
    (children.get(parentId) || []).forEach((technology) => {
      const id = Number(technology.id);
      const hasChildren = (children.get(id) || []).length > 0;
      rows.push({ ...technology, depth, hasChildren });
      if (hasChildren && expandedTechnologies.value.has(id)) walk(id, depth + 1);
    });
  };
  walk(null, 0);
  return rows;
});

const technologyParentOptions = computed(() => {
  const currentId = Number(technologyEditor.id || 0);
  const excluded = new Set();
  const children = new Map();
  (workspace.value.catalog.technologies || []).forEach((technology) => {
    const parentId = technology.parent_id ? Number(technology.parent_id) : null;
    if (!children.has(parentId)) children.set(parentId, []);
    children.get(parentId).push(Number(technology.id));
  });
  const mark = (id) => {
    excluded.add(id);
    (children.get(id) || []).forEach(mark);
  };
  if (currentId) mark(currentId);
  return (workspace.value.catalog.technologies || []).filter((technology) => !excluded.has(Number(technology.id)));
});

const toggleTechnologyRow = (id) => {
  const next = new Set(expandedTechnologies.value);
  id = Number(id);
  if (next.has(id)) next.delete(id); else next.add(id);
  expandedTechnologies.value = next;
};

const resetTechnologyEditor = () => {
  technologyEditor.id = null;
  technologyEditor.name = '';
  technologyEditor.alias = '';
  technologyEditor.category = '';
  technologyEditor.parent_id = '';
};
const openCreateTechnology = (parent = null) => {
  resetTechnologyEditor();
  if (parent?.id) technologyEditor.parent_id = String(parent.id);
  technologyModalOpen.value = true;
};
const openEditTechnology = (technology) => {
  technologyEditor.id = Number(technology.id);
  technologyEditor.name = technology.name || '';
  technologyEditor.alias = technology.alias || '';
  technologyEditor.category = technology.category || '';
  technologyEditor.parent_id = technology.parent_id ? String(technology.parent_id) : '';
  technologyModalOpen.value = true;
};
const closeTechnologyModal = () => {
  if (catalogSaving.value) return;
  technologyModalOpen.value = false;
  resetTechnologyEditor();
};
const saveTechnology = async () => {
  if (!technologyEditor.name.trim()) return;
  catalogSaving.value = true;
  error.value = '';
  try {
    const payload = {
      name: technologyEditor.name.trim(),
      alias: technologyEditor.alias.trim() || null,
      category: technologyEditor.category.trim() || null,
      parent_id: technologyEditor.parent_id ? Number(technologyEditor.parent_id) : null,
    };
    if (technologyEditor.id) {
      await api(`/catalog/technologies/${technologyEditor.id}`, { method: 'PUT', body: JSON.stringify(payload) });
    } else {
      await api('/catalog/technologies', { method: 'POST', body: JSON.stringify(payload) });
    }
    await refreshCatalog();
    technologyModalOpen.value = false;
    resetTechnologyEditor();
  } catch (e) {
    error.value = e.message;
  } finally {
    catalogSaving.value = false;
  }
};
const deleteTechnology = async (technology) => {
  if (!window.confirm(`Удалить технологию «${technology.name}»?`)) return;
  catalogSaving.value = true;
  error.value = '';
  try {
    await api(`/catalog/technologies/${technology.id}`, { method: 'DELETE' });
    await refreshCatalog();
  } catch (e) {
    error.value = e.message;
  } finally {
    catalogSaving.value = false;
  }
};

const initials = (name = '') => name.split(/\s+/).filter(Boolean).slice(0, 2).map((x) => x[0]).join('').toUpperCase();
const toggleTechnology = (id) => {
  id = Number(id);
  editor.technology_ids = editor.technology_ids.includes(id)
    ? editor.technology_ids.filter((value) => value !== id)
    : [...editor.technology_ids, id];
};

onMounted(async () => {
  window.addEventListener('popstate', onPopState);
  await loadWorkspace();
  const parents = (workspace.value.catalog.technologies || []).filter((t) => (workspace.value.catalog.technologies || []).some((c) => Number(c.parent_id) === Number(t.id)));
  expandedTechnologies.value = new Set(parents.map((t) => Number(t.id)));
  if (route.value.employeeId) await loadPerson(route.value.employeeId);
});
onBeforeUnmount(() => window.removeEventListener('popstate', onPopState));

const openPerson = async (id) => {
  navigate(`people/${id}`);
  await loadPerson(id);
};
</script>

<template>
  <div class="specialists-app">
    <UiAppSidebar
      :section="sidebarSection"
      :items="navItems"
      current-service="specialists"
      :current-user="auth.user"
      aria-label="Навигация сервиса специалистов"
      @update:section="(id) => navigate(id === 'dashboard' ? '' : id)"
      @logout="auth.logout()"
    />

    <main class="content">
      <div v-if="error" class="alert">{{ error }}</div>
      <div v-if="loading" class="loading">Загрузка данных направления…</div>

      <template v-else-if="route.section === 'dashboard'">
        <header class="page-head">
          <div><div class="eyebrow">Specialists</div><h1>Дашборд направления</h1><p>Профессиональный профиль команды и состояние компетенций.</p></div>
          <div class="scope-pill">{{ workspace.scope === 'all' ? 'Все направления' : 'Моё направление и дочерние' }}</div>
        </header>
        <section class="metric-grid">
          <article class="metric"><span>Работают</span><strong>{{ activePeople.length }}</strong><small>специалистов в доступном дереве</small></article>
          <article class="metric"><span>Уволены</span><strong>{{ dismissedPeople.length }}</strong><small>профили сохранены</small></article>
          <article class="metric"><span>Неполные профили</span><strong>{{ incompleteCount }}</strong><small>нет грейда или технологий</small></article>
          <article class="metric"><span>Технологии</span><strong>{{ workspace.catalog.technologies.length }}</strong><small>в корпоративном каталоге</small></article>
        </section>
        <section class="dashboard-grid">
          <article class="panel"><div class="panel-head"><div><h2>Грейды</h2><p>Распределение активной команды</p></div></div><div v-if="gradeStats.length" class="stat-list"><div v-for="([name, count]) in gradeStats" :key="name" class="stat-row"><span>{{ name }}</span><div class="bar"><i :style="{ width: `${Math.max(8, count / Math.max(activePeople.length, 1) * 100)}%` }"></i></div><strong>{{ count }}</strong></div></div><div v-else class="empty">Пока нет данных по грейдам.</div></article>
          <article class="panel"><div class="panel-head"><div><h2>Технологии команды</h2><p>Наиболее представленные навыки</p></div></div><div v-if="techStats.length" class="chips large"><span v-for="([name, count]) in techStats" :key="name">{{ name }} <b>{{ count }}</b></span></div><div v-else class="empty">Технологии ещё не заполнены.</div></article>
        </section>
        <section class="panel attention-panel"><div class="panel-head"><div><h2>Требуют внимания</h2><p>Профили, которые стоит заполнить в первую очередь</p></div><button class="ghost" @click="navigate('people')">Все специалисты →</button></div><div class="people-mini"><button v-for="person in activePeople.filter((p) => !p.grade || !(p.technologies || []).length).slice(0, 6)" :key="person.id" @click="openPerson(person.id)"><span class="avatar">{{ initials(person.full_name) }}</span><span><b>{{ person.full_name }}</b><small>{{ person.department_name }} · {{ person.position || 'Должность не указана' }}</small></span><em>Заполнить</em></button><div v-if="!incompleteCount" class="empty">У всех активных специалистов заполнены базовые профессиональные данные.</div></div></section>
      </template>

      <template v-else-if="route.section === 'people'">
        <header class="page-head"><div><div class="eyebrow">Команда</div><h1>Специалисты</h1><p>Все сотрудники доступного вам дерева направлений, включая уволенных.</p></div></header>
        <section class="filters"><input v-model="filters.search" placeholder="Поиск по имени, должности или направлению" /><select v-model="filters.department"><option value="">Все направления</option><option v-for="d in workspace.departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option></select><select v-model="filters.grade"><option value="">Все грейды</option><option v-for="grade in grades" :key="grade">{{ grade }}</option></select><select v-model="filters.technology"><option value="">Все технологии</option><option v-for="t in workspace.catalog.technologies" :key="t.id" :value="String(t.id)">{{ t.name }}</option></select><select v-model="filters.status"><option value="active">Работают</option><option value="dismissed">Уволены</option><option value="all">Все</option></select></section>
        <section class="table-card"><table><thead><tr><th>Специалист</th><th>Направление</th><th>Грейд</th><th>Технологии</th><th>Статус</th><th></th></tr></thead><tbody><tr v-for="person in filteredPeople" :key="person.id" :class="{ dismissed: person.employment_status === 'Уволен' }" @click="openPerson(person.id)"><td><div class="person-cell"><span class="avatar">{{ initials(person.full_name) }}</span><span><b>{{ person.full_name }}</b><small>{{ person.position || 'Должность не указана' }}</small></span></div></td><td>{{ person.department_name || '—' }}</td><td><span class="grade">{{ person.grade || 'Не указан' }}</span></td><td><div class="chips"><span v-for="t in (person.technologies || []).slice(0, 3)" :key="t.id">{{ t.name }}</span><span v-if="(person.technologies || []).length > 3">+{{ person.technologies.length - 3 }}</span><em v-if="!(person.technologies || []).length">Не заполнено</em></div></td><td><span :class="['status', person.employment_status === 'Уволен' ? 'off' : 'on']">{{ person.employment_status === 'Уволен' ? 'Уволен' : 'Работает' }}</span></td><td class="arrow">→</td></tr></tbody></table><div v-if="!filteredPeople.length" class="empty padded">По заданным фильтрам специалистов нет.</div></section>
      </template>

      <template v-else-if="route.section === 'person'">
        <button class="back" @click="navigate('people')">← К специалистам</button>
        <div v-if="!selected" class="loading">Загрузка профиля…</div>
        <template v-else>
          <header class="profile-head"><span class="avatar xl">{{ initials(selected.employee.full_name) }}</span><div><div class="profile-title"><h1>{{ selected.employee.full_name }}</h1><span v-if="selected.employee.employment_status === 'Уволен'" class="status off">Уволен</span></div><p>{{ selected.employee.position || 'Должность не указана' }} · {{ selected.employee.department_name || 'Направление не указано' }}</p></div></header>
          <section class="profile-grid"><article class="panel form-panel"><h2>Профессиональный профиль</h2><label>Грейд<input v-model="editor.grade" placeholder="Например: Middle" /></label><label>Комментарий руководителя<textarea v-model="editor.manager_note" rows="5" placeholder="Краткий контекст по развитию специалиста"></textarea></label><h3>Технологии</h3><div v-if="workspace.catalog.technologies.length" class="choice-grid"><button v-for="t in workspace.catalog.technologies" :key="t.id" :class="{ selected: editor.technology_ids.includes(Number(t.id)) }" @click="toggleTechnology(t.id)">{{ t.name }}</button></div><div v-else class="empty">Каталог технологий пока пуст.</div><div class="actions"><button class="primary" :disabled="saving" @click="savePerson">{{ saving ? 'Сохраняем…' : 'Сохранить профиль' }}</button></div></article><aside class="stack"><article class="panel"><h2>Компетенции</h2><div v-if="selected.professional.competencies?.length" class="competency-list"><div v-for="c in selected.professional.competencies" :key="c.id"><span><b>{{ c.name }}</b><small>{{ c.technology_name || 'Общая компетенция' }}</small></span><strong>{{ c.level ?? '—' }}</strong></div></div><div v-else class="empty">Компетенции пока не оценены.</div></article><article class="panel future"><h2>Следующие этапы</h2><p>Assessments, обучение и Performance Review будут добавлены следующими итерациями.</p></article></aside></section>
        </template>
      </template>

      <template v-else-if="route.section === 'competencies'">
        <header class="page-head"><div><div class="eyebrow">Source of truth</div><h1>Технологии и компетенции</h1><p>Корпоративный каталог профессиональных знаний.</p></div></header>
        <section class="technology-panel panel">
          <div class="panel-head"><div><h2>Технологии</h2><p>{{ workspace.catalog.technologies.length }} записей · поддерживается вложенность</p></div><button class="primary" @click="openCreateTechnology()">+ Добавить технологию</button></div>
          <div v-if="technologyTree.length" class="technology-table">
            <div class="technology-table-head"><span>Название</span><span>Алиас</span><span>Направление</span><span></span></div>
            <div v-for="technology in technologyTree" :key="technology.id" class="technology-row">
              <div class="technology-name" :style="{ paddingLeft: `${technology.depth * 28}px` }">
                <button v-if="technology.hasChildren" class="tree-toggle" :aria-label="expandedTechnologies.has(Number(technology.id)) ? 'Свернуть' : 'Развернуть'" @click="toggleTechnologyRow(technology.id)">{{ expandedTechnologies.has(Number(technology.id)) ? '⌄' : '›' }}</button>
                <span v-else class="tree-spacer"></span>
                <b>{{ technology.name }}</b>
              </div>
              <span>{{ technology.alias || '—' }}</span>
              <span>{{ technology.category || '—' }}</span>
              <div class="row-actions">
                <button title="Добавить дочернюю технологию" @click="openCreateTechnology(technology)">＋</button>
                <button title="Редактировать" @click="openEditTechnology(technology)">✎</button>
                <button class="danger-icon" title="Удалить" :disabled="catalogSaving" @click="deleteTechnology(technology)">×</button>
              </div>
            </div>
          </div>
          <div v-else class="empty">Каталог пока пуст.</div>
        </section>
        <section class="panel competencies-panel"><div class="panel-head"><div><h2>Компетенции</h2><p>{{ workspace.catalog.competencies.length }} записей</p></div></div><div v-if="workspace.catalog.competencies.length" class="catalog-list"><div v-for="c in workspace.catalog.competencies" :key="c.id"><b>{{ c.name }}</b><span>{{ c.technology_name || 'Общая' }}</span></div></div><div v-else class="empty">Компетенции пока не заведены.</div></section>
      </template>
    </main>

    <div v-if="technologyModalOpen" class="modal-backdrop" @click.self="closeTechnologyModal">
      <form class="modal-card" @submit.prevent="saveTechnology">
        <div class="modal-head"><div><div class="eyebrow">Каталог</div><h2>{{ technologyEditor.id ? 'Редактировать технологию' : 'Новая технология' }}</h2></div><button type="button" class="modal-close" @click="closeTechnologyModal">×</button></div>
        <label>Название<input v-model="technologyEditor.name" maxlength="255" required placeholder="Например, React Native" /></label>
        <label>Алиас<input v-model="technologyEditor.alias" maxlength="255" placeholder="Например, RN" /></label>
        <label>Направление<input v-model="technologyEditor.category" maxlength="255" placeholder="Например, Mobile" /></label>
        <label>Родительская технология<select v-model="technologyEditor.parent_id"><option value="">Без родителя</option><option v-for="technology in technologyParentOptions" :key="technology.id" :value="String(technology.id)">{{ technology.name }}</option></select></label>
        <div class="modal-actions"><button type="button" class="secondary" :disabled="catalogSaving" @click="closeTechnologyModal">Отмена</button><button class="primary" :disabled="catalogSaving || !technologyEditor.name.trim()">{{ catalogSaving ? 'Сохраняем…' : 'Сохранить' }}</button></div>
      </form>
    </div>
  </div>
</template>

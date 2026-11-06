<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { UiAppTopbar, UiAppSidebar, UiRegistryTable, UiFilterBar, UiSearchSelect, UiTreeToggle, UiErrorState, UiLoader, UiDrawer, UiButton, UiDepartmentTreeSelect, UiTechnologyGrade, UiToast, employeesWithinDepartments, normalizeDepartmentSelection } from '@irlix/ui';
import { auth } from './auth';
import SpecialistsRolePermissionsView from './SpecialistsRolePermissionsView.vue';
import SpecialistProfileBody from './SpecialistProfileBody.vue';
import { accessibleSpecialistsNavigation, specialistsLandingPath } from './accessNavigation.js';

const roleMatrixAdmin=ref(false);
const roleAccessPhase=ref('loading');
const permissionsPhase=ref('loading');
const effectivePermissions=ref(null);
const canViewProfiles=computed(()=>effectivePermissions.value?.profiles_view === true);
const canManageProfiles=computed(()=>effectivePermissions.value?.profiles_manage === true);
const canViewCatalog=computed(()=>effectivePermissions.value?.catalog_view === true);
const canEditTechnologyCatalog=computed(()=>effectivePermissions.value?.catalog_manage === true);
const navItems=computed(()=>accessibleSpecialistsNavigation(effectivePermissions.value, roleMatrixAdmin.value));
const mainNavItems=computed(()=>navItems.value.filter(item=>item.id!=='role-permissions'));
const bottomNavItems=computed(()=>navItems.value.filter(item=>item.id==='role-permissions'));
// One employee-scoped tree selection is shared by all Specialists people views.
const selectedDepartments = ref([]);
const canFilterDepartments = computed(() => canViewProfiles.value && (
  ['people','person'].includes(route.value.section) || workspace.value.scope === 'all'
  || (workspace.value.managed_department_ids || []).length > 1
));
const scopedPeople = computed(() => employeesWithinDepartments(
  workspace.value.people, workspace.value.departments, selectedDepartments.value));
const departmentStorageKey = () => 'irlix.specialists.departments.v2.' + (workspace.value.actor?.id || 'unknown');
const rememberDepartments = values => {
  selectedDepartments.value = normalizeDepartmentSelection(workspace.value.departments, values);
  try { localStorage.setItem(departmentStorageKey(), JSON.stringify(selectedDepartments.value)); } catch (_) {}
};
const restoreDepartments = () => {
  let stored = [];
  try {
    const v2 = localStorage.getItem(departmentStorageKey());
    const older = localStorage.getItem('irlix.specialists.dashboard.departments.v1.' + (workspace.value.actor?.id || 'unknown'));
    stored = JSON.parse(v2 ?? older ?? '[]');
  } catch (_) {}
  selectedDepartments.value = normalizeDepartmentSelection(workspace.value.departments, stored);
};
const dashboardPeople = computed(() => scopedPeople.value);

const loading = ref(true);
const saving = ref(false);
const catalogSaving = ref(false);
const error = ref('');
const workspace = ref({ people: [], departments: [], catalog: { technologies: [], additional_technologies: [], competencies: [] } });
const activeRoute = useRoute();
const router = useRouter();
const selected = ref(null);
const profileErrorStatus = ref(null);
const projectAssignments=ref([]);
const projectLoading=ref(false);
const projectError=ref('');
const filters = reactive({ search: '', grade: '', technology: '', status: 'active' });
const technologyEditor = reactive({ id: null, name: '', alias: '', category: '', parent_id: '' });
const technologyModalOpen = ref(false);
const editingAdditionalId = ref(null);
const editingAdditionalName = ref('');
const additionalRenameError = ref('');
const additionalRenameInput = ref(null);
const deletingAdditional = ref(null);
const replacementAdditional = ref('');
const additionalDeleteError = ref('');
const catalogToast = ref(null);
let catalogToastSequence = 0;
const expandedTechnologies = ref(new Set());

const route = computed(() => ({
  section: activeRoute.meta.section || 'not-found',
  employeeId: Number(activeRoute.params.employeeId) || null,
}));
const sidebarSection = computed(() => route.value.section === 'person' ? 'people' : route.value.section);
const navigate = path => router.push({ path: path ? '/' + path : '/' });

const api = async (path, options = {}) => {
  const response = await fetch(`/api/specialists${path}`, {
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    ...options,
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) { const failure = new Error(payload.message || `Ошибка ${response.status}`); failure.status = response.status; throw failure; }
  return payload.data;
};

const loadRoleAccess = async () => {
  roleAccessPhase.value='loading';
  try {
    roleMatrixAdmin.value=!!(await api('/role-permissions'))?.can_manage;
    roleAccessPhase.value=roleMatrixAdmin.value?'allowed':'forbidden';
  } catch(e) {
    roleMatrixAdmin.value=false;
    roleAccessPhase.value=e.status===403?'forbidden':'error';
  }
};

const loadWorkspace = async () => {
  loading.value = true;
  error.value = '';
  try {
    if (canViewProfiles.value) {
      workspace.value = await api('/workspace');
      restoreDepartments();
    } else if (canViewCatalog.value) {
      // A catalog-only role must not call the protected profiles workspace.
      // Specialists owns the catalog; access is checked by the backend.
      const catalog = await api('/catalog');
      workspace.value = { ...workspace.value, catalog };
    }
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

const loadProjects = async (id) => {
  projectAssignments.value=[];
  projectError.value='';
  projectLoading.value=true;
  try {
    const response=await fetch('/api/clients/specialists/people/'+id+'/projects',{headers:{Accept:'application/json'}});
    const body=await response.json().catch(()=>({}));
    if (!response.ok) throw new Error(body.message || 'Projects unavailable');
    if(route.value.employeeId===id) projectAssignments.value=Array.isArray(body.data)?body.data:[];
  } catch (_) {
    if(route.value.employeeId===id) projectError.value='Не удалось загрузить проекты.';
  } finally {
    if(route.value.employeeId===id) projectLoading.value=false;
  }
};
const loadPerson = async (id) => {
  selected.value=null;
  projectAssignments.value=[];
  projectError.value='';
  profileErrorStatus.value=null;
  error.value='';
  try {
    const result=await api(`/people/${id}`);
    if (route.value.employeeId!==id) return;
    selected.value=result;
    loadProjects(id);
  } catch (e) {
    if(route.value.employeeId!==id)return;
    error.value = e.message;
    profileErrorStatus.value = [403,404].includes(e.status) ? e.status : null;
    selected.value = null;
  }
};

const activePeople = computed(() => dashboardPeople.value.filter((p) => p.employment_status !== 'Уволен'));
const dismissedPeople = computed(() => dashboardPeople.value.filter((p) => p.employment_status === 'Уволен'));
const gradeStats = computed(() => {
  const map = new Map();
  activePeople.value.forEach((p) => map.set(p.grade || 'Не указан', (map.get(p.grade || 'Не указан') || 0) + 1));
  return [...map.entries()].sort((a, b) => b[1] - a[1]);
});
// Registry, technology filter and team analytics all use MAIN technology
// assignments; the separate additional catalog never enters these views.
const mainTechnologies = person => (person.technologies || []).filter(t => t.kind === 'primary');
const techStats = computed(() => {
  const map = new Map();
  activePeople.value.forEach((p) => mainTechnologies(p).forEach((t) => map.set(t.name, (map.get(t.name) || 0) + 1)));
  return [...map.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8);
});
const incompleteCount = computed(() => activePeople.value.filter((p) => !p.grade || !(p.technologies || []).length).length);
const grades = computed(() => [...new Set(workspace.value.people.map((p) => p.grade).filter(Boolean))].sort());
const gradeOptions = computed(() => grades.value.map(grade => ({ value: grade, label: grade })));
const technologyOptions = computed(() => (workspace.value.catalog.technologies || []).map(t => ({ value: String(t.id), label: t.name })));
const statusOptions = [
  { value: 'active', label: 'Работают' },
  { value: 'dismissed', label: 'Уволены' },
  { value: 'all', label: 'Все статусы' },
];
const filteredPeople = computed(() => {
  const q = filters.search.trim().toLowerCase();
  return scopedPeople.value.filter((p) => {
    if (filters.status === 'active' && p.employment_status === 'Уволен') return false;
    if (filters.status === 'dismissed' && p.employment_status !== 'Уволен') return false;
    if (filters.grade && p.grade !== filters.grade) return false;
    if (filters.technology && !mainTechnologies(p).some((t) => String(t.id) === filters.technology)) return false;
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
    if (route.value.section === 'technologies') {
      await api('/catalog/additional-technologies/quick', {
        method: 'POST', body: JSON.stringify({ name: technologyEditor.name.trim() }),
      });
      await refreshCatalog();
      technologyModalOpen.value = false;
      resetTechnologyEditor();
      return;
    }
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
const reloadAccess = async () => {
  loading.value = true;
  permissionsPhase.value = 'loading';
  try {
    effectivePermissions.value = await api('/permissions');
    permissionsPhase.value = 'allowed';
  } catch (e) {
    effectivePermissions.value = null;
    permissionsPhase.value = e.status === 403 ? 'forbidden' : 'error';
  }
  await loadRoleAccess();
  if (permissionsPhase.value === 'allowed') {
    await loadWorkspace();
    const landing = specialistsLandingPath(effectivePermissions.value);
    if (landing && landing !== '/' && route.value.section === 'dashboard') {
      await router.replace({ path: landing });
    }
  } else loading.value = false;
  const technologies = workspace.value.catalog.technologies || [];
  const parents = technologies.filter(t => technologies.some(c => Number(c.parent_id) === Number(t.id)));
  expandedTechnologies.value = new Set(parents.map(t => Number(t.id)));
  if (canViewProfiles.value && route.value.employeeId) await loadPerson(route.value.employeeId);
};
onMounted(reloadAccess);
watch(() => route.value.employeeId, id => {
  if (id && canViewProfiles.value && !loading.value) loadPerson(id);
  if (!id) { selected.value=null; projectAssignments.value=[]; }
});

const openPerson = id => navigate(`people/${id}`);
const closePerson=()=>navigate('people');
const sortedAdditionalTechnologies = computed(() => [...(workspace.value.catalog?.additional_technologies || [])].sort((a,b) => a.name.localeCompare(b.name, 'ru', { sensitivity: 'base' })));
const additionalReplacementOptions = computed(() => sortedAdditionalTechnologies.value
  .filter(technology => Number(technology.id) !== Number(deletingAdditional.value?.id))
  .map(technology => ({ value: String(technology.id), label: technology.name })));

const startAdditionalRename = async technology => {
  if (!canEditTechnologyCatalog.value || catalogSaving.value || deletingAdditional.value) return;
  editingAdditionalId.value = Number(technology.id);
  editingAdditionalName.value = technology.name;
  additionalRenameError.value = '';
  await nextTick();
  // The only active field is inside a v-for; Vue exposes it as an array ref.
  const input = Array.isArray(additionalRenameInput.value)
    ? additionalRenameInput.value[0] : additionalRenameInput.value;
  input?.focus();
  input?.select();
};
const cancelAdditionalRename = () => {
  if (catalogSaving.value) return;
  editingAdditionalId.value = null;
  editingAdditionalName.value = '';
  additionalRenameError.value = '';
};
const saveAdditionalRename = async () => {
  if (editingAdditionalId.value === null || !canEditTechnologyCatalog.value || catalogSaving.value) return;
  const technology = sortedAdditionalTechnologies.value.find(t => Number(t.id) === editingAdditionalId.value);
  if (!technology) {
    additionalRenameError.value = 'Технология больше не доступна в каталоге.';
    return;
  }
  const name = editingAdditionalName.value.trim();
  if (!name) {
    additionalRenameError.value = 'Введите название технологии.';
    return;
  }
  if (name === technology.name) {
    cancelAdditionalRename();
    return;
  }
  additionalRenameError.value = '';
  catalogSaving.value = true;
  try {
    const updated = await api(`/catalog/additional-technologies/${technology.id}`, {
      method: 'PUT', body: JSON.stringify({ name }),
    });
    // Render the successful rename immediately even if the separate catalog
    // refresh temporarily fails. The association ID stays unchanged.
    workspace.value = {
      ...workspace.value,
      catalog: {
        ...workspace.value.catalog,
        additional_technologies: (workspace.value.catalog.additional_technologies || [])
          .map(t => Number(t.id) === Number(updated.id) ? { ...t, name: updated.name } : t),
      },
    };
    editingAdditionalId.value = null;
    editingAdditionalName.value = '';
    catalogToast.value = {
      id: ++catalogToastSequence, tone: 'success',
      message: `Технология «${technology.name}» переименована в «${updated.name}».`,
    };
    try {
      await refreshCatalog();
    } catch (refreshError) {
      console.warn('Specialists catalog refresh failed after rename', refreshError);
    }
  } catch (error) {
    additionalRenameError.value = error.message || 'Не удалось переименовать технологию.';
  } finally {
    catalogSaving.value = false;
  }
};
const openAdditionalDeletion = technology => {
  if (!canEditTechnologyCatalog.value || catalogSaving.value) return;
  deletingAdditional.value = technology;
  replacementAdditional.value = '';
  additionalDeleteError.value = '';
};
const closeAdditionalDeletion = () => {
  if (catalogSaving.value) return;
  deletingAdditional.value = null;
  additionalDeleteError.value = '';
  replacementAdditional.value = '';
};
const confirmAdditionalDeletion = async () => {
  if (!deletingAdditional.value || !canEditTechnologyCatalog.value || catalogSaving.value) return;
  catalogSaving.value = true;
  additionalDeleteError.value = '';
  const source = deletingAdditional.value;
  const replacement = sortedAdditionalTechnologies.value.find(t => String(t.id) === replacementAdditional.value);
  if (replacementAdditional.value && !replacement) {
    additionalDeleteError.value = 'Выбранная технология для замены больше недоступна.';
    catalogSaving.value = false;
    return;
  }
  try {
    const result = await api(`/catalog/additional-technologies/${source.id}`, {
      method: 'DELETE',
      body: JSON.stringify({ replacement_id: replacement ? Number(replacement.id) : null }),
    });
    deletingAdditional.value = null;
    replacementAdditional.value = '';
    catalogToast.value = {
      id: ++catalogToastSequence, tone: 'success',
      message: replacement
        ? `«${source.name}» заменена на «${replacement.name}» у ${result.affected_specialists} специалистов.`
        : `«${source.name}» удалена у ${result.affected_specialists} специалистов.`,
    };
    try {
      await refreshCatalog();
    } catch (refreshError) {
      error.value = 'Технология удалена, но обновить список не удалось: ' + refreshError.message;
    }
  } catch (e) {
    additionalDeleteError.value = e.message;
  } finally {
    catalogSaving.value = false;
  }
};
const refreshSelectedProfile = async () => {
  // Autosave owns the live editor state. Refresh registry/dashboard projections
  // only: reloading selected from a prior response can overwrite a newer edit
  // that was queued while the previous save was still in flight.
  if (!canViewProfiles.value) return;
  try {
    const current = await api('/workspace');
    workspace.value = current;
    restoreDepartments();
  } catch (e) {
    // A successful write must not be reported as failed because the separate
    // registry refresh was unavailable. The next navigation reloads the list.
    console.warn('Specialists registry refresh failed after autosave', e);
  }
};
</script>

<template>
  <div class="specialists-app">
    <UiAppSidebar
      :section="sidebarSection"
      :items="mainNavItems"
      :bottom-items="bottomNavItems"
      current-service="specialists"
      :current-user="auth.user"
      :platform-access="() => auth.fetch('/api/employees/access/me')"
      :report-submit="(url, options) => auth.fetch(url, options)"
      aria-label="Навигация сервиса специалистов"
      @update:section="(id) => navigate(id === 'dashboard' ? '' : id)"
      @logout="auth.logout()"
    />

    <main class="irlix-service-workspace">
      <UiAppTopbar service="specialists" :section="sidebarSection" :items="navItems" :loading="loading">
        <template #actions>
          <UiDepartmentTreeSelect v-if="canFilterDepartments && ['dashboard','people','person'].includes(route.section)"
            class="specialists-topbar-departments" :model-value="selectedDepartments"
            :departments="workspace.departments" placeholder="Подразделения"
            aria-label="Фильтр по дереву подразделений" @update:model-value="rememberDepartments" />
          <UiButton v-if="['competencies','technologies'].includes(route.section) && canEditTechnologyCatalog"
            type="button" @click="openCreateTechnology()">{{ route.section === 'technologies' ? '+ Дополнительная технология' : '+ Технология' }}</UiButton>
        </template>
      </UiAppTopbar>
      <div class="content irlix-service-content">
      <div v-if="error" class="alert">{{ error }}</div>
      <UiLoader v-if="loading || (route.section==='role-permissions' && roleAccessPhase==='loading')" page label="Загрузка данных направления" />

      <UiErrorState v-else-if="route.section === 'not-found'" :status="404" back-href="/specialists/" back-label="На главную Specialists" />

      <UiErrorState v-else-if="route.section==='role-permissions' && roleAccessPhase==='error'" :status="503" description="Не удалось проверить права доступа." retryable @retry="loadRoleAccess" />
      <UiErrorState v-else-if="route.section==='role-permissions' && roleAccessPhase==='forbidden'" :status="403"/>
      <SpecialistsRolePermissionsView v-else-if="route.section==='role-permissions' && roleAccessPhase==='allowed'"/>
      <UiErrorState v-else-if="permissionsPhase==='forbidden'" :status="403" />
      <UiErrorState v-else-if="permissionsPhase==='error'" :status="503" description="Не удалось загрузить разрешения." retryable @retry="reloadAccess" />
      <UiErrorState v-else-if="['dashboard','people','person'].includes(route.section) && !canViewProfiles" :status="403" />
      <UiErrorState v-else-if="['competencies','technologies'].includes(route.section) && !canViewCatalog" :status="403" />
      <template v-else-if="route.section === 'dashboard'">
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

      <template v-else-if="route.section === 'people' || route.section === 'person'">
        <UiFilterBar class="specialists-filter-bar">
          <input v-model="filters.search" class="specialists-search irlix-search" type="search" aria-label="Поиск специалистов" placeholder="Поиск по имени, должности или направлению" />
          <UiSearchSelect v-model="filters.grade" :options="gradeOptions" placeholder="Все грейды" search-placeholder="Поиск грейда" aria-label="Грейд" />
          <UiSearchSelect v-model="filters.technology" :options="technologyOptions" placeholder="Все технологии" search-placeholder="Поиск технологии" aria-label="Технология" />
          <UiSearchSelect v-model="filters.status" :options="statusOptions" placeholder="Статус" :clearable="false" aria-label="Статус специалиста" />
          <span class="specialists-filter-count">Найдено: {{ filteredPeople.length }}</span>
        </UiFilterBar>
        <section class="table-card table-card--registry"><UiRegistryTable class="shared-registry-content" :columns="['27%','16%','12%','25%','14%','6%']" min-width="1000px" aria-label="Специалисты" density="compact"><template #head><tr><th>Специалист</th><th>Направление</th><th>Грейд</th><th>Технологии</th><th>Статус</th><th></th></tr></template><tr v-for="person in filteredPeople" :key="person.id" :class="{ dismissed: person.employment_status === 'Уволен' }" @click="openPerson(person.id)"><td><div class="person-cell"><span class="avatar">{{ initials(person.full_name) }}</span><span><b>{{ person.full_name }}</b><small>{{ person.position || 'Должность не указана' }}</small></span></div></td><td>{{ person.department_name || '—' }}</td><td><span class="grade">{{ person.grade || 'Не указан' }}</span></td><td><div class="chips"><span v-for="t in mainTechnologies(person).slice(0, 3)" :key="t.id"><UiTechnologyGrade :name="t.name" :grade="t.grade || ''" /></span><span v-if="mainTechnologies(person).length > 3">+{{ mainTechnologies(person).length - 3 }}</span><em v-if="!mainTechnologies(person).length">Не заполнено</em></div></td><td><span :class="['status', person.employment_status === 'Уволен' ? 'off' : 'on']">{{ person.employment_status === 'Уволен' ? 'Уволен' : 'Работает' }}</span></td><td class="arrow">→</td></tr></UiRegistryTable><div v-if="!filteredPeople.length" class="empty padded">По заданным фильтрам специалистов нет.</div></section>
      </template>

      <template v-else-if="route.section === 'technologies'">
        <section class="specialists-catalog-section specialists-catalog-section--technologies">
          <UiRegistryTable :columns="canEditTechnologyCatalog ? ['88%','12%'] : ['100%']" min-width="520px" density="compact" aria-label="Дополнительные технологии по алфавиту">
            <template #head><tr><th>Технология</th><th v-if="canEditTechnologyCatalog" aria-label="Действия"></th></tr></template>
            <tr v-for="technology in sortedAdditionalTechnologies" :key="technology.id">
              <td>
                <div v-if="editingAdditionalId === Number(technology.id)" class="specialists-additional-inline-editor">
                  <input ref="additionalRenameInput" v-model="editingAdditionalName"
                    class="specialists-additional-inline-input" maxlength="255" type="text"
                    :aria-label="'Новое название технологии ' + technology.name"
                    :aria-invalid="!!additionalRenameError" :disabled="catalogSaving"
                    @input="additionalRenameError = ''"
                    @keydown.enter.prevent="saveAdditionalRename"
                    @keydown.esc.stop.prevent="cancelAdditionalRename" />
                  <span v-if="additionalRenameError" class="specialists-additional-inline-error" role="alert">{{ additionalRenameError }}</span>
                </div>
                <span v-else>{{ technology.name }}</span>
              </td>
              <td v-if="canEditTechnologyCatalog">
                <div class="row-actions">
                  <template v-if="editingAdditionalId === Number(technology.id)">
                    <button type="button" class="specialists-additional-save" title="Сохранить название"
                      :aria-label="'Сохранить название ' + technology.name"
                      :disabled="catalogSaving" @click="saveAdditionalRename">✓</button>
                    <button type="button" title="Отменить редактирование"
                      :aria-label="'Отменить редактирование ' + technology.name"
                      :disabled="catalogSaving" @click="cancelAdditionalRename">×</button>
                  </template>
                  <template v-else>
                    <button type="button" title="Редактировать название"
                      :aria-label="'Редактировать название ' + technology.name"
                      :disabled="catalogSaving || editingAdditionalId !== null"
                      @click="startAdditionalRename(technology)">✎</button>
                    <button type="button" class="danger-icon" title="Удалить технологию"
                      :aria-label="'Удалить дополнительную технологию ' + technology.name"
                      :disabled="catalogSaving || editingAdditionalId !== null"
                      @click="openAdditionalDeletion(technology)">×</button>
                  </template>
                </div>
              </td>
            </tr>
          </UiRegistryTable>
          <div v-if="!sortedAdditionalTechnologies.length" class="empty padded">Дополнительные технологии пока не добавлены.</div>
        </section>
      </template>

      <template v-else-if="route.section === 'competencies'">
        
        <section class="specialists-catalog-section specialists-catalog-section--technologies">

          <UiRegistryTable v-if="technologyTree.length" :columns="['49%','18%','18%','15%']" min-width="800px" density="compact" aria-label="Дерево технологий">
            <template #head><tr><th>Технология</th><th>Алиас</th><th>Направление</th><th aria-label="Действия"></th></tr></template>
            <tr v-for="technology in technologyTree" :key="technology.id">
              <td>
                <div class="technology-name" :style="{ paddingLeft: (technology.depth * 20) + 'px' }">
                  <UiTreeToggle v-if="technology.hasChildren"
                    :expanded="expandedTechnologies.has(Number(technology.id))"
                    :label="expandedTechnologies.has(Number(technology.id)) ? 'Свернуть ' + technology.name : 'Развернуть ' + technology.name"
                    @click="toggleTechnologyRow(technology.id)" />
                  <span v-else class="technology-tree-spacer"></span>
                  <span class="technology-name-text">{{ technology.name }}</span>
                </div>
              </td>
              <td>{{ technology.alias || '—' }}</td>
              <td>{{ technology.category || '—' }}</td>
              <td>
                <div v-if="canEditTechnologyCatalog" class="row-actions">
                  <button type="button" title="Добавить дочернюю технологию" :aria-label="'Добавить дочернюю технологию для ' + technology.name" @click="openCreateTechnology(technology)">＋</button>
                  <button type="button" title="Редактировать" :aria-label="'Редактировать ' + technology.name" @click="openEditTechnology(technology)">✎</button>
                  <button type="button" class="danger-icon" title="Удалить" :aria-label="'Удалить ' + technology.name" :disabled="catalogSaving" @click="deleteTechnology(technology)">×</button>
                </div>
              </td>
            </tr>
          </UiRegistryTable>
          <div v-else class="empty padded">Каталог технологий пока пуст.</div>
        </section>
      </template>
      </div>
    </main>

    <UiDrawer :open="route.section==='person' && canViewProfiles" width="min(780px, 96vw)" :resizable="true" :scroll-body="false" title="Карточка специалиста" @close="closePerson">
      <template #title><strong>{{ selected?.employee?.full_name || 'Карточка специалиста' }}</strong></template>
      <div class="specialists-profile-drawer">
        <div v-if="profileErrorStatus" class="alert" role="alert">{{ profileErrorStatus }} — {{ error || 'Профиль недоступен' }}</div>
        <UiLoader v-else-if="!selected" page label="Загрузка профиля специалиста" />
        <template v-else>
          <div class="specialists-profile-identity">
            <span class="avatar xl">{{ initials(selected.employee.full_name) }}</span>
            <div>
              <strong>{{ selected.employee.full_name }}</strong>
              <p>{{ selected.employee.position || 'Должность не указана' }}</p>
              <p>{{ selected.employee.department_name || 'Направление не указано' }}</p>
              <span v-if="selected.employee.employment_status==='Уволен'" class="status off">Уволен</span>
            </div>
          </div>
          <SpecialistProfileBody :profile="selected" :catalog="workspace.catalog"
            :projects="projectAssignments" :projects-loading="projectLoading" :projects-error="projectError"
            :editable="canManageProfiles" @profile-updated="refreshSelectedProfile"
            @catalog-updated="refreshCatalog" />
        </template>
      </div>
    </UiDrawer>

    <div v-if="canEditTechnologyCatalog && technologyModalOpen" class="modal-backdrop" @click.self="closeTechnologyModal">
      <form class="modal-card" @submit.prevent="saveTechnology">
        <div class="modal-head"><div><div class="eyebrow">Каталог</div><h2>{{ route.section === 'technologies' ? 'Новая дополнительная технология' : (technologyEditor.id ? 'Редактировать технологию' : 'Новая технология') }}</h2></div><button type="button" class="modal-close" @click="closeTechnologyModal">×</button></div>
        <label>Название<input v-model="technologyEditor.name" maxlength="255" required placeholder="Например, React Native" /></label>
        <label v-if="route.section !== 'technologies'">Алиас<input v-model="technologyEditor.alias" maxlength="255" placeholder="Например, RN" /></label>
        <label v-if="route.section !== 'technologies'">Направление<input v-model="technologyEditor.category" maxlength="255" placeholder="Например, Mobile" /></label>
        <label v-if="route.section !== 'technologies'">Родительская технология<select v-model="technologyEditor.parent_id"><option value="">Без родителя</option><option v-for="technology in technologyParentOptions" :key="technology.id" :value="String(technology.id)">{{ technology.name }}</option></select></label>
        <div class="modal-actions"><button type="button" class="secondary" :disabled="catalogSaving" @click="closeTechnologyModal">Отмена</button><button class="primary" :disabled="catalogSaving || !technologyEditor.name.trim()">{{ catalogSaving ? 'Сохраняем…' : 'Сохранить' }}</button></div>
      </form>
    </div>

    <div v-if="deletingAdditional && canEditTechnologyCatalog" class="modal-backdrop"
      @click.self="closeAdditionalDeletion" @keydown.esc="closeAdditionalDeletion">
      <form class="modal-card specialists-additional-delete-modal" role="dialog" aria-modal="true"
        aria-labelledby="specialists-delete-additional-title" @submit.prevent="confirmAdditionalDeletion">
        <div class="modal-head">
          <div>
            <div class="eyebrow">Дополнительные технологии</div>
            <h2 id="specialists-delete-additional-title">Удалить «{{ deletingAdditional.name }}»?</h2>
          </div>
          <button type="button" class="modal-close" :disabled="catalogSaving"
            aria-label="Закрыть окно" @click="closeAdditionalDeletion">×</button>
        </div>
        <p class="specialists-delete-description">
          Технология будет удалена из каталога и из карточек всех специалистов, включая уволенных.
          При необходимости можно заменить её другой дополнительной технологией у всех, кто её использовал.
        </p>
        <div class="specialists-delete-replacement">
          <span class="specialists-delete-replacement-label">Заменить на</span>
          <UiSearchSelect v-model="replacementAdditional" :options="additionalReplacementOptions"
            teleport compact placeholder="Без замены — удалить из всех карточек"
            search-placeholder="Найти дополнительную технологию"
            aria-label="Дополнительная технология для замены" :disabled="catalogSaving" />
        </div>
        <p v-if="additionalDeleteError" class="alert" role="alert">{{ additionalDeleteError }}</p>
        <div class="modal-actions">
          <UiButton type="button" variant="secondary" :disabled="catalogSaving"
            @click="closeAdditionalDeletion">Отмена</UiButton>
          <UiButton type="submit" variant="danger" :disabled="catalogSaving">
            {{ catalogSaving ? 'Удаление…' : (replacementAdditional ? 'Заменить и удалить' : 'Удалить у всех') }}
          </UiButton>
        </div>
      </form>
    </div>
    <UiToast v-if="catalogToast" :key="catalogToast.id" :tone="catalogToast.tone"
      :message="catalogToast.message" @close="catalogToast = null" />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { createProfessionalAutosave } from './professionalAutosave.js';
import { UiButton, UiHistoryTimeline, UiSearchSelect, UiTabs, UiTechnologyGrade } from '@irlix/ui';

const props = defineProps({
  profile: { type: Object, required: true },
  catalog: { type: Object, required: true },
  projects: { type: Array, default: () => [] },
  projectsLoading: { type: Boolean, default: false },
  projectsError: { type: String, default: '' },
  editable: { type: Boolean, default: false },
});
const emit = defineEmits(['profile-updated', 'catalog-updated']);
const grades = ['Junior', 'Middle', 'Middle+', 'Senior'];
const tabs = [{ value: 'history', label: 'История' }, { value: 'about', label: 'О специалисте' }];
const tab = ref('history');
const busy = ref(false);
const message = ref('');
const autosaveStatus = ref('idle');
const primary = ref([]);
const secondary = ref([]);
const editing = ref(null);
const draftTech = ref('');
const draftGrade = ref('');
const showSecondarySelect = ref(false);
const timeline = ref([]);
const timelineLoading = ref(false);
const timelinePartial = ref('');
const employeeId = computed(() => Number(props.profile?.employee?.id || 0));
const technologies = computed(() => [...(props.catalog?.technologies || [])].sort((a,b) =>
  a.name.localeCompare(b.name, 'ru', { sensitivity: 'base' })));
const additionalTechnologies = computed(() => [...(props.catalog?.additional_technologies || [])].sort((a,b) =>
  a.name.localeCompare(b.name, 'ru', { sensitivity: 'base' })));
const gradeOptions = grades.map(value => ({ value, label: value }));
const technologyOptions = computed(() => technologies.value.map(t => ({ value: String(t.id), label: t.name })));
const additionalTechnologyOptions = computed(() => additionalTechnologies.value.map(t => ({ value: String(t.id), label: t.name })));
const byId = computed(() => new Map(technologies.value.map(t => [Number(t.id), t])));
const additionalById = computed(() => new Map(additionalTechnologies.value.map(t => [Number(t.id), t])));
const sortedPrimary = computed(() => primary.value.map((entry,index) => ({ ...entry,index })));
const secondaryTags = computed(() => secondary.value.map(id => additionalById.value.get(id)).filter(Boolean));
const parseResponse = async response => {
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`);
  return body.data;
};
const fetchData = async (url, options = {}) => parseResponse(await fetch(url, {
  ...options,
  headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) },
}));
const initProfile = () => {
  const values = props.profile?.professional?.technologies || [];
  primary.value = values.filter(t => t.kind === 'primary')
    .map(t => ({ technology_id: Number(t.id), grade: t.grade || '' }));
  secondary.value = (props.profile?.professional?.additional_technologies || []).map(t => Number(t.id));
  editing.value = null;
  draftTech.value = '';
  draftGrade.value = '';
  showSecondarySelect.value = false;
  tab.value = 'history';
  message.value = '';
  autosaveStatus.value = 'idle';
};
const loadTimeline = async () => {
  const id = employeeId.value;
  if (!id) return;
  timelineLoading.value = true;
  timelinePartial.value = '';
  timeline.value = [];
  // Each source owns its records; never substitute other sources' invented events.
  const urls = [
    `/api/specialists/people/${id}/timeline`,
    `/api/employees/specialists-history/${id}`,
    `/api/clients/specialists/people/${id}/history`,
  ];
  const results = await Promise.allSettled(urls.map(url => fetchData(url)));
  if (employeeId.value !== id) return;
  timeline.value = results.flatMap(result => result.status === 'fulfilled' && Array.isArray(result.value) ? result.value : []);
  const failed = results.filter(result => result.status === 'rejected').length;
  if (failed) timelinePartial.value = failed === urls.length
    ? 'Не удалось загрузить историю сотрудника.'
    : 'Часть событий истории недоступна. Попробуйте обновить карточку.';
  timelineLoading.value = false;
};
// Professional changes are persisted without a form submit. The API replaces
// the full set, so writes are serialized and only the latest selection wins.
const autosave = createProfessionalAutosave({
  persist: (id, data) => fetchData(`/api/specialists/people/${id}/professional`, {
    method: 'PUT', body: JSON.stringify(data),
  }),
  onStatus: state => { autosaveStatus.value = state; message.value = ''; },
  onSaved: () => {
    autosaveStatus.value = 'saved';
    emit('profile-updated');
    void loadTimeline();
  },
  onError: error => {
    autosaveStatus.value = 'error';
    message.value = error?.message || 'Не удалось сохранить технологии.';
  },
});
const queueProfessionalSave = () => {
  if (!props.editable || !employeeId.value) return;
  autosave.queue(employeeId.value, {
    primary: primary.value,
    secondary_ids: secondary.value,
  });
};
onBeforeUnmount(() => { void autosave.close(); });
watch(employeeId, () => { initProfile(); loadTimeline(); }, { immediate: true });
watch(() => props.profile?.professional, () => { const currentTab = tab.value; initProfile(); tab.value = currentTab; });
const startPrimary = index => {
  if (!props.editable) return;
  editing.value = index;
  draftTech.value = index === -1 ? '' : String(primary.value[index]?.technology_id || '');
  draftGrade.value = index === -1 ? '' : (primary.value[index]?.grade || '');
};
const commitPrimary = () => {
  const id = Number(draftTech.value);
  if (!id || !grades.includes(draftGrade.value)) return;
  if (primary.value.some((item,index) => index !== editing.value && item.technology_id === id)) {
    message.value = 'Эта технология уже добавлена как основная.';
    return;
  }
  const next = { technology_id: id, grade: draftGrade.value };
  if (editing.value === -1) primary.value.push(next);
  else if (editing.value !== null) primary.value.splice(editing.value, 1, next);
  editing.value = null;
  message.value = '';
  queueProfessionalSave();
};
const removePrimary = index => {
  if (!props.editable) return;
  primary.value.splice(index, 1);
  editing.value = null;
  queueProfessionalSave();
};
const chooseSecondary = values => {
  // Numeric IDs belong to a different catalog: never compare them to main IDs.
  secondary.value = [...new Set(values.map(Number).filter(id => id > 0))];
  queueProfessionalSave();
};
const quickCreate = async name => {
  if (!props.editable) return;
  busy.value = true;
  message.value = '';
  try {
    const technology = await fetchData('/api/specialists/catalog/additional-technologies/quick', {
      method: 'POST', body: JSON.stringify({ name }),
    });
    chooseSecondary([...secondary.value, Number(technology.id)]);
    await emit('catalog-updated');
  } catch (e) {
    message.value = e.message;
  } finally {
    busy.value = false;
  }
};
const comment = async text => {
  if (!props.editable || !employeeId.value) return;
  busy.value = true;
  message.value = '';
  try {
    await fetchData(`/api/specialists/people/${employeeId.value}/timeline`, {
      method: 'POST', body: JSON.stringify({ text }),
    });
    await loadTimeline();
  } catch (e) {
    message.value = e.message;
  } finally {
    busy.value = false;
  }
};
</script>

<template>
  <div class="specialist-profile-body">
    <UiTabs v-model="tab" :items="tabs" />
    <p v-if="message" class="specialist-profile-feedback error" role="alert">
      {{ message }}
      <button v-if="autosaveStatus === 'error'" type="button" class="specialist-autosave-retry" @click="autosave.retry()">Повторить сохранение</button>
    </p>
    <p v-if="autosaveStatus === 'saving'" class="specialist-profile-feedback" role="status">Сохраняем изменения…</p>
    <p v-else-if="autosaveStatus === 'saved'" class="specialist-profile-feedback success" role="status">Изменения сохранены</p>
    <section v-if="tab === 'history'" class="specialist-tab-history">
      <p v-if="timelineLoading" class="specialist-muted">Загрузка истории…</p>
      <p v-if="timelinePartial" class="specialist-profile-feedback error" role="status">{{ timelinePartial }}</p>
      <UiHistoryTimeline :events="timeline" :editable="editable" :busy="busy"
        placeholder="Комментарий руководителя…" @submit="comment" />
    </section>
    <template v-else>
      <section class="specialists-profile-section">
        <h3>Основные технологии</h3>
        <div class="specialist-primary-list">
          <div v-for="entry in sortedPrimary" :key="entry.technology_id" class="specialist-primary-row">
            <UiTechnologyGrade :name="byId.get(entry.technology_id)?.name || 'Технология'" :grade="entry.grade" />
            <span v-if="editable" class="specialist-primary-actions">
              <button type="button" aria-label="Редактировать основную технологию" title="Редактировать" @click="startPrimary(entry.index)">✎</button>
              <button type="button" aria-label="Убрать основную технологию" title="Убрать" @click="removePrimary(entry.index)">×</button>
            </span>
          </div>
          <button v-if="editable && editing === null" type="button" class="specialist-primary-add"
            aria-label="Добавить основную технологию" title="Добавить основную технологию" @click="startPrimary(-1)">+</button>
          <p v-if="!primary.length && !editable" class="specialist-muted">Основные технологии не указаны.</p>
        </div>
        <div v-if="editing !== null" class="specialist-primary-editor">
          <UiSearchSelect v-model="draftTech" :options="technologyOptions" teleport compact placeholder="Выберите технологию" search-placeholder="Поиск технологии" aria-label="Основная технология" />
          <UiSearchSelect v-model="draftGrade" :options="gradeOptions" teleport compact placeholder="Выберите грейд" aria-label="Грейд основной технологии" />
          <UiButton compact :disabled="!draftTech || !draftGrade" @click="commitPrimary">Применить</UiButton>
          <UiButton compact variant="secondary" @click="editing = null">Отмена</UiButton>
        </div>
      </section>
      <section class="specialists-profile-section">
        <h3>Дополнительные технологии</h3>
        <div class="specialist-aux-layout">
          <button v-if="editable" type="button" class="specialist-aux-plus" aria-label="Добавить дополнительные технологии" title="Добавить технологии" @click="showSecondarySelect = !showSecondarySelect">+</button>
          <div v-if="editable && showSecondarySelect" class="specialist-aux-select">
            <UiSearchSelect :model-value="secondary.map(String)" :options="additionalTechnologyOptions" multiple teleport compact creatable
              placeholder="Выберите технологии" search-placeholder="Поиск или новая технология" aria-label="Дополнительные технологии"
              @update:model-value="chooseSecondary" @create="quickCreate" />
          </div>
          <div class="specialist-aux-tags">
            <span v-for="technology in secondaryTags" :key="technology.id" class="specialist-aux-tag">
              {{ technology.name }}
              <button v-if="editable" type="button" :aria-label="'Удалить ' + technology.name" @click="chooseSecondary(secondary.filter(id => id !== Number(technology.id)))">×</button>
            </span>
            <span v-if="!secondaryTags.length" class="specialist-muted">Технологии не выбраны.</span>
          </div>
        </div>
      </section>
      <section class="specialists-profile-section">
        <h3>История проектов</h3>
        <p v-if="projectsLoading" class="specialist-muted">Загрузка проектов…</p>
        <p v-else-if="projectsError" class="specialist-muted" role="status">{{ projectsError }}</p>
        <div v-else-if="projects.length" class="specialists-projects">
          <div v-for="(project,index) in projects" :key="project.project_id + ':' + index">
            <strong>{{ project.project_name }}</strong>
            <span>{{ project.client_name }}
              <template v-if="project.valid_from"> · {{ project.valid_from }} — {{ project.valid_to || 'н. в.' }}</template>
            </span>
          </div>
        </div>
        <p v-else class="specialist-muted">Проекты не найдены.</p>
      </section>
    </template>
  </div>
</template>

<style scoped>
.specialist-profile-body{display:flex;flex-direction:column;min-height:0;gap:0}
.specialist-tab-history{display:flex;flex-direction:column;min-height:330px;padding:18px 20px}
.specialist-primary-list{display:flex;align-items:center;flex-wrap:wrap;gap:8px 14px;min-height:32px}
.specialist-primary-row{display:inline-flex;align-items:center;gap:3px;padding:3px 0;border:0;min-width:0;white-space:nowrap}
.specialist-primary-actions{display:inline-flex;gap:2px;align-items:center;opacity:0;transition:opacity .12s ease}
.specialist-primary-row:hover .specialist-primary-actions,
.specialist-primary-row:focus-within .specialist-primary-actions{opacity:1}
.specialist-primary-actions button{display:grid;place-items:center;width:20px;height:22px;padding:0}
.specialist-primary-actions button,.specialist-aux-tag button{border:0;background:none;cursor:pointer;color:var(--irlix-color-text-muted);font:inherit}
.specialist-primary-add{width:29px;height:29px;display:grid;place-items:center;flex:none;border:1px solid var(--irlix-color-border);border-radius:8px;background:var(--irlix-color-surface);color:var(--irlix-color-primary-text);font-size:20px;cursor:pointer}
@media(hover:none){.specialist-primary-actions{opacity:1}}
.specialist-primary-editor{display:grid;grid-template-columns:minmax(160px,1.4fr) minmax(110px,1fr) auto auto;gap:7px;align-items:start;padding:12px 0}
.specialist-inline-add{margin-top:12px;padding:5px 0;border:0;background:transparent;color:var(--irlix-color-primary-text);cursor:pointer;font-size:var(--irlix-font-size-table)}
.specialist-aux-layout{display:flex;align-items:flex-start;flex-wrap:wrap;gap:10px}
.specialist-aux-plus{width:28px;height:28px;flex:none;border:1px solid var(--irlix-color-border);border-radius:8px;background:var(--irlix-color-surface);color:var(--irlix-color-primary-text);font-size:20px;cursor:pointer}
.specialist-aux-select{flex:0 1 220px;min-width:180px;font-size:var(--irlix-font-size-table)}
.specialist-aux-tags{display:flex;align-items:center;flex-wrap:wrap;gap:6px;flex:1 1 260px;min-height:30px}
.specialist-aux-tag{display:inline-flex;align-items:center;gap:8px;padding:5px 8px;border:1px solid var(--irlix-color-border);border-radius:7px;background:var(--irlix-color-surface-muted);font-size:var(--irlix-font-size-table)}
.specialist-muted{margin:0;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-table)}
.specialist-profile-feedback{padding:8px 20px;margin:0;font-size:var(--irlix-font-size-table)}
.specialist-profile-feedback.error{color:var(--irlix-status-danger-text)}
.specialist-profile-feedback.success{color:var(--irlix-status-success-text)}
.specialist-autosave-retry{margin-left:10px;border:0;background:transparent;color:var(--irlix-color-primary-text);font:inherit;text-decoration:underline;cursor:pointer}
@media(max-width:720px){.specialist-primary-editor{grid-template-columns:1fr 1fr}.specialist-tab-history{padding:12px}}
</style>

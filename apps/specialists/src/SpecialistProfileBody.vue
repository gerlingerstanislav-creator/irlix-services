<script setup>
import { computed, ref, watch } from 'vue';
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
const generalGrade = ref('');
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
const gradeOptions = grades.map(value => ({ value, label: value }));
const technologyOptions = computed(() => technologies.value.map(t => ({ value: String(t.id), label: t.name })));
const byId = computed(() => new Map(technologies.value.map(t => [Number(t.id), t])));
const sortedPrimary = computed(() => primary.value.map((entry,index) => ({ ...entry,index })));
const secondaryTags = computed(() => secondary.value.map(id => byId.value.get(id)).filter(Boolean));
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
  generalGrade.value = props.profile?.professional?.grade || '';
  const values = props.profile?.professional?.technologies || [];
  primary.value = values.filter(t => t.kind === 'primary')
    .map(t => ({ technology_id: Number(t.id), grade: t.grade || '' }));
  secondary.value = values.filter(t => t.kind !== 'primary').map(t => Number(t.id));
  editing.value = null;
  draftTech.value = '';
  draftGrade.value = '';
  showSecondarySelect.value = false;
  tab.value = 'history';
  message.value = '';
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
watch(employeeId, () => { initProfile(); loadTimeline(); }, { immediate: true });
watch(() => props.profile?.professional, () => initProfile());
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
  secondary.value = secondary.value.filter(value => value !== id);
  editing.value = null;
  message.value = '';
};
const removePrimary = index => {
  if (!props.editable) return;
  primary.value.splice(index, 1);
  editing.value = null;
};
const chooseSecondary = values => {
  const primarySet = new Set(primary.value.map(x => x.technology_id));
  secondary.value = [...new Set(values.map(Number).filter(id => id && !primarySet.has(id)))];
};
const quickCreate = async name => {
  if (!props.editable) return;
  busy.value = true;
  message.value = '';
  try {
    const technology = await fetchData('/api/specialists/catalog/technologies/quick', {
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
const save = async () => {
  if (!props.editable || !employeeId.value) return;
  busy.value = true;
  message.value = '';
  try {
    await fetchData(`/api/specialists/people/${employeeId.value}/professional`, {
      method: 'PUT', body: JSON.stringify({
        grade: grades.includes(generalGrade.value) ? generalGrade.value : null,
        primary: primary.value, secondary_ids: secondary.value,
      }),
    });
    message.value = 'Профиль сохранён.';
    emit('profile-updated');
    await loadTimeline();
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
    <p v-if="message" :class="['specialist-profile-feedback', message === 'Профиль сохранён.' ? 'success' : 'error']" role="status">{{ message }}</p>
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
          <p v-if="!primary.length" class="specialist-muted">Основные технологии не указаны.</p>
        </div>
        <button v-if="editable && editing === null" type="button" class="specialist-inline-add" @click="startPrimary(-1)">+ Добавить основную технологию</button>
        <div v-if="editing !== null" class="specialist-primary-editor">
          <UiSearchSelect v-model="draftTech" :options="technologyOptions" teleport placeholder="Выберите технологию" search-placeholder="Поиск технологии" aria-label="Основная технология" />
          <UiSearchSelect v-model="draftGrade" :options="gradeOptions" teleport placeholder="Выберите грейд" aria-label="Грейд основной технологии" />
          <UiButton compact :disabled="!draftTech || !draftGrade" @click="commitPrimary">Применить</UiButton>
          <UiButton compact variant="secondary" @click="editing = null">Отмена</UiButton>
        </div>
      </section>
      <section class="specialists-profile-section">
        <h3>Вспомогательные технологии</h3>
        <div class="specialist-aux-layout">
          <button v-if="editable" type="button" class="specialist-aux-plus" aria-label="Добавить вспомогательные технологии" title="Добавить технологии" @click="showSecondarySelect = !showSecondarySelect">+</button>
          <div v-if="editable && showSecondarySelect" class="specialist-aux-select">
            <UiSearchSelect :model-value="secondary.map(String)" :options="technologyOptions" multiple teleport creatable
              placeholder="Выберите технологии" search-placeholder="Поиск или новая технология" aria-label="Вспомогательные технологии"
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
        <h3>Общий грейд</h3>
        <label>Грейд специалиста
          <select v-model="generalGrade" :disabled="!editable">
            <option value="">Не указан</option>
            <option v-if="generalGrade && !grades.includes(generalGrade)" :value="generalGrade">{{ generalGrade }} (ранее указанный)</option>
            <option v-for="grade in grades" :key="grade" :value="grade">{{ grade }}</option>
          </select>
        </label>
      </section>
      <div v-if="editable" class="specialists-profile-footer">
        <UiButton :disabled="busy || editing !== null" @click="save">{{ busy ? 'Сохраняем…' : 'Сохранить изменения' }}</UiButton>
      </div>
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
.specialist-primary-list{display:grid;gap:0}
.specialist-primary-row{display:flex;align-items:baseline;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid var(--irlix-color-border)}
.specialist-primary-actions{display:flex;gap:8px}
.specialist-primary-actions button,.specialist-aux-tag button{border:0;background:none;cursor:pointer;color:var(--irlix-color-text-muted);font:inherit}
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
@media(max-width:720px){.specialist-primary-editor{grid-template-columns:1fr 1fr}.specialist-tab-history{padding:12px}}
</style>

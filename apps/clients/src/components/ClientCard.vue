<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { UiButton, UiSearchSelect } from '@irlix/ui';

const props = defineProps({
  clientId: { type: Number, default: null },
  employees: { type: Array, default: () => [] },
  technologyOptions: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'changed']);

const activeTab = ref('about');
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const data = reactive({ client: null, contacts: [], reporting_periods: [], legal_entities: [], notes: [] });
const draft = reactive({});
const newLegal = reactive({ name: '', inn: '' });
const contactForm = reactive({ open: false, id: null, full_name: '', position: '', phone: '', email: '' });
const reportForm = reactive({ open: false, period_start: '', period_end: '' });
const noteText = ref('');

const tabs = [
  { value: 'about', label: 'О клиенте', icon: 'ⓘ' },
  { value: 'contacts', label: 'Контакты', icon: '▣' },
  { value: 'reports', label: 'Отчётные периоды', icon: '▤' },
  { value: 'notes', label: 'Заметки', icon: '≡' },
];

const employeeOptions = computed(() => props.employees.map(employee => ({
  value: String(employee.id),
  label: employee.full_name || `#${employee.id}`,
})).sort((a, b) => a.label.localeCompare(b.label, 'ru')));
const employeeName = id => props.employees.find(employee => Number(employee.id) === Number(id))?.full_name || (id ? `#${id}` : '—');
const techOptions = computed(() => [...new Set([
  ...(props.technologyOptions || []).map(option => typeof option === 'object' ? option.value ?? option.label : option),
  ...((draft.technologies || []).filter(Boolean)),
])].filter(Boolean).sort((a, b) => String(a).localeCompare(String(b), 'ru')).map(value => ({ value, label: value })));
const initial = computed(() => String(data.client?.name || '?').trim().charAt(0).toUpperCase());

async function api(url, options = {}) {
  const response = await fetch(url, {
    ...options,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) },
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`);
  return body;
}

function applyClient(client) {
  Object.keys(draft).forEach(key => delete draft[key]);
  Object.assign(draft, {
    name: client?.name || '',
    type: client?.type || '',
    sector: client?.sector || '',
    sales_employee_id: client?.sales_employee_id ? String(client.sales_employee_id) : '',
    account_employee_id: client?.account_employee_id ? String(client.account_employee_id) : '',
    description: client?.description || '',
    act_approval_days: client?.act_approval_days ?? '',
    payment_days: client?.payment_days ?? '',
    technologies: Array.isArray(client?.technologies) ? [...client.technologies] : [],
  });
}

async function load() {
  if (!props.clientId) return;
  loading.value = true;
  error.value = '';
  try {
    const body = await api(`/api/clients/clients/${props.clientId}/card`);
    Object.assign(data, body.data || {});
    applyClient(data.client);
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    loading.value = false;
  }
}

async function saveClient() {
  saving.value = true;
  error.value = '';
  try {
    await api(`/api/clients/clients/${props.clientId}/card`, {
      method: 'PATCH',
      body: JSON.stringify({
        name: draft.name,
        type: draft.type || null,
        sector: draft.sector || null,
        sales_employee_id: draft.sales_employee_id ? Number(draft.sales_employee_id) : null,
        account_employee_id: Number(draft.account_employee_id),
        description: draft.description || null,
        act_approval_days: draft.act_approval_days === '' ? null : Number(draft.act_approval_days),
        payment_days: draft.payment_days === '' ? null : Number(draft.payment_days),
        technologies: draft.technologies || [],
      }),
    });
    emit('changed');
    await load();
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    saving.value = false;
  }
}

function editContact(contact = null) {
  contactForm.open = true;
  contactForm.id = contact?.id || null;
  contactForm.full_name = contact?.full_name || '';
  contactForm.position = contact?.position || '';
  contactForm.phone = contact?.phone || '';
  contactForm.email = contact?.email || '';
}
function closeContactForm() {
  contactForm.open = false;
  contactForm.id = null;
}
async function saveContact() {
  saving.value = true;
  error.value = '';
  try {
    const payload = {
      full_name: contactForm.full_name,
      position: contactForm.position || null,
      phone: contactForm.phone || null,
      email: contactForm.email || null,
    };
    if (contactForm.id) {
      await api(`/api/clients/contacts/${contactForm.id}`, { method: 'PATCH', body: JSON.stringify(payload) });
    } else {
      const created = await api('/api/clients/contacts', { method: 'POST', body: JSON.stringify(payload) });
      await api(`/api/clients/contacts/${created.data.id}/relations`, {
        method: 'POST',
        body: JSON.stringify({ entity_type: 'client', entity_id: props.clientId }),
      });
    }
    closeContactForm();
    emit('changed');
    await load();
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    saving.value = false;
  }
}

async function addLegalEntity() {
  if (!newLegal.name.trim()) return;
  saving.value = true;
  error.value = '';
  try {
    await api(`/api/clients/clients/${props.clientId}/legal-entities`, {
      method: 'POST',
      body: JSON.stringify({ name: newLegal.name, inn: newLegal.inn || null }),
    });
    newLegal.name = '';
    newLegal.inn = '';
    await load();
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    saving.value = false;
  }
}

async function saveLegalEntity(entity) {
  saving.value = true;
  error.value = '';
  try {
    await api(`/api/clients/legal-entities/${entity.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ name: entity.name, inn: entity.inn || null }),
    });
    await load();
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    saving.value = false;
  }
}

async function addReport() {
  if (!reportForm.period_start || !reportForm.period_end) return;
  saving.value = true;
  error.value = '';
  try {
    await api('/api/clients/reporting-periods', {
      method: 'POST',
      body: JSON.stringify({ client_id: props.clientId, period_start: reportForm.period_start, period_end: reportForm.period_end }),
    });
    reportForm.open = false;
    reportForm.period_start = '';
    reportForm.period_end = '';
    emit('changed');
    await load();
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    saving.value = false;
  }
}

async function addNote() {
  if (!noteText.value.trim()) return;
  saving.value = true;
  error.value = '';
  try {
    await api(`/api/clients/clients/${props.clientId}/notes`, {
      method: 'POST',
      body: JSON.stringify({ text: noteText.value }),
    });
    noteText.value = '';
    await load();
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    saving.value = false;
  }
}

const dateRu = value => value ? new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString('ru-RU') : '—';
const dateTimeRu = value => value ? new Date(value).toLocaleString('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }) : '—';

watch(() => props.clientId, (value) => {
  if (value) {
    activeTab.value = 'about';
    load();
  }
}, { immediate: true });
</script>

<template>
  <div v-if="clientId" class="client-card-overlay" @mousedown.self="emit('close')">
    <section class="client-card-modal" role="dialog" aria-modal="true" :aria-label="data.client?.name || 'Карточка клиента'">
      <header class="client-card-breadcrumbs">
        <span>Клиенты</span><span class="sep">›</span><strong>{{ data.client?.name || 'Клиент' }}</strong>
        <button type="button" class="client-card-close" aria-label="Закрыть" @click="emit('close')">×</button>
      </header>

      <div v-if="error" class="client-card-error">{{ error }}<button type="button" @click="load">Повторить</button></div>
      <div v-if="loading" class="client-card-loading">Загрузка карточки…</div>

      <template v-else-if="data.client">
        <section class="client-card-hero">
          <div class="client-card-title">
            <span class="client-avatar">{{ initial }}</span>
            <div>
              <strong>{{ data.client.name }}</strong>
              <small>{{ data.client.type || 'Тип клиента не указан' }} · {{ data.client.sector || 'Сектор не указан' }}</small>
            </div>
          </div>
          <div class="manager-cards">
            <div class="manager-card"><span class="manager-icon">♙</span><div><strong>{{ employeeName(data.client.account_employee_id) }}</strong><small>Аккаунт менеджер</small></div></div>
            <div class="manager-card"><span class="manager-icon">♙</span><div><strong>{{ employeeName(data.client.sales_employee_id) }}</strong><small>Sales менеджер</small></div></div>
          </div>
        </section>

        <nav class="client-card-tabs" aria-label="Разделы карточки клиента">
          <button v-for="tab in tabs" :key="tab.value" type="button" :class="{ active: activeTab === tab.value }" @click="activeTab = tab.value">
            <span>{{ tab.icon }}</span>{{ tab.label }}
          </button>
        </nav>

        <main class="client-card-body">
          <section v-if="activeTab === 'about'" class="about-tab">
            <div class="client-fields">
              <label><span>Название клиента</span><input v-model="draft.name" /></label>
              <label><span>Sales-менеджер</span><UiSearchSelect v-model="draft.sales_employee_id" :options="employeeOptions" placeholder="Sales-менеджер" search-placeholder="Поиск сотрудника" /></label>
              <label><span>Аккаунт-менеджер</span><UiSearchSelect v-model="draft.account_employee_id" :options="employeeOptions" placeholder="Аккаунт-менеджер" search-placeholder="Поиск сотрудника" /></label>
              <label><span>Тип клиента</span><input v-model="draft.type" placeholder="Например, прямой" /></label>
              <label><span>Сектор клиента</span><input v-model="draft.sector" placeholder="Сектор" /></label>
              <label class="wide"><span>Описание клиента</span><textarea v-model="draft.description" rows="4" placeholder="Описание клиента"></textarea></label>
              <label><span>Срок согласования акта (в днях)</span><input v-model="draft.act_approval_days" type="number" min="0" /></label>
              <label><span>Срок оплаты (в днях)</span><input v-model="draft.payment_days" type="number" min="0" /></label>
              <label class="wide"><span>Технологии</span><UiSearchSelect v-model="draft.technologies" :options="techOptions" multiple placeholder="Технологии" search-placeholder="Поиск технологии" /></label>
            </div>
            <div class="about-actions"><UiButton :disabled="saving || !draft.name || !draft.account_employee_id" @click="saveClient">{{ saving ? 'Сохраняю…' : 'Сохранить изменения' }}</UiButton></div>

            <section class="legal-section">
              <div class="section-line"><h3>Юридические лица</h3></div>
              <div class="legal-table">
                <div class="legal-head"><span>Название</span><span>ИНН</span><span></span></div>
                <div v-for="entity in data.legal_entities" :key="entity.id" class="legal-row">
                  <input v-model="entity.name" />
                  <input v-model="entity.inn" />
                  <button type="button" @click="saveLegalEntity(entity)">Сохранить</button>
                </div>
                <div class="legal-row legal-new">
                  <input v-model="newLegal.name" placeholder="Название" />
                  <input v-model="newLegal.inn" placeholder="ИНН" />
                  <button type="button" :disabled="!newLegal.name.trim()" @click="addLegalEntity">＋ Добавить</button>
                </div>
              </div>
            </section>
          </section>

          <section v-else-if="activeTab === 'contacts'" class="contacts-tab">
            <div class="tab-toolbar"><UiButton @click="editContact()">＋ Новый контакт</UiButton></div>
            <form v-if="contactForm.open" class="inline-form contact-form" @submit.prevent="saveContact">
              <input v-model="contactForm.full_name" required placeholder="Имя" />
              <input v-model="contactForm.position" placeholder="Должность" />
              <input v-model="contactForm.phone" placeholder="Телефон" />
              <input v-model="contactForm.email" type="email" placeholder="Email" />
              <UiButton type="submit" :disabled="saving">{{ contactForm.id ? 'Сохранить' : 'Добавить' }}</UiButton>
              <button type="button" class="text-button" @click="closeContactForm">Отмена</button>
            </form>
            <div class="card-table contacts-table">
              <div class="card-table-head"><span>Имя</span><span>Должность</span><span>Контактная информация</span><span></span></div>
              <div v-for="contact in data.contacts" :key="contact.id" class="card-table-row">
                <strong>{{ contact.full_name }}</strong>
                <span>{{ contact.position || '—' }}</span>
                <span class="contact-info"><span v-if="contact.phone">{{ contact.phone }}</span><span v-if="contact.email">{{ contact.email }}</span><span v-if="!contact.phone && !contact.email">—</span></span>
                <button type="button" class="edit-button" aria-label="Редактировать контакт" @click="editContact(contact)">✎</button>
              </div>
              <div v-if="!data.contacts.length" class="card-empty">У клиента пока нет контактных лиц</div>
            </div>
          </section>

          <section v-else-if="activeTab === 'reports'" class="reports-tab">
            <div class="tab-toolbar"><UiButton @click="reportForm.open = !reportForm.open">＋ Новый отчётный период</UiButton></div>
            <form v-if="reportForm.open" class="inline-form report-form" @submit.prevent="addReport">
              <label>Начало<input v-model="reportForm.period_start" type="date" required /></label>
              <label>Конец<input v-model="reportForm.period_end" type="date" required /></label>
              <UiButton type="submit" :disabled="saving">Добавить</UiButton>
            </form>
            <div class="report-list">
              <article v-for="period in data.reporting_periods" :key="period.id">
                <div><strong>Отчётный период {{ dateRu(period.period_start) }} - {{ dateRu(period.period_end) }}</strong><small>{{ period.status }}</small></div>
                <span class="status-pill">{{ period.status }}</span>
              </article>
              <div v-if="!data.reporting_periods.length" class="card-empty">Отчётных периодов пока нет</div>
            </div>
          </section>

          <section v-else class="notes-tab">
            <form class="note-composer" @submit.prevent="addNote">
              <textarea v-model="noteText" rows="4" placeholder="Новая заметка аккаунт-менеджера"></textarea>
              <UiButton type="submit" :disabled="saving || !noteText.trim()">Добавить заметку</UiButton>
            </form>
            <div class="notes-history">
              <article v-for="note in data.notes" :key="note.id">
                <div class="note-meta"><strong>{{ note.created_by_username || 'Аккаунт-менеджер' }}</strong><span>{{ dateTimeRu(note.created_at) }}</span></div>
                <p>{{ note.text }}</p>
              </article>
              <div v-if="!data.notes.length" class="card-empty">Заметок пока нет</div>
            </div>
          </section>
        </main>
      </template>
    </section>
  </div>
</template>

<style scoped>
.client-card-overlay{position:fixed;inset:0;z-index:500;background:rgba(18,25,32,.28);padding:14px;display:flex}.client-card-modal{width:100%;height:100%;min-width:0;overflow:hidden;background:#fff;border:1px solid #e1e4e8;border-radius:10px;box-shadow:0 18px 60px rgba(18,25,32,.18);display:grid;grid-template-rows:42px auto auto minmax(0,1fr)}.client-card-breadcrumbs{display:flex;align-items:center;gap:10px;padding:0 16px;border-bottom:1px solid #e4e6e9;color:#56606b;font-size:12px}.client-card-breadcrumbs strong{padding:4px 8px;border-radius:5px;background:#dff7f0;color:#078d6c}.client-card-breadcrumbs .sep{color:#a5abb3}.client-card-close{margin-left:auto;width:30px;height:30px;border:0;border-radius:7px;background:#fff0f0;color:#dc4655;font-size:22px;line-height:1;cursor:pointer}.client-card-error{margin:10px 12px 0;padding:9px 12px;border:1px solid #f1b8b8;border-radius:7px;background:#fff4f4;color:#b42318;display:flex;justify-content:space-between}.client-card-error button{border:0;background:transparent;color:inherit;text-decoration:underline}.client-card-loading{padding:30px;color:#737c86}.client-card-hero{margin:12px 10px 10px;padding:18px 28px;min-height:96px;border-radius:9px;background:#f7f7f7;display:flex;align-items:center;justify-content:space-between;gap:22px}.client-card-title{display:flex;align-items:center;gap:16px}.client-avatar{width:38px;height:38px;border-radius:9px;background:#fff;display:grid;place-items:center;font-weight:700}.client-card-title>div{display:grid;gap:4px}.client-card-title strong{font-size:20px}.client-card-title small{color:#737b85}.manager-cards{display:flex;gap:10px}.manager-card{min-width:230px;padding:12px 16px;border-radius:8px;background:#fff;display:flex;align-items:center;gap:11px}.manager-icon{width:34px;height:34px;border-radius:7px;background:#e9f1ff;color:#3073da;display:grid;place-items:center;font-size:20px}.manager-card div{display:grid}.manager-card small{font-size:11px;color:#7b838d}.client-card-tabs{margin:0 10px 0;min-height:44px;border-radius:8px;background:#f4f4f4;display:grid;grid-template-columns:repeat(4,1fr);gap:4px;padding:4px}.client-card-tabs button{border:0;border-radius:8px;background:transparent;color:#555e69;font:inherit;font-weight:600;cursor:pointer}.client-card-tabs button.active{background:#fff;color:#20262d;box-shadow:0 1px 3px rgba(0,0,0,.08)}.client-card-tabs button span{margin-right:7px}.client-card-body{min-height:0;overflow:auto;padding:16px 18px 28px}.client-fields{display:grid;grid-template-columns:1fr 1fr;gap:14px 24px;max-width:1380px}.client-fields label{display:grid;grid-template-columns:250px minmax(0,1fr);align-items:center;gap:16px;color:#56606b;font-size:13px}.client-fields label.wide{grid-column:1/-1}.client-fields input,.client-fields textarea,.inline-form input,.inline-form textarea,.legal-row input,.note-composer textarea{width:100%;min-height:34px;padding:7px 10px;border:1px solid #dde1e7;border-radius:8px;background:#fff;color:#20262d;font:inherit;outline:0}.client-fields textarea{resize:vertical}.client-fields input:focus,.client-fields textarea:focus,.inline-form input:focus,.legal-row input:focus,.note-composer textarea:focus{border-color:#12b890;box-shadow:0 0 0 2px rgba(18,184,144,.1)}.about-actions{display:flex;justify-content:flex-end;max-width:1380px;margin:16px 0}.legal-section{margin-top:18px}.section-line{display:flex;align-items:center;justify-content:space-between}.section-line h3{margin:0 0 10px;font-size:14px}.legal-table{border:1px solid #dfe2e6;border-radius:8px;overflow:hidden}.legal-head,.legal-row{display:grid;grid-template-columns:1.7fr .7fr 120px;align-items:center;gap:10px}.legal-head{padding:9px;background:#f2f2f2;font-size:12px;font-weight:700}.legal-row{padding:7px 9px;border-top:1px solid #e5e7ea}.legal-row button,.text-button{border:0;background:transparent;color:#087f67;font-weight:600;cursor:pointer}.legal-new{background:#fafafa}.tab-toolbar{display:flex;justify-content:flex-start;margin-bottom:12px}.inline-form{display:flex;align-items:end;gap:9px;padding:12px;margin-bottom:12px;border:1px solid #e1e4e7;border-radius:8px;background:#fafafa}.contact-form input{flex:1}.report-form label{display:grid;gap:4px;font-size:11px;color:#606873}.card-table{border-top:1px solid #dfe2e6}.card-table-head,.card-table-row{display:grid;grid-template-columns:220px 220px 1fr 54px;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid #e4e6e9}.card-table-head{padding:9px 0;background:#f2f2f2;font-size:12px;font-weight:700}.card-table-head>*:first-child,.card-table-row>*:first-child{padding-left:16px}.contact-info{display:grid;gap:3px}.edit-button{width:28px;height:28px;border:0;border-radius:6px;background:#3375d6;color:#fff;cursor:pointer}.card-empty{padding:26px;color:#8a929c;text-align:center}.report-list{display:grid;gap:8px}.report-list article{min-height:48px;padding:8px 10px;border:1px solid #dfe2e6;border-radius:5px;display:flex;align-items:center;justify-content:space-between}.report-list article>div{display:grid;gap:3px}.report-list small{color:#7d858e}.status-pill{padding:4px 8px;border-radius:6px;background:#eef8f5;color:#087f67;font-size:11px}.note-composer{display:grid;gap:9px;max-width:900px;margin-bottom:18px}.note-composer .irlix-button{justify-self:end}.notes-history{display:grid;gap:10px;max-width:1000px}.notes-history article{padding:13px 15px;border:1px solid #e0e3e6;border-radius:8px;background:#fff}.note-meta{display:flex;justify-content:space-between;gap:12px;font-size:12px}.note-meta span{color:#848c96}.notes-history p{margin:8px 0 0;white-space:pre-wrap;line-height:1.45}@media(max-width:1000px){.client-card-overlay{padding:0}.client-card-modal{border-radius:0}.client-card-hero{align-items:flex-start;flex-direction:column}.manager-cards{width:100%;flex-wrap:wrap}.manager-card{flex:1;min-width:190px}.client-card-tabs{grid-template-columns:repeat(2,1fr)}.client-fields{grid-template-columns:1fr}.client-fields label,.client-fields label.wide{grid-column:auto;grid-template-columns:1fr;gap:5px}.card-table-head,.card-table-row{grid-template-columns:1fr 1fr}.card-table-head>*:nth-child(3),.card-table-row>*:nth-child(3){grid-column:1/-1}.inline-form{align-items:stretch;flex-direction:column}}
</style>

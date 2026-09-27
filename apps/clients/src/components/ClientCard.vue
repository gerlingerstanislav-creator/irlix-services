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
const editingField = ref('');
const editValue = ref(null);
const data = reactive({ client: null, contacts: [], reporting_periods: [], legal_entities: [], notes: [] });
const legalForm = reactive({ open: false, id: null, name: '', inn: '', full_name: '', ogrn: '', kpp: '', registration_date: '', okpo: '', oktmo: '', address: '' });
const contactForm = reactive({ open: false, id: null, full_name: '', position: '', phone: '', email: '' });
const reportForm = reactive({ open: false, period_start: '', period_end: '' });
const noteText = ref('');

const tabs = [
  { value: 'about', label: 'О клиенте', icon: 'ⓘ' },
  { value: 'contacts', label: 'Контакты', icon: '▣' },
  { value: 'reports', label: 'Отчётные периоды', icon: '▤' },
  { value: 'notes', label: 'Заметки', icon: '≡' },
];

const employeeOptions = computed(() => props.employees.map(employee => ({ value: String(employee.id), label: employee.full_name || `#${employee.id}` })).sort((a, b) => a.label.localeCompare(b.label, 'ru')));
const employeeName = id => props.employees.find(employee => Number(employee.id) === Number(id))?.full_name || (id ? `#${id}` : '—');
const techOptions = computed(() => [...new Set([...(props.technologyOptions || []).map(option => typeof option === 'object' ? option.value ?? option.label : option), ...((data.client?.technologies || []).filter(Boolean))])].filter(Boolean).sort((a, b) => String(a).localeCompare(String(b), 'ru')).map(value => ({ value, label: value })));
const initial = computed(() => String(data.client?.name || '?').trim().charAt(0).toUpperCase());

async function api(url, options = {}) {
  const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`);
  return body;
}

async function load() {
  if (!props.clientId) return;
  loading.value = true; error.value = '';
  try { Object.assign(data, (await api(`/api/clients/clients/${props.clientId}/card`)).data || {}); }
  catch (e) { error.value = e.message || String(e); }
  finally { loading.value = false; }
}

function startEdit(field) {
  editingField.value = field;
  const value = data.client?.[field];
  editValue.value = Array.isArray(value) ? [...value] : (value ?? '');
}
function cancelEdit() { editingField.value = ''; editValue.value = null; }
async function saveField(field, rawValue = editValue.value) {
  saving.value = true; error.value = '';
  try {
    let value = rawValue;
    if (['account_employee_id', 'sales_employee_id'].includes(field)) value = value ? Number(value) : null;
    if (['act_approval_days', 'payment_days'].includes(field)) value = value === '' || value === null ? null : Number(value);
    if (field === 'name' && !String(value || '').trim()) throw new Error('Название клиента обязательно');
    if (field === 'account_employee_id' && !value) throw new Error('Аккаунт-менеджер обязателен');
    data.client = (await api(`/api/clients/clients/${props.clientId}/card`, { method: 'PATCH', body: JSON.stringify({ [field]: value }) })).data;
    cancelEdit(); emit('changed');
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

function editContact(contact = null) {
  Object.assign(contactForm, { open: true, id: contact?.id || null, full_name: contact?.full_name || '', position: contact?.position || '', phone: contact?.phone || '', email: contact?.email || '' });
}
function closeContactForm() { contactForm.open = false; contactForm.id = null; }
async function saveContact() {
  saving.value = true; error.value = '';
  try {
    const payload = { full_name: contactForm.full_name, position: contactForm.position || null, phone: contactForm.phone || null, email: contactForm.email || null };
    if (contactForm.id) await api(`/api/clients/contacts/${contactForm.id}`, { method: 'PATCH', body: JSON.stringify(payload) });
    else {
      const created = await api('/api/clients/contacts', { method: 'POST', body: JSON.stringify(payload) });
      await api(`/api/clients/contacts/${created.data.id}/relations`, { method: 'POST', body: JSON.stringify({ entity_type: 'client', entity_id: props.clientId }) });
    }
    closeContactForm(); emit('changed'); await load();
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

function openLegal(entity = null) {
  legalForm.open = true; legalForm.id = entity?.id || null;
  for (const field of ['name','inn','full_name','ogrn','kpp','registration_date','okpo','oktmo','address']) legalForm[field] = entity?.[field] || '';
}
function closeLegal() { legalForm.open = false; legalForm.id = null; }
async function saveLegal() {
  saving.value = true; error.value = '';
  try {
    const payload = { name: legalForm.name, inn: legalForm.inn, full_name: legalForm.full_name, ogrn: legalForm.ogrn || null, kpp: legalForm.kpp || null, registration_date: legalForm.registration_date || null, okpo: legalForm.okpo || null, oktmo: legalForm.oktmo || null, address: legalForm.address || null };
    if (legalForm.id) await api(`/api/clients/legal-entities/${legalForm.id}`, { method: 'PATCH', body: JSON.stringify(payload) });
    else await api(`/api/clients/clients/${props.clientId}/legal-entities`, { method: 'POST', body: JSON.stringify(payload) });
    closeLegal(); await load();
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

async function addReport() {
  if (!reportForm.period_start || !reportForm.period_end) return;
  saving.value = true; error.value = '';
  try {
    await api('/api/clients/reporting-periods', { method: 'POST', body: JSON.stringify({ client_id: props.clientId, period_start: reportForm.period_start, period_end: reportForm.period_end }) });
    reportForm.open = false; reportForm.period_start = ''; reportForm.period_end = ''; emit('changed'); await load();
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

async function addNote() {
  if (!noteText.value.trim()) return;
  saving.value = true; error.value = '';
  try { await api(`/api/clients/clients/${props.clientId}/notes`, { method: 'POST', body: JSON.stringify({ text: noteText.value }) }); noteText.value = ''; await load(); }
  catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

const dateRu = value => value ? new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString('ru-RU') : '—';
const dateTimeRu = value => value ? new Date(value).toLocaleString('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
const textValue = value => value === null || value === undefined || value === '' ? '—' : value;
watch(() => props.clientId, value => { if (value) { activeTab.value = 'about'; cancelEdit(); load(); } }, { immediate: true });
</script>

<template>
<div v-if="clientId" class="client-card-overlay" @mousedown.self="emit('close')">
  <section class="client-card-drawer" role="dialog" aria-modal="true" :aria-label="data.client?.name || 'Карточка клиента'">
    <header class="client-card-breadcrumbs"><span>Клиенты</span><span class="sep">›</span><strong>{{ data.client?.name || 'Клиент' }}</strong><button type="button" class="client-card-close" aria-label="Закрыть" @click="emit('close')">×</button></header>
    <div v-if="error" class="client-card-error">{{ error }}<button type="button" @click="error=''">×</button></div>
    <div v-if="loading" class="client-card-loading">Загрузка карточки…</div>
    <template v-else-if="data.client">
      <section class="client-card-hero">
        <div class="client-card-title"><span class="client-avatar">{{ initial }}</span><div class="hero-name">
          <template v-if="editingField === 'name'"><input v-model="editValue" class="hero-input" @keyup.enter="saveField('name')" /><span class="edit-actions"><button @click="saveField('name')">✓</button><button @click="cancelEdit">×</button></span></template>
          <template v-else><strong>{{ data.client.name }}</strong><button class="pencil" aria-label="Редактировать название" @click="startEdit('name')">✎</button></template>
          <small>{{ data.client.type || 'Тип клиента не указан' }} · {{ data.client.sector || 'Сектор не указан' }}</small>
        </div></div>
        <div class="manager-cards">
          <div class="manager-card"><span class="manager-icon">♙</span><div class="manager-content">
            <template v-if="editingField === 'account_employee_id'"><UiSearchSelect v-model="editValue" :options="employeeOptions" :clearable="false" placeholder="Аккаунт-менеджер" search-placeholder="Поиск сотрудника" /><span class="edit-actions"><button @click="saveField('account_employee_id')">✓</button><button @click="cancelEdit">×</button></span></template>
            <template v-else><strong>{{ employeeName(data.client.account_employee_id) }}</strong><button class="pencil" @click="startEdit('account_employee_id')">✎</button></template><small>Аккаунт менеджер</small>
          </div></div>
          <div class="manager-card"><span class="manager-icon">♙</span><div class="manager-content">
            <template v-if="editingField === 'sales_employee_id'"><UiSearchSelect v-model="editValue" :options="employeeOptions" placeholder="Sales-менеджер" search-placeholder="Поиск сотрудника" /><span class="edit-actions"><button @click="saveField('sales_employee_id')">✓</button><button @click="cancelEdit">×</button></span></template>
            <template v-else><strong>{{ employeeName(data.client.sales_employee_id) }}</strong><button class="pencil" @click="startEdit('sales_employee_id')">✎</button></template><small>Sales менеджер</small>
          </div></div>
        </div>
      </section>
      <nav class="client-card-tabs" aria-label="Разделы карточки клиента"><button v-for="tab in tabs" :key="tab.value" type="button" :class="{ active: activeTab === tab.value }" @click="activeTab = tab.value"><span>{{ tab.icon }}</span>{{ tab.label }}</button></nav>
      <main class="client-card-body">
        <section v-if="activeTab === 'about'" class="about-tab">
          <div class="client-fields-one-column">
            <article v-for="field in [{key:'type',label:'Тип клиента'},{key:'sector',label:'Сектор клиента'},{key:'act_approval_days',label:'Срок согласования акта (в днях)',type:'number'},{key:'payment_days',label:'Срок оплаты (в днях)',type:'number'}]" :key="field.key" class="editable-row"><span class="field-label">{{ field.label }}</span><div class="field-value"><template v-if="editingField === field.key"><input v-model="editValue" :type="field.type || 'text'" min="0" /><span class="edit-actions"><button @click="saveField(field.key)">✓</button><button @click="cancelEdit">×</button></span></template><template v-else><span>{{ textValue(data.client[field.key]) }}</span><button class="pencil" @click="startEdit(field.key)">✎</button></template></div></article>
            <article class="editable-row align-start"><span class="field-label">Описание клиента</span><div class="field-value"><template v-if="editingField === 'description'"><textarea v-model="editValue" rows="5"></textarea><span class="edit-actions"><button @click="saveField('description')">✓</button><button @click="cancelEdit">×</button></span></template><template v-else><span class="multiline">{{ textValue(data.client.description) }}</span><button class="pencil" @click="startEdit('description')">✎</button></template></div></article>
            <article class="editable-row align-start"><span class="field-label">Технологии</span><div class="field-value technologies-value"><template v-if="editingField === 'technologies'"><UiSearchSelect v-model="editValue" :options="techOptions" multiple placeholder="Технологии" search-placeholder="Поиск технологии" /><span class="edit-actions"><button @click="saveField('technologies')">✓</button><button @click="cancelEdit">×</button></span></template><template v-else><div class="chips"><span v-for="technology in data.client.technologies || []" :key="technology">{{ technology }}</span><em v-if="!(data.client.technologies || []).length">—</em></div><button class="pencil" @click="startEdit('technologies')">✎</button></template></div></article>
          </div>
          <section class="legal-section"><div class="section-line"><h3>Юридические лица</h3><UiButton variant="secondary" @click="openLegal()">＋ Добавить</UiButton></div><div class="legal-table"><div class="legal-head"><span>Название</span><span>ИНН</span><span>Полное название</span><span></span></div><button v-for="entity in data.legal_entities" :key="entity.id" type="button" class="legal-row" @click="openLegal(entity)"><strong>{{ entity.name }}</strong><span>{{ entity.inn }}</span><span>{{ entity.full_name }}</span><span class="edit-button">✎</span></button><div v-if="!data.legal_entities.length" class="card-empty">Юридические лица не добавлены</div></div></section>
        </section>
        <section v-else-if="activeTab === 'contacts'" class="contacts-tab"><div class="tab-toolbar"><UiButton @click="editContact()">＋ Новый контакт</UiButton></div><form v-if="contactForm.open" class="inline-form contact-form" @submit.prevent="saveContact"><input v-model="contactForm.full_name" required placeholder="Имя" /><input v-model="contactForm.position" placeholder="Должность" /><input v-model="contactForm.phone" placeholder="Телефон" /><input v-model="contactForm.email" type="email" placeholder="Email" /><UiButton type="submit" :disabled="saving">{{ contactForm.id ? 'Сохранить' : 'Добавить' }}</UiButton><button type="button" class="text-button" @click="closeContactForm">Отмена</button></form><div class="card-table contacts-table"><div class="card-table-head"><span>Имя</span><span>Должность</span><span>Контактная информация</span><span></span></div><div v-for="contact in data.contacts" :key="contact.id" class="card-table-row"><strong>{{ contact.full_name }}</strong><span>{{ contact.position || '—' }}</span><span class="contact-info"><span v-if="contact.phone">{{ contact.phone }}</span><span v-if="contact.email">{{ contact.email }}</span><span v-if="!contact.phone && !contact.email">—</span></span><button type="button" class="edit-button" @click="editContact(contact)">✎</button></div><div v-if="!data.contacts.length" class="card-empty">У клиента пока нет контактных лиц</div></div></section>
        <section v-else-if="activeTab === 'reports'" class="reports-tab"><div class="tab-toolbar"><UiButton @click="reportForm.open = !reportForm.open">＋ Новый отчётный период</UiButton></div><form v-if="reportForm.open" class="inline-form report-form" @submit.prevent="addReport"><label>Начало<input v-model="reportForm.period_start" type="date" required /></label><label>Конец<input v-model="reportForm.period_end" type="date" required /></label><UiButton type="submit" :disabled="saving">Добавить</UiButton></form><div class="report-list"><article v-for="period in data.reporting_periods" :key="period.id"><div><strong>Отчётный период {{ dateRu(period.period_start) }} - {{ dateRu(period.period_end) }}</strong><small>{{ period.status }}</small></div><span class="status-pill">{{ period.status }}</span></article><div v-if="!data.reporting_periods.length" class="card-empty">Отчётных периодов пока нет</div></div></section>
        <section v-else class="notes-tab"><form class="note-composer" @submit.prevent="addNote"><textarea v-model="noteText" rows="4" placeholder="Новая заметка аккаунт-менеджера"></textarea><UiButton type="submit" :disabled="saving || !noteText.trim()">Добавить заметку</UiButton></form><div class="notes-history"><article v-for="note in data.notes" :key="note.id"><div class="note-meta"><strong>{{ note.created_by_username || 'Аккаунт-менеджер' }}</strong><span>{{ dateTimeRu(note.created_at) }}</span></div><p>{{ note.text }}</p></article><div v-if="!data.notes.length" class="card-empty">Заметок пока нет</div></div></section>
      </main>
    </template>
    <div v-if="legalForm.open" class="nested-overlay" @mousedown.self="closeLegal"><form class="legal-drawer" @submit.prevent="saveLegal"><header><h2>{{ legalForm.id ? 'Редактировать юридическое лицо' : 'Добавить юридическое лицо' }}</h2><button type="button" @click="closeLegal">×</button></header><div class="legal-form-body"><label>Название<span>*</span><input v-model="legalForm.name" required /></label><label>ИНН<span>*</span><input v-model="legalForm.inn" required /></label><label>Полное название<span>*</span><input v-model="legalForm.full_name" required /></label><div class="legal-form-grid"><label>ОГРН<input v-model="legalForm.ogrn" /></label><label>КПП<input v-model="legalForm.kpp" /></label></div><label>Дата регистрации<input v-model="legalForm.registration_date" type="date" /></label><div class="legal-form-grid"><label>ОКПО<input v-model="legalForm.okpo" /></label><label>ОКТМО<input v-model="legalForm.oktmo" /></label></div><label>Адрес<input v-model="legalForm.address" /></label></div><footer><UiButton type="submit" :disabled="saving || !legalForm.name.trim() || !legalForm.inn.trim() || !legalForm.full_name.trim()">✓ {{ legalForm.id ? 'Сохранить' : 'Добавить' }}</UiButton></footer></form></div>
  </section>
</div>
</template>

<style scoped>
.client-card-overlay{position:fixed;inset:0;z-index:500;background:rgba(18,25,32,.28);display:flex;justify-content:flex-end}.client-card-drawer{position:relative;width:min(88vw,1500px);height:100%;min-width:0;overflow:hidden;background:#fff;border-left:1px solid #e1e4e8;box-shadow:-18px 0 50px rgba(18,25,32,.18);display:grid;grid-template-rows:42px auto auto minmax(0,1fr);animation:drawer-in .18s ease-out}.client-card-breadcrumbs{display:flex;align-items:center;gap:10px;padding:0 16px;border-bottom:1px solid #e4e6e9;color:#56606b;font-size:12px}.client-card-breadcrumbs strong{padding:4px 8px;border-radius:5px;background:#dff7f0;color:#078d6c}.client-card-breadcrumbs .sep{color:#a5abb3}.client-card-close{margin-left:auto;width:30px;height:30px;border:0;border-radius:7px;background:transparent;color:#555;font-size:22px;line-height:1;cursor:pointer}.client-card-error{margin:8px 12px 0;padding:9px 12px;border:1px solid #f1b8b8;border-radius:7px;background:#fff4f4;color:#b42318;display:flex;justify-content:space-between}.client-card-error button{border:0;background:transparent;color:inherit}.client-card-loading{padding:30px;color:#737c86}.client-card-hero{margin:12px 10px 10px;padding:16px 24px;min-height:96px;border-radius:9px;background:#f7f7f7;display:flex;align-items:center;justify-content:space-between;gap:20px}.client-card-title{display:flex;align-items:center;gap:14px;min-width:280px}.client-avatar{width:38px;height:38px;border-radius:9px;background:#fff;display:grid;place-items:center;font-weight:700}.hero-name{display:grid;grid-template-columns:auto auto;align-items:center;gap:4px 6px}.hero-name strong{font-size:20px}.hero-name small{grid-column:1/-1;color:#737b85}.hero-input{font-size:18px;font-weight:700;min-width:250px}.manager-cards{display:flex;gap:10px}.manager-card{min-width:230px;padding:11px 14px;border-radius:8px;background:#fff;display:flex;align-items:center;gap:10px}.manager-icon{width:34px;height:34px;border-radius:7px;background:#e9f1ff;color:#3073da;display:grid;place-items:center;font-size:20px}.manager-content{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:3px 5px;min-width:0;flex:1}.manager-content small{grid-column:1/-1;font-size:11px;color:#7b838d}.manager-content .ui-search-select{grid-column:1/-1}.pencil{border:0;background:transparent;color:#63707d;cursor:pointer;font-size:15px;padding:3px 5px}.pencil:hover{color:#0a9474}.edit-actions{display:inline-flex;gap:4px}.edit-actions button{width:27px;height:27px;border:1px solid #dfe3e7;border-radius:6px;background:#fff;color:#087f67;cursor:pointer}.client-card-tabs{margin:0 10px;min-height:44px;border-radius:8px;background:#f4f4f4;display:grid;grid-template-columns:repeat(4,1fr);gap:4px;padding:4px}.client-card-tabs button{border:0;border-radius:8px;background:transparent;color:#555e69;font:inherit;font-weight:600;cursor:pointer}.client-card-tabs button.active{background:#fff;color:#20262d;box-shadow:0 1px 3px rgba(0,0,0,.08)}.client-card-tabs button span{margin-right:7px}.client-card-body{min-height:0;overflow:auto;padding:16px 18px 28px}.client-fields-one-column{display:grid;gap:0;max-width:1180px}.editable-row{display:grid;grid-template-columns:270px minmax(0,1fr);align-items:center;gap:18px;min-height:54px;border-bottom:1px solid #eef0f2}.editable-row.align-start{align-items:start;padding:11px 0}.field-label{color:#59636e;font-size:13px}.field-value{display:flex;align-items:center;gap:8px;min-width:0}.field-value input,.field-value textarea{width:min(760px,100%);min-height:34px;padding:7px 10px;border:1px solid #dde1e7;border-radius:8px;font:inherit;outline:0}.field-value textarea{resize:vertical}.field-value .ui-search-select{width:min(760px,100%)}.multiline{white-space:pre-wrap;line-height:1.45}.technologies-value{align-items:flex-start}.chips{display:flex;flex-wrap:wrap;gap:6px}.chips span{padding:4px 7px;border-radius:5px;background:#dff7f0;color:#078d6c;font-size:12px}.chips em{font-style:normal;color:#8a929c}.legal-section{margin-top:24px}.section-line{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.section-line h3{margin:0;font-size:14px}.legal-table{border:1px solid #dfe2e6;border-radius:8px;overflow:hidden}.legal-head,.legal-row{display:grid;grid-template-columns:1fr .45fr 1.6fr 42px;align-items:center;gap:10px}.legal-head{padding:9px 12px;background:#f2f2f2;font-size:12px;font-weight:700}.legal-row{width:100%;padding:10px 12px;border:0;border-top:1px solid #e5e7ea;background:#fff;text-align:left;font:inherit;cursor:pointer}.legal-row:hover{background:#fafbfb}.tab-toolbar{display:flex;justify-content:flex-start;margin-bottom:12px}.inline-form{display:flex;align-items:end;gap:9px;padding:12px;margin-bottom:12px;border:1px solid #e1e4e7;border-radius:8px;background:#fafafa}.inline-form input,.note-composer textarea{width:100%;min-height:34px;padding:7px 10px;border:1px solid #dde1e7;border-radius:8px;background:#fff;color:#20262d;font:inherit;outline:0}.contact-form input{flex:1}.report-form label{display:grid;gap:4px;font-size:11px;color:#606873}.text-button{border:0;background:transparent;color:#087f67;font-weight:600;cursor:pointer}.card-table{border-top:1px solid #dfe2e6}.card-table-head,.card-table-row{display:grid;grid-template-columns:220px 220px 1fr 54px;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid #e4e6e9}.card-table-head{padding:9px 0;background:#f2f2f2;font-size:12px;font-weight:700}.card-table-head>*:first-child,.card-table-row>*:first-child{padding-left:16px}.contact-info{display:grid;gap:3px}.edit-button{width:28px;height:28px;border:0;border-radius:6px;background:#3375d6;color:#fff;display:grid;place-items:center;cursor:pointer}.card-empty{padding:26px;color:#8a929c;text-align:center}.report-list{display:grid;gap:8px}.report-list article{min-height:48px;padding:8px 10px;border:1px solid #dfe2e6;border-radius:5px;display:flex;align-items:center;justify-content:space-between}.report-list article>div{display:grid;gap:3px}.report-list small{color:#7d858e}.status-pill{padding:4px 8px;border-radius:6px;background:#eef8f5;color:#087f67;font-size:11px}.note-composer{display:grid;gap:9px;max-width:900px;margin-bottom:18px}.note-composer .irlix-button{justify-self:end}.notes-history{display:grid;gap:10px;max-width:1000px}.notes-history article{padding:13px 15px;border:1px solid #e0e3e6;border-radius:8px;background:#fff}.note-meta{display:flex;justify-content:space-between;gap:12px;font-size:12px}.note-meta span{color:#848c96}.notes-history p{margin:8px 0 0;white-space:pre-wrap;line-height:1.45}.nested-overlay{position:absolute;inset:0;z-index:20;background:rgba(18,25,32,.2);display:flex;justify-content:flex-end}.legal-drawer{width:min(575px,100%);height:100%;background:#fff;box-shadow:-14px 0 36px rgba(18,25,32,.18);display:grid;grid-template-rows:62px minmax(0,1fr) 64px}.legal-drawer header{display:flex;align-items:center;justify-content:space-between;padding:0 18px;border-bottom:1px solid #e5e7ea}.legal-drawer h2{margin:0;font-size:21px}.legal-drawer header button{border:0;background:transparent;font-size:24px;cursor:pointer}.legal-form-body{overflow:auto;padding:20px 18px;display:grid;align-content:start;gap:8px}.legal-form-body label{display:grid;gap:5px;color:#68717c;font-size:13px}.legal-form-body label>span{color:#e3484f}.legal-form-body input{width:100%;min-height:36px;padding:7px 10px;border:1px solid #d8dce1;border-radius:10px;font:inherit}.legal-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.legal-drawer footer{display:flex;justify-content:flex-end;align-items:center;padding:10px 18px;border-top:1px solid #e5e7ea;background:#f7f7f7}@keyframes drawer-in{from{transform:translateX(24px);opacity:.7}to{transform:translateX(0);opacity:1}}@media(max-width:1050px){.client-card-drawer{width:100vw}.client-card-hero{align-items:flex-start;flex-direction:column}.manager-cards{width:100%;flex-wrap:wrap}.manager-card{flex:1;min-width:210px}.editable-row{grid-template-columns:1fr;gap:5px;padding:10px 0}.card-table-head,.card-table-row{grid-template-columns:1fr 1fr}.card-table-head>*:nth-child(3),.card-table-row>*:nth-child(3){grid-column:1/-1}.inline-form{align-items:stretch;flex-direction:column}}@media(max-width:700px){.client-card-tabs{grid-template-columns:repeat(2,1fr)}.legal-head{display:none}.legal-row{grid-template-columns:1fr 1fr}.legal-row>*:nth-child(3){grid-column:1/-1}.manager-cards{display:grid}.legal-form-grid{grid-template-columns:1fr}}
</style>

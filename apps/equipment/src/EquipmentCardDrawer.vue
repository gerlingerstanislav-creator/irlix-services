<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiTabs } from '@irlix/ui';

const props = defineProps({
  itemId: { type: Number, required: true },
  employees: { type: Array, default: () => [] },
  canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'updated']);

const loading = ref(false);
const error = ref('');
const item = ref(null);
const tab = ref('description');
const editField = ref(null);
const editValue = ref('');

const labels = {
  pc: 'ПК', laptop: 'Ноутбук', smartphone: 'Смартфон', tablet: 'Планшет',
  development: 'Разработка', management_qa: 'Менеджмент + QA', qa: 'QA', management: 'Менеджмент',
  ok: 'Исправно', damaged: 'Повреждено', needs_repair: 'Требует ремонта',
};

const tabs = computed(() => [
  { value: 'description', label: 'Описание' },
  { value: 'assignments', label: 'История выдачи', count: item.value?.assignments?.length ?? 0 },
  { value: 'cost', label: 'Стоимость' },
]);

const fields = computed(() => [
  { title: 'Основные данные', rows: [
    { key: 'inventory_number', label: 'Инвентарный номер' },
    { key: 'type', label: 'Тип', type: 'select', options: ['pc', 'laptop', 'smartphone', 'tablet'] },
    { key: 'manufacturer', label: 'Изготовитель' },
    { key: 'model', label: 'Модель' },
    { key: 'serial_number', label: 'Серийный номер' },
    { key: 'purpose', label: 'Назначение', type: 'select', options: ['development', 'management_qa', 'qa', 'management'] },
    { key: 'condition', label: 'Состояние', type: 'select', options: ['ok', 'damaged', 'needs_repair'] },
    { key: 'comment', label: 'Комментарий', type: 'textarea' },
  ]},
  { title: 'Характеристики', rows: [
    { key: 'cpu', label: 'Процессор' },
    { key: 'ram', label: 'ОЗУ' },
    { key: 'storage', label: 'HDD / SSD' },
    { key: 'gpu', label: 'Видеокарта' },
    { key: 'os', label: 'ОС' },
    { key: 'os_version', label: 'Версия ОС' },
    ...(['smartphone', 'tablet'].includes(item.value?.type) ? [{ key: 'imei', label: 'IMEI' }] : []),
  ]},
]);

const request = async (url, options = {}) => {
  const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers ?? {}) } });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
  return payload.data;
};

const load = async () => {
  loading.value = true;
  error.value = '';
  try { item.value = await request(`/api/items/${props.itemId}`); }
  catch (e) { error.value = e.message; }
  finally { loading.value = false; }
};

const shortEmployeeName = (id) => {
  const full = props.employees.find((employee) => Number(employee.id) === Number(id))?.full_name || `#${id}`;
  return full.trim().split(/\s+/).slice(0, 2).join(' ');
};
const formatDate = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString('ru-RU') : '—';
const money = (value) => value == null ? '—' : new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 2 }).format(Number(value));
const display = (field) => {
  const value = item.value?.[field.key];
  if (field.type === 'select') return labels[value] || value || '—';
  return value === null || value === undefined || value === '' ? '—' : value;
};

const startEdit = (field) => {
  if (!props.canManage) return;
  editField.value = field.key;
  editValue.value = item.value?.[field.key] ?? '';
};
const cancelEdit = () => { editField.value = null; editValue.value = ''; };
const saveField = async (field) => {
  try {
    const value = editValue.value === '' ? null : editValue.value;
    await request(`/api/items/${props.itemId}`, { method: 'PATCH', body: JSON.stringify({ [field.key]: value }) });
    cancelEdit();
    await load();
    emit('updated');
  } catch (e) { error.value = e.message; }
};

watch(() => props.itemId, () => { tab.value = 'description'; cancelEdit(); load(); });
onMounted(load);
</script>

<template>
  <UiDrawer :open="true" width="720px" :min-width="520" @close="emit('close')">
    <template #title>
      <div class="equipment-drawer-title">
        <strong>{{ item?.inventory_number || 'Карточка техники' }}</strong>
        <span v-if="item">{{ item.manufacturer }} {{ item.model }}</span>
      </div>
    </template>

    <div v-if="loading" class="equipment-drawer-state">Загрузка…</div>
    <div v-else-if="error" class="equipment-card-error">{{ error }}</div>
    <template v-else-if="item">
      <section class="equipment-card-hero">
        <div>
          <div class="equipment-card-kicker">{{ labels[item.type] }}</div>
          <h2>{{ item.manufacturer }} {{ item.model }}</h2>
          <p>{{ item.serial_number || 'Серийный номер не указан' }}</p>
        </div>
        <UiBadge :tone="item.condition === 'ok' ? 'success' : item.condition === 'damaged' ? 'warning' : 'danger'">{{ labels[item.condition] }}</UiBadge>
      </section>

      <UiTabs v-model="tab" :items="tabs" />
      <div v-if="error" class="equipment-card-error">{{ error }}</div>

      <section v-if="tab === 'description'" class="equipment-card-content">
        <div v-for="section in fields" :key="section.title" class="equipment-info-section">
          <h3>{{ section.title }}</h3>
          <dl>
            <div v-for="field in section.rows" :key="field.key" class="equipment-attribute">
              <dt>{{ field.label }}</dt>
              <dd v-if="editField !== field.key">{{ display(field) }}</dd>
              <dd v-else class="equipment-attribute-editor">
                <select v-if="field.type === 'select'" v-model="editValue">
                  <option v-for="option in field.options" :key="option" :value="option">{{ labels[option] || option }}</option>
                </select>
                <textarea v-else-if="field.type === 'textarea'" v-model="editValue" rows="3" />
                <input v-else v-model="editValue" />
                <div class="equipment-editor-actions">
                  <UiButton compact @click="saveField(field)">✓</UiButton>
                  <UiButton compact variant="secondary" @click="cancelEdit">×</UiButton>
                </div>
              </dd>
              <button v-if="canManage && editField !== field.key" type="button" class="equipment-pencil" :aria-label="`Редактировать ${field.label}`" @click="startEdit(field)">✎</button>
            </div>
          </dl>
        </div>
      </section>

      <section v-else-if="tab === 'assignments'" class="equipment-card-content">
        <div v-if="!item.assignments?.length" class="equipment-drawer-state">Истории выдачи пока нет.</div>
        <div v-else class="assignment-history">
          <article v-for="record in item.assignments" :key="record.id" class="assignment-history-row">
            <div><strong>{{ shortEmployeeName(record.employee_id) }}</strong><span>{{ formatDate(record.starts_on) }} — {{ record.returned_on ? formatDate(record.returned_on) : 'по настоящее время' }}</span></div>
            <p v-if="record.issue_comment"><b>При выдаче:</b> {{ record.issue_comment }}</p>
            <p v-if="record.return_comment"><b>После возврата:</b> {{ record.return_comment }}</p>
          </article>
        </div>
      </section>

      <section v-else class="equipment-card-content">
        <div class="cost-grid">
          <div><span>Дата приобретения</span><strong>{{ formatDate(item.purchased_on) }}</strong></div>
          <div><span>Стоимость закупки</span><strong>{{ money(item.purchase_cost) }}</strong></div>
          <div><span>Срок амортизации</span><strong>{{ item.useful_life_months ? `${item.useful_life_months} мес.` : '—' }}</strong></div>
          <div><span>Амортизация в месяц</span><strong>{{ money(item.depreciation?.monthly) }}</strong></div>
          <div><span>Накопленная амортизация</span><strong>{{ money(item.depreciation?.accumulated) }}</strong></div>
          <div><span>Остаточная стоимость</span><strong>{{ money(item.depreciation?.residual) }}</strong></div>
        </div>
        <div class="damage-accounting-note">
          <h3>Повреждения</h3>
          <p><strong>Текущее состояние:</strong> {{ labels[item.condition] }}</p>
          <p>В текущей версии повреждения фиксируются состоянием и комментарием, но ещё не уменьшают расчётную остаточную стоимость. Модель финансового влияния повреждений будет добавлена после согласования правила оценки.</p>
        </div>
      </section>
    </template>
  </UiDrawer>
</template>

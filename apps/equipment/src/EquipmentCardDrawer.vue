<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiSearchSelect, UiTabs } from '@irlix/ui';

const props = defineProps({
  itemId: { type: Number, required: true },
  employees: { type: Array, default: () => [] },
  canManage: { type: Boolean, default: false },
  canOperate: { type: Boolean, default: false },
  refreshKey: { type: Number, default: 0 },
});
const emit = defineEmits(['close', 'updated', 'write-off', 'assign', 'return']);

const loading = ref(false);
const error = ref('');
const item = ref(null);
const tab = ref('description');
const editField = ref(null);
const editValue = ref('');
const assignmentEdit = reactive({ id: null, employee_id: '', starts_on: '', returned_on: '', issue_comment: '', return_comment: '' });
const damageForm = reactive({ open: false, id: null, occurred_on: '', description: '', repair_cost: '', repaired_on: '', value_loss: '' });

const labels = {
  pc: 'ПК', laptop: 'Ноутбук', smartphone: 'Смартфон', tablet: 'Планшет',
  development: 'Разработка', management_qa: 'Менеджмент + QA', qa: 'QA', management: 'Менеджмент',
  ok: 'Исправно', damaged: 'Повреждено', needs_repair: 'Требует ремонта',
};
const tabs = computed(() => [
  { value: 'description', label: 'Описание' },
  { value: 'assignments', label: 'История выдачи', count: item.value?.assignments?.length ?? 0 },
  { value: 'cost', label: 'Стоимость' },
  { value: 'damages', label: 'Повреждения', count: item.value?.damages?.length ?? 0 },
]);
const employeeOptions = computed(() => {
  const grouped = new Map();
  for (const employee of props.employees) {
    const group = employee.department_name || 'Без подразделения';
    if (!grouped.has(group)) grouped.set(group, []);
    grouped.get(group).push(employee);
  }
  return [...grouped.entries()].sort(([a],[b]) => a.localeCompare(b, 'ru')).flatMap(([department, rows]) => [
    { value: `group:${department}`, label: department, kind: 'group' },
    ...rows.sort((a,b) => String(a.full_name).localeCompare(String(b.full_name),'ru')).map((employee) => ({ value: String(employee.id), label: employee.full_name, depth: 1 })),
  ]);
});
const fields = computed(() => [
  { title: 'Основные данные', rows: [
    { key: 'inventory_number', label: 'Инвентарный номер' },
    { key: 'type', label: 'Тип', type: 'select', options: ['pc','laptop','smartphone','tablet'] },
    { key: 'manufacturer', label: 'Изготовитель' },
    { key: 'model', label: 'Модель' },
    { key: 'serial_number', label: 'Серийный номер' },
    { key: 'purpose', label: 'Назначение', type: 'select', options: ['development','management_qa','qa','management'] },
    { key: 'condition', label: 'Состояние', type: 'select', options: ['ok','damaged','needs_repair'] },
    { key: 'comment', label: 'Комментарий', type: 'textarea' },
  ]},
  { title: 'Характеристики', rows: [
    { key: 'cpu', label: 'Процессор' }, { key: 'ram', label: 'ОЗУ' }, { key: 'storage', label: 'HDD / SSD' },
    { key: 'gpu', label: 'Видеокарта' }, { key: 'os', label: 'ОС' }, { key: 'os_version', label: 'Версия ОС' },
    ...(['smartphone','tablet'].includes(item.value?.type) ? [{ key: 'imei', label: 'IMEI' }] : []),
  ]},
]);
const costFields = computed(() => [
  { key: 'purchased_on', label: 'Дата приобретения', type: 'date' },
  { key: 'purchase_cost', label: 'Стоимость закупки', type: 'number' },
  { key: 'useful_life_months', label: 'Срок амортизации', type: 'number' },
]);

const request = async (url, options = {}) => {
  const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers ?? {}) } });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
  return payload.data;
};
const load = async () => { loading.value = true; error.value = ''; try { item.value = await request(`/api/items/${props.itemId}`); } catch(e) { error.value = e.message; } finally { loading.value = false; } };
const shortEmployeeName = (id) => { const full = props.employees.find((e) => Number(e.id) === Number(id))?.full_name || `#${id}`; return full.trim().split(/\s+/).slice(0,2).join(' '); };
const formatDate = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString('ru-RU') : '—';
const money = (value) => value == null ? '—' : new Intl.NumberFormat('ru-RU',{style:'currency',currency:'RUB',maximumFractionDigits:2}).format(Number(value));
const display = (field) => { const value=item.value?.[field.key]; if(field.type==='select')return labels[value]||value||'—'; if(field.type==='date')return formatDate(value); if(field.key==='purchase_cost')return money(value); if(field.key==='useful_life_months')return value?`${value} мес.`:'—'; return value===null||value===undefined||value===''?'—':value; };
const startEdit = (field) => { if(!props.canManage)return; editField.value=field.key; editValue.value=item.value?.[field.key]??''; };
const cancelEdit = () => { editField.value=null; editValue.value=''; };
const saveField = async (field) => { try { let value=editValue.value===''?null:editValue.value; if(field.type==='number'&&value!==null)value=Number(value); await request(`/api/items/${props.itemId}`,{method:'PATCH',body:JSON.stringify({[field.key]:value})}); cancelEdit(); await load(); emit('updated'); } catch(e){ error.value=e.message; } };

const startAssignmentEdit = (record) => Object.assign(assignmentEdit,{ id:record.id, employee_id:String(record.employee_id), starts_on:record.starts_on, returned_on:record.returned_on, issue_comment:record.issue_comment||'', return_comment:record.return_comment||'' });
const cancelAssignmentEdit = () => Object.assign(assignmentEdit,{ id:null, employee_id:'', starts_on:'', returned_on:'', issue_comment:'', return_comment:'' });
const saveAssignment = async () => { try { await request(`/api/assignments/${assignmentEdit.id}`,{method:'PATCH',body:JSON.stringify({employee_id:Number(assignmentEdit.employee_id),starts_on:assignmentEdit.starts_on,returned_on:assignmentEdit.returned_on,issue_comment:assignmentEdit.issue_comment||null,return_comment:assignmentEdit.return_comment||null})}); cancelAssignmentEdit(); await load(); emit('updated'); } catch(e){ error.value=e.message; } };
const deleteAssignment = async (record) => { if(!confirm(`Удалить выдачу ${shortEmployeeName(record.employee_id)} ${formatDate(record.starts_on)} — ${formatDate(record.returned_on)}?`))return; try { await request(`/api/assignments/${record.id}`,{method:'DELETE'}); await load(); emit('updated'); } catch(e){error.value=e.message;} };

const resetDamage = () => Object.assign(damageForm,{open:false,id:null,occurred_on:'',description:'',repair_cost:'',repaired_on:'',value_loss:''});
const newDamage = () => Object.assign(damageForm,{open:true,id:null,occurred_on:new Date().toISOString().slice(0,10),description:'',repair_cost:'',repaired_on:'',value_loss:''});
const editDamage = (damage) => Object.assign(damageForm,{open:true,id:damage.id,occurred_on:damage.occurred_on,description:damage.description,repair_cost:String(damage.repair_cost??''),repaired_on:damage.repaired_on||'',value_loss:String(damage.value_loss??'')});
const saveDamage = async () => { try { const payload={occurred_on:damageForm.occurred_on,description:damageForm.description,repair_cost:Number(damageForm.repair_cost||0),repaired_on:damageForm.repaired_on||null,value_loss:Number(damageForm.value_loss||0)}; await request(damageForm.id?`/api/damages/${damageForm.id}`:`/api/items/${props.itemId}/damages`,{method:damageForm.id?'PATCH':'POST',body:JSON.stringify(payload)}); resetDamage(); await load(); emit('updated'); } catch(e){error.value=e.message;} };
const deleteDamage = async (damage) => { if(!confirm('Удалить запись о повреждении?'))return; try { await request(`/api/damages/${damage.id}`,{method:'DELETE'}); await load(); emit('updated'); } catch(e){error.value=e.message;} };

watch(() => props.itemId, () => { tab.value='description'; cancelEdit(); cancelAssignmentEdit(); resetDamage(); load(); });
watch(() => props.refreshKey, load);
onMounted(load);
</script>

<template>
  <UiDrawer :open="true" width="760px" :min-width="540" @close="emit('close')">
    <template #title>
      <div v-if="item" class="equipment-drawer-meta">
        <span>{{ labels[item.type] }}</span>
        <UiBadge :tone="item.condition === 'ok' ? 'success' : item.condition === 'damaged' ? 'warning' : 'danger'">{{ labels[item.condition] }}</UiBadge>
      </div>
    </template>
    <template #actions><UiButton v-if="item && canOperate && !item.written_off_at && !item.assignment" compact variant="danger" @click="emit('write-off', item)">Списать</UiButton></template>

    <div v-if="loading" class="equipment-drawer-state">Загрузка…</div>
    <div v-else-if="error && !item" class="equipment-card-error">{{ error }}</div>
    <template v-else-if="item">
      <div class="equipment-tabs-full"><UiTabs v-model="tab" :items="tabs" /></div>
      <div v-if="error" class="equipment-card-error">{{ error }}</div>

      <section v-if="tab === 'description'" class="equipment-card-content">
        <div v-for="section in fields" :key="section.title" class="equipment-info-section">
          <h3>{{ section.title }}</h3><dl>
            <div v-for="field in section.rows" :key="field.key" class="equipment-attribute">
              <dt>{{ field.label }}</dt><dd v-if="editField !== field.key">{{ display(field) }}</dd>
              <dd v-else class="equipment-attribute-editor"><select v-if="field.type==='select'" v-model="editValue"><option v-for="option in field.options" :key="option" :value="option">{{ labels[option]||option }}</option></select><textarea v-else-if="field.type==='textarea'" v-model="editValue" rows="3"></textarea><input v-else v-model="editValue"><div class="equipment-editor-actions"><UiButton compact @click="saveField(field)">✓</UiButton><UiButton compact variant="secondary" @click="cancelEdit">×</UiButton></div></dd>
              <button v-if="canManage && editField !== field.key" type="button" class="equipment-pencil" @click="startEdit(field)">✎</button>
            </div>
          </dl>
        </div>
      </section>

      <section v-else-if="tab === 'assignments'" class="equipment-card-content">
        <div v-if="canOperate && !item.written_off_at" class="equipment-tab-actions"><UiButton v-if="item.assignment" compact @click="emit('return', item)">Вернуть технику</UiButton><UiButton v-else compact @click="emit('assign', item)">Выдать технику</UiButton></div>
        <div v-if="!item.assignments?.length" class="equipment-drawer-state">Истории выдачи пока нет.</div>
        <div v-else class="assignment-history">
          <article v-for="record in item.assignments" :key="record.id" class="assignment-history-row">
            <template v-if="assignmentEdit.id !== record.id">
              <div><strong>{{ shortEmployeeName(record.employee_id) }}</strong><span>{{ formatDate(record.starts_on) }} — {{ record.returned_on ? formatDate(record.returned_on) : 'по настоящее время' }}</span></div>
              <p v-if="record.issue_comment"><b>При выдаче:</b> {{ record.issue_comment }}</p><p v-if="record.return_comment"><b>После возврата:</b> {{ record.return_comment }}</p>
              <div v-if="canOperate && record.returned_on" class="equipment-history-actions"><UiButton compact variant="secondary" @click="startAssignmentEdit(record)">Редактировать</UiButton><UiButton compact variant="danger" @click="deleteAssignment(record)">Удалить</UiButton></div>
            </template>
            <form v-else class="equipment-history-editor" @submit.prevent="saveAssignment">
              <label>Сотрудник<UiSearchSelect v-model="assignmentEdit.employee_id" :options="employeeOptions" placeholder="Сотрудник" search-placeholder="Поиск" /></label>
              <label>Дата выдачи<input v-model="assignmentEdit.starts_on" type="date" required></label><label>Дата возврата<input v-model="assignmentEdit.returned_on" type="date" required></label>
              <label class="wide">Комментарий при выдаче<textarea v-model="assignmentEdit.issue_comment"></textarea></label><label class="wide">Комментарий после возврата<textarea v-model="assignmentEdit.return_comment"></textarea></label>
              <div class="equipment-modal-actions wide"><UiButton variant="secondary" type="button" @click="cancelAssignmentEdit">Отмена</UiButton><UiButton type="submit">Сохранить</UiButton></div>
            </form>
          </article>
        </div>
      </section>

      <section v-else-if="tab === 'cost'" class="equipment-card-content">
        <div class="equipment-info-section"><h3>Закупка и срок</h3><dl><div v-for="field in costFields" :key="field.key" class="equipment-attribute"><dt>{{ field.label }}</dt><dd v-if="editField !== field.key">{{ display(field) }}</dd><dd v-else class="equipment-attribute-editor"><input v-model="editValue" :type="field.type" :min="field.type==='number' ? (field.key==='useful_life_months'?1:0) : undefined" :step="field.key==='purchase_cost'?'0.01':undefined"><div class="equipment-editor-actions"><UiButton compact @click="saveField(field)">✓</UiButton><UiButton compact variant="secondary" @click="cancelEdit">×</UiButton></div></dd><button v-if="canManage && editField !== field.key" type="button" class="equipment-pencil" @click="startEdit(field)">✎</button></div></dl></div>
        <div class="cost-grid"><div><span>Амортизация в месяц</span><strong>{{ money(item.depreciation?.monthly) }}</strong></div><div><span>Начислено месяцев</span><strong>{{ item.depreciation?.months ?? 0 }}</strong></div><div><span>Накопленная амортизация</span><strong>{{ money(item.depreciation?.accumulated) }}</strong></div><div><span>Учётная остаточная стоимость</span><strong>{{ money(item.depreciation?.residual) }}</strong></div><div><span>Снижение из-за повреждений</span><strong>{{ money(item.damage_accounting?.value_loss) }}</strong></div><div><span>Текущая оценочная стоимость</span><strong>{{ money(item.damage_accounting?.current_value) }}</strong></div></div>
      </section>

      <section v-else class="equipment-card-content">
        <div class="cost-grid damage-summary"><div><span>Повреждений</span><strong>{{ item.damage_accounting?.damage_count ?? 0 }}</strong></div><div><span>Общие затраты на ремонт</span><strong>{{ money(item.damage_accounting?.repair_cost) }}</strong></div><div><span>Суммарное снижение стоимости</span><strong>{{ money(item.damage_accounting?.value_loss) }}</strong></div><div><span>Текущая оценочная стоимость</span><strong>{{ money(item.damage_accounting?.current_value) }}</strong></div></div>
        <div v-if="canManage" class="equipment-tab-actions"><UiButton compact @click="newDamage">+ Повреждение</UiButton></div>
        <form v-if="damageForm.open" class="damage-editor" @submit.prevent="saveDamage"><label>Дата повреждения<input v-model="damageForm.occurred_on" type="date" required></label><label>Дата ремонта<input v-model="damageForm.repaired_on" type="date"></label><label class="wide">Описание повреждения<textarea v-model="damageForm.description" required></textarea></label><label>Стоимость ремонта<input v-model="damageForm.repair_cost" type="number" min="0" step="0.01"></label><label>Снижение стоимости<input v-model="damageForm.value_loss" type="number" min="0" step="0.01"></label><div class="equipment-modal-actions wide"><UiButton variant="secondary" type="button" @click="resetDamage">Отмена</UiButton><UiButton type="submit">Сохранить</UiButton></div></form>
        <div v-if="!item.damages?.length" class="equipment-drawer-state">Повреждений не зафиксировано.</div>
        <div v-else class="damage-list"><article v-for="damage in item.damages" :key="damage.id" class="damage-row"><div><strong>{{ formatDate(damage.occurred_on) }}</strong><span v-if="damage.repaired_on">Ремонт: {{ formatDate(damage.repaired_on) }}</span></div><p>{{ damage.description }}</p><div class="damage-values"><span>Ремонт: <b>{{ money(damage.repair_cost) }}</b></span><span>Снижение стоимости: <b>{{ money(damage.value_loss) }}</b></span></div><div v-if="canManage" class="equipment-history-actions"><UiButton compact variant="secondary" @click="editDamage(damage)">Редактировать</UiButton><UiButton compact variant="danger" @click="deleteDamage(damage)">Удалить</UiButton></div></article></div>
      </section>
    </template>
  </UiDrawer>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { UiBadge, UiPanel, UiSearchSelect, UiTreeToggle, serviceGroups } from '@irlix/ui';
import ResourceChart from './ResourceChart.vue';
import ResourceDonut from './ResourceDonut.vue';
import { resourceAllocation, resourceColor, specialLabels } from './resourceAllocation.js';
import './resources.css';
const props = defineProps({ auth: {type:Object,required:true} });
const snapshot = ref(null), error = ref(''), historyError = ref(''), busy = ref(false), forbidden = ref(false);
const period = ref('1h'), selected = ref('__host__'), sort = ref('memory_bytes'), expanded = ref(new Set()), points = ref([]);
let timer, controller, alive = true, historySequence = 0;
const labels = new Map(serviceGroups.flatMap(g=>g.items).map(s=>[s.key,s.label]));
for (const [key,label] of Object.entries({'platform-core':'Платформенное ядро','postgres':'PostgreSQL','redis':'Redis','rabbitmq':'RabbitMQ','keycloak':'Keycloak'})) labels.set(key,label);
const label = key => specialLabels[key] || labels.get(key) || key;
const active = ref(null);
const legend = computed(()=>[...(snapshot.value?.services || []).map(s=>({id:s.id,label:label(s.id),color:resourceColor(s.id)})).sort((a,b)=>a.label.localeCompare(b.label,'ru')), ...Object.keys(specialLabels).map(id=>({id,label:label(id),color:resourceColor(id)}))]);
const services = computed(()=>[...(snapshot.value?.services || [])].sort((a,b)=>(b[sort.value] || 0)-(a[sort.value] || 0)));
const host = computed(()=>snapshot.value?.host);
const allocations = computed(()=>({
  ram:resourceAllocation(snapshot.value?.services || [],'working_bytes',host.value?.memory_total,host.value?.memory_used),
  disk:resourceAllocation(snapshot.value?.services || [],'disk_bytes',host.value?.disk_total,host.value ? host.value.disk_total-host.value.disk_available : null),
  cpu:resourceAllocation(snapshot.value?.services || [],'cpu_cores',host.value?.cpu_count,host.value?.cpu_percent == null ? null : host.value.cpu_count*host.value.cpu_percent/100),
}));
const fmtBytes = value => value == null ? '—' : value >= 1024**3 ? `${(value/1024**3).toFixed(2)} ГБ` : `${(value/1024**2).toFixed(1)} МБ`;
const fmtCpu = value => value == null ? '—' : Number(value).toFixed(2);
const percent = (used,total) => used != null && total ? `${(100*used/total).toFixed(1)}%` : '—';
const toggle = id => { const next = new Set(expanded.value); next.has(id) ? next.delete(id) : next.add(id); expanded.value = next; };
const options = computed(()=>[{value:'__host__',label:'Вся VM'}, ...(snapshot.value?.services || []).map(s=>({value:s.id,label:label(s.id)}))]);
const cpuChartFormat = v => Number(v).toFixed(selected.value==='__host__' ? 1 : 2);
const totalContainer = computed(()=> (snapshot.value?.services || []).reduce((sum,s)=>sum+s.memory_bytes,0));

async function request(path, signal) {
  const response = await props.auth.fetch(path,{headers:{Accept:'application/json'},cache:'no-store',signal});
  const body = await response.json();
  if (!response.ok) {
    if (response.status===401 || response.status===403) {
      forbidden.value=true; snapshot.value=null; points.value=[]; clearTimeout(timer); historySequence++;
    }
    throw new Error(body.message || `Ошибка загрузки (${response.status})`);
  }
  return body.data;
}
async function loadHistory() {
  if (!alive || forbidden.value) return;
  const sequence = ++historySequence;
  try {
    const data = await request(`/api/platform/resources/history?period=${period.value}&service=${encodeURIComponent(selected.value)}`,controller?.signal);
    if (alive && sequence===historySequence) {points.value=data;historyError.value='';}
  } catch(e) { if (alive && sequence===historySequence && e.name!=='AbortError') {historyError.value=e.message; points.value=[];} }
}
async function refresh() {
  if (busy.value || forbidden.value || !alive) return;
  clearTimeout(timer); busy.value=true; controller = new AbortController();
  try {
    const data = await request('/api/platform/resources',controller.signal);
    if (alive && !forbidden.value) {snapshot.value=data;error.value='';await loadHistory();}
  } catch(e) { if (alive && e.name!=='AbortError') error.value=e.message; }
  finally { busy.value=false; if (alive && !forbidden.value) timer=setTimeout(refresh,10000); }
}
watch([period,selected],()=>{points.value=[];loadHistory();});
onMounted(refresh);
onUnmounted(()=>{alive=false;historySequence++;clearTimeout(timer);controller?.abort();});
</script>

<template>
  <div class="resource-monitor">
    <p v-if="error" role="alert" class="resource-error">{{ error }}</p>
    <p v-if="snapshot?.stale" role="alert" class="resource-warning">Данные устарели: сборщик не обновлял их {{ snapshot.age_seconds }} с. Показан последний доступный замер.</p>
    <p v-if="snapshot?.partial" role="alert" class="resource-warning">Часть контейнеров не ответила. Итоги по таким сервисам неполные.</p>
    <p v-if="snapshot?.host?.memory_estimated" role="status" class="resource-warning">RAM VM определена через ядро: занятость приблизительная, поскольку точный MemAvailable недоступен из контейнера мониторинга.</p>
    <p v-if="snapshot?.host?.memory_unavailable" role="status" class="resource-warning">Занятая RAM VM временно недоступна: источники памяти не прошли проверку. CPU, диск и сервисы продолжают обновляться.</p>
    <p v-if="!snapshot && !error" class="resource-muted" role="status">Загружаем показатели сервера…</p>
    <template v-if="snapshot">
      <div class="resource-summary">
        <UiPanel><div class="resource-card"><div class="resource-card-heading"><h2>Оперативная память</h2><span>{{ percent(host.memory_used,host.memory_total) }}</span></div><ResourceDonut :allocation="allocations.ram" title="Оперативная память" :format="fmtBytes" :label="label" :active="active" @activate="active=$event" /><small>Доступно {{ fmtBytes(host.memory_available) }} · кеш {{ fmtBytes(host.memory_cache) }}</small><small>Swap {{ fmtBytes(host.swap_used) }} / {{ fmtBytes(host.swap_total) }}</small></div></UiPanel>
        <UiPanel><div class="resource-card"><div class="resource-card-heading"><h2>Корневой диск</h2><span>{{ percent(host.disk_total-host.disk_available,host.disk_total) }}</span></div><ResourceDonut :allocation="allocations.disk" title="Корневой диск" :format="fmtBytes" :label="label" :active="active" @activate="active=$event" /><small>Свободно {{ fmtBytes(host.disk_available) }}</small><small>Тома и записываемые слои контейнеров</small></div></UiPanel>
        <UiPanel><div class="resource-card"><div class="resource-card-heading"><h2>Процессор</h2><span>{{ host.cpu_percent==null ? '—' : `${Number(host.cpu_percent).toFixed(1)}%` }}</span></div><ResourceDonut :allocation="allocations.cpu" title="Процессор" :format="v=>v==null ? '—' : `${fmtCpu(v)} ядра`" :label="label" :active="active" @activate="active=$event" /><small>{{ host.cpu_count }} vCPU · load {{ host.load_average.map(v=>v.toFixed(2)).join(' / ') }}</small><small>1,00 = одно полностью занятое ядро</small></div></UiPanel>
      </div>
      <div class="resource-legend" aria-label="Общая легенда диаграмм"><button v-for="item in legend" :key="item.id" type="button" :aria-pressed="active===item.id" @mouseenter="active=item.id" @mouseleave="active=null" @focus="active=item.id" @blur="active=null" @click="active=item.id" @keydown.esc="active=null"><span class="resource-color" :style="{background:item.color}" />{{ item.label }}</button></div>
      <p class="resource-muted">RAM сервисов — без неактивного кеша. «Система / прочее» — оставшаяся занятость VM, в том числе общие дисковые данные.</p>
      <p v-if="Object.values(allocations).some(a=>a.normalized)" class="resource-warning">Сумма показателей сервисов превышает замер VM: размеры секторов нормированы по занятости VM. При наведении показаны исходные значения.</p>
      <p v-if="snapshot.disk?.error || snapshot.disk?.stale || snapshot.disk?.partial" class="resource-warning">{{ snapshot.disk?.error ? 'Не удалось обновить дисковые метрики.' : snapshot.disk?.stale ? 'Дисковые метрики устарели.' : 'Часть дисковых данных не измерена.' }} {{ snapshot.disk?.collected_at ? 'Показан последний доступный дисковый замер.' : 'Неизмеренные данные включены в «Система / прочее».' }}</p>
      <UiPanel>
        <div class="resource-table-title"><h2>Сервисы</h2><div class="resource-sort"><UiSearchSelect v-model="sort" :options="[{value:'memory_bytes',label:'По памяти'},{value:'cpu_cores',label:'По CPU'},{value:'disk_bytes',label:'По диску'}]" :clearable="false" /></div></div>
        <div class="resource-table-scroll"><table class="irlix-data-table resource-table">
          <thead><tr><th>Сервис / компонент</th><th>RAM, всего</th><th>Без неактивного кеша</th><th>CPU, ядра</th><th>Диск</th><th>Лимит RAM / CPU</th><th>Состояние</th></tr></thead>
          <tbody>
            <template v-for="service in services" :key="service.id">
              <tr :class="{'resource-active':active===service.id}"><td><div class="resource-service-name"><UiTreeToggle :expanded="expanded.has(service.id)" :label="`Компоненты: ${label(service.id)}`" @click="toggle(service.id)" /><span class="resource-color" :style="{background:resourceColor(service.id)}" /><button class="resource-service-link" @click="selected=service.id">{{ label(service.id) }}</button><span class="resource-muted"> · {{ service.components.length }} комп.</span></div></td><td>{{ fmtBytes(service.memory_bytes) }}</td><td>{{ fmtBytes(service.working_bytes) }}</td><td>{{ fmtCpu(service.cpu_cores) }}</td><td>{{ service.disk_partial && service.disk_bytes!=null ? '≥ ' : '' }}{{ fmtBytes(service.disk_bytes) }}</td><td class="resource-muted">По компонентам</td><td><UiBadge :tone="service.partial ? 'warning' : service.components.every(c=>c.state==='running') ? 'success' : 'neutral'">{{ service.partial ? 'Неполные данные' : service.components.every(c=>c.state==='running') ? 'Работает' : 'Есть остановленные' }}</UiBadge></td></tr>
              <tr v-for="component in expanded.has(service.id) ? service.components : []" :key="component.id" class="resource-component"><td>{{ component.service }} <small>{{ component.id }}</small></td><td>{{ component.error ? '—' : fmtBytes(component.memory_bytes) }}</td><td>{{ component.error ? '—' : fmtBytes(component.working_bytes) }}</td><td>{{ fmtCpu(component.cpu_cores) }}</td><td>{{ fmtBytes(component.disk_bytes) }}<small>Записываемый слой</small></td><td>{{ component.memory_limit ? fmtBytes(component.memory_limit) : 'Без лимита' }} / {{ component.cpu_limit ? `${fmtCpu(component.cpu_limit)} ядра` : 'Без лимита' }}</td><td><UiBadge :tone="component.error || component.oom_killed ? 'danger' : 'neutral'">{{ component.error ? 'Нет данных' : component.oom_killed ? 'Нехватка RAM (OOM)' : component.state }}</UiBadge><small>Перезапусков: {{ component.restarts ?? '—' }}</small></td></tr>
            </template>
          </tbody>
        </table></div>
        <p class="resource-explanation">Контейнеры проекта: {{ fmtBytes(totalContainer) }}. Полная RAM включает кеш; эти суммы не обязаны совпадать с занятостью VM. PostgreSQL, Redis и RabbitMQ общие — их потребление показано отдельно.</p>
      </UiPanel>
      <UiPanel>
        <div class="resource-history-title"><h2>История потребления</h2><div class="resource-actions"><div class="resource-select"><UiSearchSelect v-model="selected" :options="options" :clearable="false" /></div><div class="resource-select"><UiSearchSelect v-model="period" :options="[{value:'1h',label:'Последний час'},{value:'24h',label:'24 часа'},{value:'7d',label:'7 дней'}]" :clearable="false" /></div></div></div>
        <p v-if="historyError" role="alert" class="resource-error">{{ historyError }}</p>
        <div v-else class="resource-charts"><ResourceChart :points="points" field="memory_bytes" peak-field="memory_peak" :format="fmtBytes" :label="`RAM · ${selected==='__host__' ? 'Вся VM' : label(selected)}`" /><ResourceChart :points="points" field="cpu" peak-field="cpu_peak" :format="cpuChartFormat" :label="`CPU · ${selected==='__host__' ? 'Вся VM' : label(selected)}`" :unit="selected==='__host__' ? '%' : 'ядра'" /></div>
      </UiPanel>


    </template>
  </div>
</template>

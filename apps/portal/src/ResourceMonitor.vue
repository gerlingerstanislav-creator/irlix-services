<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { UiBadge, UiButton, UiPanel, UiSearchSelect, UiTreeToggle, serviceGroups } from '@irlix/ui';
import ResourceChart from './ResourceChart.vue';
import './resources.css';
const props = defineProps({ auth: {type:Object,required:true} });
const snapshot = ref(null), error = ref(''), historyError = ref(''), busy = ref(false), forbidden = ref(false);
const period = ref('1h'), selected = ref('__host__'), sort = ref('memory_bytes'), expanded = ref(new Set()), points = ref([]);
let timer, controller, alive = true, historySequence = 0;
const labels = new Map(serviceGroups.flatMap(g=>g.items).map(s=>[s.key,s.label]));
for (const [key,label] of Object.entries({'platform-core':'Платформенное ядро','postgres':'PostgreSQL','redis':'Redis','rabbitmq':'RabbitMQ','keycloak':'Keycloak'})) labels.set(key,label);
const label = key => labels.get(key) || key;
const services = computed(()=>[...(snapshot.value?.services || [])].sort((a,b)=>(b[sort.value] || 0)-(a[sort.value] || 0)));
const host = computed(()=>snapshot.value?.host);
const fmtBytes = value => value == null ? '—' : value >= 1024**3 ? `${(value/1024**3).toFixed(2)} ГБ` : `${(value/1024**2).toFixed(1)} МБ`;
const fmtCpu = value => value == null ? '—' : Number(value).toFixed(2);
const percent = (used,total) => total ? `${(100*used/total).toFixed(1)}%` : '—';
const date = ts => new Date(ts*1000).toLocaleTimeString('ru-RU');
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
    <div class="resource-toolbar">
      <div><h1>Ресурсный монитор</h1><p class="resource-muted">VM и сервисы · обновление каждые 10 секунд</p></div>
      <div class="resource-actions"><span v-if="snapshot" class="resource-muted">Замер {{ date(snapshot.collected_at) }}</span><UiButton :disabled="busy || forbidden" variant="secondary" @click="refresh">{{ busy ? 'Обновление…' : 'Обновить' }}</UiButton></div>
    </div>
    <p v-if="error" role="alert" class="resource-error">{{ error }}</p>
    <p v-if="snapshot?.stale" role="alert" class="resource-warning">Данные устарели: сборщик не обновлял их {{ snapshot.age_seconds }} с. Показан последний доступный замер.</p>
    <p v-if="snapshot?.partial" role="alert" class="resource-warning">Часть контейнеров не ответила. Итоги по таким сервисам неполные.</p>
    <p v-if="!snapshot && !error" class="resource-muted" role="status">Загружаем показатели сервера…</p>
    <template v-if="snapshot">
      <div class="resource-summary">
        <UiPanel><div class="resource-card"><span>Оперативная память VM</span><strong>{{ fmtBytes(host.memory_used) }} / {{ fmtBytes(host.memory_total) }}</strong><small>Занято {{ percent(host.memory_used,host.memory_total) }} · доступно {{ fmtBytes(host.memory_available) }}</small><small>Файловый кеш: {{ fmtBytes(host.memory_cache) }}</small></div></UiPanel>
        <UiPanel><div class="resource-card"><span>Процессор VM</span><strong>{{ host.cpu_percent==null ? 'Первый замер' : `${Number(host.cpu_percent).toFixed(1)}%` }}</strong><small>{{ host.cpu_count }} vCPU · load {{ host.load_average.map(v=>v.toFixed(2)).join(' / ') }}</small><small>Потребление сервиса в ядрах: 1,00 = одно полностью занятое ядро</small></div></UiPanel>
        <UiPanel><div class="resource-card"><span>Swap</span><strong>{{ fmtBytes(host.swap_used) }} / {{ fmtBytes(host.swap_total) }}</strong><small>{{ host.swap_total ? 'Подкачка памяти на диск' : 'Подкачка не настроена' }}</small></div></UiPanel>
        <UiPanel><div class="resource-card"><span>Корневой диск VM</span><strong>{{ fmtBytes(host.disk_available) }} свободно</strong><small>Объём {{ fmtBytes(host.disk_total) }}</small></div></UiPanel>
      </div>
      <UiPanel>
        <div class="resource-table-title"><h2>Сервисы</h2><div class="resource-sort"><UiSearchSelect v-model="sort" :options="[{value:'memory_bytes',label:'По памяти'},{value:'cpu_cores',label:'По CPU'}]" :clearable="false" /></div></div>
        <div class="resource-table-scroll"><table class="irlix-data-table resource-table">
          <thead><tr><th>Сервис / компонент</th><th>RAM, всего</th><th>Без неактивного кеша</th><th>CPU, ядра</th><th>Лимит RAM / CPU</th><th>Состояние</th></tr></thead>
          <tbody>
            <template v-for="service in services" :key="service.id">
              <tr><td><UiTreeToggle :expanded="expanded.has(service.id)" :label="`Компоненты: ${label(service.id)}`" @click="toggle(service.id)" /><button class="resource-service-link" @click="selected=service.id">{{ label(service.id) }}</button><small>{{ service.components.length }} комп.</small></td><td>{{ fmtBytes(service.memory_bytes) }}</td><td>{{ fmtBytes(service.working_bytes) }}</td><td>{{ fmtCpu(service.cpu_cores) }}</td><td class="resource-muted">По компонентам</td><td><UiBadge :tone="service.partial ? 'warning' : service.components.every(c=>c.state==='running') ? 'success' : 'neutral'">{{ service.partial ? 'Неполные данные' : service.components.every(c=>c.state==='running') ? 'Работает' : 'Есть остановленные' }}</UiBadge></td></tr>
              <tr v-for="component in expanded.has(service.id) ? service.components : []" :key="component.id" class="resource-component"><td>{{ component.service }} <small>{{ component.id }}</small></td><td>{{ component.error ? '—' : fmtBytes(component.memory_bytes) }}</td><td>{{ component.error ? '—' : fmtBytes(component.working_bytes) }}</td><td>{{ fmtCpu(component.cpu_cores) }}</td><td>{{ component.memory_limit ? fmtBytes(component.memory_limit) : 'Без лимита' }} / {{ component.cpu_limit ? `${fmtCpu(component.cpu_limit)} ядра` : 'Без лимита' }}</td><td><UiBadge :tone="component.error || component.oom_killed ? 'danger' : 'neutral'">{{ component.error ? 'Нет данных' : component.oom_killed ? 'Нехватка RAM (OOM)' : component.state }}</UiBadge><small>Перезапусков: {{ component.restarts ?? '—' }}</small></td></tr>
            </template>
          </tbody>
        </table></div>
        <p class="resource-explanation">Контейнеры проекта: {{ fmtBytes(totalContainer) }}. Полная RAM включает кеш; эти суммы не обязаны совпадать с занятостью VM. PostgreSQL, Redis и RabbitMQ общие — их потребление показано отдельно.</p>
      </UiPanel>
      <UiPanel>
        <div class="resource-history-title"><h2>История потребления</h2><div class="resource-actions"><div class="resource-select"><UiSearchSelect v-model="selected" :options="options" :clearable="false" /></div><div class="resource-select"><UiSearchSelect v-model="period" :options="[{value:'1h',label:'Последний час'},{value:'24h',label:'24 часа'},{value:'7d',label:'7 дней'}]" :clearable="false" /></div></div></div>
        <p v-if="historyError" role="alert" class="resource-error">{{ historyError }}</p>
        <div v-else class="resource-charts"><ResourceChart :points="points" field="memory_bytes" peak-field="memory_peak" :format="fmtBytes" :label="`RAM · ${selected==='__host__' ? 'Вся VM' : label(selected)}`" /><ResourceChart :points="points" field="cpu" peak-field="cpu_peak" :format="cpuChartFormat" :label="`CPU · ${selected==='__host__' ? 'Вся VM' : label(selected)}`" :unit="selected==='__host__' ? '%' : 'ядра'" /></div>
        <p class="resource-explanation">Замеры раз в минуту, хранение 7 дней. График показывает среднее по интервалу, подпись — максимальный наблюдавшийся замер. Управление лимитами будет добавлено следующим этапом.</p>
      </UiPanel>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import UiIcon from './UiIcon.vue';
import { getVisibleServiceGroups } from '../serviceCatalog';

const props = defineProps({ platformAdmin: { type: Boolean, default: false } });
const groups = computed(() => getVisibleServiceGroups(props.platformAdmin));
</script>

<template>
  <div class="ui-service-dashboard irlix-ui" data-component="ui-service-dashboard">
    <section v-for="group in groups" :key="group.key" class="ui-service-dashboard__contour" :data-contour="group.key" :aria-label="group.label">
      <h2>{{ group.label }}</h2>
      <div class="ui-service-dashboard__grid">
        <component :is="service.available && service.href ? 'a' : 'article'" v-for="service in group.items" :key="service.key"
          :href="service.available ? service.href : undefined" class="ui-service-dashboard__card"
          :class="{ 'is-unavailable': !service.available }" :data-service="service.key" :aria-disabled="!service.available || undefined">
          <div class="ui-service-dashboard__head">
            <span class="ui-service-dashboard__icon"><UiIcon :name="service.icon" /></span>
            <h3>{{ service.label }}</h3>
            <span class="ui-service-dashboard__lifecycle" :data-status="service.status">{{ service.status }}</span>
            <UiIcon v-if="service.available" name="power" class="ui-service-dashboard__status" role="img" aria-label="Сервис доступен" />
          </div>
          <p>{{ service.description }}</p>
          <div v-if="!service.available" class="ui-service-dashboard__footer">Недоступен</div>
          <div v-else-if="platformAdmin" class="ui-service-dashboard__footer">
            <span class="ui-service-dashboard__audience-label">Доступ</span>
            <span>{{ service.audience?.length ? service.audience.join(' · ') : 'Только администратор платформы' }}</span>
          </div>
        </component>
      </div>
    </section>
  </div>
</template>

<style>
.ui-service-dashboard { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); column-gap:12px; row-gap:24px; padding:24px 20px 40px; }
.ui-service-dashboard__contour { grid-column:span 2; min-width:0; }
.ui-service-dashboard__contour > h2 { height:22px; margin:0 0 10px; color:var(--irlix-color-text-muted); font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
.ui-service-dashboard__grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.ui-service-dashboard__card { height:210px; min-width:0; display:flex; flex-direction:column; gap:10px; padding:14px; border:1px solid var(--irlix-color-border); border-radius:12px; background:var(--irlix-color-surface); color:var(--irlix-color-text); text-decoration:none; }
a.ui-service-dashboard__card:hover { border-color:var(--irlix-color-primary); }
a.ui-service-dashboard__card:focus-visible { outline:2px solid var(--irlix-color-primary); outline-offset:2px; }
.ui-service-dashboard__head { display:flex; align-items:center; gap:8px; }
.ui-service-dashboard__icon { width:30px; height:30px; flex:none; display:grid; place-items:center; border-radius:9px; background:var(--irlix-color-primary-soft); color:var(--irlix-color-primary-text); }
.ui-service-dashboard svg { width:18px; height:18px; stroke:currentColor; stroke-width:1.6; stroke-linecap:round; stroke-linejoin:round; }
.ui-service-dashboard__head > h3 { min-width:0; margin:0; font-size:14px; line-height:1.25; font-weight:600; }
.ui-service-dashboard__lifecycle { flex:none; margin-left:auto; padding:3px 7px; border:1px solid var(--irlix-color-border); border-radius:999px; background:var(--irlix-color-surface); color:var(--irlix-color-text-muted); font-size:10px; font-weight:600; line-height:1.2; white-space:nowrap; }
.ui-service-dashboard__lifecycle[data-status="Production"] { color:var(--irlix-color-primary-text); }
.ui-service-dashboard__status { flex:none; margin-left:2px; color:var(--irlix-color-primary-text); }
.ui-service-dashboard__card > p { margin:0; color:var(--irlix-color-text-muted); font-size:12px; line-height:1.4; }
.ui-service-dashboard__footer { display:grid; gap:5px; margin-top:auto; padding-top:8px; border-top:1px solid var(--irlix-color-border); color:var(--irlix-color-text-muted); font-size:11px; line-height:1.45; }
.ui-service-dashboard__audience-label { font-size:10px; text-transform:uppercase; letter-spacing:.05em; }
.ui-service-dashboard__card.is-unavailable { opacity:.65; }
@media(max-width:1199px) { .ui-service-dashboard { grid-template-columns:repeat(3,minmax(0,1fr)); }.ui-service-dashboard__contour { grid-column:1 / -1; }.ui-service-dashboard__grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
@media(max-width:720px) { .ui-service-dashboard { padding:18px 14px 32px; } }
@media(max-width:620px) { .ui-service-dashboard,.ui-service-dashboard__grid { grid-template-columns:repeat(2,minmax(0,1fr)); }.ui-service-dashboard__card { height:250px; }.ui-service-dashboard__head { flex-wrap:wrap; }.ui-service-dashboard__head > h3 { flex-basis:100%; }.ui-service-dashboard__lifecycle { margin-left:0; }.ui-service-dashboard__status { display:none; } }
@media(max-width:390px) { .ui-service-dashboard,.ui-service-dashboard__grid { grid-template-columns:1fr; } }
</style>

<script setup>
import {computed,ref} from 'vue';
import UiIcon from './UiIcon.vue';
import {getVisibleServiceGroups} from '../serviceCatalog';
const props=defineProps({
  platformAdmin:{type:Boolean,default:false},
  serviceAccess:{type:Object,default:null},
  layout:{type:String,default:'grid',validator:value=>['grid','list'].includes(value)},
});
const search=ref('');
const groups=computed(()=>getVisibleServiceGroups(props.platformAdmin,props.serviceAccess)
.map(g=>({...g,items:g.items.filter(s=>s.key!=='dashboard'&&s.showInLauncher!==false&&
(!search.value||(s.label+' '+s.description).toLocaleLowerCase('ru').includes(search.value.toLocaleLowerCase('ru'))))})).filter(g=>g.items.length));
</script>
<template>
<section class="ui-service-dashboard irlix-ui" data-component="ui-service-dashboard" :data-layout="layout" aria-label="Каталог сервисов">
 <div class="ui-service-dashboard__toolbar"><h2>Сервисы</h2><label><UiIcon name="search"/><input v-model="search" placeholder="Найти сервис" aria-label="Поиск сервисов"/></label></div>
 <section v-for="group in groups" :key="group.key" class="ui-service-dashboard__contour" :data-contour="group.key">
 <h3>{{group.label}}</h3><div class="ui-service-dashboard__grid">
 <component :is="service.available&&service.href?'a':'article'" v-for="service in group.items" :key="service.key"
 :href="service.available?service.href:undefined" class="ui-service-dashboard__card" :class="{'is-unavailable':!service.available}" :data-service="service.key" :title="service.description" :aria-disabled="!service.available||undefined">
 <span class="ui-service-dashboard__icon"><UiIcon :name="service.icon"/></span><span class="ui-service-dashboard__label">{{service.label}}</span>
 <span class="ui-service-dashboard__status" :class="service.available ? 'is-available' : 'is-unavailable'"
   role="img" :aria-label="service.available ? 'Сервис доступен' : 'Сервис недоступен'"
   :title="service.available ? 'Доступен' : 'Недоступен'"><UiIcon name="power"/></span>
 <UiIcon v-if="service.available" name="chevron-right" class="ui-service-dashboard__arrow"/>
 </component></div></section><p v-if="!groups.length" class="ui-service-dashboard__empty">Нет сервисов по вашему запросу.</p>
</section>
</template>
<style>
.ui-service-dashboard{display:flex;flex-direction:column;gap:12px}
.ui-service-dashboard__toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;min-width:0}
.ui-service-dashboard__toolbar h2{font-size:var(--irlix-font-size-body);margin:0;font-weight:700}
.ui-service-dashboard__toolbar label{display:flex;align-items:center;gap:7px;border:1px solid var(--irlix-color-border);border-radius:9px;padding:7px 10px;background:var(--irlix-color-surface);min-width:0}
.ui-service-dashboard__toolbar input{border:0;outline:0;background:transparent;color:var(--irlix-color-text);width:190px;font:inherit;min-width:0}
.ui-service-dashboard__toolbar label svg{width:16px;height:16px;stroke:var(--irlix-color-text-muted)}
.ui-service-dashboard__contour{min-width:0}
.ui-service-dashboard__contour h3{margin:8px 0 9px;font-size:12px;color:var(--irlix-color-text-muted);font-weight:700;letter-spacing:.055em;text-transform:uppercase}
.ui-service-dashboard__grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.ui-service-dashboard__card{display:flex;align-items:center;gap:10px;min-height:64px;min-width:0;padding:10px 12px;border:1px solid var(--irlix-color-border);border-radius:10px;background:var(--irlix-color-surface);color:var(--irlix-color-text);text-decoration:none}
a.ui-service-dashboard__card:hover{border-color:var(--irlix-color-primary);background:var(--irlix-color-primary-soft)}
a.ui-service-dashboard__card:focus-visible{outline:2px solid var(--irlix-color-primary);outline-offset:2px}
.ui-service-dashboard__icon{width:34px;height:34px;flex:none;display:grid;place-items:center;border-radius:9px;color:var(--irlix-color-primary-text);background:var(--irlix-color-primary-soft)}
.ui-service-dashboard__icon svg{width:18px;height:18px;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.ui-service-dashboard__label{min-width:0;overflow:hidden;text-overflow:ellipsis;font-weight:600;font-size:13px}
.ui-service-dashboard__status{margin-left:auto;display:grid;place-items:center;width:20px;height:20px;flex:none}
.ui-service-dashboard__status svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.ui-service-dashboard__status.is-available{color:var(--irlix-color-success)}
.ui-service-dashboard__status.is-unavailable{color:var(--irlix-color-danger)}
.ui-service-dashboard__arrow{width:15px!important;height:15px!important;flex:none;color:var(--irlix-color-text-muted)}
.ui-service-dashboard__empty{font-size:13px;color:var(--irlix-color-text-muted)}
/* The dashboard sidebar uses a single-column variant; the default grid stays unchanged for other consumers. */
.ui-service-dashboard[data-layout="list"]{gap:8px}
.ui-service-dashboard[data-layout="list"] .ui-service-dashboard__grid{grid-template-columns:minmax(0,1fr);gap:6px}
.ui-service-dashboard[data-layout="list"] .ui-service-dashboard__contour h3{font-size:11px;line-height:1.2;font-weight:600;letter-spacing:.025em;margin:0 0 5px}
.ui-service-dashboard[data-layout="list"] .ui-service-dashboard__contour:not(:first-of-type){margin-top:5px}
.ui-service-dashboard[data-layout="list"] .ui-service-dashboard__card{min-height:50px;padding:7px 10px;gap:9px}
.ui-service-dashboard[data-layout="list"] .ui-service-dashboard__icon{width:30px;height:30px;border-radius:8px}
.ui-service-dashboard[data-layout="list"] .ui-service-dashboard__icon svg{width:16px;height:16px}
.ui-service-dashboard[data-layout="list"] .ui-service-dashboard__toolbar label{flex:1;max-width:190px}
.ui-service-dashboard[data-layout="list"] .ui-service-dashboard__toolbar input{width:100%;box-sizing:border-box}
@media(min-width:1760px){.ui-service-dashboard__grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
@media(max-width:1180px){.ui-service-dashboard__grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:860px){.ui-service-dashboard__grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:450px){.ui-service-dashboard__grid{grid-template-columns:1fr}.ui-service-dashboard__toolbar input{width:120px}}
</style>
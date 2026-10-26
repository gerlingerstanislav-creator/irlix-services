import '@fontsource-variable/onest/wght.css';
import { createApp, h, ref, computed } from 'vue';
import { router } from './router';
import { UiAppShell, isPlatformAdministratorAccess } from '@irlix/ui';
import { createBrowserAuth } from '@irlix/auth';
import ResourceMonitor from '../../portal/src/ResourceMonitor.vue';
import ServiceMap from '../../portal/src/ServiceMap.vue';
import MigrationConsole from '../../migration/src/MigrationConsole.vue';
import '@irlix/ui/styles/base.css';
import './system.css';
const auth = createBrowserAuth({storagePrefix:'irlix.system.auth',defaultReturnTo:'/system/'});
const sections=[
{id:'resources',label:'Ресурсный монитор',icon:'chart',href:'/system/resources/'},
{id:'service-map',label:'Карта сервисов',icon:'org',href:'/system/service-map/'},
{id:'migration-control',label:'Контроль переноса',icon:'tasks',href:'/system/migration/control/'},
];
const section = computed(() => router.currentRoute.value.meta.section || null);
const loading = ref(true), authorized = ref(false), error = ref(''), access = ref(null);
function navigate(id) {
  if (!router.hasRoute(id)) return;
  const query = section.value === 'migration-control' && id === 'migration-control' ? router.currentRoute.value.query : {};
  router.push({ name: id, query });
}
const app = createApp({setup(){
const breadcrumbs=computed(()=>[{label:sections.find(i=>i.id===section.value)?.label||'Ресурсный монитор'}]);
return ()=>loading.value?h('main',{class:'system-status',role:'status'},'Проверяем доступ…')
:(error.value||!authorized.value||!section.value)?h('main',{class:'system-status',role:'alert'},[
h('h1', error.value ? '503 — Сервис временно недоступен' : (!section.value && authorized.value ? '404 — Страница не найдена' : '403 — Доступ запрещён')),h('p',error.value||(!section.value && authorized.value ? 'Такой страницы в сервисе нет.' : 'Доступ разрешён только администратору платформы.')),h('a',{href:'/'},'Вернуться на дашборд')])
:h(UiAppShell,{service:'system',serviceName:'Система',section:section.value,items:sections,breadcrumbs:breadcrumbs.value,
  currentUser:auth.user,platformAdmin:true,serviceAccess:access.value,'onUpdate:section':navigate,onLogout:()=>auth.logout()},
{default:()=>h('main',{class:'system-workspace'},section.value==='resources'?h(ResourceMonitor,{auth}):section.value==='service-map'?h(ServiceMap):h(MigrationConsole,{auth,embedded:true}))});
}});
app.use(router);
router.isReady().then(() => app.mount('#app'));

(async()=>{try{if(!await auth.init())return;
// Auth can restore a different deep link via history.replaceState during OIDC callback.
// Reconcile Vue Router after callback processing (the router was installed earlier).
await router.isReady();
const restoredPath = window.location.pathname.replace(/^\/system(?=\/|$)/, '') || '/';
const restoredTarget = restoredPath + window.location.search + window.location.hash;
if (router.currentRoute.value.fullPath !== restoredTarget) await router.replace(restoredTarget);
const response=await auth.fetch('/api/employees/access/me',{headers:{Accept:'application/json'},cache:'no-store'});
if(!response.ok)throw new Error('Role check failed');
access.value=(await response.json())?.data||null;authorized.value=isPlatformAdministratorAccess(access.value);
if (authorized.value && router.currentRoute.value.name === 'root') await router.replace({ name: 'resources' });
}catch(e){error.value='Не удалось проверить доступ. Повторите позднее.';console.error('System access check failed',e);}
finally{loading.value=false;}})();

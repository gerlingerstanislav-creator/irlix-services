import '@fontsource-variable/onest/wght.css';
import { createApp, h, ref, computed, onMounted, onUnmounted } from 'vue';
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
const routeFor=path=>['/system/migration','/system/migration/'].includes(path)?'migration-control':
  sections.find(item=>path===item.href||path===item.href.slice(0,-1))?.id||'resources';
if(['/system/migration','/system/migration/'].includes(location.pathname))
  history.replaceState(history.state,'','/system/migration/control/'+location.search+location.hash);
const section=ref(routeFor(location.pathname)),loading=ref(true),authorized=ref(false),error=ref(''),access=ref(null);
function navigate(id){const item=sections.find(i=>i.id===id);if(!item)return;const preserveMigrationQuery=section.value==='migration-control'&&id==='migration-control';const target=item.href+(preserveMigrationQuery?location.search:'');section.value=id;if(location.pathname+location.search!==target)history.pushState({},'',target);}
function onHistory(){section.value=routeFor(location.pathname);}
createApp({setup(){
onMounted(()=>window.addEventListener('popstate',onHistory));onUnmounted(()=>window.removeEventListener('popstate',onHistory));
const breadcrumbs=computed(()=>[{label:sections.find(i=>i.id===section.value)?.label||'Ресурсный монитор'}]);
return ()=>loading.value?h('main',{class:'system-status',role:'status'},'Проверяем доступ…')
:(error.value||!authorized.value)?h('main',{class:'system-status',role:'alert'},[
h('h1','Система'),h('p',error.value||'Доступ разрешён только администратору платформы.'),h('a',{href:'/'},'Вернуться на дашборд')])
:h(UiAppShell,{service:'system',serviceName:'Система',section:section.value,items:sections,breadcrumbs:breadcrumbs.value,
  currentUser:auth.user,platformAdmin:true,serviceAccess:access.value,'onUpdate:section':navigate,onLogout:()=>auth.logout()},
{default:()=>h('main',{class:'system-workspace'},section.value==='resources'?h(ResourceMonitor,{auth}):section.value==='service-map'?h(ServiceMap):h(MigrationConsole,{auth,embedded:true}))});
}}).mount('#app');
(async()=>{try{if(!await auth.init())return;
const response=await auth.fetch('/api/employees/access/me',{headers:{Accept:'application/json'},cache:'no-store'});
if(!response.ok)throw new Error('Role check failed');
access.value=(await response.json())?.data||null;authorized.value=isPlatformAdministratorAccess(access.value);
if(authorized.value && (location.pathname==='/system/'||location.pathname==='/system'))history.replaceState({},'','/system/resources/');
}catch(e){error.value='Не удалось проверить доступ. Повторите позднее.';console.error('System access check failed',e);}
finally{loading.value=false;}})();

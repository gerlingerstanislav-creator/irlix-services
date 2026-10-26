<script setup>
import {computed,reactive,ref,watch} from 'vue';
import {UiBadge,UiButton,UiDrawer,UiLoader} from '@irlix/ui';
import {serviceTitles} from './migration-control-model.js';

const props=defineProps({
  auth:{type:Object,required:true},
  view:{type:String,default:''},
  service:{type:String,default:'employees'},
  backupScope:{type:String,default:'all'},
  backupActionRequest:{type:String,default:''},
  busy:{type:Boolean,default:false},
});
const emit=defineEmits(['close','changed']);
const modules=ref([]);
const selectedService=ref(props.service);
const scope=ref('all');
const pending=ref(false),loading=ref(false),error=ref(''),notice=ref('');
const backups=ref([]),target=ref(null),backupAction=ref(''),confirmation=ref('');
const retentionCount=ref(2),savedRetentionCount=ref(2),retentionConfirm=ref(false);
const retentionOptions=Array.from({length:20},(_,i)=>i+1);
const form=reactive({
  host:'',port:5432,database:'',username:'',password:'',sslmode:'disable',
  readonly_acknowledged:false,legacy_app_key:'',legacy_cipher:'AES-256-CBC',
  clear_legacy_app_key:false,
});
const selectedModule=computed(()=>modules.value.find(m=>m.key===selectedService.value));
const isVerified=computed(()=>selectedModule.value?.connection?.credential_status==='ready'&&!!selectedModule.value?.connection?.verified_at);
const canChange=computed(()=>!props.busy&&!pending.value&&!loading.value);
const available=computed(()=>modules.value.filter(m=>m.status==='implemented'));
const allScopes=computed(()=>[{value:'all',label:'Все сервисы'},...available.value.map(m=>({value:m.key,label:serviceTitles[m.key]||m.title}))]);
const visibleBackups=computed(()=>backups.value.filter(item=>item.scope===scope.value).sort((a,b)=>String(b.created_at||'').localeCompare(String(a.created_at||''))));
function backupDate(value){
  if(!value)return 'Дата неизвестна';
  const normalized=typeof value==='number'?value*1000:Date.parse(String(value));
  return Number.isFinite(normalized)
    ?new Intl.DateTimeFormat('ru-RU',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}).format(new Date(normalized))
    :String(value);
}
async function api(path,options={}){
  const controller=new AbortController();
  const timeout=setTimeout(()=>controller.abort(),18000);
  try{
    const response=await props.auth.fetch('/api/migration'+path,{
      cache:'no-store',signal:controller.signal,
      headers:{Accept:'application/json','Content-Type':'application/json'},
      ...options,
    });
    let body;try{body=await response.json();}catch{body={};}
    if(!response.ok)throw new Error(body.message||'Migration API: '+response.status);
    return body.data??body;
  }finally{clearTimeout(timeout);}
}
function fillConnection(){
  const current=selectedModule.value?.connection||{};
  Object.assign(form,{
    host:current.host||'',port:current.port||5432,database:current.database||'',
    username:current.username||'',password:'',sslmode:current.sslmode||'disable',
    readonly_acknowledged:!!current.readonly_acknowledged,
    legacy_app_key:'',legacy_cipher:current.legacy_cipher||'AES-256-CBC',clear_legacy_app_key:false,
  });
}
watch(()=>props.service,value=>{selectedService.value=value;error.value='';notice.value='';fillConnection();});
watch(()=>props.view,value=>{
  error.value='';notice.value='';target.value=null;backupAction.value='';confirmation.value='';retentionConfirm.value=false;
  if(value==='connections'){
    selectedService.value=props.service;
    void loadConnections();
  }
  if(value==='backups'){
    void loadConnections();
    scope.value=props.backupScope;
    void loadBackups().then(()=>{
      // Open the requested service/overall rollback list; never restore
      // automatically. Confirmation remains a separate human action.
      target.value=null;
    });
  }
});
watch(()=>[props.backupScope,props.backupActionRequest],([selectedScope])=>{
  if(props.view==='backups') {
    scope.value=selectedScope;
    target.value=null;backupAction.value='';confirmation.value='';
  }
});
async function loadConnections(){
  loading.value=true;error.value='';
  try{
    const data=await api('/state');
    modules.value=(data.modules||[]).filter(m=>m.status==='implemented');
    if(!modules.value.some(m=>m.key===selectedService.value))selectedService.value=modules.value[0]?.key||'employees';
    fillConnection();
  }catch(e){error.value=e.message;}
  finally{loading.value=false;}
}
async function loadBackups(){
  loading.value=true;error.value='';
  try{
    const [control,employees,vacations]=await Promise.all([
      api('/control/state'),
      api('/snapshots').catch(()=>null),
      api('/vacations/snapshots').catch(()=>null),
    ]);
    const rule=control.snapshot_retention||{};
    retentionCount.value=Number.isInteger(rule.count)?rule.count:2;
    savedRetentionCount.value=retentionCount.value;
    retentionConfirm.value=false;
    const current=control.snapshots||{};
    const items=Object.entries(current).flatMap(([key,rows])=>
      (Array.isArray(rows)?rows:[]).map(row=>({...row,scope:key,source:'console'})));
    // Legacy Employees and Vacations snapshots are separate resources.
    // Keep their actions pointed at their original protected endpoints.
    for(const [service,response] of [['employees',employees],['vacations',vacations]]){
      const older=Array.isArray(response?.snapshots)?response.snapshots:
        Array.isArray(response?.snapshots?.[service])?response.snapshots[service]:[];
      for(const row of older)items.push({...row,scope:service,source:'legacy'});
    }
    backups.value=items;
  }catch(e){error.value=e.message;}
  finally{loading.value=false;}
}
async function run(action){
  if(!canChange.value)return;
  pending.value=true;error.value='';notice.value='';
  try{
    const service=selectedService.value;
    if(action==='save'){
      await api('/services/'+service+'/connection',{method:'PUT',body:JSON.stringify({...form,port:Number(form.port)})});
      form.password='';form.legacy_app_key='';form.clear_legacy_app_key=false;
      notice.value='Подключение сохранено. Перед переносом выполните read-only проверку.';
    }else if(action==='verify'){
      await api('/services/'+service+'/verify',{method:'POST',body:'{}'});
      notice.value='Read-only подключение проверено.';
    }else if(action==='reachability'){
      await api('/services/'+service+'/reachability',{method:'POST',body:JSON.stringify({host:form.host,port:Number(form.port)})});
      notice.value='Сервер доступен по TCP. Это не проверка прав доступа.';
    }else if(action==='delete'){
      await api('/services/'+service+'/connection',{method:'DELETE'});
      notice.value='Подключение удалено.';
    }else if(action==='inspect'||action==='dry-run'){
      await api('/services/'+service+'/runs',{method:'POST',body:JSON.stringify({mode:action})});
      notice.value=action==='inspect'?'Inspect запущен. Результат появится в журнале этапа.':'Dry run запущен; запись бизнес-данных не выполняется.';
    }
    const previous=notice.value;
    await loadConnections();
    notice.value=previous;
    emit('changed');
  }catch(e){error.value=e.message;}
  finally{pending.value=false;}
}
async function saveRetention(){
  if(!canChange.value||!retentionConfirm.value)return;
  pending.value=true;error.value='';notice.value='';
  try{
    const result=await api('/control/snapshot-retention',{
      method:'PUT',body:JSON.stringify({count:Number(retentionCount.value)}),
    });
    notice.value='Правило сохранено. Удалено старых точек: '+result.removed+
      (result.skipped_protected?' · защищено от удаления: '+result.skipped_protected:'')+
      (result.skipped_unverified?' · не удалось проверить: '+result.skipped_unverified:'')+'.';
    const message=notice.value;
    await loadBackups();
    notice.value=message;
    emit('changed');
  }catch(e){error.value=e.message;}
  finally{pending.value=false;retentionConfirm.value=false;}
}
async function snapshot(){
  if(!canChange.value)return;
  pending.value=true;error.value='';notice.value='';
  try{
    await api('/control/snapshot',{method:'POST',body:JSON.stringify({scope:scope.value})});
    notice.value='Создание контрольной точки запрошено. Результат подтверждается отдельной операцией.';
    emit('changed');
  }catch(e){error.value=e.message;}
  finally{pending.value=false;}
}
function chooseBackup(item,kind){
  target.value=item;backupAction.value=kind;confirmation.value='';error.value='';notice.value='';
}
async function commitBackup(){
  if(!target.value||!backupAction.value||!canChange.value)return;
  const item=target.value,kind=backupAction.value;
  if(kind==='restore'&&confirmation.value!=='RESTORE '+item.scope.toUpperCase())return;
  pending.value=true;error.value='';notice.value='';
  try{
    if(kind==='delete'){
      await api(item.source==='console'?'/control/snapshots/'+item.scope+'/'+item.id:
        item.scope==='employees'?'/snapshots/'+item.id:'/'+item.scope+'/snapshots/'+item.id,{method:'DELETE'});
      notice.value='Снимок удалён.';
    }else{
      await api(item.source==='console'?'/control/restore':
        item.scope==='employees'?'/snapshots/'+item.id+'/restore':'/'+item.scope+'/snapshots/'+item.id+'/restore',{
        method:'POST',body:JSON.stringify(item.source==='console'?
          {scope:item.scope,snapshot_id:item.id,confirmation:confirmation.value}:
          {confirmation:confirmation.value}),
      });
      notice.value='Операция восстановления запрошена. Успешный откат пока не подтверждён.';
      emit('changed');
    }
    target.value=null;backupAction.value='';await loadBackups();
  }catch(e){error.value=e.message;}
  finally{pending.value=false;}
}
</script>

<template>
  <UiDrawer :open="view==='connections'" :title="'Настройки импорта · '+(serviceTitles[selectedService]||selectedService)" width="560px" @close="emit('close')">
    <div class="mctrl-admin">
      <UiLoader v-if="loading" show-label label="Читаем настройки подключений…"/>
      <p v-if="error" role="alert" class="mctrl-admin-error">{{error}}</p>
      <p v-if="notice" role="status">{{notice}}</p>
      <p>Подключение к исходной БД и параметры импорта для выбранного сервиса.</p>
      <template v-if="selectedModule">
        <UiBadge :tone="isVerified?'success':'warning'">{{isVerified?'Подключение проверено':'Требуется проверка read-only'}}</UiBadge>
        <form class="mctrl-admin-form" @submit.prevent="run('save')">
          <fieldset :disabled="!canChange">
            <label class="irlix-field">Хост<input v-model="form.host" required autocomplete="off"/></label>
            <div class="mctrl-admin-pair">
              <label class="irlix-field">Порт<input v-model.number="form.port" type="number" min="1" max="65535" required/></label>
              <label class="irlix-field">База данных<input v-model="form.database" required/></label>
            </div>
            <label class="irlix-field">Пользователь<input v-model="form.username" required autocomplete="off"/></label>
            <label class="irlix-field">Пароль<input v-model="form.password" type="password" autocomplete="new-password" placeholder="Пусто — оставить сохранённый"/></label>
            <p v-if="selectedModule.connection?.password_set" class="mctrl-admin-secret-status">Пароль БД сохранён; для замены введите новый. Значение не отображается.</p>
            <template v-if="selectedService==='clients'">
              <label class="irlix-field">Legacy Clients APP_KEY<input v-model="form.legacy_app_key" type="password" autocomplete="new-password" :disabled="form.clear_legacy_app_key" placeholder="Пусто — оставить ключ"/></label>
              <p class="mctrl-admin-secret-status">{{selectedModule.connection?.legacy_app_key_configured?'Старый APP_KEY сохранён. Пустое поле не изменит его.':'Старый APP_KEY не задан. Для переноса зашифрованных ставок Clients его необходимо указать.'}} Значение ключа не отображается.</p>
              <label class="irlix-field">Алгоритм шифрования
                <select v-model="form.legacy_cipher">
                  <option v-for="v in ['AES-256-CBC','AES-128-CBC','AES-256-GCM','AES-128-GCM']" :key="v">{{v}}</option>
                </select>
              </label>
              <label v-if="selectedModule.connection?.legacy_app_key_configured">
                <input v-model="form.clear_legacy_app_key" type="checkbox"/> Удалить сохранённый legacy APP_KEY
              </label>
            </template>
            <label class="irlix-field">SSL
              <select v-model="form.sslmode"><option v-for="v in ['disable','allow','prefer','require','verify-ca','verify-full']" :key="v">{{v}}</option></select>
            </label>
            <label><input v-model="form.readonly_acknowledged" type="checkbox"/> Подтверждаю: учётная запись БД имеет только права чтения</label>
          </fieldset>
          <div class="mctrl-admin-actions">
            <UiButton type="submit" :disabled="!canChange||!form.readonly_acknowledged">Сохранить доступ</UiButton>
            <UiButton variant="secondary" :disabled="!canChange" @click="run('reachability')">Проверить сервер</UiButton>
            <UiButton variant="secondary" :disabled="!canChange" @click="run('verify')">Проверить подключение</UiButton>
            <UiButton variant="secondary" :disabled="!canChange||!isVerified" @click="run('inspect')">Inspect</UiButton>
            <UiButton variant="secondary" :disabled="!canChange||!isVerified" @click="run('dry-run')">Dry run</UiButton>
            <UiButton variant="danger" :disabled="!canChange" @click="run('delete')">Удалить доступ</UiButton>
          </div>
        </form>
      </template>
      <p v-if="busy">При активном переносе изменение доступа запрещено.</p>
    </div>
  </UiDrawer>
  <UiDrawer :open="view==='backups'" title="Снимки и восстановление" width="760px" @close="emit('close')">
    <div class="mctrl-admin mctrl-backups">
      <p class="mctrl-backups-intro">Снимок сохраняет точку отката. Восстановление изменяет данные и требует подтверждения.</p>
      <UiLoader v-if="loading" show-label label="Читаем снимки…"/>
      <p v-if="error" role="alert" class="mctrl-admin-error">{{error}}</p>
      <p v-if="notice" role="status" class="mctrl-backups-notice">{{notice}}</p>
      <p v-if="props.backupActionRequest==='snapshot'" class="mctrl-backups-context">
        Создать точку отката для {{serviceTitles[scope]||scope}}.
      </p>
      <p v-else-if="props.backupActionRequest==='restore'" class="mctrl-backups-context">
        Выберите точку для {{serviceTitles[scope]||scope}} и подтвердите восстановление.
      </p>
      <section class="mctrl-backups-main" aria-label="Управление снимками">
        <label class="irlix-field mctrl-backups-scope">Контур
          <select v-model="scope" :disabled="pending" aria-label="Контур снимков">
            <option v-for="item in allScopes" :key="item.value" :value="item.value">{{item.label}}</option>
          </select>
        </label>
        <div class="mctrl-admin-actions mctrl-backups-toolbar">
          <UiButton :disabled="!canChange" @click="snapshot">Создать снимок</UiButton>
          <UiButton variant="secondary" :disabled="loading||pending" @click="loadBackups">Обновить список</UiButton>
        </div>
      </section>
      <section class="mctrl-backups-list" aria-label="Точки отката">
        <div class="mctrl-backups-section-title">
          <strong>Точки отката</strong>
          <span class="mctrl-backups-counter">{{loading?'Загрузка…':visibleBackups.length+' шт.'}}</span>
        </div>
        <p v-if="!loading&&!visibleBackups.length" class="mctrl-backups-empty">
          Для выбранного контура снимков пока нет.
        </p>
        <div v-for="item in visibleBackups" :key="item.source+':'+item.scope+':'+item.id"
          class="mctrl-backup-card">
          <div class="mctrl-backup-card-main">
            <div class="mctrl-backup-info">
              <div class="mctrl-backup-heading">
                <strong>#{{item.id}}</strong>
                <span class="mctrl-backup-date">{{backupDate(item.created_at)}}</span>
              </div>
              <div class="mctrl-backup-meta">
                <span>{{item.source==='console'?'Контрольная точка':'Исторический снимок'}}</span>
                <span v-if="item.compatible===false" class="mctrl-backup-incompatible">Несовместимый состав сервисов</span>
                <span v-else-if="item.restored">Ранее восстанавливался</span>
              </div>
            </div>
            <div class="mctrl-admin-actions mctrl-backup-card-actions">
              <UiButton variant="secondary" compact :disabled="!canChange||item.compatible===false"
                :aria-label="'Восстановить снимок #'+item.id" @click="chooseBackup(item,'restore')">Восстановить</UiButton>
              <UiButton variant="danger" compact :disabled="!canChange"
                :aria-label="'Удалить снимок #'+item.id" @click="chooseBackup(item,'delete')">Удалить</UiButton>
            </div>
          </div>
          <div v-if="target===item" class="mctrl-backup-confirm">
            <strong>{{backupAction==='delete'?'Удаление снимка #':'Восстановление снимка #'}}{{item.id}}</strong>
            <p v-if="backupAction==='restore'">Изменения после этой точки могут быть потеряны. Проверьте, что worker остановлен.</p>
            <p v-else>Удалённую точку нельзя восстановить.</p>
            <label v-if="backupAction==='restore'" class="irlix-field">
              Для подтверждения введите RESTORE {{item.scope.toUpperCase()}}
              <input v-model="confirmation" autocomplete="off" :disabled="!canChange"/>
            </label>
            <div class="mctrl-admin-actions">
              <UiButton variant="danger" :disabled="!canChange||(backupAction==='restore'&&confirmation!=='RESTORE '+item.scope.toUpperCase())"
                @click="commitBackup">{{backupAction==='restore'?'Подтвердить восстановление':'Подтвердить удаление'}}</UiButton>
              <UiButton variant="secondary" @click="target=null;backupAction=''">Отмена</UiButton>
            </div>
          </div>
        </div>
      </section>
      <details class="mctrl-backups-settings">
        <summary>
          <span>Настройки хранения</span>
          <span class="mctrl-backups-settings-summary">Последних {{savedRetentionCount}} на контур</span>
        </summary>
        <div class="mctrl-backups-settings-content">
          <p>Единое правило для новых снимков всех сервисов. Исторические legacy-снимки управляются отдельно.</p>
          <div class="mctrl-backups-retention-row">
            <label class="irlix-field">Сохранять последних снимков
              <select v-model.number="retentionCount" :disabled="!canChange||retentionConfirm"
                aria-label="Количество хранимых точек отката">
                <option v-for="n in retentionOptions" :key="n" :value="n">{{n}}</option>
              </select>
            </label>
            <UiButton variant="secondary" :disabled="!canChange||retentionCount===savedRetentionCount"
              @click="retentionConfirm=true">Применить</UiButton>
          </div>
          <div v-if="retentionConfirm" class="mctrl-admin-confirm" role="alertdialog"
            aria-label="Подтверждение удаления старых снимков">
            <strong>Оставить последние {{retentionCount}} точек на контур?</strong>
            <p>Старые проверенные снимки будут удалены. Защищённые и не прошедшие проверку целостности сохранятся.</p>
            <div class="mctrl-admin-actions">
              <UiButton variant="danger" :disabled="!canChange" @click="saveRetention">Подтвердить и очистить</UiButton>
              <UiButton variant="secondary" @click="retentionConfirm=false">Отмена</UiButton>
            </div>
          </div>
        </div>
      </details>
    </div>
  </UiDrawer>
</template>

<style scoped>
.mctrl-admin{display:flex;flex-direction:column;gap:12px;padding:4px 0}
.mctrl-admin .irlix-field{display:flex;flex-direction:column;gap:5px;min-width:0}
.mctrl-admin input:not([type=checkbox]),.mctrl-admin select{width:100%;min-height:34px}
.mctrl-admin-form,.mctrl-admin-form fieldset{display:flex;flex-direction:column;gap:12px}
.mctrl-admin-form fieldset{border:0;padding:0;margin:0;min-width:0}
.mctrl-admin-pair{display:grid;grid-template-columns:110px minmax(0,1fr);gap:12px}
.mctrl-admin-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.mctrl-backups{gap:14px}
.mctrl-backups-intro{margin:0;color:var(--irlix-color-text-muted);line-height:1.5}
.mctrl-backups-context{margin:0;font-weight:600}
.mctrl-backups-main{display:flex;align-items:end;gap:12px;flex-wrap:wrap;min-width:0}
.mctrl-backups-scope{flex:1 1 220px;max-width:370px}
.mctrl-backups-toolbar{padding-bottom:1px}
.mctrl-backups-list{display:flex;flex-direction:column;min-width:0;gap:0}
.mctrl-backups-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:4px 0 9px}
.mctrl-backups-counter,.mctrl-backup-date{color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}
.mctrl-backups-empty{padding:18px 12px;text-align:center;border:1px dashed var(--irlix-color-border);border-radius:8px;color:var(--irlix-color-text-muted)}
.mctrl-backup-card{border-top:1px solid var(--irlix-color-border);min-width:0}
.mctrl-backup-card:last-child{border-bottom:1px solid var(--irlix-color-border)}
.mctrl-backup-card-main{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0;min-width:0}
.mctrl-backup-info{flex:1 1 auto;min-width:0}
.mctrl-backup-heading{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;overflow-wrap:anywhere}
.mctrl-backup-meta{display:flex;flex-wrap:wrap;gap:6px 12px;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption);margin-top:4px}
.mctrl-backup-incompatible{color:var(--irlix-color-danger)}
.mctrl-backup-card-actions{flex:0 0 auto}
.mctrl-backup-confirm{margin:0 0 12px;padding:12px;border:1px solid var(--irlix-color-border);border-radius:8px;background:var(--irlix-color-surface-muted);display:flex;flex-direction:column;gap:9px}
.mctrl-backup-confirm p{margin:0}
.mctrl-backups-notice{margin:0;padding:8px 10px;border:1px solid var(--irlix-color-border);border-radius:8px}
.mctrl-backups-settings{border:1px solid var(--irlix-color-border);border-radius:8px;margin-top:2px}
.mctrl-backups-settings summary{cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:12px;font-weight:600}
.mctrl-backups-settings summary::marker{content:''}
.mctrl-backups-settings summary::-webkit-details-marker{display:none}
.mctrl-backups-settings summary:before{content:'›';margin-right:4px;color:var(--irlix-color-text-muted);font-size:18px}
.mctrl-backups-settings[open] summary:before{transform:rotate(90deg)}
.mctrl-backups-settings-summary{margin-left:auto;color:var(--irlix-color-text-muted);font-weight:400;font-size:var(--irlix-font-size-caption)}
.mctrl-backups-settings-content{padding:0 12px 12px;display:flex;flex-direction:column;gap:10px}
.mctrl-backups-settings-content p{margin:0;color:var(--irlix-color-text-muted)}
.mctrl-backups-retention-row{display:flex;align-items:end;gap:12px;flex-wrap:wrap}
.mctrl-backups-retention-row .irlix-field{flex:1 1 175px;max-width:260px}
.mctrl-admin-secret-status{margin:0;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption);line-height:1.4}
.mctrl-admin-error{color:var(--irlix-color-danger,#b91c1c)}
.mctrl-admin-confirm{padding:12px;border:1px solid var(--irlix-color-border);border-radius:8px}
@media(max-width:600px){.mctrl-backup-card-main{align-items:flex-start;flex-direction:column}
.mctrl-backup-card-actions{width:100%}.mctrl-backup-card-actions .ui-button{flex:1}
.mctrl-backups-main{align-items:stretch}.mctrl-backups-scope{max-width:none}}

</style>

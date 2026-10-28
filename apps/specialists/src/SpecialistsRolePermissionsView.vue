<script setup>
import { computed, onMounted, ref } from 'vue';
import { auth } from './auth';

const loading=ref(false),saving=ref(''),error=ref('');
const policy=ref({permissions:[],matrix:[],enabled:false});
const newRole=ref(''),addedRoles=ref([]);
const defaultRoles=['org:irlix','head:backend','head:frontend','head:qa','special:platform-tester'];
const roles=computed(()=>[...new Set([...defaultRoles,...addedRoles.value,
  ...policy.value.matrix.map(row=>row.role)])].filter(role=>role!=='special:platform-admin').sort());
const validRole=computed(()=>/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/.test(newRole.value.trim())
  && newRole.value.trim()!=='special:platform-admin');
const labels={
  'specialists.profiles.view':'Специалисты — просмотр',
  'specialists.profiles.manage':'Специалисты — изменение',
  'specialists.catalog.view':'Каталог технологий — просмотр',
  'specialists.catalog.manage':'Каталог технологий — изменение',
};
const cell=(role,permission)=>{
  const record=policy.value.matrix.find(row=>row.role===role && row.permission===permission);
  return !record?'inherit':(record.allowed?record.scope:'deny');
};
async function request(options={}){
  const response=await auth.fetch('/api/specialists/role-permissions',{
    ...options,headers:{Accept:'application/json','Content-Type':'application/json'},
  });
  const body=await response.json().catch(()=>({}));
  if(!response.ok)throw new Error(body.message||'Недостаточно прав');
  return body.data;
}
async function load(){
  loading.value=true;error.value='';
  try{policy.value=await request();}
  catch(e){error.value=e.message;}
  finally{loading.value=false;}
}
async function save(role,permission,scope){
  if(saving.value)return;
  saving.value=role+permission;error.value='';
  try{
    policy.value=await request(scope==='inherit'
      ? {method:'DELETE',body:JSON.stringify({role,permission})}
      : {method:'PUT',body:JSON.stringify({
          role,permission,allowed:scope!=='deny',scope:scope==='deny'?'none':scope,
        })});
  }catch(e){error.value=e.message;}
  finally{saving.value='';}
}
function addRole(){
  if(!validRole.value)return;
  addedRoles.value=[...addedRoles.value,newRole.value.trim()];
  newRole.value='';
}
onMounted(load);
</script>

<template>
  <section class="specialists-roles">
    <h2>Разрешения Specialists</h2>
    <p>Права задаются независимо от других сервисов. Область «Команда» дополнительно проверяется текущей оргструктурой: руководитель видит только управляемые производственные направления и их дочерние подразделения.</p>
    <p v-if="!policy.enabled" class="specialists-roles-note">Матрица пока в режиме подготовки. Действующие разрешения не изменены.</p>
    <p v-if="error" role="alert">{{error}} <button @click="load">Повторить</button></p>
    <p v-if="loading">Загрузка…</p>
    <template v-else>
      <div class="specialists-roles-add">
        <label>Роль из Keycloak <input v-model="newRole" placeholder="head:department-alias"/></label>
        <button type="button" :disabled="!validRole" @click="addRole">Добавить колонку</button>
      </div>
      <div class="specialists-roles-table">
        <table>
          <thead><tr><th>Разрешение</th><th v-for="role in roles" :key="role">{{role}}</th></tr></thead>
          <tbody>
            <tr v-for="permission in policy.permissions" :key="permission">
              <th scope="row">{{labels[permission]||permission}}</th>
              <td v-for="role in roles" :key="role">
                <select :value="cell(role,permission)" :disabled="!!saving"
                  :aria-label="role+' — '+permission" @change="save(role,permission,$event.target.value)">
                  <option value="inherit">Не назначено</option>
                  <option value="deny">Явный запрет</option>
                  <option v-if="permission.startsWith('specialists.profiles.')" value="team">Команда</option>
                  <option value="all">Все</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p>Каталог технологий доступен только в области «Все». Изменение матрицы не отменяет ограничений Employee Directory на доступ к чужим профилям.</p>
    </template>
  </section>
</template>

<style scoped>
.specialists-roles{display:flex;flex-direction:column;gap:12px;min-width:0}
.specialists-roles h2{font-size:18px;margin:0}
.specialists-roles p{font-size:13px;margin:0;line-height:1.6}
.specialists-roles-note{border:1px solid var(--irlix-color-border);padding:12px;border-radius:8px;background:var(--irlix-color-surface-muted)}
.specialists-roles-add{display:flex;gap:8px;align-items:flex-end}
.specialists-roles-add label{display:flex;flex-direction:column;gap:5px}
.specialists-roles-add input,.specialists-roles-add button,.specialists-roles-table select{border:1px solid var(--irlix-color-border);background:var(--irlix-color-surface);color:var(--irlix-color-text);font:inherit;padding:6px 8px;border-radius:6px}
.specialists-roles-table{max-height:calc(100dvh - 260px);overflow:auto;border:1px solid var(--irlix-color-border);border-radius:8px}
.specialists-roles-table table{min-width:900px;border-spacing:0;border-collapse:separate}
.specialists-roles-table th,.specialists-roles-table td{padding:9px 11px;white-space:nowrap;text-align:left;font-size:12px;border-right:1px solid var(--irlix-color-border);border-bottom:1px solid var(--irlix-color-border)}
.specialists-roles-table th{background:var(--irlix-color-surface-muted)}
</style>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { auth } from './auth';

const policy=ref({permissions:[],matrix:[],enabled:false});
const loading=ref(false),error=ref(''),saving=ref('');
const newRole=ref(''),addedRoles=ref([]);
const roles=computed(()=>[...new Set([
  'org:irlix','org:hr','head:backend','special:recruiter',...addedRoles.value,
  ...policy.value.matrix.map(row=>row.role),
])].filter(role=>role!=='special:platform-admin').sort());
const roleValid=computed(()=>/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/.test(newRole.value.trim())
  && newRole.value.trim()!=='special:platform-admin');
const cell=(role,permission)=>{
  const row=policy.value.matrix.find(row=>row.role===role && row.permission===permission);
  return !row?'inherit':(row.allowed?'all':'deny');
};
async function request(options={}) {
  const response=await auth.fetch('/api/recruitment/role-permissions',{
    ...options,headers:{Accept:'application/json','Content-Type':'application/json'},
  });
  const payload=await response.json().catch(()=>({}));
  if(!response.ok)throw new Error(payload.message||'Недостаточно прав');
  return payload.data;
}
async function load(){
  loading.value=true;error.value='';
  try{policy.value=await request();}
  catch(e){error.value=e.message;}
  finally{loading.value=false;}
}
async function save(role,permission,value){
  if(saving.value)return;
  saving.value=role+permission;error.value='';
  try{
    policy.value=await request(value==='inherit'
      ? {method:'DELETE',body:JSON.stringify({role,permission})}
      : {method:'PUT',body:JSON.stringify({
          role,permission,allowed:value==='all',scope:value==='all'?'all':'none',
        })});
  }catch(e){error.value=e.message;}
  finally{saving.value='';}
}
function addRole(){
  if(!roleValid.value)return;
  addedRoles.value=[...addedRoles.value,newRole.value.trim()];
  newRole.value='';
}
onMounted(load);
</script>

<template>
  <section class="recruitment-roles">
    <h2>Разрешения Recruitment</h2>
    <p>Каждый вид действия регулируется локальной матрицей. Роль Keycloak не предоставляет автоматически доступа к кандидатам, интервью или офферам. Администратор платформы имеет системный полный доступ.</p>
    <p v-if="!policy.enabled" class="recruitment-roles-note">Матрица в режиме подготовки: существующие разрешения не изменились. Переключение будет возможно после согласования объектных scopes и безопасной миграции.</p>
    <p v-if="error" role="alert">{{error}} <button type="button" @click="load">Повторить</button></p>
    <p v-if="loading">Загрузка…</p>
    <template v-else>
      <div class="recruitment-roles-add">
        <label>Добавить роль <input v-model="newRole" placeholder="org:department-alias"/></label>
        <button type="button" :disabled="!roleValid" @click="addRole">Добавить колонку</button>
      </div>
      <div class="recruitment-roles-table">
        <table>
          <thead><tr><th>Разрешение</th><th v-for="role in roles" :key="role">{{role}}</th></tr></thead>
          <tbody>
            <tr v-for="permission in policy.permissions" :key="permission">
              <th scope="row">{{permission}}</th>
              <td v-for="role in roles" :key="role">
                <select :value="cell(role,permission)" :disabled="!!saving"
                  :aria-label="role+' — '+permission" @change="save(role,permission,$event.target.value)">
                  <option value="inherit">Не назначено</option>
                  <option value="deny">Запретить</option>
                  <option value="all">Весь контур</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p>Области «Свои» и «Команда» недоступны до реализации защищённого разграничения кандидатов, офферов и заявок по подразделениям.</p>
    </template>
  </section>
</template>

<style scoped>
.recruitment-roles{display:flex;flex-direction:column;gap:12px;min-width:0}
.recruitment-roles h2{font-size:18px;margin:0}
.recruitment-roles p{margin:0;font-size:13px;line-height:1.6}
.recruitment-roles-note{padding:12px;background:var(--irlix-color-surface-muted);border:1px solid var(--irlix-color-border);border-radius:8px}
.recruitment-roles-add{display:flex;align-items:flex-end;gap:8px}
.recruitment-roles-add label{display:flex;flex-direction:column;gap:4px}
.recruitment-roles-add input,.recruitment-roles-add button,.recruitment-roles-table select{border:1px solid var(--irlix-color-border);border-radius:7px;background:var(--irlix-color-surface);color:var(--irlix-color-text);padding:7px 9px;font:inherit}
.recruitment-roles-table{overflow:auto;border:1px solid var(--irlix-color-border);border-radius:8px;max-height:calc(100dvh - 280px)}
.recruitment-roles-table table{min-width:900px;border-collapse:separate;border-spacing:0}
.recruitment-roles-table th,.recruitment-roles-table td{padding:9px 11px;font-size:12px;white-space:nowrap;text-align:left;border-bottom:1px solid var(--irlix-color-border);border-right:1px solid var(--irlix-color-border)}
.recruitment-roles-table th{background:var(--irlix-color-surface-muted)}
</style>

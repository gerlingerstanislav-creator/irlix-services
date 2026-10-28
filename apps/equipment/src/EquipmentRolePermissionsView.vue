<script setup>
import { computed, onMounted, ref } from 'vue';
import { auth } from './auth';

const loading=ref(false), saving=ref('');
const error=ref(''), policy=ref({permissions:[],matrix:[],enabled:false});
const newRole=ref(''), rolesAdded=ref([]);
const defaultRoles=['org:accounting','head:accounting','special:system-admin'];
const roleValid=computed(()=>/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/.test(newRole.value.trim())
  && newRole.value.trim()!=='special:platform-admin');
const roles=computed(()=>[...new Set([...defaultRoles,...rolesAdded.value,
  ...policy.value.matrix.map(row=>row.role)])].filter(role=>role!=='special:platform-admin').sort());
const labels={
  'equipment.view':'Техника — просмотр',
  'equipment.manage':'Техника — изменение данных, стоимости и настроек',
  'equipment.operate':'Выдача, возврат, списание и история',
};
const cell=(role,permission)=>{
  const row=policy.value.matrix.find(row=>row.role===role && row.permission===permission);
  return !row?'inherit':(row.allowed?'all':'deny');
};
async function request(options={}) {
  const response=await auth.fetch('/api/equipment/role-permissions',{
    ...options,headers:{Accept:'application/json','Content-Type':'application/json'},
  });
  const result=await response.json().catch(()=>({}));
  if(!response.ok)throw new Error(result.message||'Нет доступа к настройкам Equipment');
  return result.data;
}
async function load() {
  loading.value=true;error.value='';
  try{policy.value=await request();}
  catch(e){error.value=e.message;}
  finally{loading.value=false;}
}
async function save(role,permission,value) {
  if(saving.value)return;
  saving.value=role+permission;error.value='';
  try {
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
  rolesAdded.value=[...rolesAdded.value,newRole.value.trim()];
  newRole.value='';
}
onMounted(load);
</script>

<template>
  <section class="equipment-role-editor">
    <h2>Разрешения Equipment</h2>
    <p>Роли приходят из Keycloak; права просмотра, изменения и выдачи/списания назначаются только здесь. Бухгалтерия не получает право выдачи техники автоматически. Администратор платформы сохраняет системный полный доступ.</p>
    <p v-if="!policy.enabled" class="equipment-role-notice">Новая матрица находится в режиме подготовки и не влияет на действующие права до безопасного переключения.</p>
    <p v-if="error" role="alert">{{error}} <button @click="load">Повторить</button></p>
    <p v-if="loading">Загрузка…</p>
    <template v-else>
      <div class="equipment-role-add">
        <label>Дополнительная роль <input v-model="newRole" placeholder="org:department-alias"/></label>
        <button :disabled="!roleValid" @click="addRole">Добавить колонку</button>
      </div>
      <div class="equipment-role-matrix">
        <table>
          <thead><tr><th>Действие</th><th v-for="role in roles" :key="role">{{role}}</th></tr></thead>
          <tbody>
            <tr v-for="permission in policy.permissions" :key="permission">
              <th>{{labels[permission]||permission}}</th>
              <td v-for="role in roles" :key="role">
                <select :value="cell(role,permission)" :disabled="!!saving"
                  :aria-label="role+' — '+permission" @change="save(role,permission,$event.target.value)">
                  <option value="inherit">Не назначено</option>
                  <option value="deny">Явный запрет</option>
                  <option value="all">Весь реестр</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.equipment-role-editor{padding:16px;display:flex;flex-direction:column;gap:12px;min-width:0;flex:1}
.equipment-role-editor h2{margin:0;font-size:18px}
.equipment-role-editor p{margin:0;font-size:13px;line-height:1.6}
.equipment-role-notice{background:var(--irlix-color-surface-muted);border:1px solid var(--irlix-color-border);padding:12px;border-radius:8px}
.equipment-role-add{display:flex;align-items:flex-end;gap:9px}
.equipment-role-add label{display:flex;flex-direction:column;gap:5px;font-size:12px}
.equipment-role-add input,.equipment-role-add button,.equipment-role-matrix select{border:1px solid var(--irlix-color-border);background:var(--irlix-color-surface);color:var(--irlix-color-text);padding:6px 9px;font:inherit;border-radius:6px}
.equipment-role-matrix{overflow:auto;border:1px solid var(--irlix-color-border);border-radius:8px}
.equipment-role-matrix table{min-width:850px;border-collapse:separate;border-spacing:0}
.equipment-role-matrix th,.equipment-role-matrix td{text-align:left;padding:9px 11px;border-right:1px solid var(--irlix-color-border);border-bottom:1px solid var(--irlix-color-border);font-size:12px;white-space:nowrap}
.equipment-role-matrix th{background:var(--irlix-color-surface-muted)}
</style>

<script setup>
import {computed,ref,watch} from 'vue';
import {UiButton,UiDrawer,UiLoader} from '@irlix/ui';
const props=defineProps({
  auth:{type:Object,required:true},
  conflict:{type:Object,default:null},
  busy:{type:Boolean,default:false},
});
const emit=defineEmits(['close','saved']);
const login=ref(''),candidate=ref(null),error=ref(''),notice=ref(''),pending=ref(false);
const sourceId=computed(()=>String(props.conflict?.legacy_id??''));
const key=computed(()=>String(props.conflict?.id??''));
watch(()=>props.conflict,()=>{
  login.value='';candidate.value=null;error.value='';notice.value='';
});
async function call(path,options={}){
  const controller=new AbortController();
  const timeout=setTimeout(()=>controller.abort(),18000);
  try{
    const response=await props.auth.fetch('/api/migration'+path,{
      cache:'no-store',signal:controller.signal,
      headers:{Accept:'application/json','Content-Type':'application/json'},
      ...options,
    });
    let body;
    try{body=await response.json();}catch{body={};}
    if(!response.ok)throw new Error(body.message||'Migration API: '+response.status);
    return body.data??body;
  }finally{clearTimeout(timeout);}
}
async function verify(){
  if(!key.value||!login.value.trim()||pending.value||props.busy)return;
  pending.value=true;candidate.value=null;error.value='';notice.value='';
  const current=key.value;
  try{
    const result=await call('/console/conflicts/'+encodeURIComponent(current)+'/employee-match?login='+encodeURIComponent(login.value.trim()));
    if(key.value===current)candidate.value=result;
  }catch(e){if(key.value===current)error.value=e.message;}
  finally{pending.value=false;}
}
async function save(){
  if(!candidate.value||!key.value||props.busy||pending.value)return;
  pending.value=true;error.value='';notice.value='';
  try{
    const result=await call('/console/conflicts/'+encodeURIComponent(key.value)+'/employee-map',{
      method:'POST',
      body:JSON.stringify({
        login:login.value.trim(),employee_id:candidate.value.id,
        source_fingerprint:candidate.value.source_fingerprint,
        confirmation:'MAP USER '+sourceId.value,
      }),
    });
    notice.value=result.message||'Сопоставление сохранено; повторите Dry run.';
    candidate.value=null;
    emit('saved');
  }catch(e){error.value=e.message;}
  finally{pending.value=false;}
}
</script>
<template>
  <UiDrawer :open="!!conflict" title="Сопоставление пользователя legacy" width="540px" @close="emit('close')">
    <div class="mctrl-identity-form">
      <p>Источник: {{conflict?.service}} / users, старый ID #{{sourceId}}. Проверьте логин существующего сотрудника Employees. Запись в source-БД не изменяется.</p>
      <label>Текущий корпоративный логин сотрудника
        <input v-model.trim="login" autocomplete="off" :disabled="pending||busy" @input="candidate=null"/>
      </label>
      <UiButton variant="secondary" :disabled="!login.trim()||pending||busy" @click="verify">Проверить сотрудника</UiButton>
      <UiLoader v-if="pending" label="Проверяем сопоставление…" show-label/>
      <p v-if="error" role="alert">{{error}}</p>
      <p v-if="notice" role="status">{{notice}}</p>
      <template v-if="candidate">
        <p>Подтверждён сотрудник: {{candidate.login||candidate.target_login}} · ID #{{candidate.id}}</p>
        <p>После сохранения будет установлена постоянная связь. Изменение уже перенесённого пользователя к другому сотруднику без отката запрещено сервером.</p>
        <UiButton :disabled="busy||pending" @click="save">Подтвердить MAP USER {{sourceId}}</UiButton>
      </template>
      <p v-if="busy">Для изменения сопоставлений дождитесь окончания активного переноса.</p>
    </div>
  </UiDrawer>
</template>
<style scoped>
.mctrl-identity-form{padding:6px 0;display:flex;flex-direction:column;gap:12px}
.mctrl-identity-form label{display:flex;flex-direction:column;gap:6px}
.mctrl-identity-form input{min-height:36px;width:100%}
</style>

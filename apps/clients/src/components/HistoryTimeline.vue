<script setup>
import { computed, nextTick, ref, watch } from 'vue';
const props = defineProps({
  events:{type:Array,default:()=>[]},
  editable:{type:Boolean,default:false},
  busy:{type:Boolean,default:false},
  maxLength:{type:Number,default:20000},
  placeholder:{type:String,default:'Добавить запись в историю'},
});
const emit=defineEmits(['submit']);
const content=ref('');
const submittedText=ref('');
const scrollArea=ref(null);
const composer=ref(null);
async function resizeComposer(){await nextTick();if(!composer.value)return;composer.value.style.height='auto';composer.value.style.height=`${Math.min(composer.value.scrollHeight,240)}px`;}
const sorted=computed(()=>[...props.events].sort((a,b)=>new Date(a.created_at||0)-new Date(b.created_at||0)||(Number(a.id)||0)-(Number(b.id)||0)));
const latestKey=computed(()=>sorted.value.map(x=>[x.id,x.created_at,x.comment,x.text].join(':')).join('|'));
async function scrollToLatest(){await nextTick();if(scrollArea.value)scrollArea.value.scrollTop=scrollArea.value.scrollHeight;}
watch(latestKey,()=>{const last=sorted.value.at(-1);if(submittedText.value && (last?.text===submittedText.value||last?.comment===submittedText.value)){content.value='';submittedText.value='';resizeComposer();}scrollToLatest();},{immediate:true,flush:'post'});
watch(content,resizeComposer,{flush:'post'});
function onComposerKeydown(event){if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing){event.preventDefault();submit();}}
function submit(){const text=content.value.trim();if(!text||props.busy)return;submittedText.value=text;emit('submit',text);}
const dateTime=value=>value?new Date(value).toLocaleString('ru-RU',{dateStyle:'short',timeStyle:'short'}):'—';
</script>
<template>
  <section class="client-history" aria-label="История изменений">
    <div ref="scrollArea" class="client-history__feed">
      <article v-for="(event,index) in sorted" :key="event.id||index" class="client-history__entry">
        <span class="client-history__dot" aria-hidden="true"></span>
        <div class="client-history__content"><header><strong>{{(event.type==='comment'||event.type==='note') ? `Комментарий${event.author ? ' - '+event.author : ''}` : (event.title||event.status||'Событие')}}</strong><time>{{dateTime(event.created_at)}}</time></header>
          <p v-if="event.text||event.comment">{{event.text||event.comment}}</p>
          <small v-if="event.author && event.type!=='comment' && event.type!=='note'">{{event.author}}</small>
        </div>
      </article>
      <div v-if="!sorted.length" class="client-history__empty">История пока пуста</div>
    </div>
    <form v-if="editable" class="client-history__composer" @submit.prevent="submit">
      <textarea ref="composer" v-model="content" rows="2" @keydown="onComposerKeydown"  :maxlength="maxLength" :placeholder="placeholder" :disabled="busy"></textarea>
      <button type="submit" :disabled="busy||!content.trim()">Добавить</button>
    </form>
  </section>
</template>
<style scoped>
.client-history{display:flex;flex-direction:column;min-height:260px;height:100%;max-height:100%;overflow:hidden;gap:10px}
.client-history__feed{flex:1;min-height:160px;overflow-y:auto;overscroll-behavior:contain;padding:4px 6px}
.client-history__entry{position:relative;display:grid;grid-template-columns:12px minmax(0,1fr);gap:10px;padding-bottom:14px}
.client-history__entry:not(:last-of-type):before{position:absolute;content:"";left:4px;top:12px;bottom:0;border-left:1px dashed var(--irlix-color-border)}
.client-history__dot{z-index:1;margin-top:6px;height:8px;width:8px;border-radius:50%;background:var(--irlix-color-primary-soft);border:1px solid var(--irlix-color-primary)}
.client-history__content{min-width:0}
.client-history__content header{display:flex;align-items:baseline;justify-content:space-between;gap:12px}
.client-history__content strong{font-size:var(--irlix-font-size-table);font-weight:600}
.client-history__content time,.client-history__content small{flex:none;font-size:var(--irlix-font-size-caption);color:var(--irlix-color-text-muted)}
.client-history__content p{margin:5px 0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:var(--irlix-font-size-body)}
.client-history__empty{padding:30px 0;text-align:center;color:var(--irlix-color-text-muted)}
.client-history__composer{flex:none;display:flex;align-items:flex-end;gap:8px}
.client-history__composer textarea{flex:1;min-width:0;resize:none;overflow-y:auto;max-height:240px;border:1px solid var(--irlix-color-border);border-radius:7px;background:var(--irlix-color-surface);color:var(--irlix-color-text);padding:9px;font:inherit}
.client-history__composer button{flex:none;border:0;background:transparent;color:var(--irlix-color-primary-text);font:inherit;font-weight:600;cursor:pointer;padding:9px}
.client-history__composer button:disabled{opacity:.4;cursor:default}
</style>

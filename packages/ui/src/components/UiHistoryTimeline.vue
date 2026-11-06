<script setup>
import { computed, nextTick, ref, watch } from 'vue';
const props = defineProps({
  events: { type: Array, default: () => [] },
  editable: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  placeholder: { type: String, default: 'Добавить комментарий в историю' },
  maxLength: { type: Number, default: 5000 },
  scrollFeed: { type: Boolean, default: true },
});
const emit = defineEmits(['submit']);
const content = ref('');
const feed = ref(null);
const sorted = computed(() => [...props.events].sort((a,b) => String(a.created_at || '').localeCompare(String(b.created_at || '')) || String(a.id).localeCompare(String(b.id))));
watch(sorted, async () => {
  if (!props.scrollFeed) return; // In flowing mode the owning drawer tab controls scrolling.
  await nextTick();
  if (feed.value) feed.value.scrollTop = feed.value.scrollHeight;
}, { immediate: true });
watch(() => props.busy, (value,previous) => { if (previous && !value && !props.events.every(e => e.text !== content.value.trim())) content.value = ''; });
const dateLabel = value => {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? String(value).slice(0, 10) : d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
};
function submit() { const text = content.value.trim(); if (text && !props.busy) emit('submit', text); }
function onKeydown(event) { if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); submit(); } }
</script>
<template>
  <section class="irlix-history" :class="{ 'irlix-history--flow': !props.scrollFeed }" aria-label="История специалиста">
    <div ref="feed" class="irlix-history__feed">
      <article v-for="(event,index) in sorted" :key="event.id || index" class="irlix-history__event">
        <span class="irlix-history__dot" aria-hidden="true"></span>
        <div>
          <header><strong>{{ event.type === 'comment' ? 'Комментарий' : (event.title || 'Событие') }}</strong><time>{{ dateLabel(event.created_at) }}</time></header>
          <p v-if="event.text">{{ event.text }}</p>
          <small v-if="event.author">{{ event.author }}</small>
        </div>
      </article>
      <p v-if="!sorted.length" class="irlix-history__empty">Пока нет событий.</p>
    </div>
    <form v-if="editable" class="irlix-history__composer" @submit.prevent="submit">
      <textarea v-model="content" rows="2" :maxlength="maxLength" :placeholder="placeholder" :disabled="busy" @keydown="onKeydown" />
      <button type="submit" :disabled="busy || !content.trim()">{{ busy ? 'Сохранение…' : 'Добавить' }}</button>
    </form>
  </section>
</template>
<style scoped>
.irlix-history{display:flex;flex-direction:column;min-height:280px;max-height:100%;gap:12px}
.irlix-history__feed{flex:1;min-height:180px;overflow-y:auto;overscroll-behavior:contain;padding:6px 2px}
/* Let the parent scroll the entire timeline (events and composer) as one unit. */
.irlix-history--flow{max-height:none;min-height:0}
.irlix-history--flow .irlix-history__feed{flex:none;min-height:0;overflow:visible;overscroll-behavior:auto}
.irlix-history__event{position:relative;display:grid;grid-template-columns:16px minmax(0,1fr);gap:9px;padding-bottom:17px}
.irlix-history__event:not(:last-of-type):before{content:'';position:absolute;top:13px;bottom:0;left:5px;border-left:1px dashed var(--irlix-color-border)}
.irlix-history__dot{z-index:1;width:10px;height:10px;margin-top:5px;border-radius:50%;border:1px solid var(--irlix-color-primary);background:var(--irlix-color-primary-soft)}
.irlix-history__event header{display:flex;align-items:baseline;justify-content:space-between;gap:12px}
.irlix-history__event strong{font-size:var(--irlix-font-size-table);font-weight:600}
.irlix-history__event time,.irlix-history__event small{flex:none;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}
.irlix-history__event p{margin:5px 0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:var(--irlix-font-size-table)}
.irlix-history__empty{color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-table)}
.irlix-history__composer{display:grid;gap:8px;border-top:1px solid var(--irlix-color-border);padding-top:12px}
.irlix-history__composer textarea{width:100%;resize:vertical;max-height:220px;min-height:55px;padding:9px;border:1px solid var(--irlix-color-border);border-radius:var(--irlix-control-radius);font:inherit;color:var(--irlix-color-text);background:var(--irlix-color-surface)}
.irlix-history__composer button{justify-self:end;border:0;background:transparent;color:var(--irlix-color-primary-text);font:inherit;font-size:var(--irlix-font-size-table);cursor:pointer}
.irlix-history__composer button:disabled{opacity:.4;cursor:default}
</style>

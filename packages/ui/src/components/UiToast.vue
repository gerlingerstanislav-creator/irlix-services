<script setup>
import { onBeforeUnmount, onMounted } from 'vue';

const props = defineProps({
  message: { type: String, required: true },
  tone: {
    type: String,
    default: 'info',
    validator: value => ['info', 'success', 'warning', 'error'].includes(value),
  },
  duration: { type: Number, default: 5000 },
  actionLabel: { type: String, default: '' },
});
const emit = defineEmits(['close', 'action']);
let closeTimer = null;

onMounted(() => {
  if (props.duration > 0) closeTimer = setTimeout(() => emit('close'), props.duration);
});
onBeforeUnmount(() => {
  if (closeTimer !== null) clearTimeout(closeTimer);
});
</script>

<template>
  <Teleport to="body">
    <div class="irlix-toast-region irlix-ui">
      <div class="irlix-toast" :class="'irlix-toast--' + tone"
        :role="tone === 'error' ? 'alert' : 'status'" aria-atomic="true">
        <div class="irlix-toast__body">
          <span class="irlix-toast__symbol" aria-hidden="true">{{ tone === 'success' ? '✓' : tone === 'error' ? '!' : 'i' }}</span>
          <p class="irlix-toast__message">{{ message }}</p>
          <button v-if="actionLabel" type="button" class="irlix-toast__action"
            @click="emit('action')">{{ actionLabel }}</button>
          <button type="button" class="irlix-toast__close" aria-label="Закрыть уведомление"
            @click="emit('close')">×</button>
        </div>
        <div v-if="duration > 0" class="irlix-toast__progress" aria-hidden="true">
          <span class="irlix-toast__progress-bar" :style="{ animationDuration: duration + 'ms' }" />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.irlix-toast-region{
  position:fixed;right:max(16px, env(safe-area-inset-right));bottom:max(16px, env(safe-area-inset-bottom));
  z-index:3000;width:min(380px,calc(100vw - 32px));pointer-events:none;
}
.irlix-toast{
  --irlix-toast-accent:var(--irlix-status-info-text);
  position:relative;overflow:hidden;pointer-events:auto;
  border:1px solid var(--irlix-color-border);border-radius:var(--irlix-radius-lg);
  background:var(--irlix-color-surface);color:var(--irlix-color-text);
  box-shadow:0 10px 32px color-mix(in srgb,var(--irlix-color-shadow-base) 20%, transparent);
}
.irlix-toast--success{--irlix-toast-accent:var(--irlix-status-success-text)}
.irlix-toast--warning{--irlix-toast-accent:var(--irlix-status-warning-text)}
.irlix-toast--error{--irlix-toast-accent:var(--irlix-status-danger-text)}
.irlix-toast__body{display:flex;align-items:center;gap:10px;min-height:48px;padding:12px 12px 13px}
.irlix-toast__symbol{
  flex:none;display:grid;place-items:center;width:20px;height:20px;
  border:1px solid currentColor;border-radius:50%;color:var(--irlix-toast-accent);
  font-size:var(--irlix-font-size-xs);font-weight:700;line-height:1;
}
.irlix-toast__message{flex:1;min-width:0;margin:0;overflow-wrap:anywhere;font-size:var(--irlix-font-size-body);line-height:var(--irlix-line-height-body)}
.irlix-toast__action{
  flex:none;border:0;padding:4px 0;background:none;color:var(--irlix-color-primary-text);
  font:inherit;font-size:var(--irlix-font-size-table);font-weight:600;cursor:pointer;text-decoration:underline;
}
.irlix-toast__close{
  flex:none;display:grid;place-items:center;width:24px;height:24px;border:0;
  border-radius:var(--irlix-radius-sm);background:none;color:var(--irlix-color-text-muted);
  font:inherit;font-size:20px;line-height:1;cursor:pointer;
}
.irlix-toast__close:hover{background:var(--irlix-color-surface-muted)}
.irlix-toast__close:focus-visible,.irlix-toast__action:focus-visible{outline:2px solid var(--irlix-color-primary);outline-offset:2px}
.irlix-toast__progress{position:absolute;bottom:0;left:0;right:0;height:3px;background:var(--irlix-color-surface-muted)}
.irlix-toast__progress-bar{
  display:block;width:100%;height:100%;background:var(--irlix-toast-accent);transform-origin:left center;
  animation:irlix-toast-countdown linear forwards;
}
@keyframes irlix-toast-countdown{from{transform:scaleX(1)}to{transform:scaleX(0)}}
@media(prefers-reduced-motion:reduce){.irlix-toast__progress-bar{animation:none}}
@media(max-width:480px){.irlix-toast-region{right:12px;bottom:max(12px, env(safe-area-inset-bottom));width:calc(100vw - 24px)}}
</style>

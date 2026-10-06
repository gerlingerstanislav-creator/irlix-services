import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

// Teleported menus stay above service scroll containers and follow their anchor.
export function useAnchoredPopover(anchor, popup, { width = 180, align = 'left' } = {}) {
  const open = ref(false);
  const style = ref({});
  let observer;
  const position = () => {
    if (!anchor.value || !popup.value) return;
    const rect = anchor.value.getBoundingClientRect();
    const menuWidth = Math.min(width, window.innerWidth - 16);
    const menuHeight = popup.value.offsetHeight;
    const left = Math.max(8, Math.min(align === 'right' ? rect.right - menuWidth : rect.left, window.innerWidth - menuWidth - 8));
    const below = rect.bottom + 6;
    const top = below + menuHeight <= window.innerHeight - 8 || rect.top < menuHeight + 8
      ? below : rect.top - menuHeight - 6;
    style.value = { position: 'fixed', left: `${left}px`, top: `${Math.max(8, top)}px`, width: `${menuWidth}px`, maxHeight: `${Math.max(48, window.innerHeight - Math.max(8, top) - 8)}px` };
  };
  const close = (restoreFocus = false) => {
    open.value = false;
    if (restoreFocus) anchor.value?.focus({ preventScroll: true });
  };
  const outside = event => {
    if (!anchor.value?.contains(event.target) && !popup.value?.contains(event.target)) close();
  };
  const escape = event => {
    if (event.key === 'Escape') { event.preventDefault(); close(true); }
  };
  const cleanup = () => {
    document.removeEventListener('pointerdown', outside);
    document.removeEventListener('keydown', escape);
    window.removeEventListener('resize', position);
    window.removeEventListener('scroll', position, true);
    observer?.disconnect();
  };
  watch(open, async value => {
    cleanup();
    if (!value) return;
    await nextTick();
    if (!open.value) return;
    position();
    document.addEventListener('pointerdown', outside);
    document.addEventListener('keydown', escape);
    window.addEventListener('resize', position);
    window.addEventListener('scroll', position, true);
    observer = new ResizeObserver(position);
    if (popup.value) observer.observe(popup.value);
    if (anchor.value) observer.observe(anchor.value);
  });
  onBeforeUnmount(cleanup);
  return { open, style, close };
}

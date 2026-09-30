import { onBeforeUnmount, ref, watch } from 'vue';
// Native img requests bypass the app's authenticated fetch.
export function useClientLogo(url) {
  const source = ref(''); let request, generation = 0;
  function clear() { if (source.value) URL.revokeObjectURL(source.value); source.value = ''; }
  watch(url, async value => {
    const current = ++generation; request?.abort(); clear(); if (!value) return;
    const target = new URL(value, window.location.origin);
    if (target.origin !== window.location.origin || !target.pathname.startsWith('/api/clients/clients/')) return;
    request = new AbortController();
    try {
      const response = await fetch(target.href, {signal:request.signal,headers:{Accept:'image/*'}});
      if (!response.ok) return;
      const blob = await response.blob();
      if (current === generation && blob.type.startsWith('image/')) source.value = URL.createObjectURL(blob);
    } catch (_) { /* Fall back to initials. */ }
  }, {immediate:true});
  onBeforeUnmount(()=>{generation++;request?.abort();clear()});
  return source;
}

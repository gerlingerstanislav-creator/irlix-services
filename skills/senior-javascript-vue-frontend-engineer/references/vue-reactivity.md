# Vue 3 Composition API: reactive correctness and cleanup

Always use the **installed Vue version**. `onWatcherCleanup()` requires Vue 3.5+ and must register synchronously. The `onCleanup` callback passed to `watch` / `watchEffect` works across older compatible versions. `defineModel()` version-gated; prefer explicit props/emit where compatibility or clarity requires.

## Debugging path

1. Reproduce the exact symptom and identify reactive source, derived value, watcher, child prop, async mutation and render key.
2. Inspect whether destructuring a `reactive` object or prop disconnects updates; use `toRefs`, `toRef`, direct property access, or version-safe reactive props destructure where appropriate.
3. Ensure `.value` use in script for refs, no accidental copying of snapshots, no `computed` side effects and no deep recursive watcher loop.
4. For props: never mutate a parent-owned prop; use emit/callback or a deliberately initialized draft with an explicit sync policy.
5. Check keyed nodes/rows and selection: index key creates reused DOM state and focus mismatches when sorted/filtered.
6. For asynchronous watchers: reject late responses; clean up requests, subscriptions, listeners, timers, `ResizeObserver` and mutation observers on change/unmount. A missing cleanup can cause ghost toasts, stale tables and leaks.
7. Create watchers synchronously inside setup when possible. Watchers created asynchronously are not automatically bound to the component lifecycle; stop them explicitly.
8. Write regression tests that change the input twice and resolve older request last. Test dirty draft preservation after a refresh, not only the happy path.

## Example: last request wins in a watcher

```ts
import { ref, watch } from 'vue'

const query = ref('')
const records = ref<ReadonlyArray<RecordSummary>>([])
const loading = ref(false)
const error = ref<string | null>(null)
let sequence = 0

watch(query, (value, _old, onCleanup) => {
  const current = ++sequence
  const controller = new AbortController()
  onCleanup(() => controller.abort())
  loading.value = true
  error.value = null
  void listRecords(value, { signal: controller.signal })
    .then(data => { if (current === sequence) records.value = data })
    .catch(err => {
      if (current !== sequence || controller.signal.aborted) return
      error.value = toUserSafeError(err)
    })
    .finally(() => { if (current === sequence) loading.value = false })
}, { immediate: true })
```

This is a **pattern fragment**, not a drop-in module. It assumes `RecordSummary`, `listRecords`, `toUserSafeError` already exist. The sequence guards state even if an adapter ignores abort; choose existing request utilities where available. At unmount the most recent watcher is stopped; for complex shared request state add explicit disposal ownership.

## Root-cause vs symptom

- A `key` change forcing remount is not a default fix for stale inputs—it may destroy focus, scroll, internal state or drafts.
- Deep clone as default cure for reactive issues is often costly and hides ownership problems.
- Using `setTimeout` or `nextTick` to paper over races does not establish causality. Use Vue tick only to wait for DOM updates in tests/measurement.

References: https://vuejs.org/guide/essentials/watchers ; https://vuejs.org/guide/essentials/reactivity-fundamentals

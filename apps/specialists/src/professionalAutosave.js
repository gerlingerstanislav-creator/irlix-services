/**
 * Serial, last-write-wins autosave for a single specialist editor.
 *
 * The API replaces the entire set of primary/secondary technologies. Parallel
 * writes would allow an old response to overwrite a newer selection, so the
 * next snapshot is sent only after the previous request finishes. The latest
 * snapshot is retained on failure for explicit retry and flushes on unmount.
 */
export function createProfessionalAutosave({
  persist, onStatus = () => {}, onSaved = () => {}, onError = () => {},
  delay = 320, schedule = setTimeout, cancel = clearTimeout,
}) {
  let pending = null;
  let timer = null;
  let running = null;
  let disposed = false;
  let failed = false;

  const clear = () => {
    if (timer !== null) {
      cancel(timer);
      timer = null;
    }
  };
  const drain = () => {
    clear();
    if (running) return running;
    if (!pending) return Promise.resolve();
    failed = false;
    running = (async () => {
      while (pending) {
        const snapshot = pending;
        pending = null;
        try {
          await persist(snapshot.employeeId, snapshot.data);
          if (!pending && !disposed) onSaved();
        } catch (error) {
          // An edit made while a request was in flight takes precedence over
          // its failed older snapshot. Never discard the latest local state.
          if (!pending) pending = snapshot;
          failed = true;
          if (!disposed) onError(error);
          break;
        }
      }
    })().finally(() => { running = null; });
    return running;
  };
  const queue = (employeeId, data) => {
    if (disposed || !employeeId) return;
    // Copy arrays: the edit controls remain mutable while a request runs.
    pending = { employeeId, data: {
      primary: data.primary.map(row => ({ ...row })),
      secondary_ids: [...data.secondary_ids],
    } };
    failed = false;
    onStatus('saving');
    clear();
    timer = schedule(() => { void drain(); }, delay);
  };
  const retry = () => {
    if (!pending || disposed) return Promise.resolve();
    onStatus('saving');
    return drain();
  };
  const close = () => {
    disposed = true;
    clear();
    // Navigation between cards must not silently throw away a pending edit.
    return drain();
  };
  return { queue, flush: drain, retry, close, hasPending: () => Boolean(pending), hasFailed: () => failed };
}

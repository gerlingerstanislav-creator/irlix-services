const prefix = 'irlix.clients.view.';
export function rememberedView(key, fallback, choices) {
  try {
    const stored = window.localStorage.getItem(prefix + key);
    return choices.includes(stored) ? stored : fallback;
  } catch { return fallback; }
}
export function rememberView(key, value) {
  try { window.localStorage.setItem(prefix + key, value); } catch { /* disabled storage */ }
}

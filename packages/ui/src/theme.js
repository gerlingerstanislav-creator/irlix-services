// Shared appearance preference for all IRLIX applications on the same origin.
// Persist the preference, not the resolved OS theme.
export const THEME_STORAGE_KEY = 'irlix:theme';
export const THEME_MODES = ['light', 'dark', 'system'];
const isMode = value => THEME_MODES.includes(value);
const resolve = mode => mode === 'system'
  ? (typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
  : mode;

export function getTheme() {
  try { return isMode(window.localStorage.getItem(THEME_STORAGE_KEY)) ? window.localStorage.getItem(THEME_STORAGE_KEY) : 'system'; }
  catch { return 'system'; }
}

export function applyTheme(mode = getTheme()) {
  if (typeof document === 'undefined') return;
  const selected = isMode(mode) ? mode : 'system';
  document.documentElement.dataset.theme = resolve(selected);
  document.documentElement.dataset.themePreference = selected;
  document.documentElement.style.colorScheme = resolve(selected);
  document.dispatchEvent(new CustomEvent('irlix:theme-change', { detail: { mode: selected, resolved: resolve(selected) } }));
}

export function setTheme(mode) {
  if (!isMode(mode)) return;
  try { window.localStorage.setItem(THEME_STORAGE_KEY, mode); } catch { /* Browser storage may be unavailable. */ }
  applyTheme(mode);
}

export function initTheme() {
  if (typeof window === 'undefined') return;
  applyTheme();
  if (window.__irlixThemeInitialized) return;
  window.__irlixThemeInitialized = true;
  window.addEventListener('storage', event => {
    if (event.key === THEME_STORAGE_KEY || event.key === null) applyTheme();
  });
  const media = window.matchMedia?.('(prefers-color-scheme: dark)');
  media?.addEventListener?.('change', () => { if (getTheme() === 'system') applyTheme('system'); });
}
initTheme();

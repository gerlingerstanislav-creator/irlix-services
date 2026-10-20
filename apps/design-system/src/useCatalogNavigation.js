import { ref, onMounted, onBeforeUnmount } from 'vue';
import { catalogAnchorIds, groupForAnchor } from './catalogSections.js';

const basePath = '/design-system/';
const legacyAnchors = { components: 'buttons-actions', patterns: 'tables-lists', history: 'panels', confirmations: 'statuses', 'service-dashboard': 'navigation' };
const knownAnchor = value => catalogAnchorIds.includes(value) ? value : (Object.hasOwn(legacyAnchors, value) ? legacyAnchors[value] : undefined);

export function useCatalogNavigation(groups) {
  const section = ref(groups[0].id);
  let frame = 0;
  let targets = [];
  const locationAnchor = () => {
    const hash = window.location.hash.replace(/^#(?:section-)?/, '');
    const path = window.location.pathname.slice(basePath.length).split('/')[0];
    return knownAnchor(hash) || knownAnchor(new URLSearchParams(window.location.search).get('section')) || knownAnchor(path) || groups[0].id;
  };
  const canonicalUrl = anchor => {
    const url = new URL(window.location.href);
    url.pathname = basePath;
    url.searchParams.delete('section');
    url.hash = `section-${anchor}`;
    return `${url.pathname}${url.search}${url.hash}`;
  };
  const scrollTo = anchor => {
    section.value = groupForAnchor(anchor);
    document.getElementById(`section-${anchor}`)?.scrollIntoView({ behavior: 'instant', block: 'start' });
  };
  const syncLocation = () => {
    const anchor = locationAnchor();
    // Migrate old path/query links in-place without creating an extra Back entry.
    window.history.replaceState(window.history.state, '', canonicalUrl(anchor));
    scrollTo(anchor);
  };
  const navigate = anchor => {
    if (!knownAnchor(anchor)) return;
    const url = canonicalUrl(anchor);
    if (`${window.location.pathname}${window.location.search}${window.location.hash}` !== url) {
      window.history.pushState(window.history.state, '', url);
    }
    scrollTo(anchor);
  };
  const syncScroll = () => {
    frame = 0;
    // Keep manual scrolling reflected in the sidebar without adding history entries
    // or replacing the deep link the user chose.
    const threshold = parseFloat(getComputedStyle(targets[0]).scrollMarginTop) + 2;
    const active = targets.filter(target => target.getBoundingClientRect().top <= threshold).at(-1) || targets[0];
    if (active) section.value = active.dataset.catalogGroup;
  };
  const queueScroll = () => { if (!frame) frame = window.requestAnimationFrame(syncScroll); };
  onMounted(() => {
    targets = [...document.querySelectorAll('[data-catalog-group]')];
    syncLocation();
    window.addEventListener('popstate', syncLocation);
    window.addEventListener('hashchange', syncLocation);
    window.addEventListener('scroll', queueScroll, { passive: true });
  });
  onBeforeUnmount(() => {
    window.removeEventListener('popstate', syncLocation);
    window.removeEventListener('hashchange', syncLocation);
    window.removeEventListener('scroll', queueScroll);
    window.cancelAnimationFrame(frame);
  });
  return { section, navigate };
}

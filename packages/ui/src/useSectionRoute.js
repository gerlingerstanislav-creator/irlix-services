import { onBeforeUnmount, ref, watch } from 'vue';

const normalizeBase = (base) => `/${String(base || '').replace(/^\/+|\/+$/g, '')}/`.replace('//', '/');
const normalizeSegment = (segment) => String(segment || '').replace(/^\/+|\/+$/g, '');

/**
 * Lightweight History API router for service-level pages.
 *
 * Every service page gets a stable path without coupling the applications to a
 * particular router library. Root service URLs remain backward-compatible and
 * are canonicalized to the configured default page.
 */
export function useSectionRoute({ base, routes, defaultSection }) {
  const basePath = normalizeBase(base);
  const routeEntries = Object.entries(routes).map(([section, segment]) => [section, normalizeSegment(segment)]);
  const bySegment = new Map(routeEntries.map(([section, segment]) => [segment, section]));
  let applyingPopState = false;

  const sectionFromLocation = () => {
    const pathname = window.location.pathname;
    if (!pathname.startsWith(basePath)) return defaultSection;
    const tail = normalizeSegment(pathname.slice(basePath.length));
    if (!tail) return defaultSection;
    return bySegment.get(tail) || defaultSection;
  };

  const pathFor = (section) => {
    const segment = normalizeSegment(routes[section] ?? routes[defaultSection] ?? '');
    return `${basePath}${segment ? `${segment}/` : ''}`;
  };

  const section = ref(sectionFromLocation());

  const canonicalize = () => {
    const expected = pathFor(section.value);
    if (window.location.pathname !== expected) {
      window.history.replaceState({ section: section.value }, '', `${expected}${window.location.search}${window.location.hash}`);
    }
  };

  canonicalize();

  watch(section, (next, previous) => {
    if (!routes[next]) {
      section.value = defaultSection;
      return;
    }
    const expected = pathFor(next);
    if (window.location.pathname === expected) return;
    if (applyingPopState) return;
    window.history.pushState({ section: next }, '', expected);
  });

  const onPopState = () => {
    applyingPopState = true;
    section.value = sectionFromLocation();
    applyingPopState = false;
  };

  window.addEventListener('popstate', onPopState);
  onBeforeUnmount(() => window.removeEventListener('popstate', onPopState));

  return { section, pathFor };
}

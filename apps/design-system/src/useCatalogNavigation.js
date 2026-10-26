import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { catalogAnchorIds, groupForAnchor } from './catalogSections.js';

const legacyAnchors = { components:'buttons-actions', patterns:'tables-lists', history:'panels', confirmations:'statuses', 'service-dashboard':'navigation' };
const knownAnchor = value => catalogAnchorIds.includes(value) ? value : (Object.hasOwn(legacyAnchors,value) ? legacyAnchors[value] : undefined);

export function useCatalogNavigation(groups) {
  const route = useRoute();
  const router = useRouter();
  const section = ref(groups[0].id);
  let frame = 0;
  let targets = [];

  const locationAnchor = () => {
    const hash = route.hash.replace(/^#(?:section-)?/, '');
    const query = typeof route.query.section === 'string' ? route.query.section : '';
    return knownAnchor(hash) || knownAnchor(query) || groups[0].id;
  };
  const canonicalLocation = anchor => {
    const query = { ...route.query };
    delete query.section;
    return { name:'catalog',query,hash:'#section-'+anchor };
  };
  const scrollTo = anchor => {
    section.value = groupForAnchor(anchor);
    document.getElementById('section-'+anchor)?.scrollIntoView({behavior:'instant',block:'start'});
  };
  const syncLocation = () => {
    if (route.name === 'not-found') return;
    const anchor = locationAnchor();
    if (route.name !== 'catalog' || route.hash !== '#section-'+anchor || route.query.section !== undefined)
      router.replace(canonicalLocation(anchor));
    scrollTo(anchor);
  };
  const navigate = anchor => {
    if (!knownAnchor(anchor)) return;
    router.push(canonicalLocation(anchor)).then(() => scrollTo(anchor));
  };
  const syncScroll = () => {
    frame = 0;
    if (!targets.length) return;
    const threshold = parseFloat(getComputedStyle(targets[0]).scrollMarginTop) + 2;
    const active = targets.filter(target => target.getBoundingClientRect().top <= threshold).at(-1) || targets[0];
    if (active) section.value = active.dataset.catalogGroup;
  };
  const queueScroll = () => { if (!frame) frame = window.requestAnimationFrame(syncScroll); };
  watch(() => route.fullPath, syncLocation);
  onMounted(() => {
    targets = [...document.querySelectorAll('[data-catalog-group]')];
    syncLocation();
    window.addEventListener('scroll',queueScroll,{passive:true});
  });
  onBeforeUnmount(() => {
    window.removeEventListener('scroll',queueScroll);
    window.cancelAnimationFrame(frame);
  });
  return {section,navigate};
}

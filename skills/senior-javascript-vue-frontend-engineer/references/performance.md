# Performance engineering (measure before adding complexity)

## Diagnostic workflow

1. Identify whether the slow experience is initial download, network fetch, route transition, render/update, interaction or memory growth. Record dataset size/device/browser/build mode/network and a reproducible baseline.
2. Inspect devtools Performance, Vue DevTools profiler, browser Performance/Memory and bundle analyzer if available. Distinguish dev-mode artifacts from production build. Define budget tied to user experience (interaction delay, responsiveness, table scroll, route latency, transfer bytes) before optimizing.
3. Confirm root cause: too many DOM nodes, large tracked arrays/deep watchers, unstable props, wasted renders, sort/filter repeated on every keystroke, blocking synchronous work, query waterfall, images/fonts, giant JS chunks, layout thrash or remounting.
4. Apply smallest applicable fix, re-measure with identical conditions, add regression/performance guard when stable. Don't assert improvement without measurements.

## Options, each with caveats

- Large lists: server pagination + filtering; virtualization if DOM is bottleneck, with keyboard/focus/a11y/sticky column validation. Evaluate row height variation and overscan.
- Computed state: derive once with stable inputs; avoid costly deep watchers; consider `shallowRef` / immutable replacement for very large mostly-static structures when compatible; avoid direct nested mutation expecting tracked changes in shallow structures.
- Component update efficiency: stable prop identity, proper keys, `v-memo`/`v-once` only when semantics established; don't change keys to suppress warnings.
- Network: eliminate duplicate requests, coalesce safely, cache with correct keys, invalidate after mutation. Avoid caching private data beyond session.
- Loading: lazy routes/components for real split opportunities, remove unused heavy packages, image sizing/lazy loading, font/subset and CSS. Route splitting can create request waterfalls; verify production chunk graph.
- Resilience: keep input responsive during filtering and large calculations; debounce as UX contract allows; background workers only after measurement and data-transfer cost review.

## Evidence format

Scenario, steps, browser/device, dataset, build mode, before/after median and spread, tool used, remaining bottleneck, correctness and accessibility checks. If only suspected, mark it a *hypothesis*.

Sources: https://vuejs.org/guide/best-practices/performance ; https://web.dev/articles/vitals

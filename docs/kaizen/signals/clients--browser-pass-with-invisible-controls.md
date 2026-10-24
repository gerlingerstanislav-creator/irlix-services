# Clients: browser PASS with invisible edit icons

## 2026-10-23 — partner directory

- Evidence: [QA run 40007630010](https://github.com/gerlingerstanislav-creator/irlix-services/actions/runs/40007630010), feature commit `0cfcd85f760dfa9bc212dbe46f031cb1384ad4de`, synthetic browser screenshots in `qa-browser-report`.
- Observed: the partner edit scenario passed because Playwright could click the button by its accessible label. Independent screenshot review found that its pencil SVG was invisible. The icon inherits `fill: none` and required consumer stroke/size styling was absent.
- Scope: a functional locator/click assertion did not establish visual discoverability. This is one confirmed episode; it does not establish a platform-wide defect rate.
- Correction in the same feature: apply the existing icon styling pattern and verify icon bounds/stroke as well as normal editing. Exact-SHA browser and screenshot recheck remain required before acceptance. No new global QA policy or redesign is introduced.

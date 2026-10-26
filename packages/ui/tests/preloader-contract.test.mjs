import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
const read = path => readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8');
const sidebar = read('../src/components/UiAppSidebar.vue');
const preloader = read('../src/components/UiPreloader.vue');
const loader = read('../src/components/UiLoader.vue');
const designSystem = read('../../../apps/design-system/src/App.vue');
const d1 = 'M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z';
const d2 = 'M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z';
assert.ok(sidebar.includes(d1) && sidebar.includes(d2), 'canonical source paths must exist in sidebar');
assert.equal(preloader.split(d1).length - 1, 6);
assert.equal(preloader.split(d2).length - 1, 6);
assert.match(preloader, /default: 'trace'/);
assert.match(loader, /default: 'trace'/);
for (const variant of ['assembly', 'trace', 'glitch']) {
  assert.ok(designSystem.includes(`variant: '${variant}'`), `missing design-system ${variant}`);
}
const keyframes = preloader.match(/@keyframes irlix-assemble-[ab]\s*{[^}]*}[^}]*}[^}]*}/g) || [];
assert.equal(keyframes.length, 2);
assert.ok(keyframes.every(frame => !frame.includes('rotate(')), 'assembly must never rotate');
assert.match(preloader, /\.ui-preloader--overlay\s*\{[^}]*inset:0/);
assert.match(preloader, /\.ui-preloader--page\s*\{[^}]*min-height/);
console.log('UI preloader source geometry, variants, default and centering: PASS');

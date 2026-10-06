import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../../../', import.meta.url));
function cssFiles(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) return ['node_modules', 'dist'].includes(entry.name) ? [] : cssFiles(file);
    return entry.name.endsWith('.css') || entry.name.endsWith('.vue') ? [file] : [];
  });
}
test('services do not override the shared topbar, view selector or period picker', () => {
  for (const app of readdirSync(path.join(root, 'apps'), { withFileTypes: true }).filter(entry => entry.isDirectory()).map(entry => entry.name)) {
    for (const file of cssFiles(path.join(root, 'apps', app, 'src'))) {
      const source = readFileSync(file, 'utf8');
      const styles = file.endsWith('.vue') ? [...source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(m => m[1]).join('\n') : source;
      // Match CSS selectors only, not Vue templates or documentation mentions.
      for (const match of styles.matchAll(/([^{}]+)\{[^{}]*\}/g)) {
        assert.doesNotMatch(match[1], /\.(?:irlix-app-topbar(?:__[\w-]+)?|irlix-breadcrumbs(?:__[\w-]+)?|ui-period-picker(?:__[\w-]+)?|ui-view-select(?:__[\w-]+)?|breadcrumb-mode|services-popover|services-group|services-list|service-label)\b/, path.relative(root, file));
      }
    }
  }
});


test('view selector focus management never scrolls the page', () => {
  const selector = readFileSync(path.join(root, 'packages/ui/src/components/UiViewSelect.vue'), 'utf8');
  const popover = readFileSync(path.join(root, 'packages/ui/src/useAnchoredPopover.js'), 'utf8');
  assert.match(selector, /focus\(\{ preventScroll: true \}\)/);
  assert.match(popover, /focus\(\{ preventScroll: true \}\)/);
});

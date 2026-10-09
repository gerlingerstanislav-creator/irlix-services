import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
const css = await readFile(new URL('../src/styles/tokens.css', import.meta.url), 'utf8');
const themeBlocks = [...css.matchAll(/:root(?:\[data-theme="dark"\])?\s*\{([\s\S]*?)\}/g)];
assert.equal(themeBlocks.length, 2, 'expected light and dark palettes');
const extract = block => Object.fromEntries([...block.matchAll(/(--irlix-[\w-]+)\s*:\s*([^;]+);/g)].map(m => [m[1], m[2].trim()]));
const light = extract(themeBlocks[0][1]);
const dark = { ...light, ...extract(themeBlocks[1][1]) };
function resolve(map, key, visited=new Set()) {
  assert.ok(!visited.has(key), 'circular token alias: '+key);
  assert.ok(map[key], 'missing token: '+key);
  const value = map[key];
  const alias = value.match(/^var\((--irlix-[\w-]+)\)$/);
  return alias ? resolve(map, alias[1], new Set([...visited,key])) : value;
}
const rgb = hex => {
  assert.match(hex, /^#[\da-f]{6}$/i, 'expected opaque hex: '+hex);
  return [1,3,5].map(i => parseInt(hex.slice(i,i+2),16)/255).map(v => v <= .04045 ? v/12.92 : ((v+.055)/1.055)**2.4);
};
const luminance = hex => { const c=rgb(hex); return c[0]*.2126+c[1]*.7152+c[2]*.0722; };
const contrast = (a,b) => {
  const x=luminance(a), y=luminance(b);
  return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);
};
const pairs = [
  ['--irlix-color-text','--irlix-color-bg'],
  ['--irlix-color-text-muted','--irlix-color-surface'],
  ['--irlix-color-primary-text','--irlix-color-surface'],
  ['--irlix-color-on-accent','--irlix-color-primary'],
  ['--irlix-color-on-danger','--irlix-color-danger'],
  ...['success','info','warning','danger'].map(name=>['--irlix-status-'+name+'-text','--irlix-status-'+name+'-bg']),
];
for (const [name,palette] of [['light',light],['dark',dark]]) {
  test(name+' token aliases resolve', () => {
    for (const key of Object.keys(palette)) {
      const value=palette[key];
      if (value.startsWith('var(--irlix-')) resolve(palette,key);
    }
  });
  test(name+' semantic text colors meet WCAG AA', () => {
    for (const [fg,bg] of pairs) {
      const f=resolve(palette,fg), b=resolve(palette,bg);
      assert.ok(contrast(f,b) >= 4.5, name+': insufficient '+fg+' / '+bg+': '+contrast(f,b).toFixed(2));
    }
  });
}

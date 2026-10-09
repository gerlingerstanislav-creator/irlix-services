#!/usr/bin/env node
// IRLIX visual-token debt inventory. Run: node scripts/audit-appearance.mjs [--strict]
import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';

const root = new URL('../', import.meta.url);
const strict = process.argv.includes('--strict');
const maxColorsFlag = process.argv.find(arg => arg.startsWith('--max-colors='));
const maxColors = maxColorsFlag ? Number(maxColorsFlag.slice('--max-colors='.length)) : Number.POSITIVE_INFINITY;
const forbidAtypicalFonts = process.argv.includes('--no-atypical-fonts');
const targets = ['apps', 'packages/ui/src'];
const colors = /(?:#[\da-f]{3,8}\b|\brgba?\s*\(|\bhsla?\s*\()/gi;
const typeSizes = /font-size\s*:\s*(\d+(?:\.\d+)?)px\b/gi;
const allowed = new Set(['12', '13', '14', '16', '24']);
const findings = [];
async function walk(relative) {
  const absolute = new URL(relative, root);
  for (const entry of await readdir(absolute, { withFileTypes: true })) {
    if (entry.name === 'node_modules' || entry.name === 'dist' || entry.name === 'vendor') continue;
    const child = path.posix.join(relative, entry.name);
    if (entry.isDirectory()) { await walk(child); continue; }
    if (!/\.(css|vue)$/.test(entry.name)) continue;
    let source = await readFile(new URL(child, root), 'utf8');
    if (entry.name.endsWith('.vue')) {
      const blocks = [...source.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)];
      source = blocks.map(x => x[1]).join('\n');
    }
    // Raw values in the palette are intentional; only consumer literals are debt.
    const colorCount = child === 'packages/ui/src/styles/tokens.css' ? 0 : [...source.matchAll(colors)].length;
    const sizes = [...source.matchAll(typeSizes)].map(x => x[1]);
    const atypical = sizes.filter(size => !allowed.has(size));
    if (colorCount || atypical.length) findings.push({ file: child, hardcodedColors: colorCount, atypicalFontSizes: atypical.length, atypicalValues: [...new Set(atypical)] });
  }
}
for (const target of targets) await walk(target);
findings.sort((a,b)=>b.hardcodedColors+b.atypicalFontSizes-a.hardcodedColors-a.atypicalFontSizes);
const totals = findings.reduce((acc,f)=>({ hardcodedColors:acc.hardcodedColors+f.hardcodedColors, atypicalFontSizes:acc.atypicalFontSizes+f.atypicalFontSizes }),{hardcodedColors:0,atypicalFontSizes:0});
console.log(JSON.stringify({ totals, files: findings }, null, 2));
if (strict && (totals.hardcodedColors || totals.atypicalFontSizes)) process.exitCode = 1;
if (forbidAtypicalFonts && totals.atypicalFontSizes) { console.error('Typography regression: nonstandard font sizes detected.'); process.exitCode = 1; }
if (totals.hardcodedColors > maxColors) { console.error('Color debt exceeds allowed transition budget:', totals.hardcodedColors, '>', maxColors); process.exitCode = 1; }

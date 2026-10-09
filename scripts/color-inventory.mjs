#!/usr/bin/env node
// Inventory literal colors in UI sources; no automatic semantic decisions.
import { readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const output = process.argv.find(arg => arg.startsWith('--out='))?.slice(6);
const root = new URL('../', import.meta.url);
const files = [];
const colorRegex = /#[\da-f]{3,8}\b|\brgba?\s*\([^)]*\)|\bhsla?\s*\([^)]*\)/gi;
async function walk(dir) {
  for (const entry of await readdir(new URL(dir + '/', root), { withFileTypes: true })) {
    if (['node_modules','dist','vendor','.git'].includes(entry.name)) continue;
    const file = path.posix.join(dir, entry.name);
    if (entry.isDirectory()) await walk(file);
    else if (/\.(css|vue)$/.test(entry.name)) files.push(file);
  }
}
await walk('apps'); await walk('packages/ui/src');
const unique = new Map();
let literals = 0, tokenDefinitions = 0;
for (const file of files) {
  const source = await readFile(new URL(file, root), 'utf8');
  const blocks = file.endsWith('.vue') ? [...source.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)].map(m=>m[1]) : [source];
  for (const block of blocks) {
    for (const line of block.split('\n')) {
      for (const match of line.matchAll(colorRegex)) {
        const name = match[0].toLowerCase().replace(/\s+/g,'');
        if (file === 'packages/ui/src/styles/tokens.css') { tokenDefinitions++; continue; }
        literals++;
        const data = unique.get(name) || { value:name, count:0, files:new Set(), contexts:new Set() };
        data.count++; data.files.add(file);
        const context = (line.slice(0,match.index).match(/([\w-]+)\s*:\s*[^:;]*$/) || [,'unknown'])[1];
        data.contexts.add(context); unique.set(name,data);
      }
    }
  }
}
const colors = [...unique.values()].map(c=>({value:c.value,count:c.count,files:[...c.files].sort(),properties:[...c.contexts].sort()})).sort((a,b)=>b.count-a.count||a.value.localeCompare(b.value));
const report = { summary:{literalOccurrences:literals,uniqueLiteralColors:colors.length,sourceFiles:files.length,tokenDefinitionOccurrences:tokenDefinitions},colors };
if (output) await writeFile(new URL(output,root),JSON.stringify(report,null,2)+'\n');
console.log(JSON.stringify(report.summary));
console.log('Top 30 literals by occurrence:',JSON.stringify(colors.slice(0,30).map(c=>({value:c.value,count:c.count,properties:c.properties})),null,2));

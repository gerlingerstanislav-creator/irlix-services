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
// Suggest neutral near-duplicates in perceptual OKLab; suggestions need semantic review.
function hexRGB(x) {
  if (!/^#[a-f0-9]{3}([a-f0-9]{3})?$/i.test(x)) return null;
  const value = x.length === 4 ? '#' + [...x.slice(1)].map(ch=>ch+ch).join('') : x;
  return [1,3,5].map(i=>parseInt(value.slice(i,i+2),16)/255);
}
function lab(rgb) {
  const [r,g,b] = rgb.map(c=>c<=0.04045?c/12.92:((c+0.055)/1.055)**2.4);
  const l=Math.cbrt(0.4122214708*r+0.5363325363*g+0.0514459929*b);
  const m=Math.cbrt(0.2119034982*r+0.6806995451*g+0.1073969566*b);
  const s=Math.cbrt(0.0883024619*r+0.2817188376*g+0.6299787005*b);
  return [0.2104542553*l+0.793617785*m-0.0040720468*s,1.9779984951*l-2.428592205*m+0.4505937099*s,0.0259040371*l+0.7827717662*m-0.808675766*s];
}
const neutralHex=colors.map(c=>({...c,lab:hexRGB(c.value)?lab(hexRGB(c.value)):null})).filter(c=>c.lab&&Math.hypot(c.lab[1],c.lab[2])<0.045);
const groups=[];
for(const c of neutralHex) {
  let group=groups.find(g=>Math.hypot(...c.lab.map((v,i)=>v-g.lab[i]))<0.025);
  if(!group){group={candidate:c.value,lab:c.lab,colors:[],occurrences:0};groups.push(group);}
  group.colors.push(c.value);group.occurrences+=c.count;
}
const similarNeutralGroups=groups.filter(g=>g.colors.length>1).map(({candidate,colors,occurrences})=>({candidate,colors,occurrences})).sort((a,b)=>b.occurrences-a.occurrences);
const report = { summary:{literalOccurrences:literals,uniqueLiteralColors:colors.length,sourceFiles:files.length,tokenDefinitionOccurrences:tokenDefinitions,similarNeutralGroups:similarNeutralGroups.length},similarNeutralGroups,colors };
if (output) await writeFile(new URL(output,root),JSON.stringify(report,null,2)+'\n');
console.log(JSON.stringify(report.summary));
console.log('Top 15 near-neutral groups:',JSON.stringify(similarNeutralGroups.slice(0,15),null,2));
console.log('Top 30 literals by occurrence:',JSON.stringify(colors.slice(0,30).map(c=>({value:c.value,count:c.count,properties:c.properties})),null,2));

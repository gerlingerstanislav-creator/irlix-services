import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../src/theme.js',import.meta.url),'utf8');
function env({stored=null,dark=false,brokenStorage=false}={}) {
  const attrs={};
  const listeners={};
  const documentListeners={};
  const events=[];
  const element={dataset:attrs,style:{}};
  let osDark=dark;
  const storage=new Map(stored===null?[]:[['irlix:theme',stored]]);
  const media={get matches(){return osDark},addEventListener(name,fn){listeners['media:'+name]=fn}};
  globalThis.window={
    localStorage:{
      getItem(k){if(brokenStorage)throw Error('blocked');return storage.get(k)??null},
      setItem(k,v){if(brokenStorage)throw Error('blocked');storage.set(k,v)}
    },
    matchMedia:()=>media,
    addEventListener(name,fn){listeners[name]=fn}
  };
  globalThis.document={documentElement:element,dispatchEvent(ev){events.push(ev)},addEventListener(name,fn){documentListeners[name]=fn}};
  globalThis.CustomEvent=class {constructor(type,opts){this.type=type;this.detail=opts.detail}};
  return {attrs,element,listeners,events,storage,setOSDark(value){osDark=value;listeners['media:change']?.()}};
}
async function load() {
  return import('data:text/javascript;base64,'+Buffer.from(source).toString('base64')+'#'+Math.random());
}
test('stored dark theme is applied and preserved', async()=>{
  const e=env({stored:'dark'});
  const t=await load();
  assert.equal(t.getTheme(),'dark');
  assert.equal(e.attrs.theme,'dark');
  assert.equal(e.element.style.colorScheme,'dark');
  t.setTheme('light');
  assert.equal(e.attrs.theme,'light');
  assert.equal(e.storage.get('irlix:theme'),'light');
});
test('system mode follows OS preference, including changes',async()=>{
  const e=env({dark:true});
  const t=await load();
  assert.equal(e.attrs.theme,'dark');
  assert.equal(e.attrs.themePreference,'system');
  e.setOSDark(false);
  assert.equal(e.attrs.theme,'light');
  t.setTheme('dark');
  e.setOSDark(false);
  assert.equal(e.attrs.theme,'dark');
});
test('cross-tab changes update the active theme',async()=>{
  const e=env({stored:'light'});
  await load();
  e.storage.set('irlix:theme','dark');
  e.listeners.storage({key:'irlix:theme'});
  assert.equal(e.attrs.theme,'dark');
});
test('blocked storage does not prevent fallback to system theme',async()=>{
  const e=env({dark:true,brokenStorage:true});
  const t=await load();
  assert.equal(t.getTheme(),'system');
  t.setTheme('light');
  assert.equal(e.attrs.theme,'light');
});

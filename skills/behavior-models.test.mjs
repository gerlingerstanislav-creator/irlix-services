import test from 'node:test';
import assert from 'node:assert/strict';
import { createTableModel, createApiSession, createRowView } from './fixtures/models.mjs';

const deferred = () => { let resolve, reject; const promise = new Promise((yes,no) => { resolve=yes; reject=no; }); return { promise, resolve, reject }; };

test('case 1 - latest query beats stale response, dirty edit survives refetch, query is shareable', async () => {
  const a = deferred(), b = deferred();
  const load = ({search}) => (search === 'new' ? b.promise : a.promise);
  const saves = [];
  const model = createTableModel({load, save:async (id,patch) => saves.push({id,patch})});
  const oldRun = model.refresh({search:'old',page:4});
  const newRun = model.refresh({search:'new',page:2});
  b.resolve([{id:'a1', label:'Newest', updated:1}]);
  await newRun;
  assert.equal(model.state.rows[0].label,'Newest');
  model.edit('a1',{label:'Unsaved'});
  a.resolve([{id:'a1', label:'Old', updated:0}]);
  await oldRun;
  assert.equal(model.state.rows[0].label,'Newest');
  assert.equal(model.state.drafts.get('a1').label,'Unsaved');
  assert.equal(model.serializeQuery(),'search=new&page=2');
  await model.commit('a1');
  assert.deepEqual(saves,[{id:'a1',patch:{label:'Unsaved'}}]);
});

test('case 1 - failure of stale request does not show ghost error or stop new loading', async () => {
  const a = deferred(), b = deferred();
  const model = createTableModel({load:({search})=>search==='a'?a.promise:b.promise, save:async()=>{}});
  const old = model.refresh({search:'a',page:1});
  const fresh = model.refresh({search:'b',page:1});
  a.reject(new Error('obsolete'));
  await old;
  assert.equal(model.state.error,null);
  assert.equal(model.state.loading,true);
  b.resolve([]);
  await fresh;
  assert.equal(model.state.loading,false);
});

test('case 2 - concurrent 401 requests renew exactly once and retry', async () => {
  let token = 'old', renewCount = 0, logoutCount = 0;
  const refresh = deferred();
  const s = createApiSession({
    getToken:()=>token,
    renew:async()=>{ renewCount++; await refresh.promise; token='new'; },
    request:async(_path,t)=>({status: t==='old' ? 401 : 200, data:'ok'}),
    logout:async()=>{logoutCount++;},
  });
  const first=s.get('/a'); const second=s.get('/b');
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(renewCount,1);
  refresh.resolve();
  assert.equal((await first).status,200);
  assert.equal((await second).status,200);
  assert.equal(logoutCount,0);
});

test('case 2 - 403 does not trigger refresh; repeated 401 cannot loop', async () => {
  let renewCount=0,logoutCount=0;
  const s=createApiSession({getToken:()=> 'invalid',renew:async()=>renewCount++,request:async path=>({status:path==='/forbidden'?403:401}),logout:async()=>{logoutCount++;}});
  assert.equal((await s.get('/forbidden')).status,403);
  assert.equal(renewCount,0);
  assert.equal((await s.get('/expired')).status,401);
  assert.equal(renewCount,1);
  assert.equal(logoutCount,1);
});

test('case 3 - stable row identity across sort/filter, memoized derived view', () => {
  const rows=Array.from({length:12000},(_,i)=>({id:`row-${i}`,label:`Record ${i}`}));
  const v=createRowView(rows);
  const first=v.filter('Record 42');
  assert.strictEqual(first,v.filter('Record 42'));
  v.select('row-420');
  v.replace([...rows].reverse());
  const after=v.filter('Record 42');
  assert.notStrictEqual(first,after);
  assert.deepEqual(v.selectedIds(),['row-420']);
  assert.equal(v.rowKey(after.find(r=>r.id==='row-420')),'row-420');
});

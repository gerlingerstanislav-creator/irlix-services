import test from 'node:test';
import assert from 'node:assert/strict';
import {VISIBILITY_KEY,readHidden,saveHidden,updateHidden,hiddenEntities,filterMigrationTree,progressPercent} from '../src/migration-control-visibility.js';

const factory=()=>({
 id:'100',issues:{errors:8,warnings:4},coverage:{expected_unprocessed_rows:120},issue_groups:[],
 services:[{
  service:'employees',processed:150,total:250,expected_unprocessed:120,unknown_outcome_tables:0,
  expected_tables:2,excluded_tables:0,undocumented_tables:0,
  entities:[
   {id:'employees:users',service:'employees',table_name:'users',processed:80,total:100,
    source_outcomes:{unprocessed:20},expectation:{status:'expected'}},
   {id:'employees:cache',service:'employees',table_name:'cache',processed:70,total:150,
    source_outcomes:{unprocessed:100},expectation:{status:'expected'}},
  ],
  issue_groups:[
   {service:'employees',table:'users',severity:'error',count:2},
   {service:'employees',table:'cache',severity:'error',count:6},
   {service:'employees',severity:'warning',count:4},
  ],issues:{errors:8,warnings:4}
 }]
});

test('hiding only employees:cache recalculates service and root totals; re-show restores report',()=>{
 const original=factory(),hidden=updateHidden({},'employees','cache',true);
 const view=filterMigrationTree(original,hidden);
 assert.equal(view.services[0].entities.length,1);
 assert.equal(view.services[0].processed,80);
 assert.equal(view.services[0].total,100);
 assert.equal(progressPercent(view.services[0].processed,view.services[0].total),80);
 assert.equal(view.services[0].expected_unprocessed,20);
 assert.equal(view.expected_unprocessed,20);
 assert.deepEqual(view.services[0].issues,{errors:2,warnings:4,groups:2});
 assert.equal(view.issues.errors,2);
 assert.equal(view.hidden_count,1);
 assert.equal(original.services[0].entities.length,2,'raw immutable operation report');
 assert.equal(original.services[0].processed,150);
 const restored=filterMigrationTree(original,updateHidden(hidden,'employees','cache',false));
 assert.equal(restored.services[0].total,250);
 assert.equal(restored.services[0].processed,150);
 assert.equal(restored.services[0].issues.errors,8);
 assert.equal(restored.expected_unprocessed,120);
});

test('hidden entities are service-scoped, preserve other service metrics',()=>{
 const source=factory();
 source.services.push({...source.services[0],service:'vacations',processed:9,total:11,
  expected_unprocessed:1,entities:[{...source.services[0].entities[0],service:'vacations',table_name:'cache'}],issue_groups:[]});
 const filtered=filterMigrationTree(source,{employees:['cache']});
 assert.equal(filtered.services[0].entities.length,1);
 assert.equal(filtered.services[1].entities.length,1);
 assert.equal(hiddenEntities(source,{employees:['cache']},'employees').length,1);
 assert.equal(hiddenEntities(source,{employees:['cache']},'vacations').length,0);
});

test('unknown data does not produce false 0% or full aggregate when hidden',()=>{
 assert.equal(progressPercent(null,100),null);
 assert.equal(progressPercent(5,null),null);
 assert.equal(progressPercent(0,0),null);
 assert.equal(progressPercent(0,10),0);
 assert.equal(progressPercent(150,100),100);
 const source=factory();
 source.services[0].entities[0].total=null;
 source.services[0].entities[0].processed=null;
 source.services[0].entities[0].source_outcomes=null;
 const view=filterMigrationTree(source,{employees:['cache']});
 assert.equal(view.services[0].total,null);
 assert.equal(view.services[0].processed,null);
 assert.equal(view.services[0].unknown_outcome_tables,1);
 assert.equal(view.expected_unprocessed,null);
});

test('preferences round-trip, tolerate invalid/blocked local storage, never persist report data',()=>{
 const memory=new Map(),storage={getItem:key=>memory.get(key),setItem:(key,val)=>memory.set(key,val)};
 const prefs=updateHidden({},'employees','public.cache',true);
 assert.equal(saveHidden(storage,prefs),true);
 assert.deepEqual(readHidden(storage),prefs);
 assert.ok(!memory.get(VISIBILITY_KEY).includes('source_outcomes'));
 assert.deepEqual(readHidden({getItem:()=>'{oops'}),{});
 assert.deepEqual(readHidden({getItem:()=>JSON.stringify({employees:['users',42,'users'],other:'str'})}),{employees:['users']});
 assert.equal(saveHidden({setItem:()=>{throw Error('blocked');}},prefs),false);
 assert.deepEqual(readHidden({getItem:()=>{throw Error('blocked');}}),{});
});

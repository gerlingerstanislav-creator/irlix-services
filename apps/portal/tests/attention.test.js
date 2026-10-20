import test from 'node:test';import assert from 'node:assert/strict';
import {normalizeTasks,sortTasks} from '../src/attentionModel.js';
test('only valid internal target tasks are displayed',()=>{
 const items=normalizeTasks([{id:'1',title:'A',href:'/vacations/',kind:'attention'},{id:'2',title:'X',href:'https://evil.example/',kind:'attention'},{id:'3',title:'Y',href:'//evil.example',kind:'attention'}],'vacations');
 assert.equal(items.length,1);assert.equal(items[0].service,'vacations');
});
test('attention actions sort ahead of future reminders',()=>{
 const items=sortTasks([{kind:'upcoming',due_at:'2026-10-20',title:'A'},{kind:'attention',due_at:'2026-10-31',title:'B'}]);
 assert.equal(items[0].title,'B');
});

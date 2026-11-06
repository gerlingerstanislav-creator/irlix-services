import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createProfessionalAutosave } from '../src/professionalAutosave.js';

const empty = () => ({ primary: [], secondary_ids: [] });
test('autosave serializes pending snapshots and persists only the newest queued state', async () => {
  const calls = [];
  let release;
  const wait = new Promise(resolve => { release = resolve; });
  const statuses = [];
  const saver = createProfessionalAutosave({
    persist: async (id, data) => {
      calls.push({ id, data });
      if (calls.length === 1) await wait;
    },
    onStatus: value => statuses.push(value),
    delay: 100000,
  });
  const initial = { primary: [{ technology_id: 1, grade: 'Junior' }], secondary_ids: [2] };
  saver.queue(11, initial);
  const first = saver.flush();
  initial.secondary_ids.push(888); // snapshot must not be mutated after queue()
  saver.queue(11, { primary: [{ technology_id: 1, grade: 'Senior' }], secondary_ids: [3] });
  saver.queue(11, { primary: [{ technology_id: 5, grade: 'Middle' }], secondary_ids: [4] });
  release();
  await first;
  assert.deepEqual(calls, [
    { id: 11, data: { primary: [{ technology_id: 1, grade: 'Junior' }], secondary_ids: [2] } },
    { id: 11, data: { primary: [{ technology_id: 5, grade: 'Middle' }], secondary_ids: [4] } },
  ]);
  assert.equal(saver.hasPending(), false);
  assert.ok(statuses.includes('saving'));
  await saver.close();
});
test('failed autosave retains the latest state for user-driven retry', async () => {
  const result = [];
  let attempts = 0;
  const errors = [];
  const saver = createProfessionalAutosave({
    persist: async (_, data) => {
      attempts += 1;
      if (attempts === 1) throw new Error('synthetic failure');
      result.push(data);
    },
    onError: error => errors.push(error.message),
    delay: 100000,
  });
  saver.queue(17, { ...empty(), secondary_ids: [12] });
  await saver.flush();
  assert.deepEqual(errors, ['synthetic failure']);
  assert.equal(saver.hasPending(), true);
  assert.equal(saver.hasFailed(), true);
  await saver.retry();
  assert.equal(attempts, 2);
  assert.deepEqual(result[0].secondary_ids, [12]);
  assert.equal(saver.hasPending(), false);
  await saver.close();
});
test('closing drawer flushes pending selection and prevents further input', async () => {
  const result = [];
  const saver = createProfessionalAutosave({
    persist: async (id, data) => result.push([id, data.secondary_ids]),
    delay: 100000,
  });
  saver.queue(11, { ...empty(), secondary_ids: [41] });
  await saver.close();
  saver.queue(12, { ...empty(), secondary_ids: [51] });
  assert.deepEqual(result, [[11, [41]]]);
  assert.equal(saver.hasPending(), false);
});
test('debounced edits collapse before any network request', async () => {
  const tasks = new Map();
  let id = 0;
  const values = [];
  const saver = createProfessionalAutosave({
    persist: async (_, data) => { values.push(data.secondary_ids); },
    schedule: fn => { const current = ++id; tasks.set(current, fn); return current; },
    cancel: current => tasks.delete(current),
  });
  saver.queue(9, { ...empty(), secondary_ids: [1] });
  saver.queue(9, { ...empty(), secondary_ids: [1, 2] });
  assert.equal(tasks.size, 1);
  assert.deepEqual(values, []);
  await saver.flush();
  assert.deepEqual(values, [[1, 2]]);
  assert.equal(tasks.size, 0);
  await saver.close();
});

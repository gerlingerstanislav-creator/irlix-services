import { test } from 'node:test';
import assert from 'node:assert/strict';
import { employeeDeletionErrorMessage } from '../src/employeeDeletionError.js';

test('protected financial history is not suggested for automatic retry', () => {
  const message = employeeDeletionErrorMessage({ service: 'clients', upstreamStatus: 423,
    message: 'Cross-service employee purge failed' });
  assert.match(message, /защищённые/);
  assert.match(message, /отдельное согласование/);
  assert.doesNotMatch(message, /Попробуйте повторить/);
});
test('unresolved ownership requires reconciliation instead of deleting someone else', () => {
  assert.match(employeeDeletionErrorMessage({ service: 'clients', upstreamStatus: 409 }),
    /ручного сопоставления/);
});
test('transient service failure retains target name and retry guidance', () => {
  const message = employeeDeletionErrorMessage({ service: 'clients', upstreamStatus: 500,
    message: 'Cross-service employee purge failed' });
  assert.match(message, /Clients \(HTTP 500\)/);
  assert.match(message, /Попробуйте повторить/);
});
test('non-service errors retain their original message', () => {
  assert.equal(employeeDeletionErrorMessage({message:'Недостаточно прав'}),
    'Недостаточно прав');
});

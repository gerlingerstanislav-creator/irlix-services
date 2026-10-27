import test from 'node:test';
import assert from 'node:assert/strict';
import { absenceRegistryColumns, absenceRegistryMinWidth, PERIOD_MIN_WIDTH } from '../src/absenceRegistryLayout.js';

const sharedOptions = {
  showProgress:true,
  showDocuments:false,
  showActions:true,
  centerColumns:true,
  typeBadgeWidth:172,
  statusBadgeWidth:144,
};

test('my vacations and management use exactly the same tracks except employee', () => {
  const mine = absenceRegistryColumns(sharedOptions);
  const manage = absenceRegistryColumns({...sharedOptions,showEmployee:true});
  assert.deepEqual(manage.slice(1), mine);
  assert.equal(manage[0], '220px');
  assert.equal(mine[0], 'auto');
  assert.equal(mine.at(-2), '112px'); // approval icons
  assert.equal(mine.at(-1), '48px'); // row actions
});

test('the period alone takes extra table width; badge tracks keep their own sizes', () => {
  const columns = absenceRegistryColumns(sharedOptions);
  assert.equal(columns.filter(width => width === 'auto').length, 1);
  assert.equal(columns[1], '110px');
  assert.equal(columns[2], '190px');
  assert.equal(columns[3], '162px');
  assert.equal(Number.parseInt(absenceRegistryMinWidth(columns),10), PERIOD_MIN_WIDTH + 110 + 190 + 162 + 112 + 48);
});

test('long text can expand a badge track without stretching a neighboring track', () => {
  const before = absenceRegistryColumns(sharedOptions);
  const after = absenceRegistryColumns({...sharedOptions,statusBadgeWidth:260});
  assert.deepEqual(after.slice(0,3), before.slice(0,3));
  assert.equal(after[3], '278px');
  assert.deepEqual(after.slice(4), before.slice(4));
});

test('optional columns are consistently toggled for other registry views', () => {
  const noActions = absenceRegistryColumns({...sharedOptions,showEmployee:true,showActions:false,showProgress:false});
  assert.equal(noActions.length,5);
  assert.equal(noActions.at(-1),'162px');
  const withStage = absenceRegistryColumns({...sharedOptions,showStage:true,showDocuments:true});
  assert.deepEqual(withStage.slice(-4),['112px','180px','135px','48px']);
});

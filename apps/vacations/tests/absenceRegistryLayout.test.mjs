import test from 'node:test';
import assert from 'node:assert/strict';
import {
  absenceRegistryColumns,
  absenceRegistryMinWidth,
  absenceTypeTone,
  formatAbsenceDays,
  PERIOD_MIN_WIDTH,
  SPACER_MIN_WIDTH,
} from '../src/absenceRegistryLayout.js';

const sharedOptions = {
  showProgress:true,
  showDocuments:false,
  showActions:true,
  centerColumns:true,
  typeBadgeWidth:172,
  statusBadgeWidth:144,
};

test('my vacations and management share every track after the optional employee', () => {
  const mine = absenceRegistryColumns(sharedOptions);
  const manage = absenceRegistryColumns({...sharedOptions,showEmployee:true});
  assert.deepEqual(manage.slice(1), mine);
  assert.equal(manage[0], '220px');
  assert.deepEqual(mine, ['310px','215px','auto','160px','96px','40px']);
});

test('period/type stay left and status/progress/actions cluster at the right edge', () => {
  const columns = absenceRegistryColumns(sharedOptions);
  assert.equal(columns.filter(width => width === 'auto').length, 1);
  assert.equal(columns.indexOf('auto'), 2); // only spacer may expand
  assert.deepEqual(columns.slice(-3), ['160px','96px','40px']);
  assert.equal(Number.parseInt(absenceRegistryMinWidth(columns,{centerColumns:true}),10),
    PERIOD_MIN_WIDTH + 215 + SPACER_MIN_WIDTH + 160 + 96 + 40);
});

test('long labels enlarge only their own track', () => {
  const before = absenceRegistryColumns(sharedOptions);
  const afterStatus = absenceRegistryColumns({...sharedOptions,statusBadgeWidth:260});
  assert.deepEqual(afterStatus.slice(0,3), before.slice(0,3));
  assert.equal(afterStatus[3], '268px');
  assert.deepEqual(afterStatus.slice(4), before.slice(4));
  const afterType = absenceRegistryColumns({...sharedOptions,typeBadgeWidth:300});
  assert.equal(afterType[1], '318px');
  assert.deepEqual(afterType.slice(2), before.slice(2));
});

test('optional columns remain consistent on other registry views', () => {
  const noActions = absenceRegistryColumns({...sharedOptions,showEmployee:true,showActions:false,showProgress:false});
  assert.deepEqual(noActions, ['220px','310px','215px','auto','160px']);
  const withStage = absenceRegistryColumns({...sharedOptions,showStage:true,showDocuments:true});
  assert.deepEqual(withStage.slice(-4),['96px','180px','135px','40px']);
  const department = absenceRegistryColumns({...sharedOptions,centerColumns:false});
  assert.deepEqual(department,['auto','205px','190px','112px','48px']);
  assert.equal(Number.parseInt(absenceRegistryMinWidth(department),10), PERIOD_MIN_WIDTH+205+190+112+48);
});

test('period duration has correct Russian inflection and handles missing values', () => {
  for (const [value, expected] of [
    [1,'(1 день)'],[2,'(2 дня)'],[4,'(4 дня)'],[5,'(5 дней)'],
    [7,'(7 дней)'],[11,'(11 дней)'],[21,'(21 день)'],[22,'(22 дня)'],[114,'(114 дней)'],
    [null,''],[undefined,''],['',''],[-1,''],['n/a',''],
  ]) assert.equal(formatAbsenceDays(value),expected);
});

test('all supported absence types have distinct muted semantic tones', () => {
  const tones=['paid_vacation','unpaid_vacation','sick_leave','maternity_leave','day_off'].map(absenceTypeTone);
  assert.deepEqual(tones,['info','warning','danger','violet','success']);
  assert.equal(new Set(tones).size,5);
  assert.equal(absenceTypeTone('unknown'),'neutral');
});

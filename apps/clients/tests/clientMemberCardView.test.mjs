import test from 'node:test';
import assert from 'node:assert/strict';
import {
  sortedMemberTerms, sortedProjectAssignments, sortedMemberFeedbacks,
  feedbackDate, memberPeriod, formatMemberDate, feedbackProjectLabel,
} from '../src/clientMemberCardView.js';

test('conditions and project assignment periods are newest first, including reconnects', () => {
 const member={terms:[
  {id:1,valid_from:'2025-09-01'}, {id:4,valid_from:'2026-03-20'}, {id:3,valid_from:'2025-10-01'}
 ],projects:[
  {id:1,project_id:2,valid_from:'2025-09-01'},
  {id:2,project_id:2,valid_from:'2026-02-01'},
  {id:3,project_id:3,valid_from:'2025-10-01'},
 ]};
 assert.deepEqual(sortedMemberTerms(member).map(x=>x.id),[4,3,1]);
 assert.deepEqual(sortedProjectAssignments(member).map(x=>x.id),[2,3,1]);
 assert.equal(member.projects.length,3);
 assert.deepEqual(sortedMemberTerms(null),[]);
});

test('feedback from every project and client sorts by visible business date', () => {
 const member={feedbacks:[
  {id:1,project_id:4,occurred_on:'2025-10-12',created_at:'2026-10-01'},
  {id:2,project_id:5,created_at:'2026-03-01T23:04:00Z'},
  {id:3,project_id:null,occurred_on:'2026-03-01'},
  {id:4,project_id:5,occurred_on:'2026-11-01'}
 ]};
 assert.deepEqual(sortedMemberFeedbacks(member).map(x=>x.id),[4,3,2,1]);
 assert.equal(feedbackDate(member.feedbacks[0]),'2025-10-12');
 assert.equal(feedbackProjectLabel(member.feedbacks[0],[{project_id:4,project_name:'Alpha'}],[]),'Alpha');
 assert.equal(feedbackProjectLabel(member.feedbacks[1],[],[{id:5,name:'Beta'}]),'Beta');
 assert.equal(feedbackProjectLabel(member.feedbacks[2],[],[]),'По клиенту');
});

test('readable Russian intervals never accidentally shift a day', () => {
 assert.equal(memberPeriod('2025-10-01','2025-10-12'),'01.10.2025 — 12.10.2025');
 assert.equal(memberPeriod('2026-03-20',null),'20.03.2026 — по настоящее время');
 assert.equal(formatMemberDate('2026-12-21T00:00:00Z'),'21.12.2026');
 assert.equal(formatMemberDate(null),'—');
});

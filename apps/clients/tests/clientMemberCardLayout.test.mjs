import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const component = readFileSync(new URL('../src/components/ClientMembersRegistry.vue', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../src/client-member-card.css', import.meta.url), 'utf8');

test('client member drawer uses design system tabs and isolated editors', () => {
 assert.match(component, /<UiTabs v-model="detailsTab"[^>]*variant="card"/);
 assert.match(component, /--irlix-card-tabs-count:3/);
 assert.match(component, /detailsTab==='terms'/);
 assert.match(component, /detailsTab==='projects'/);
 assert.match(component, /detailsTab==='feedbacks'/);
 assert.match(component, /v-if="termOpen"/);
 assert.match(component, /v-if="assignmentOpen"/);
 assert.match(component, /v-if="feedbackOpen"/);
 assert.match(component, /@click="startEditTerm\(t\)"/);
 assert.match(component, /@click="editAssignment\(a\)"/);
 assert.match(component, /@click="saveFeedback"/);
 assert.match(component, /@close="closeDetails"/);
});

test('term, project and feedback rows have date on left and content on right', () => {
 assert.match(component, /v-for="t in termRows"/);
 assert.match(component, /v-for="a in projectRows"/);
 assert.match(component, /v-for="f in feedbackRows"/);
 assert.match(component, /cm-detail-date/);
 assert.match(component, /cm-detail-main/);
 assert.match(component, /memberPeriod\(t.valid_from,t.valid_to\)/);
 assert.match(component, /memberPeriod\(a.valid_from,a.valid_to\)/);
 assert.match(component, /:datetime="feedbackDate\(f\)"/);
 assert.match(component, /projectFeedbackLabel\(f\)/);
 assert.match(styles, /\.cm-detail-row\{display:grid;grid-template-columns:180px minmax\(0,1fr\)/);
 assert.match(styles, /\.cm-field-label\{font-size:12px/);
 assert.match(styles, /\.cm-field-value\{font-size:14px/);
 assert.match(styles, /\.cm-member-card\{[^}]*font-size:14px/);
});

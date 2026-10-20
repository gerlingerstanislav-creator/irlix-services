# Information architecture and interaction design

## Map jobs to objects
Understand objects (customer, timesheet, request, order), their lifecycle and the operations users most often apply. Navigation nouns should reflect user language, not database entities. Identify hierarchy, depth, crosslinks, search, filters, breadcrumbs (if useful), saved views, deep links and back navigation. Minimize context switching between related tasks.

## User flow specification
For every critical task capture: entry point → preconditions → primary path → branches → outcome → feedback → subsequent action. Include not only screen pages but data/state transitions, access checks and error paths.

Suggested scenario matrix:
| Dimension | Examples |
|---|---|
| Roles | viewer, contributor, approver, admin, recently revoked |
| Data | 0, 1, many, missing, duplicate, stale, extremely long |
| Platform/input | desktop keyboard, touch, narrow viewport, screen reader, zoom |
| Network | slow, pending, error, offline, retry and conflict |
| Integrity | unsaved, partial success, duplicate submission, undo |
| Context | direct link, browser Back, resumed after timeout, interrupted work |

For transitions specify origin, triggering control, allowed users, reversible or not, server contract, visible feedback and failure recovery. Do not manufacture backend behavior.

## Principles
- Visibility of status and progress; durable success confirmation for consequential operations.
- Recognition over recall: visible context, strong labeling, recently used relevant filters and explanatory statuses.
- Match mental model; consistent terms per object, avoid hidden mode switches.
- User control: cancel/back/undo when safe; destructive actions need deliberate friction proportional to consequence.
- Error prevention before late validation; preserve entered data after recoverable failure.
- Efficient expert paths **without** removing discoverability for new users: keyboard shortcuts and bulk operations should be documented and reversible.
- Progressive disclosure: show required/contextual fields first, keep advanced options accessible rather than hidden forever.

## Navigation patterns
- Side navigation for multi-domain B2B workspace with stable sections; preserve selection, scroll and breadcrumbs after drill-down.
- Tabs for peer views of the same entity, with stable tab order and discoverable counts, not for wizard steps.
- Steps/wizard for long ordered flows that require validation; show progress and back path.
- Search for known-item retrieval; filters for faceted narrowing; saved views for repetitive work.
- On mobile, bottom navigation for a small stable set of top-level destinations, not dozens of nested modules.

Avoid loading the entire information model into the navigation surface. Think role/context and recent tasks.

## Interaction states
State chart per object: draft → sent → approved → rejected (illustrative; project state machine always wins). For each transition ask who may do it, whether data remains editable, state indication, date/actor, audit, notification, reversal and conflict handling. Use action language that cannot be confused with status (“Approve” versus “Approved”). Never encode state by color alone.

## Content design
Short specific labels. Distinguish empty collection, no search results, access denied, missing permission and technical failure. Error messages explain what happened and next feasible step; include relevant field context and avoid jargon. Icons always have stable names/tooltips or accessible names when necessary.

# Requirements engineering quality

## Writing rules
Atomic, necessary, feasible, unambiguous, consistent, complete relative to scope, verifiable, traceable, implementation-independent when appropriate, and uniquely identified. Avoid vague adjectives (`fast`, `convenient`, `correctly`) unless a measurable threshold or example defines them. Separate goal from solution design and separate functional behavior from quality attributes.

Example: `AUTH-03 [confirmed] When an authenticated user requests another tenant's record by ID, the server MUST deny access regardless of whether the navigation UI hides the record. The response MUST not disclose the record content. AC-09.` The response code and audit retention must be resolved according to existing system policy rather than invented.

## Acceptance criterion shape
`Given <state/roles/data> When <single action/event> Then <observable result and side effects>`; use Scenario Outline for boundary sets; reference requirement IDs. Include success, denied/invalid, failures, state transitions, concurrency/idempotency when high impact. AC must be reproducible with supplied data and an unambiguous oracle. QA should not have to invent expected behavior.

## Non-functional qualities
Operationalize each: metric, threshold, population, observation window, test setup and owner. E.g., `NFR-02: p95 response < 800ms at 100 requests/second measured at service boundary with baseline dataset X` only when these numbers are actual decisions; otherwise placeholder and BLOCKER.

## Traceability
`business goal -> stakeholder need -> BR/FR/AUTH/INT/DATA/NFR -> AC/test -> implementation component -> evidence`. Link defects and change requests both ways. Avoid orphan requirements and untested high-risk rules.

## Business rules vs design
Business rule: what policy must hold. Functional requirement: system reaction. Interface contract: proposed/existing externally observable interaction. Technical design: implementation approach chosen by engineering. Do not force a database or framework from analyst preferences.

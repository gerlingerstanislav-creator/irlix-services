# Modeling playbook

## BPMN 2.0.2
Use pools for participants, lanes for responsibilities; start/end events, tasks, exclusive/parallel/event-based gateways, message flows across pools and sequence flows within a pool. Annotate timers, errors, boundary events, compensation where necessary. Check token behavior at joins, failed/cancelled paths and ownership. Never connect pools with sequence flow. If editor/export unavailable, present precise process steps plus clearly labeled simplified diagram, not false claims of standards-valid BPMN XML.

## UML 2.5.1
- Use case: goal, primary actor, preconditions, trigger, main success, alternates, exceptions, guarantees.
- Sequence: lifelines, sync/async calls, response/timeout, retry, alt/opt/loop, authorization/data ownership; include request/response contract references.
- Activity: branching/parallel flow; state machine: states/events/guards/actions/terminal and forbidden transitions; class/ER: identity, cardinality, optionality and lifecycle. Label Mermaid diagram as *UML-inspired* unless notation conformance is verified.

## Decision table
For combinations of conditions, list condition columns and outcomes for every valid combination or explicit wildcard classes. Detect conflicts (same condition -> different outcomes), overlap (two winning rules), gaps (uncovered inputs), precedence and default denial. Include boundary and invalid input rows.

## State transitions
Record `(current state, actor, event, guard) -> next state, side effects, audit, failure behavior`. Confirm unreachable states, unauthorized transitions, repeated events, concurrent transition race, idempotency and rollback. Never let UI state alone define server authority.

## Choosing a model
Process crosses participants -> BPMN; complicated API interactions -> sequence; lifecycle -> state machine; tangled policies -> decision table; complex objects -> data model; single feature -> focused use case + AC may suffice.

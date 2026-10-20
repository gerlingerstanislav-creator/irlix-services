# Discovery and elicitation

1. Map stakeholders: sponsor, process owner, operator, support, admins, data owner, security/legal, QA, developer, integration owner. Identify who has decision authority; don't equate interview claims with approved policy.
2. Build context inventory: existing requirements, UX/screens, vocabulary, service boundaries, API specs, data schemas, config, feature flags, permissions, tests, incidents, audit logs, deployment and ownership constraints. Probe actual behavior where authorized. Record confidence and provenance.
3. For each free-form request, rewrite: **problem -> objective -> scope -> actor -> trigger -> normal path -> alternative path -> exceptions -> business rules -> data -> permissions -> verification**.
4. Elicit via concrete examples, counterexamples, event storming (commands/events/policies), walkthroughs, observation, five whys, process mapping, CRUD analysis, prototype and scenario interviews. Do not assume stakeholder's suggested UI is the business goal.
5. Tag statements `CONFIRMED` (approved or directly evidenced), `OBSERVED` (actually found), `PROPOSED`, `INFERRED`, `UNKNOWN`, `CONFLICT`. Specify source, date where possible, owner and decision needed.
6. Question priority: P0 blocks safe implementation/security/data correctness; P1 changes behavior or scope; P2 enhancement/preference. Ask targeted questions: options, trade-offs, recommended default if defensible. If unresolved, keep alternative branches conditional.
7. Maintain change log and decision log with ID, date, source, rationale, superseded decisions. Avoid circular reconfirmation.

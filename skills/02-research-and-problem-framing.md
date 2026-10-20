# UX research, evidence, product framing

## Problem statement
Use: `For [observed user segment] who need [job/outcome], [current step/barrier] causes [measured/observed consequence] under [context]. We believe [change] could improve [metric/behavior]; validate via [method].`

Separate: evidence (direct observation, log, direct quote), interpretation (why it might happen), and hypothesis (what to change). Never invent research sessions, quotes, quantitative improvements, funnel data, sample sizes, interviews or statistical significance.

## Research ladder
- **Desk/context**: task documents, existing journeys, support issues, competitive conventions, analytics instrument map. Useful for initial scope; cannot establish actual user motivation.
- **Generative**: contextual inquiry, semi-structured interviews, field shadowing, diary where repeated behavior matters. Ask for recent specific episodes, not hypothetical wishes; account for role and environment.
- **Evaluative**: moderated usability with realistic tasks and think-aloud, unmoderated for stable straightforward tasks, expert heuristic audit for early risk discovery, tree testing for findability, card sorting for IA hypotheses.
- **Quantitative**: funnel/drop-offs, search refinement rate, time to task, error/undo rate, completion, keyboard reliance, repeated attempts. Define events, segment, denominator and baseline before interpreting numbers.
- **Experiment**: A/B only when traffic, risk control, measurement integrity and ethics justify. For small B2B samples, sequential qualitative rounds may be better.

## Research operating instructions
1. Decision to support: what choice will change if evidence differs?
2. Recruitment: actual roles (novice/expert; permission levels; platform; assistive needs when relevant), avoid convenience sample overgeneralization.
3. Task script: outcome, not step-by-step UI instructions; realistic but non-sensitive fixtures.
4. Observe independently: task success, incorrect attempts, help requests, errors, confidence (self-report not proof), task time, deviations.
5. Synthesize issues: severity x frequency x impact, with verbatim evidence appropriately anonymized.
6. Validate with independent participants/behavior; distinguish insights from design preferences.

## Notes on sample size
Five evaluative participants per meaningful homogeneous segment can be a useful **qualitative early diagnostic**, not a universal rule, confidence level or statistical sample. Diverse roles/technologies may need separate rounds; quantitative detection and comparative studies require justified power/sample calculation. Source: NN/g, *How Many Test Users in a Usability Study?*.

## Jobs and journey documentation
Capture trigger, goal, environment, constraints, prior knowledge, current route, pain, workaround, outcome, after-action, success metric. Map backstage failure modes where UI depends on async integration. Avoid fabricated persona biographies; behavior-based segmentation is more actionable.

## Problem prioritization
Classify:
- P0: makes core task impossible, misleads about important data, risks loss/unauthorized operation, or severe accessibility blocker.
- P1: frequent major friction, repeated work, serious navigation/feedback issue or large accessible segment excluded.
- P2: moderate friction with known workaround.
- P3: minor polish; cosmetic drift with low impact.

Priority is not an objective number without context. Report frequency and evidence separately. Beware conflating aesthetic preference with usability outcomes.

## Metrics library
Task completion (defined end state); first-try success; time on task (interpret learning); error and recovery rates; data correction rate; abandonment; unnecessary navigation; SUS/SEQ when justified; support tickets; accessibility barriers; perceived workload. Instrument baseline and segment before/after; control for seasonality and process changes.

# Context first: project reconnaissance and design constraints

## Goal
Before prescribing a pixel, reconstruct the UI's existing rules. Discovery must scale to provided evidence: screenshots only → infer visual patterns cautiously; codebase → inspect components and tokens; Figma → inspect libraries and variant states; running product → inspect actual flows and breakpoints. Never claim an unobserved artifact was audited.

## Evidence inventory
| Source | Inspect | Trust/limit |
|---|---|---|
| Design system docs / Storybook / Figma library | foundations, tokens, themes, typography, control taxonomy, variants, spacing, breakpoints, states | could be outdated; compare shipped UI |
| Existing production/staging screens | actual density, nav shell, interaction affordance, localization, responsive behavior | snapshots miss keyboard and dynamic states |
| Code | reusable components, design tokens, CSS themes, aria semantics, route/access checks, feature flags | implementation != intended behavior |
| Product specs / tasks | rules, roles, jobs, acceptance criteria, operating constraints | document assumptions and conflicts |
| Analytics / interviews / support | task frequency, errors, actual friction, role differences | samples biased; triangulate |

Create `Design Context Snapshot` with: product type, target action, roles, evidence sources, current components, system tokens, preferred patterns, constraints, unknowns, accessibility target, responsive devices and change budget.

## Source-of-truth hierarchy
1. Confirmed requirements: safety, privacy, domain constraints, accessibility/contractual obligations, security/permissions and user-critical flows.
2. Adopted project design-system rules and component interfaces.
3. Operating-system conventions (mobile/desktop).
4. Established local screen patterns, validated by user outcomes.
5. External guidelines as decision aids where gaps exist.

When conflicting, state which rule wins, why, owner/decision pending. Example: project has 28px dense table rows but WCAG pointer targets may be too small. Do not enlarge every cell on assumption: distinguish row height from actionable hit area, provide keyboard and/or larger associated controls, assess actual target-size rule and its exceptions with care.

## Reuse-first ladder
1. Use existing component + documented variant.
2. Compose existing components to meet task.
3. Extend an existing component with backward-compatible prop/state.
4. Introduce one new component in a defined product namespace with full states, design tokens and migration note.
5. Propose new foundations only if defect is widespread and owner approves.

Never introduce a new visual language, arbitrary accent colors, shadows, spacing scale, rounded-corner scheme, icons or typography merely to make a screen look modern.

## Baseline questions to answer without demanding answers from user
- Who uses the screen, how often, under what stress and with what device/input?
- What is their actual unit of work (record, day, order, ticket, customer)?
- Which fields/actions represent money, identity, access, legal obligations or destructive operations?
- Where is the navigation shell, and can you reach the same data by URL, search and browser Back?
- Are there constraints on width, zoom, language length, contrast, loading time, assistive technology?
- Is the user requesting redesign or minimum viable adjustment? Optimize the smallest verified scope.

## Permissions as interaction design
For each action classify: visible, available, executable, rejected, auditable. Separate server enforcement from presentation. Never infer rights from menu visibility alone. Permission denied must remain understandable and not expose restricted values. Designs must handle role changes mid-session and stale UI access.

## Required output format (compact)
```text
Design context: [task, roles, platforms]
Sources inspected: [exact artifacts]
Existing rules: [components/tokens/conventions]
Unverified: [unseen screens, uncertain requirements]
Constraints: [width, data density, rights, localization]
Change approach: reuse / compose / extend / new
```

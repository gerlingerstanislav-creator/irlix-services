# Test report — Senior Product UI/UX Designer

**Type:** local synthetic scenario exercise and structural smoke validation. Fixtures deliberately contain known issues. No real users or production interfaces were tested. Chromium screenshots and DOM observations are stored alongside this report.

| Fixture | Width | Seeded/observed issues | Detection | Written review |
|---|---:|---|---:|---|
| Dense B2B timesheet | 375 CSS px | 16×16 edit, color-only statuses, clipped table content | 3/3 | `assets/examples/01-dense-b2b-timesheet-review.md` |
| Mobile preorder | 375 CSS px | allergen conflict, color-only seat selection, primary touch ergonomics | 3/3 | `assets/examples/02-mobile-preorder-review.md` |
| Transfer form | 390 CSS px | no amount label, false success, absent client validation | 3/3 | `assets/examples/03-financial-transfer-review.md` |
| Warehouse tablet | 800 CSS px | 22×22 controls, mouse-only details, color-only status | 3/3 | `assets/examples/04-warehouse-tablet-review.md` |

## Gates
- Skill YAML frontmatter, linked files, UTF-8 files: PASS.
- Four synthetic Chromium DOM smoke tests: PASS, 12/12 selected seeded issues detected.
- Four JSON handoff reports passed required context/evidence/verification fields: PASS.
- Intentionally incomplete report correctly rejected: PASS.

## Remaining limitations and next best validations
This exercise checks **bounded detection** and review completeness rather than autonomous model activation. It does not assess all WCAG criteria, cross-browser/real-device behavior, disabled roles, a11y screen reader, user comprehension, project-specific tokens, or compliance. Before deploying in a real team, test on existing Figma/component library and 1–2 actual screens using developer + QA feedback; iterate templates and rules against observed false positives.

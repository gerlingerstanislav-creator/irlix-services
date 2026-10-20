# Component / screen states

Fill `N/A` with reason rather than deleting columns. Provide both interaction behavior and visual/accessible output. Extend columns for platform-specific variants.

| Component | Idle | Focus/hover/pressed | Disabled/readonly | Loading | Empty/no results | Error | Success | Dirty/conflict | Permission | Mobile/zoom | Accessible semantics |
|---|---|---|---|---|---|---|---|---|---|---|---|
| [name] | ... | ... | ... | ... | ... | ... | ... | ... | ... | ... | ... |

## Transition contract
| From | Trigger | To | Who may trigger | Feedback | Recovery/undo |
|---|---|---|---|---|---|
| ... | ... | ... | ... | ... | ... |

## Data boundary cases
0 rows; 1 row; thousands; null/zero; long text; duplicates; invalid/expired; new or revoked permission; multi-user race; no network; unexpected API response.

# Scenario 04 — Mobile notes offline synchronization

**Test task:** "Test a mobile application that lets users edit notes offline, sync on reconnect and share notes with teammates." No implementation/device is given. **Expected mode: design-only, not_run.**

## Risk model
- Object state: local dirty, queued, syncing, synced, conflict, deleted.
- Actor state: current owner, collaborator, revoked collaborator, logged-out user.
- Network: offline, degraded, recovered, duplicated requests, reordered delivery.
- Unknown conflict oracle: latest-write-wins, per-field merge, explicit conflict resolution? **MISSING.**

## Cases
| ID | Action | Expected / condition | Level | Status |
|---|---|---|---|---|
| TC-01 | Edit offline, force-close/reopen | Local change durable and visibly queued | mobile | not_run |
| TC-02 | Edit same note on two devices offline, reconnect | Chosen conflict policy, no silent lost data | integration | blocked: rule missing |
| TC-03 | Delete on device A while B edits offline | Tombstone/version policy; no accidental resurrection | integration | blocked: rule missing |
| TC-04 | Revoke collaborator while their device is offline; reconnect | Server denies queued write and excludes privileged content | auth/sync | not_run |
| TC-05 | Logout offline, then background sync | No unauthorized sync under old identity; local data privacy per policy | auth/mobile | not_run |
| TC-06 | Token expires during sync; refresh fails | Safe pause/re-auth, no unintended duplicate queue processing | integration | not_run |
| TC-07 | Server commits but client does not receive ack, resends | Idempotency and no duplicate note/version | integration | not_run |
| TC-08 | OS kills app mid-sync, reopen | Recover queue and preserve eventual result | mobile | not_run |
| TC-09 | Different locale/timezone + DST transition | Consistent ordering/timestamps and conflict comparisons | domain | not_run |
| TC-10 | Accessibility: edit/conflict banners, voice support, focus | Critical actions usable with assistive technology | UI/a11y | not_run |

## Coverage conclusions
A response only checking online create/edit is not acceptable. Focus on durable local queue, authorization at server commit, conflicts and privacy after logout. No actual devices exercised; no defects confirmed.

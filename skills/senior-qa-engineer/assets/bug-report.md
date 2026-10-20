# Evidence-based defect report template

## `[Subsystem] Actual failure in condition — impact`

- **ID:** BUG-xxx or pending identifier
- **Classification:** confirmed defect | likely defect | test artifact | requirement gap
- **Severity:** blocker / critical / major / minor / trivial; explain user/data/security consequence. **Priority:** team-assigned or proposed separately.
- **Environment/build:** branch/hash, build, service versions, browser/device where relevant, flags and UTC timestamp. Never claim an unavailable build number.
- **Requirements/oracle:** link / text / assumption with confidence.
- **Setup:** synthetic accounts A/B and object ownership; state, feature flags; safely replayable fixtures.
- **Reproduction:** numbered minimum complete steps, including exact API method/path and sanitized payload when useful.
- **Expected:** objectively verifiable response, state and side effects; explicit policy source.
- **Actual:** observed response, state, logs and unintended effects. Separate facts from interpretation.
- **Frequency:** n/m on what environment, or not yet reliably reproducible.
- **Evidence:** redacted request/response; trace/request ID; screenshot/video/log/test report; commands; file/line if reviewing code. Never invent these artifacts.
- **Blast radius / security / data integrity:** affected roles, tenants, objects and persistence, based on observation or hypothesis explicitly labeled.
- **Probable cause (hypothesis only):** ...
- **Regression guard:** test IDs + assertions at lowest effective level, plus cross-boundary test if required.
- **Workaround / containment:** verified or proposed, label clearly.
- **Uncertainty / blocker:** what must be verified before closing.

## Good vs weak
Weak: "User can edit foreign record; broken permissions." (no build, oracle, reproduction, backend evidence).
Good: "On test build `abc`, actor A in tenant X submitted PATCH on synthetic record B in tenant Y; 200 and DB field changed; approved policy REQ-2 denies editing foreign records; redacted trace `...`; replayed twice." This is an **illustration, not an executed finding**.

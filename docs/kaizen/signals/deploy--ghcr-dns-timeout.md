# Production: GHCR hostname resolution stalls before image download

- **Scope / tags:** `ci`, `deployment`, `dns`
- **Fingerprint:** `docker login ghcr.io` fails due to resolver timeout before bounded `docker compose pull` retries can run
- **First noted:** 2026-10-21 (earlier transient GHCR DNS mitigation)
- **Latest incident:** 2026-10-22
- **Analysis status:** repeated class, confirmed missing retry at login; upstream DNS failure origin unconfirmed

## Evidence

- 2026-10-21 — [PR #504](https://github.com/gerlingerstanislav-creator/irlix-services/pull/504) added retry for transient GHCR image pulls. This is evidence of an earlier comparable registry DNS failure class, not necessarily the same outage.
- 2026-10-22 — [production CI run #39818810624](https://github.com/gerlingerstanislav-creator/irlix-services/actions/runs/39818810624), including repeated deploy attempts, failed at `docker login ghcr.io` with a DNS timeout from the server resolver, before image pulls or container recreation. The same run's validation, build and QA stages passed.

## Analysis and mitigation

- **Confirmed:** Login was outside the existing bounded pull retry. This caused an immediate deploy failure even when registry access might recover shortly thereafter.
- **Unknown:** Whether the LAN resolver, upstream provider, firewall, or transient congestion caused the underlying timeout. No server DNS changes are justified without measurement.
- **Code fix:** The deployment script now retries GHCR login up to four bounded attempts before pulling or replacing containers, with regression checks. The release remains fail-closed if all attempts fail.
- **Effect verification:** Not yet verified on a successful production rollout. Do not treat the retry as a fix for a prolonged DNS outage; collect reachability evidence before changing server network settings.

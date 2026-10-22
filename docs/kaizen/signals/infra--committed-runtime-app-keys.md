# Infrastructure: committed Laravel application encryption keys

- Date discovered: 2026-10-23.
- Observed in \`docker-compose.yml\`, \`docker-compose.override.yml\` and
  \`docker-compose.equipment.yml\`: several Laravel containers received literal
  application keys from the repository; some worker keys differed from the
  owning API service.
- Risk: public encryption/signing keys and incompatible API/worker crypto.
- Remediation: provision private server-side keys, store legacy keys only in
  the server environment for Laravel decryption, fail closed on unreadable
  prior runtime. A production rollout and legacy data re-encryption still
  require independent operational verification.
- Evidence: this change's Compose diff, key provisioning code and offline tests.

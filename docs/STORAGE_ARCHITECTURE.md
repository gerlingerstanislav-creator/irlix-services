# Storage — V1 architecture and pilot runbook

**27 October 2026. Decision source:** owner approved self-hosted first, cloud-compatible storage, cloud copy/archive in V1, and UI integration into Employees, Vacations, Clients and Timesheets. This document is a safe technical outline; confidential product rules and unresolved roles remain in private ideas/storage.

**Status:** Technical preparation / isolated pilot, **NOT production-ready**. No production release was authorized. Source of truth for product scope: `ideas/company-internal-services-81-2.4/services/storage/STORAGE_V1_REQUIREMENTS.md`. Track #445.

## Separation of responsibilities

- **S3 object layer:** only encrypted/controlled binary object storage with isolated credentials/buckets. It must never grant business-file permission to end users. Initial pilot candidate: SeaweedFS `weed mini` in Docker on a **separate physical filesystem/host**. Selection for production requires operational and license/security review.
- **Storage API (planned):** owns File ID, object key, checksum, versions, state (staged/scanning/ready/quarantined/deleted), immutable audit, bucket routing, and lifecycle. Planned Laravel service has its own database schema, never queries another service's tables. `storage-file-id` does not authorize reads.
- **Corporate Drive UI (planned):** own/team/shared folders and search, role-aware ACL. Uses Keycloak authentication and effective Employees rights. Reuses `@irlix/ui`/service catalog.
- **Business-service integration (planned):** Employees/Vacations/Clients/Timesheets each authorizes operations against their business object before invoking the Storage capability using a trusted service-to-service contract; record links remain under owning service. Do not surface existing CV/HR/absence documents in general Drive automatically.

Cross-service integration is API/event based, aligned with docs/ARCHITECTURE.md. No cross-schema SQL or shared storage-root mounts in business services.

## Planned API boundary (contract, not implemented endpoints)

`POST /api/storage/files/initiate` (scope + owner-service + entity reference + size/MIME), `POST /api/storage/files/{fileId}/complete` (checksum verification, ready after AV scanning), `GET /api/storage/files/{fileId}`, `POST /api/storage/files/{fileId}/download` (short-lived scoped ticket), `POST /api/storage/files/{fileId}/versions`, `DELETE /api/storage/files/{fileId}`. Service-bound capabilities must be authenticated and cryptographically bound to issuer, target object, action, expiry and single-use constraints where relevant.

Object identifiers should be nonpredictable and immutable per revision; never use a client-provided object key or path. No unrestricted direct browser S3 access and never expose S3 credentials. Until corporate HTTPS is enabled, API-controlled streaming is preferred to presigned URLs pointing to nonsecure/inaccessible endpoints.

## Storage layout / multi-tenant readiness

- Separate namespace/bucket/credentials per deployment and service, enforcing least privilege. Do not embed customer or employee data in paths/object keys.
- Version relation points to immutable object IDs and stored `sha256`; PostgreSQL metadata and the S3 bytes must be reconciled after partial failures.
- Create → staged → AV scanning → ready, eventual cleanup of aborted uploads. Delete → tombstone → retention expiry; no premature physical deletions.
- S3 provider abstraction must accept endpoint, region, path-style, TLS, access credentials and bucket. Credentials are provisioned outside Git and not returned by API.

## Cloud duplication vs archive

1. **Replica/clone:** asynchronous object copy to a separate S3-compatible provider with checksum validation, retries and lag monitoring. Do not propagate delete by default; a replica is not a backup.
2. **Restore-capable archive:** consistent checkpoint of committed objects **and** Storage/business metadata, a signed/checksummed manifest, versioned retention, independent archive credentials and recovery into a separate instance. Snapshot and restore tests must verify cross-service object relations and ACL, not just object existence.
3. Recoverability requires at least one complete restore exercise and measured RPO/RTO. Cloud region, encryption/key custody, retention and backup schedules must be confirmed by owner/security before activation with production data.
4. The included `scripts/storage/cloud-copy-pilot.sh` is ONLY a safe, immutable-copy object pilot. It is not the archive implementation and must not be sold/described as one.

## Existing data migration

Existing `vacations_files` and `clients_files` stay authoritative until dual-read/verification and a complete reconciliation. Never delete these Docker volumes via deploy/prune. Use idempotent per-file migration with sha256, size/MIME, stable business attachment IDs, per-entity fallback, audit and resumable conflict reports. Rollback must keep historical files available.

## Isolated local pilot

Included `docker-compose.storage.pilot.yml` is **not part of the main CI/service registry** and is deliberately not launched by normal production deploy. The pilot is for a reviewed dedicated host/volume only.

1. Provision an independent encrypted disk/filesystem, mount to an absolute path and give container UID/GID 1000 write access. Confirm free-space margin and physical backup plan. Never point at the existing 45-GiB application root disk.
2. Select and review a release image digest of `chrislusf/seaweedfs` (not latest), then provision a secure external env file based on `infra/storage/pilot.env.example`, replacing ALL placeholders with secrets. Review operational exposure: the S3 port is loopback-only; no Admin/Filer/Master ports published.
3. On the storage host: `set -a; source /secure/path/to/storage.env; set +a; bash scripts/storage/preflight-pilot.sh`. Then `docker compose --env-file /secure/path/to/storage.env -f docker-compose.storage.pilot.yml up -d`. For a separate host, ensure this Compose file is used alone and S3 is accessed only via private TLS/tunnel.
4. Use AWS CLI with explicit `--endpoint-url http://127.0.0.1:8333` to PUT/GET/DELETE synthetic test objects; test unauthenticated and incorrect credentials (must fail), multipart, restart persistence, and checksum. No real company documents until security review.
5. To exercise **copy only**, configure rclone S3 endpoints and restrictive credentials outside Git and run `STORAGE_COPY_SOURCE=local:bucket STORAGE_COPY_DEST=cloud:archive-prefix bash scripts/storage/cloud-copy-pilot.sh`. Verify no delete/overwrite and test copied objects from cloud. This does NOT replace a restore drill.
6. Stop/remove the pilot containers without `-v`; never issue `docker volume prune` against business data. Keep the pilot data volume/mount intact for investigation.

## Promotion gates / blockers

- Production server disk capacity: docs/DEPLOYMENT.md records **45 GiB root, 99% used, ~717 MiB free (22 Oct)**. A dedicated device or server, capacity forecast and disk alerting are mandatory before rollout.
- Pilot S3 object tests with realistic uploads, authentication, encryption at rest, firewall and recovery.
- Storage API and Drive UI implemented, tested through 4 scoped service adapters, server-side permissions and audit.
- Consistent cloud archive with PostgreSQL metadata + checksum manifest, configured provider and verified isolated restore; do not claim cloud backup on object copy success.
- Update `infra/ci/services.json`, generated compose/images, nginx, service catalog, `/system/service-map/` and end-to-end QA **at the point the new service is actually integrated**, not for the isolated pilot.
- No production release until direct owner instruction. Configuration secrets and customer files stay off the public repository.

## Acceptance evidence

Pilot infra syntax (not Docker-tested in this environment) and safe command contracts can be checked statically by `python3 -m unittest scripts/ci/tests/test_storage_pilot.py`. Test real S3 and Docker on an isolated host before any connection to app data. Label all unexecuted checks **NOT TESTED**.

## 27.10.2026 — pilot deployment overlay

In addition to the optional dedicated-host pilot file, the release introduces `docker-compose.storage.yml` with a SeaweedFS S3 origin and a Filestash browser test surface. During pilot it uses an isolated `/srv/irlix-storage-pilot` directory with a strict preflight: >=8 GiB free before activation. The main 45-GiB VM root is a **temporary synthetic-only test location**; this is not approved for business documents. Persistent Filestash state uses `storage_filestash_state` volume. The S3 port is host-loopback-only; the file manager is protected by an Employees platform-admin SSO gate on port 8110. Path `/storage/` sends the user through the Dashboard OIDC flow. Open inbound port 8110 inside the corporate VPN if remote browser access is desired; do not publish this port to the Internet.

The server deploy script provisions long random credentials and a bcrypt Filestash administration secret into the root-only server .env. The initial Filestash admin password is available only to a privileged host operator under `STORAGE_FILESTASH_ADMIN_INITIAL_PASSWORD`; never copy it into public code/logs. The first Storage deployment now seeds a **single isolated S3 connection** in Filestash from the server-side `storage-s3:8333` endpoint. It enables the Filestash `passthrough/direct` middleware *behind* the Employees/nginx platform-admin gate so a user never needs S3 access/secret keys. Filestash's AWS SDK gets keys from Docker environment; the persisted `config.json` has only a nonsecret endpoint and middleware rule. The Filestash `CONFIG_ENCRYPT=false` setting applies **only to this pilot** because all backend middleware parameters on disk are deliberately nonsecret; do not reuse it when provisioning genuine secrets into Filestash configuration. The protected admin UI remains available. Initial activation is idempotent, backs up existing configuration, and refuses to replace any pre-existing custom backend. `scripts/storage/ensure-filestash-pilot.py --check` is part of the production smoke. New images must be validated with direct browser upload/list/download through Filestash; synthetic S3 API smoke alone is insufficient for that claim.

The S3/Filestash images are built as pinned CI-input GHCR wrappers; Filestash base `latest` is not pinned upstream yet, so a locked digest must be selected after functional verification. Neither backend is connected to existing service attachments; no cloud backup runs. Do not store confidential material in this pilot. The release requires an actual Docker and browser check for upload/download/checksum and permission revocation before declaring completion.

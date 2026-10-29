-- Storage V1 irrecoverable working-copy deletion ledger. No migration deletes data.
-- Backups/replicas are deliberately outside this operation and retain their own TTL.
-- Do not enable STORAGE_V1_PERMANENT_DELETE_ENABLED before restore rehearsal,
-- retention/legal-hold approval and an operator confirmation of S3 versioning.
BEGIN;
CREATE TABLE IF NOT EXISTS storage.purge_requests (
 object_id uuid PRIMARY KEY REFERENCES storage.objects(id) ON DELETE RESTRICT,
 owner_employee_id bigint NOT NULL CHECK (owner_employee_id > 0),
 requested_by_employee_id bigint NOT NULL CHECK (requested_by_employee_id > 0),
 state text NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','completed')),
 created_at timestamptz NOT NULL DEFAULT now(),
 completed_at timestamptz,
 CONSTRAINT purge_state_consistency CHECK (
   (state='pending' AND completed_at IS NULL)
   OR (state='completed' AND completed_at IS NOT NULL)
 )
);
CREATE INDEX IF NOT EXISTS storage_purge_owner_idx
 ON storage.purge_requests(owner_employee_id,state);
-- Audit events and file_versions remain append-only; only bytes are removed.
-- Runtime Storage role needs SELECT, INSERT, UPDATE on purge_requests (no DELETE).
COMMIT;

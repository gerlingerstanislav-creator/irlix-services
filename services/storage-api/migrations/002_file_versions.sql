-- Append-only file bytes and version history. No retention purge or data deletion.
-- Requires 001_storage_acl_audit.sql and a verified pre-migration restore.
BEGIN;
CREATE TABLE IF NOT EXISTS storage.file_versions (
 id uuid PRIMARY KEY,
 object_id uuid NOT NULL REFERENCES storage.objects(id) ON DELETE RESTRICT,
 s3_key text NOT NULL UNIQUE,
 created_by_employee_id bigint NOT NULL CHECK (created_by_employee_id > 0),
 size_bytes bigint NOT NULL CHECK (size_bytes >= 0),
 sha256 text NOT NULL CHECK (length(sha256) = 64),
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS storage_versions_object_time_idx
 ON storage.file_versions(object_id,created_at DESC);
CREATE OR REPLACE FUNCTION storage.reject_version_mutation()
 RETURNS trigger LANGUAGE plpgsql AS $$
 BEGIN RAISE EXCEPTION 'storage.file_versions is append-only'; END;
 $$;
DROP TRIGGER IF EXISTS storage_version_immutable ON storage.file_versions;
CREATE TRIGGER storage_version_immutable BEFORE UPDATE OR DELETE ON storage.file_versions
 FOR EACH ROW EXECUTE FUNCTION storage.reject_version_mutation();
-- Runtime role: SELECT/INSERT on file_versions ONLY; no UPDATE, DELETE, TRUNCATE.
COMMIT;

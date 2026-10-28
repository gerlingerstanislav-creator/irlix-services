-- Storage-owned schema only. DO NOT run against production without backed-up S3
-- metadata, a reviewed migration, and a restore rehearsal.
BEGIN;
CREATE SCHEMA IF NOT EXISTS storage;
CREATE TABLE IF NOT EXISTS storage.objects (
 id uuid PRIMARY KEY,
 kind text NOT NULL CHECK (kind IN ('file','folder')),
 owner_employee_id bigint NOT NULL CHECK (owner_employee_id > 0),
 parent_id uuid REFERENCES storage.objects(id) ON DELETE RESTRICT,
 display_name text NOT NULL CHECK (length(display_name) BETWEEN 1 AND 160),
 s3_key text UNIQUE,
 state text NOT NULL DEFAULT 'active' CHECK (state IN ('active','trashed')),
 deleted_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 revision bigint NOT NULL DEFAULT 1 CHECK (revision > 0),
 CONSTRAINT storage_key_shape CHECK ((kind='file' AND s3_key IS NOT NULL) OR (kind='folder' AND s3_key IS NULL)),
 CONSTRAINT storage_state_consistency CHECK ((state='active' AND deleted_at IS NULL) OR (state='trashed' AND deleted_at IS NOT NULL)),
 CONSTRAINT storage_no_own_parent CHECK (parent_id IS DISTINCT FROM id)
);
CREATE UNIQUE INDEX IF NOT EXISTS storage_object_child_name_idx
 ON storage.objects(owner_employee_id,parent_id,lower(display_name)) WHERE parent_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS storage_object_root_name_idx
 ON storage.objects(owner_employee_id,lower(display_name)) WHERE parent_id IS NULL;
CREATE INDEX IF NOT EXISTS storage_object_parent_idx ON storage.objects(parent_id);
CREATE INDEX IF NOT EXISTS storage_object_owner_idx ON storage.objects(owner_employee_id,state);

CREATE TABLE IF NOT EXISTS storage.grants (
 object_id uuid NOT NULL REFERENCES storage.objects(id) ON DELETE RESTRICT,
 employee_id bigint NOT NULL CHECK (employee_id > 0),
 role text NOT NULL CHECK (role IN ('reader','editor')),
 granted_by_employee_id bigint NOT NULL CHECK (granted_by_employee_id > 0),
 granted_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(object_id,employee_id)
);
CREATE INDEX IF NOT EXISTS storage_grant_recipient_idx ON storage.grants(employee_id,object_id);

CREATE TABLE IF NOT EXISTS storage.audit_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 event_id uuid NOT NULL UNIQUE,
 actor_employee_id bigint NOT NULL CHECK (actor_employee_id > 0),
 object_id uuid,
 action text NOT NULL,
 outcome text NOT NULL CHECK (outcome IN ('allowed','denied','error')),
 correlation_id uuid NOT NULL,
 occurred_at timestamptz NOT NULL DEFAULT now(),
 details jsonb NOT NULL DEFAULT '{}'::jsonb,
 CONSTRAINT details_object CHECK (jsonb_typeof(details)='object')
);
CREATE INDEX IF NOT EXISTS storage_audit_object_time_idx ON storage.audit_events(object_id,occurred_at DESC);
CREATE INDEX IF NOT EXISTS storage_audit_actor_time_idx ON storage.audit_events(actor_employee_id,occurred_at DESC);
CREATE OR REPLACE FUNCTION storage.reject_audit_mutation()
 RETURNS trigger LANGUAGE plpgsql AS $$
 BEGIN RAISE EXCEPTION 'storage.audit_events is append-only'; END;
 $$;
DROP TRIGGER IF EXISTS storage_audit_immutable ON storage.audit_events;
CREATE TRIGGER storage_audit_immutable BEFORE UPDATE OR DELETE ON storage.audit_events
 FOR EACH ROW EXECUTE FUNCTION storage.reject_audit_mutation();
-- Runtime database role: SELECT/INSERT on audit, SELECT/INSERT/UPDATE on objects
-- and grants; no DELETE on any metadata or bytes. Dedicated migration role
-- owns schema and tables; database role permissions must be provisioned separately.
COMMIT;

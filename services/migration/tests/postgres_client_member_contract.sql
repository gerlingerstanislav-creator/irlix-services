-- Read-only PostgreSQL gate for an ISOLATED restored target database.
-- Run after applying real Clients and Timesheets Laravel migrations.
-- Does not run the legacy import, modify data, or connect to production.
BEGIN TRANSACTION READ ONLY;
DO $contract$
DECLARE
    required text;
BEGIN
    FOREACH required IN ARRAY ARRAY[
        'clients.client_members',
        'clients.member_terms',
        'clients.project_assignments',
        'timesheets.timesheet_entries'
    ] LOOP
        IF to_regclass(required) IS NULL THEN
            RAISE EXCEPTION 'Missing migrated target table: %', required;
        END IF;
    END LOOP;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint c
        WHERE c.conrelid = 'clients.client_members'::regclass
          AND c.conname = 'client_members_identity_xor' AND c.contype = 'c'
    ) THEN
        RAISE EXCEPTION 'Clients identity XOR constraint missing';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint c
        WHERE c.conrelid = 'clients.member_terms'::regclass
          AND c.conname = 'member_terms_canonical_owner' AND c.contype = 'c'
    ) THEN
        RAISE EXCEPTION 'Canonical MemberTerms owner constraint missing';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger t
        WHERE t.tgrelid = 'clients.member_terms'::regclass
          AND t.tgname = 'member_terms_interval_guard' AND t.tgenabled <> 'D'
    ) THEN
        RAISE EXCEPTION 'MemberTerms overlap guard missing or disabled';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger t
        WHERE t.tgrelid = 'clients.project_assignments'::regclass
          AND t.tgname = 'project_assignments_interval_guard' AND t.tgenabled <> 'D'
    ) THEN
        RAISE EXCEPTION 'ProjectAssignment overlap guard missing or disabled';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint c
        WHERE c.conrelid = 'timesheets.timesheet_entries'::regclass
          AND c.conname = 'timesheet_entries_source_xor' AND c.contype = 'c'
    ) THEN
        RAISE EXCEPTION 'Timesheets legacy/project XOR constraint missing';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint c
        WHERE c.conrelid = 'timesheets.timesheet_entries'::regclass
          AND c.conname = 'timesheet_entries_client_member_id_work_date_unique'
          AND c.contype = 'u'
    ) THEN
        RAISE EXCEPTION 'One ClientMember/day unique constraint missing';
    END IF;

    IF EXISTS (
        SELECT 1 FROM clients.client_members
        WHERE (employee_id IS NULL) = (partner_specialist_id IS NULL)
    ) THEN
        RAISE EXCEPTION 'Existing Clients member has invalid employee/partner identity';
    END IF;

    IF EXISTS (
        SELECT 1 FROM timesheets.timesheet_entries
        WHERE client_member_id IS NOT NULL AND project_id IS NOT NULL
    ) THEN
        RAISE EXCEPTION 'Timesheets entry contains both project and ClientMember IDs';
    END IF;

    RAISE NOTICE 'ClientMember PostgreSQL structural and existing-data checks passed';
END
$contract$;
COMMIT;

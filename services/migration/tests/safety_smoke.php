<?php

require __DIR__.'/../vendor/autoload.php';

use App\Migration\Core\LegacyReader;

LegacyReader::assertReadQuery('SELECT id, email FROM public.employees ORDER BY id');
LegacyReader::assertReadQuery("\n SELECT count(*) FROM public.vacation");
LegacyReader::assertReadQuery("SELECT current_setting('default_transaction_read_only') AS default_read_only");
LegacyReader::assertReadQuery("SELECT has_table_privilege(current_user, c.oid, 'INSERT') FROM pg_class c LIMIT 1");
LegacyReader::assertReadQuery("SELECT has_database_privilege(current_user, current_database(), 'CREATE') AS database_create");
LegacyReader::assertReadQuery("SELECT has_schema_privilege(current_user, n.oid, 'CREATE') FROM pg_namespace n LIMIT 1");
LegacyReader::assertReadQuery("SELECT has_sequence_privilege(current_user, c.oid, 'USAGE') FROM pg_class c LIMIT 1");

$blocked = [
    'UPDATE public.employees SET email = email',
    'DELETE FROM public.vacation',
    'INSERT INTO public.employee(id) VALUES (1)',
    'ALTER TABLE public.employee ADD COLUMN danger text',
    'DROP TABLE public.employee',
    'WITH deleted AS (DELETE FROM public.employee RETURNING *) SELECT * FROM deleted',
    'SELECT * INTO public.employee_copy FROM public.employee',
    'SELECT * FROM public.employee FOR UPDATE',
    'SELECT * FROM public.employee FOR SHARE',
    "SELECT pg_advisory_lock(1)",
    "SELECT pg_sleep(60)",
    "SELECT pg_terminate_backend(123)",
    "SELECT pg_notify('migration', 'x')",
    "SELECT dblink_exec('dbname=x', 'DELETE FROM t')",
    "SELECT nextval('some_sequence')",
    "SELECT setval('some_sequence', 1)",
    "SELECT set_config('search_path', 'public', false)",
    'SELECT 1; DELETE FROM public.employee',
    'SELECT 1 /* hidden statement */',
];

foreach ($blocked as $sql) {
    try {
        LegacyReader::assertReadQuery($sql);
        fwrite(STDERR, "Unsafe legacy SQL was accepted: {$sql}\n");
        exit(1);
    } catch (RuntimeException) {
        // Expected.
    }
}

fwrite(STDOUT, "legacy read-only SQL guard: ok\n");

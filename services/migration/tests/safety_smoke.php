<?php

require __DIR__.'/../vendor/autoload.php';

use App\Migration\Core\LegacyReader;

LegacyReader::assertReadQuery('SELECT id, email FROM public.employees ORDER BY id');
LegacyReader::assertReadQuery("\n SELECT count(*) FROM public.vacation");

$blocked = [
    'UPDATE public.employees SET email = email',
    'DELETE FROM public.vacation',
    'INSERT INTO public.employee(id) VALUES (1)',
    'ALTER TABLE public.employee ADD COLUMN danger text',
    'DROP TABLE public.employee',
    'WITH deleted AS (DELETE FROM public.employee RETURNING *) SELECT * FROM deleted',
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

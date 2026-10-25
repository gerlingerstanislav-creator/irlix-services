<?php

/**
 * Vacations layering regression — source-level guard, not a substitute for
 * PostgreSQL/Keycloak or real HTTP integration tests.
 */
$base = dirname(__DIR__);
foreach (['routes/api.php', 'routes/internal.php'] as $route) {
    $source = file_get_contents($base.'/'.$route);
    if (str_contains($source, 'DB::table(')
        || preg_match('/Route::(?:get|post|put|patch|delete)\([^\n]*\b(?:function|fn)\s*\(/', $source)) {
        throw new RuntimeException("Business queries/handlers must not live in {$route}");
    }
}
foreach (['app/Application', 'app/Http/Controllers'] as $directory) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $base.'/'.$directory, FilesystemIterator::SKIP_DOTS
    ));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $source = file_get_contents($file->getPathname());
        if (str_contains($source, 'DB::table(')) {
            throw new RuntimeException('Unscoped DB facade query: '.$file->getPathname());
        }
        if (str_contains($directory, 'Controllers') && str_contains($source, 'DB::transaction(')) {
            throw new RuntimeException('HTTP controller owns a DB transaction: '.$file->getPathname());
        }
    }
}
foreach ([
    'Absence', 'AbsenceApproval', 'AbsenceAttachment', 'AbsenceStatusHistory',
    'AbsenceAuditLog', 'ProductionCalendarDay', 'ProductionCalendarYear',
] as $model) {
    $code = file_get_contents($base.'/app/Models/'.$model.'.php');
    if (!str_contains($code, 'extends Model')) throw new RuntimeException("Missing Eloquent model {$model}");
}
echo "Vacations Eloquent controller/application boundary: PASS\n";

<?php

// Clients-wide architectural boundary: every HTTP handler remains free of SQL
// and Eloquent persistence. This inventories new controllers automatically.
$root = dirname(__DIR__);
$files = glob($root.'/app/Http/Controllers/*.php');
if (!$files) throw new RuntimeException('Clients HTTP controllers were not found');
foreach ($files as $path) {
    $name = basename($path);
    if ($name === 'Controller.php') continue;
    $code = file_get_contents($path);
    if ($code === false || preg_match('/\\bDB::(?:table|transaction|select|statement)\\s*\\(/', $code)
        || preg_match('/\\b[A-Z][A-Za-z0-9_]*::query\\s*\\(/', $code)) {
        throw new RuntimeException("HTTP controller still owns persistence: {$name}");
    }
}
foreach (['api.php', 'internal.php'] as $name) {
    $routes = file_get_contents($root.'/routes/'.$name);
    if ($routes === false
        || preg_match('/\\bDB::(?:table|transaction|select|statement)\\s*\\(/', $routes)
        || preg_match('/Route::(?:get|post|put|patch|delete)\\s*\\([^\\n]*?(?:fn\\s*\\(|function\\s*\\()/',
            $routes)) {
        throw new RuntimeException("Clients {$name} contains a business route or raw database access");
    }
}
// Only documented infrastructure/low-level SQL exceptions may remain.
foreach (glob($root.'/app/Services/*.php') as $path) {
    $code = file_get_contents($path);
    if ($code === false || preg_match('/\\bDB::table\\s*\\(/', $code)) {
        throw new RuntimeException("Application service still queries via DB::table: ".basename($path));
    }
}
echo "Clients-wide Eloquent HTTP/application architecture gate: PASS\\n";

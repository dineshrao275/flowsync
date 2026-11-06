<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Counts SQL statements per API call, per connection, and lists repeated statements.
 * Run inside the app container with a logged-in session cookie jar (Netscape format):
 *   docker exec -e JAR=/tmp/jar flowsync-app php scripts/perf/query-count.php auth/me dashboard
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$cookies = [];
foreach (file(getenv('JAR') ?: '/tmp/jar') as $line) {
    $line = preg_replace('/^#HttpOnly_/', '', trim($line));
    $f = explode("\t", $line);
    if (count($f) >= 7 && $line !== '' && $line[0] !== '#') {
        $cookies[$f[5]] = urldecode($f[6]);
    }
}

$log = [];
DB::listen(function ($q) use (&$log) {
    $log[] = [$q->connectionName, $q->sql, $q->time];
});

foreach (array_slice($argv, 1) as $uri) {
    $log = [];
    $request = Request::create('/api/'.$uri, 'GET', [], $cookies, [], ['HTTP_ACCEPT' => 'application/json']);
    $t = microtime(true);
    $response = $kernel->handle($request);
    $ms = round((microtime(true) - $t) * 1000);
    $by = array_count_values(array_column($log, 0));
    $dups = array_count_values(array_column($log, 1));
    arsort($dups);
    printf("%-28s HTTP %d  %d queries  %d ms  sql_ms=%.0f  by_conn=%s\n", $uri, $response->getStatusCode(), count($log), $ms, array_sum(array_column($log, 2)), json_encode($by));
    foreach (array_slice($dups, 0, 4, true) as $sql => $n) {
        if ($n > 1) {
            printf("   x%-3d %s\n", $n, substr($sql, 0, 110));
        }
    }
    $kernel->terminate($request, $response);
    $app->forgetInstance('db');
}

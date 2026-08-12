<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$startedAt = microtime(true);

try {
    DB::select('select 1 as ok');
    $dbOk = true;
} catch (Throwable $error) {
    $dbOk = false;
}

echo json_encode([
    'status' => $dbOk ? 'ok' : 'degraded',
    'app' => 'ok',
    'db' => $dbOk ? 'ok' : 'error',
    'time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'time' => date('c'),
]);

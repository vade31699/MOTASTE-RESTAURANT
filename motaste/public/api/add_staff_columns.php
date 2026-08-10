<?php
header('Content-Type: text/plain');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
                    echo 'staff columns added';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage();
}
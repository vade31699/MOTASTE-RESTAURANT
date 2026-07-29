<?php
header('Content-Type: text/plain');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS full_name VARCHAR(191)");
    DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS role VARCHAR(100)");
    DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS email VARCHAR(191)");
    DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS password_hash VARCHAR(191)");
    echo 'staff columns added';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage();
}
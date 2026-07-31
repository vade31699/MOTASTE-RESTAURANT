<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $staffRecords = DB::table('staff')
        ->select('id', 'full_name', 'role', 'email')
        ->orderBy('id', 'asc')
        ->get()
        ->map(function ($record) {
            return [
                'id' => (int)($record->id ?? 0),
                'full_name' => trim((string)($record->full_name ?? '')),
                'role' => trim((string)($record->role ?? '')),
                'email' => trim((string)($record->email ?? '')),
            ];
        })
        ->all();

    echo json_encode(['staff' => $staffRecords]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to list staff', 'details' => $error->getMessage()]);
}

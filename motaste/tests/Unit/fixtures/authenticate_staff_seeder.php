<?php

/**
 * Seeder/cleanup subprocess for the authenticate_staff.php integration tests.
 *
 * The parent Pest process must NOT boot Laravel: the unit suite shares one
 * PHP process, and booting with a file-based SQLite DB here would hijack the
 * process-wide database config that other test files (which expect :memory:)
 * rely on. All DB work — seeding, cleanup, and the endpoint requests — happens
 * in short-lived subprocesses against a shared SQLite file.
 *
 * Reads TEST_SEED_JSON (a path to a JSON file containing an array of actions)
 * and performs each action in order:
 *   {"action":"ensureSchema"}                              - ensure tables exist
 *   {"action":"seedStaff","email":...,"password":...,"role":...}
 *   {"action":"delete","table":...,"column":...,"value":...}
 *
 * Writes a JSON result envelope to TEST_SEED_RESULT_FILE: {ok:bool, error?:string}
 */

$seedFile = (string)getenv('TEST_SEED_JSON');
$actions = is_file($seedFile) ? json_decode((string)file_get_contents($seedFile), true) : null;
if (!is_array($actions)) {
    file_put_contents((string)getenv('TEST_SEED_RESULT_FILE'), json_encode(['ok' => false, 'error' => 'TEST_SEED_JSON does not point to a JSON action array']));
    exit(1);
}

try {
    require __DIR__ . '/../../../vendor/autoload.php';

    $app = require __DIR__ . '/../../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    require_once __DIR__ . '/../../../public/api/_staff_auth_helpers.php';
    require_once __DIR__ . '/../../../public/api/_device_auth_helpers.php';

    foreach ($actions as $action) {
        switch ($action['action'] ?? '') {
            case 'ensureSchema':
                ensureStaffEnhancementSchema();
                ensureTrustedDeviceTables();
                // The staff table itself is created by migrations in
                // production; the test DB needs it explicitly.
                if (!Illuminate\Support\Facades\Schema::hasTable('staff')) {
                    Illuminate\Support\Facades\Schema::create('staff', function ($table) {
                        $table->id();
                        $table->string('email', 191)->unique();
                        $table->string('role', 100)->nullable();
                        $table->string('password_hash', 255)->nullable();
                        $table->timestamps();
                    });
                }
                break;

            case 'seedStaff':
                Illuminate\Support\Facades\DB::table('staff')->updateOrInsert(
                    ['email' => strtolower(trim((string)$action['email']))],
                    [
                        'role' => (string)($action['role'] ?? 'Admin'),
                        'password_hash' => Illuminate\Support\Facades\Hash::make((string)$action['password']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                break;

            case 'delete':
                Illuminate\Support\Facades\DB::table((string)$action['table'])
                    ->where((string)$action['column'], $action['value'])
                    ->delete();
                break;

            default:
                throw new RuntimeException('Unknown seeder action: ' . json_encode($action));
        }
    }

    file_put_contents((string)getenv('TEST_SEED_RESULT_FILE'), json_encode(['ok' => true]));
    exit(0);
} catch (Throwable $error) {
    file_put_contents(
        (string)getenv('TEST_SEED_RESULT_FILE'),
        json_encode(['ok' => false, 'error' => $error->getMessage()])
    );
    exit(1);
}

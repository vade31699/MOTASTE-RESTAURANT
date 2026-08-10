<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AdminCredentialController;
use App\Http\Controllers\Api\CsrfController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Public website
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return response()->file(public_path('index.html'));
});

/*
|--------------------------------------------------------------------------
| Unified admin dashboard (Laravel-rendered)
|--------------------------------------------------------------------------
*/
Route::get('/admin/login', [AdminController::class, 'loginPage'])->name('admin.login');
Route::post('/admin/logout', [AdminController::class, 'logout'])->name('admin.logout')->middleware('staff.session');
Route::get('/admin', [AdminController::class, 'dashboard'])->name('admin.dashboard')->middleware('staff.session');

// Legacy staff URLs redirect to the unified dashboard.
Route::get('/staff', fn () => redirect()->route('admin.dashboard'));
Route::get('/staff.html', fn () => redirect()->route('admin.dashboard'));

/*
|--------------------------------------------------------------------------
| Consolidated API — staff (clean paths, fully protected)
|--------------------------------------------------------------------------
*/
Route::prefix('api/staff')->middleware(['web', 'staff.session'])->group(function () {
    // Session
    Route::post('logout', [StaffAuthController::class, 'logout']);
    Route::get('active-count', [StaffAuthController::class, 'activeCount']);
    Route::post('notify-session', [StaffAuthController::class, 'notify']);

    // Orders
    Route::get('orders/pending', [OrderController::class, 'pending']);
    Route::get('orders/completed', [OrderController::class, 'completed']);
    Route::post('orders/{orderId}/status', [OrderController::class, 'updateStatus']);
    Route::post('orders/{orderId}/payment', [OrderController::class, 'updatePayment']);
    Route::post('orders/{orderId}/complete', [OrderController::class, 'markComplete']);
    Route::post('orders/items/update', [OrderController::class, 'updateItem']);
    Route::get('orders/status', [OrderController::class, 'status']);
    Route::get('orders/logs', [OrderController::class, 'logs']);
    Route::get('orders/events', [OrderController::class, 'events']);

    // Inventory
    Route::get('inventory', [InventoryController::class, 'index']);
    Route::post('inventory/save', [InventoryController::class, 'save']);
    Route::post('inventory/delete', [InventoryController::class, 'delete']);
    Route::post('inventory/upload-image', [InventoryController::class, 'uploadImage']);

    // Reviews
    Route::get('reviews', [ReviewController::class, 'index']);
    Route::post('reviews/publish', [ReviewController::class, 'publish']);
    Route::post('reviews/delete', [ReviewController::class, 'destroy']);
    Route::get('reviews/logs', [ReviewController::class, 'logs']);

    // Menu / highlights
    Route::get('menu', [MenuController::class, 'menu']);
    Route::post('menu/save', [MenuController::class, 'saveMenu'])->middleware('staff.session:Admin');
    Route::get('highlights', [MenuController::class, 'highlights']);
    Route::post('highlights/save', [MenuController::class, 'saveHighlights'])->middleware('staff.session:Admin');

    // Devices (admin only)
    Route::get('devices', [DeviceController::class, 'index'])->middleware('staff.session:Admin');
    Route::post('devices/revoke', [DeviceController::class, 'revoke'])->middleware('staff.session:Admin');

    // Credentials (admin only)
    Route::get('credentials', [AdminCredentialController::class, 'get'])->middleware('staff.session:Admin');
    Route::post('credentials/change-request', [AdminCredentialController::class, 'requestChange'])->middleware('staff.session:Admin');
    Route::post('credentials/change-confirm', [AdminCredentialController::class, 'confirmChange'])->middleware('staff.session:Admin');

    // Staff management (admin only)
    Route::get('list', [StaffController::class, 'index'])->middleware('staff.session:Admin');
    Route::post('create', [StaffController::class, 'store'])->middleware('staff.session:Admin');
    Route::post('update', [StaffController::class, 'update'])->middleware('staff.session:Admin');
    Route::post('delete', [StaffController::class, 'destroy'])->middleware('staff.session:Admin');
    Route::get('accounts', [StaffController::class, 'accounts'])->middleware('staff.session:Admin');
    Route::post('accounts/save', [StaffController::class, 'saveAccounts'])->middleware('staff.session:Admin');
    Route::post('invite', [StaffController::class, 'sendInvite'])->middleware('staff.session:Admin');
    Route::post('invite/confirm', [StaffController::class, 'confirmInvite'])->middleware('staff.session:Admin');

    // Activity log
    Route::post('activity-log', [ActivityLogController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Consolidated API — public (guest checkout / menu / reviews)
|--------------------------------------------------------------------------
*/
Route::prefix('api')->middleware('web')->group(function () {
    Route::get('csrf-token', [CsrfController::class, 'token']);
    Route::get('menu', [MenuController::class, 'menu']);
    Route::get('highlights', [MenuController::class, 'highlights']);
    Route::get('reviews', [ReviewController::class, 'index']);
    Route::post('reviews/save', [ReviewController::class, 'store']);
    Route::post('orders/create', [OrderController::class, 'create']);
    Route::post('orders/status', [OrderController::class, 'status']);

    // Login/session endpoints live outside the staff session guard.
    Route::get('staff/session', [StaffAuthController::class, 'session']);
    Route::post('staff/login', [StaffAuthController::class, 'login']);
    Route::post('staff/verify-device', [StaffAuthController::class, 'verifyDevice']);

    // Inventory read is used by the public menu renderer.
    Route::get('inventory', [InventoryController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Legacy-compat API paths (same controllers, exact old URLs)
|--------------------------------------------------------------------------
| These keep the existing deployed front-end working while serving every
| request through Laravel. Write paths that historically had no CSRF guard
| are excluded from CSRF validation in bootstrap/app.php.
|--------------------------------------------------------------------------
*/
// Read-only legacy paths accept GET+POST so the deployed front-end keeps
// working unchanged. State-changing legacy paths are POST-only (Laravel's
// CSRF middleware only guards non-GET verbs; GET-matched writes would be
// exploitable via cross-site requests).
Route::prefix('api')->middleware('web')->group(function () {
    Route::match(['get', 'post'], 'get_csrf_token.php', [CsrfController::class, 'token']);
    Route::match(['get', 'post'], 'get_custom_menu.php', [MenuController::class, 'menu']);
    Route::match(['get', 'post'], 'get_highlights.php', [MenuController::class, 'highlights']);
    Route::match(['get', 'post'], 'get_inventory.php', [InventoryController::class, 'index']);
    Route::match(['get', 'post'], 'get_order_status.php', [OrderController::class, 'status']);
    Route::match(['get', 'post'], 'get_reviews.php', [ReviewController::class, 'index']);
    Route::match(['get', 'post'], 'check_session.php', [StaffAuthController::class, 'session']);

    // Guest writes (public checkout / reviews).
    Route::post('create_order.php', [OrderController::class, 'create']);
    Route::post('save_review.php', [ReviewController::class, 'store']);

    // Login endpoints (pre-auth, historically no CSRF).
    Route::post('authenticate_staff.php', [StaffAuthController::class, 'login']);
    Route::post('verify_device_login.php', [StaffAuthController::class, 'verifyDevice']);
});

// Staff-protected legacy paths (transitional parity with the old staff UI).
Route::prefix('api')->middleware(['web', 'staff.session'])->group(function () {
    // Reads (GET polling by the legacy staff UI).
    Route::match(['get', 'post'], 'get_pending_orders.php', [OrderController::class, 'pending']);
    Route::match(['get', 'post'], 'get_completed_orders.php', [OrderController::class, 'completed']);
    Route::match(['get', 'post'], 'get_order_logs.php', [OrderController::class, 'logs']);
    Route::match(['get', 'post'], 'get_review_logs.php', [ReviewController::class, 'logs']);
    Route::match(['get', 'post'], 'order_events.php', [OrderController::class, 'events']);
    Route::match(['get', 'post'], 'get_staff_active_count.php', [StaffAuthController::class, 'activeCount']);

    // Writes (POST only).
    Route::post('notify_staff_session.php', [StaffAuthController::class, 'notify']);
    Route::post('mark_order_complete.php', [OrderController::class, 'markComplete']);
    Route::post('update_pending_order_item.php', [OrderController::class, 'updateItem']);
    Route::post('publish_review.php', [ReviewController::class, 'publish']);
    Route::post('delete_review.php', [ReviewController::class, 'destroy']);
    Route::post('add_activity_log.php', [ActivityLogController::class, 'store']);

    // Inventory writes (any staff).
    Route::post('update_inventory.php', [InventoryController::class, 'save']);
    Route::post('delete_inventory_item.php', [InventoryController::class, 'delete']);
    Route::post('upload_special_food_image.php', [InventoryController::class, 'uploadImage']);

    // Admin-only reads.
    Route::match(['get', 'post'], 'get_trusted_devices.php', [DeviceController::class, 'index'])->middleware('staff.session:Admin');
    Route::match(['get', 'post'], 'get_admin_credentials.php', [AdminCredentialController::class, 'get'])->middleware('staff.session:Admin');
    Route::match(['get', 'post'], 'get_staff_accounts.php', [StaffController::class, 'accounts'])->middleware('staff.session:Admin');
    Route::match(['get', 'post'], 'list_staff.php', [StaffController::class, 'index'])->middleware('staff.session:Admin');

    // Admin-only writes (menu, highlights, devices, credentials, staff).
    Route::post('save_custom_menu.php', [MenuController::class, 'saveMenu'])->middleware('staff.session:Admin');
    Route::post('save_highlights.php', [MenuController::class, 'saveHighlights'])->middleware('staff.session:Admin');
    Route::post('revoke_trusted_device.php', [DeviceController::class, 'revoke'])->middleware('staff.session:Admin');
    Route::post('request_admin_credentials_change.php', [AdminCredentialController::class, 'requestChange'])->middleware('staff.session:Admin');
    Route::post('confirm_admin_credentials_change.php', [AdminCredentialController::class, 'confirmChange'])->middleware('staff.session:Admin');
    Route::post('save_staff_accounts.php', [StaffController::class, 'saveAccounts'])->middleware('staff.session:Admin');
    Route::post('create_staff.php', [StaffController::class, 'store'])->middleware('staff.session:Admin');
    Route::post('update_staff.php', [StaffController::class, 'update'])->middleware('staff.session:Admin');
    Route::post('delete_staff.php', [StaffController::class, 'destroy'])->middleware('staff.session:Admin');
    Route::post('send_staff_invite.php', [StaffController::class, 'sendInvite'])->middleware('staff.session:Admin');
    Route::post('confirm_staff_invite.php', [StaffController::class, 'confirmInvite'])->middleware('staff.session:Admin');
});

/*
|--------------------------------------------------------------------------
| Temporary admin bootstrap (kept for safe credential recovery)
|--------------------------------------------------------------------------
*/
Route::get('/temp-admin-setup', function (Request $request) {
    $email = strtolower(trim((string) $request->query('email', '')));
    $password = trim((string) $request->query('password', ''));
    $name = trim((string) $request->query('name', 'Administrator'));

    if ($email === '' || $password === '') {
        return response()->json(['success' => false, 'error' => 'email and password query parameters are required'], 400);
    }

    try {
        $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'name' => $name,
                'password' => $passwordHash,
                'email_verified_at' => now(),
                'updated_at' => now(),
            ]);
            $userId = $user->id;
        } else {
            $userId = DB::table('users')->insertGetId([
                'name' => $name,
                'email' => $email,
                'password' => $passwordHash,
                'email_verified_at' => now(),
                'remember_token' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $staff = DB::table('staff')->where('user_id', $userId)->first();
        if ($staff) {
            DB::table('staff')->where('id', $staff->id)->update([
                'full_name' => $name,
                'role' => 'Admin',
                'email' => $email,
                'password_hash' => $passwordHash,
                'updated_at' => now(),
            ]);
        } else {
            $existingStaff = DB::table('staff')->whereRaw('LOWER(email) = ? OR LOWER(role) = ?', [$email, 'admin'])->first();
            if ($existingStaff) {
                DB::table('staff')->where('id', $existingStaff->id)->update([
                    'user_id' => $userId,
                    'full_name' => $name,
                    'role' => 'Admin',
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('staff')->insert([
                    'user_id' => $userId,
                    'position' => null,
                    'full_name' => $name,
                    'role' => 'Admin',
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'email' => $email,
            'role' => 'Admin',
            'message' => 'Temporary admin credentials created/updated. Remove this route after use.',
        ]);
    } catch (Throwable $error) {
        return response()->json(['success' => false, 'error' => $error->getMessage()], 500);
    }
});

/*
|--------------------------------------------------------------------------
| Laravel Breeze user dashboard (kept for the authenticated web users)
|--------------------------------------------------------------------------
*/
Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

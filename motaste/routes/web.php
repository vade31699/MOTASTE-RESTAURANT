<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Throwable;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
    $indexPath = base_path('../index.html');

    if (file_exists($indexPath)) {
        return response(File::get($indexPath), 200)->header('Content-Type', 'text/html');
    }

    return view('welcome');
});

Route::get('/staff', function () {
    $staffPath = public_path('staff.html');

    if (!file_exists($staffPath)) {
        abort(404);
    }

    return response()->file($staffPath);
})->name('staff');

Route::get('/staff.html', function () {
    return redirect()->route('staff');
});

Route::get('/temp-admin-setup', function (Request $request) {
    $email = strtolower(trim((string)$request->query('email', '')));
    $password = trim((string)$request->query('password', ''));
    $name = trim((string)$request->query('name', 'Administrator'));

    if ($email === '' || $password === '') {
        return response()->json(['success' => false, 'error' => 'email and password query parameters are required'], 400);
    }

    try {
        DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS full_name VARCHAR(191)");
        DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS role VARCHAR(100)");
        DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS email VARCHAR(191)");
        DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS password_hash VARCHAR(191)");

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

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

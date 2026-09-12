<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    // Served via PHP (public/home.html) rather than the platform's static-file
    // layer so the SecurityHeaders middleware applies CSP/HSTS to the homepage
    // like every other routed page.
    return response()->file(public_path('home.html'));
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

// Same portal, admin entry point. script.js uses the URL to decide the login
// surface: /admin offers password recovery, /staff never does.
Route::get('/admin', function () {
    $adminPath = public_path('staff.html');

    if (!file_exists($adminPath)) {
        abort(404);
    }

    return response()->file($adminPath);
})->name('admin.login');

Route::get('/admin.html', function () {
    return redirect()->route('admin.login');
});

// Legal pages (Philippine DPA compliance). Routed through PHP (not the
// platform's static-file layer) so the SecurityHeaders middleware applies
// CSP/HSTS to them like every other page.
Route::get('/privacy', function () {
    $path = public_path('privacy.html');
    return file_exists($path) ? response()->file($path) : abort(404);
})->name('privacy');

Route::get('/terms', function () {
    $path = public_path('terms.html');
    return file_exists($path) ? response()->file($path) : abort(404);
})->name('terms');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

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
    //
    // The homepage must also be sent with an explicit no-store header: without
    // it browsers apply heuristic caching to the HTML and keep serving the
    // older markup — including stale ?v= URLs for script.js/style.css — after
    // an update, so customers don't see new content until a hard refresh.
    $homeHeaders = ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0'];
    return response()->file(public_path('home.html'), $homeHeaders);
});

// The portal is served as a static HTML file, so it needs an explicit no-store
// header: without it browsers apply heuristic caching to the HTML and keep
// showing an older dashboard/account-management markup after an update.
$portalHeaders = ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0'];

Route::get('/staff', function () use ($portalHeaders) {
    $staffPath = public_path('staff.html');

    if (!file_exists($staffPath)) {
        abort(404);
    }

    return response()->file($staffPath, $portalHeaders);
})->name('staff');

Route::get('/staff.html', function () {
    return redirect()->route('staff');
});

// Same portal, admin entry point. script.js uses the URL to decide the login
// surface: password recovery belongs to /staff only, so /admin never offers it
// (the reset flow rejects the Admin account, which lives in the admins table).
Route::get('/admin', function () use ($portalHeaders) {
    $adminPath = public_path('staff.html');

    if (!file_exists($adminPath)) {
        abort(404);
    }

    return response()->file($adminPath, $portalHeaders);
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

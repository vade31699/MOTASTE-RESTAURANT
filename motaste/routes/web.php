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
    return response()->file(public_path('index.html'));
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

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

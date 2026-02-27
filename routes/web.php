<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::match(['GET', 'POST'], '/streams', function () {
        if (request()->isMethod('post')) {
            ob_start();
            include resource_path('views/dashboard_streams.php');
            return response(ob_get_clean());
        }

        return view('streams');
    })->name('streams');

    Route::match(['GET', 'POST'], '/streams-content', function () {
        ob_start();
        include resource_path('views/dashboard_streams.php');
        return response(ob_get_clean());
    })->name('streams.content');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

});

require __DIR__.'/auth.php';

<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\TodayPatientsController;
use App\Models\Visit;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('guest', 'throttle:6,1')
    ->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Public waiting-room screen for the TV: read-only, no patient data, no auth.
Route::view('/waiting', 'waiting')->name('waiting');

Route::middleware('auth')->group(function () {
    Route::view('/', 'reception')->name('reception');
    Route::view('/queue-number', 'queue_number')->name('queue-number');
    Route::view('/consultation-queue', 'consultation_queue')->name('consultation-queue');
    Route::view('/review-queue', 'review_queue')->name('review-queue');

    Route::get('/patients/today', [TodayPatientsController::class, 'index'])->name('patients.today');

    Route::get('/tickets/visits/{visit}', function (Visit $visit) {
        return view('ticket', ['visit' => $visit]);
    })->whereNumber('visit');

    // Queue management endpoints. They run in the web group so the staff
    // session and CSRF protection apply, exactly like the pages that call them.
    Route::prefix('api/queue')->group(function () {
        Route::get('state', [QueueController::class, 'state']);
        Route::get('stages/{stage}/visits', [QueueController::class, 'stageVisits']);
        Route::post('visits', [QueueController::class, 'store']);
        Route::post('stages/{stage}/start', [QueueController::class, 'start']);
        Route::post('stages/{stage}/call-next', [QueueController::class, 'callNext']);
        Route::post('end-work', [QueueController::class, 'endWork']);
        Route::post('clear-today', [QueueController::class, 'clearToday']);
        Route::post('visits/{visit}/complete', [QueueController::class, 'complete']);
        Route::post('visits/{visit}/skip', [QueueController::class, 'skip']);
        Route::post('visits/{visit}/move-to-review', [QueueController::class, 'moveToReview']);
        Route::post('visits/{visit}/call', [QueueController::class, 'callOne']);
        Route::post('visits/{visit}/restore', [QueueController::class, 'restore']);
    });
});

<?php

use App\Http\Controllers\QueueController;
use Illuminate\Support\Facades\Route;

/*
 * Public, read-only wait-room screen data. This endpoint is opened on the TV
 * without a login and returns only the current number and stage. Every other
 * queue endpoint lives in routes/web.php behind the authenticated session.
 */
Route::get('queue/display', [QueueController::class, 'display']);

<?php

use App\Workforce\Http\FoundationController;
use Illuminate\Support\Facades\Route;

/*
 * The workforce module: staff records, attendance, leave, payroll, advances and expenses.
 * Loaded from routes/api.php under /api/workforce, for signed-in staff only. Each route checks its own
 * permission (App\Workforce\Support\Permissions) and reach (App\Workforce\Support\Access).
 */
Route::controller(FoundationController::class)->group(function () {
    Route::get('/access', 'access');
    Route::get('/permission-matrix', 'matrix');
    Route::post('/confirm-password', 'confirmPassword')->middleware('throttle:6,1');
    Route::get('/audit', 'audit');
    Route::get('/audit/verify', 'verifyAudit');
});

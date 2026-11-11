<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Middleware\EnsureCanManageStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $database = 'connected';
    } catch (Throwable) {
        $database = 'unavailable';
    }

    return response()->json([
        'app' => config('app.name'),
        'status' => 'ok',
        'database' => $database,
    ]);
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('patients', PatientController::class)->except('destroy');

    Route::middleware(EnsureCanManageStaff::class)->group(function () {
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::put('/staff/{staff}', [StaffController::class, 'update']);
    });
});
